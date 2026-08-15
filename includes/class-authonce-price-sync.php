<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixes a real correctness problem, not just a missing convenience feature:
 * WooCommerce's own Regular Price field and AuthOnce's real, binding price
 * were two independent numbers with nothing keeping them in sync. A
 * merchant could type any price into WooCommerce, see that number in the
 * cart, then get redirected to AuthOnce's pay page showing a completely
 * different price — confusing at best, and a real trust problem for a
 * payments product.
 *
 * The fix: AuthOnce is the only source of truth. This file overwrites
 * WooCommerce's price field with AuthOnce's real price every time a linked
 * product is saved (server-side — wins over whatever was typed, even if
 * someone bypasses the UI), and again every hour in the background, so a
 * price change made only on AuthOnce still propagates here without anyone
 * touching the WooCommerce product at all.
 */

/**
 * Resolves a merchant handle (e.g. "promerchant") to the real wallet
 * address AuthOnce's product API actually needs — the by-slug product
 * endpoint takes an address, not a handle, confirmed directly against
 * PayPage.jsx's own fetch calls. Cached for 6 hours; a merchant's handle
 * practically never changes, and this avoids one extra HTTP round trip on
 * every single price sync.
 */
function authonce_wc_resolve_merchant_address( $handle ) {
	$handle = sanitize_text_field( $handle );
	if ( empty( $handle ) ) {
		error_log( '[AuthOnce] resolve_merchant_address: no handle configured' );
		return null;
	}

	$cache_key = 'authonce_addr_' . md5( $handle );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		if ( empty( $cached ) ) {
			error_log( '[AuthOnce] resolve_merchant_address: using cached FAILURE for handle "' . $handle . '" (retries in up to 5 min)' );
		}
		return $cached ?: null; // cached null means "resolution failed last time" — see below.
	}

	$response = wp_remote_get(
		WC_Gateway_AuthOnce::API_BASE_TESTNET . '/api/handle/' . rawurlencode( $handle ),
		array( 'timeout' => 10 )
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[AuthOnce] resolve_merchant_address: wp_remote_get error for handle "' . $handle . '": ' . $response->get_error_message() );
		set_transient( $cache_key, '', 5 * MINUTE_IN_SECONDS );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		error_log( '[AuthOnce] resolve_merchant_address: HTTP ' . $code . ' for handle "' . $handle . '". Body: ' . wp_remote_retrieve_body( $response ) );
		set_transient( $cache_key, '', 5 * MINUTE_IN_SECONDS );
		return null;
	}

	$body    = json_decode( wp_remote_retrieve_body( $response ), true );
	$address = isset( $body['wallet_address'] ) ? sanitize_text_field( $body['wallet_address'] ) : null;

	if ( ! $address ) {
		error_log( '[AuthOnce] resolve_merchant_address: HTTP 200 but no wallet_address in response for handle "' . $handle . '". Body: ' . wp_remote_retrieve_body( $response ) );
	}

	set_transient( $cache_key, $address ?: '', 6 * HOUR_IN_SECONDS );
	return $address;
}

/**
 * Fetches the real product record from AuthOnce — same public endpoint
 * PayPage.jsx itself uses, so this plugin sees exactly what a customer's
 * browser would see at checkout time. No caching here (unlike the handle
 * resolver above) — price is the one thing we specifically do NOT want
 * served stale.
 */
function authonce_wc_fetch_authonce_product( $merchant_address, $slug ) {
	$url = WC_Gateway_AuthOnce::API_BASE_TESTNET
		. '/api/products/' . rawurlencode( $merchant_address )
		. '/' . rawurlencode( $slug );

	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

	if ( is_wp_error( $response ) ) {
		error_log( '[AuthOnce] fetch_authonce_product: wp_remote_get error for ' . $url . ': ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		error_log( '[AuthOnce] fetch_authonce_product: HTTP ' . $code . ' for ' . $url . '. Body: ' . wp_remote_retrieve_body( $response ) );
		return null;
	}

	return json_decode( wp_remote_retrieve_body( $response ), true );
}

/**
 * Core sync — safe to call repeatedly. Does nothing (leaves the existing
 * WooCommerce price untouched) if the product has no AuthOnce slug, or if
 * anything about the fetch fails. A failed sync must never corrupt the
 * price to zero/blank — leaving a stale-but-real number is far less
 * harmful than silently wiping it.
 */
function authonce_wc_sync_product_price( $product_id ) {
	$slug = authonce_wc_get_product_slug( $product_id );
	if ( empty( $slug ) ) {
		return false;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	error_log( '[AuthOnce] sync_product_price(' . $product_id . '): available gateway keys: [' . implode( ', ', array_keys( $gateways ) ) . ']' );

	$gateway  = isset( $gateways['authonce'] ) ? $gateways['authonce'] : null;
	if ( ! $gateway || empty( $gateway->merchant_handle ) ) {
		error_log( '[AuthOnce] sync_product_price(' . $product_id . '): gateway not found or merchant_handle not configured' );
		return false;
	}

	$address = authonce_wc_resolve_merchant_address( $gateway->merchant_handle );
	if ( ! $address ) {
		error_log( '[AuthOnce] sync_product_price(' . $product_id . '): could not resolve address for handle "' . $gateway->merchant_handle . '" — see resolve_merchant_address log above' );
		return false;
	}

	$product_data = authonce_wc_fetch_authonce_product( $address, $slug );
	if ( ! $product_data || ! isset( $product_data['amount'] ) ) {
		error_log( '[AuthOnce] sync_product_price(' . $product_id . '): fetch failed or no amount field for slug "' . $slug . '" / address "' . $address . '". Response: ' . wp_json_encode( $product_data ) );
		return false;
	}

	$price = wc_format_decimal( $product_data['amount'] );

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		error_log( '[AuthOnce] sync_product_price(' . $product_id . '): wc_get_product() returned nothing' );
		return false;
	}

	$product->set_regular_price( $price );
	$product->set_price( $price );
	// AuthOnce subscriptions have no quantity concept — one wallet signs
	// once for one fixed recurring amount. Locking to 1 here prevents a
	// WooCommerce cart from showing "x2" while AuthOnce's own pay page has
	// no way to honor anything but a single subscription.
	$product->set_sold_individually( true );
	$product->save();

	update_post_meta( $product_id, '_authonce_price_synced_at', time() );
	update_post_meta( $product_id, '_authonce_price_interval', isset( $product_data['interval'] ) ? sanitize_text_field( $product_data['interval'] ) : '' );

	// First accepted crypto token, used for display (e.g. "USDC"). The API's
	// payment_methods array mixes a generic category label ("crypto") in
	// with the actual token names — confirmed directly against a real API
	// response (payment_methods: ["crypto", "usdc"]), not assumed. Taking
	// index 0 blindly grabbed "crypto" and displayed that literally
	// everywhere ("7.00 CRYPTO") — a real, confirmed bug, not a guess.
	// Filtering to only the known real tokens, and joining all of them if
	// a product accepts more than one (e.g. "USDC/EURC"), fixes this
	// correctly rather than just picking a different single index.
	$token = '';
	if ( ! empty( $product_data['payment_methods'] ) && is_array( $product_data['payment_methods'] ) ) {
		$known_tokens = array( 'usdc', 'usdt', 'eurc' );
		$lower        = array_map( 'strtolower', array_map( 'sanitize_text_field', $product_data['payment_methods'] ) );
		$real_tokens  = array_values( array_intersect( $lower, $known_tokens ) );
		if ( ! empty( $real_tokens ) ) {
			$token = strtoupper( implode( '/', $real_tokens ) );
		}
	}
	update_post_meta( $product_id, '_authonce_price_token', $token );

	// Intro pricing and yearly billing — deliberately stored as plain info
	// meta, NOT mapped into WooCommerce's own Sale Price or Variable
	// Product mechanics. Those are structurally different concepts (a WC
	// sale price is a store-wide current discount shown to every shopper;
	// AuthOnce's intro price is a per-subscriber discount on their own
	// first N billing cycles, something WooCommerce never actually
	// tracks or enforces — only the FIRST checkout ever touches
	// WooCommerce at all). Forcing this into WC's sale-price machinery
	// would risk real side effects (tax calc, "on sale" badges,
	// scheduling) for something that was never really a WooCommerce sale.
	update_post_meta( $product_id, '_authonce_intro_amount', isset( $product_data['intro_amount'] ) ? wc_format_decimal( $product_data['intro_amount'] ) : '' );
	update_post_meta( $product_id, '_authonce_intro_pulls', isset( $product_data['intro_pulls'] ) ? absint( $product_data['intro_pulls'] ) : '' );
	update_post_meta( $product_id, '_authonce_yearly_amount', isset( $product_data['yearly_amount'] ) ? wc_format_decimal( $product_data['yearly_amount'] ) : '' );

	error_log( '[AuthOnce] sync_product_price(' . $product_id . '): SUCCESS — price set to ' . $price );

	return true;
}

/**
 * Immediate correction on every save. Priority 20 — deliberately after
 * authonce_wc_save_product_slug_field()'s default priority 10, so if a
 * merchant just typed in a brand-new slug and saved, this sync reads the
 * slug that was JUST saved, not whatever was there before this request.
 */
add_action( 'woocommerce_process_product_meta', 'authonce_wc_sync_product_price', 20 );

/**
 * Background catch-all — every product with an AuthOnce slug gets
 * re-synced hourly, regardless of whether anyone has touched it in
 * WooCommerce. This is what covers "merchant changed the price only on
 * AuthOnce and never opens this WooCommerce product again."
 */
function authonce_wc_cron_sync_all_prices() {
	$product_ids = get_posts(
		array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_authonce_product_slug',
					'value'   => '',
					'compare' => '!=',
				),
			),
		)
	);

	foreach ( $product_ids as $product_id ) {
		authonce_wc_sync_product_price( $product_id );
	}
}
add_action( 'authonce_sync_product_prices', 'authonce_wc_cron_sync_all_prices' );

/**
 * Read-only status line under the slug field — shows the merchant the
 * REAL price this product will actually charge, and when it was last
 * confirmed, so nobody is surprised when a typed price "reverts" after
 * save. Deliberately not a live API call on every admin page load (that
 * would slow down the product list/edit screen) — just reads what the
 * last sync already stored.
 */
add_action( 'woocommerce_product_options_general_product_data', 'authonce_wc_show_price_sync_status', 20 );

function authonce_wc_show_price_sync_status() {
	global $post;
	if ( ! $post ) {
		return;
	}

	$slug = authonce_wc_get_product_slug( $post->ID );
	if ( empty( $slug ) ) {
		return;
	}

	$price      = get_post_meta( $post->ID, '_regular_price', true );
	$interval   = get_post_meta( $post->ID, '_authonce_price_interval', true );
	$token      = get_post_meta( $post->ID, '_authonce_price_token', true );
	$synced_at  = get_post_meta( $post->ID, '_authonce_price_synced_at', true );
	$intro_amt  = get_post_meta( $post->ID, '_authonce_intro_amount', true );
	$intro_pulls = get_post_meta( $post->ID, '_authonce_intro_pulls', true );
	$yearly_amt = get_post_meta( $post->ID, '_authonce_yearly_amount', true );

	echo '<div class="options_group" style="padding: 0 12px 12px;">';
	echo '<p class="form-field" style="color:#666; font-size:12px;">';
	echo '🔒 ' . esc_html__( 'Price is managed on AuthOnce, not here — this field is overwritten from AuthOnce every time you save, and again automatically every hour.', 'authonce-woocommerce' );

	if ( $price && $synced_at ) {
		// wc_price() returns HTML (a formatted <span> structure) — do NOT
		// esc_html() it, that was the bug: esc_html() turned the HTML tags
		// into literal visible text instead of letting them render. It's
		// WooCommerce's own trusted output, safe to print directly here,
		// same as WC does in its own templates.
		echo '<br>' . sprintf(
			/* translators: 1: formatted price HTML, 2: crypto token e.g. USDC, 3: interval, 4: human-readable time since last sync */
			esc_html__( 'Real price: %1$s %2$s / %3$s — last confirmed %4$s ago.', 'authonce-woocommerce' ),
			wc_price( $price ), // phpcs:ignore -- trusted WC-generated HTML, intentionally not escaped
			esc_html( $token ?: '' ),
			esc_html( $interval ?: '?' ),
			esc_html( human_time_diff( $synced_at, time() ) )
		);

		// Informational only — see the comment in authonce_wc_sync_product_price()
		// for why these are NOT mapped to WC's Sale Price / Variable Product
		// features. This is just telling the merchant what AuthOnce will
		// actually do, not something WooCommerce enforces or displays to
		// shoppers anywhere else.
		if ( $intro_amt && $intro_pulls ) {
			echo '<br>' . sprintf(
				/* translators: 1: intro price HTML, 2: number of cycles, 3: token */
				esc_html__( 'Intro pricing on AuthOnce: %1$s %3$s for the first %2$s cycle(s), then reverts to the regular price automatically.', 'authonce-woocommerce' ),
				wc_price( $intro_amt ), // phpcs:ignore -- trusted WC-generated HTML
				esc_html( $intro_pulls ),
				esc_html( $token ?: '' )
			);
		}
		if ( $yearly_amt ) {
			echo '<br>' . sprintf(
				/* translators: 1: yearly price HTML, 2: token */
				esc_html__( 'Yearly option also available on AuthOnce: %1$s %2$s/year (the customer chooses monthly vs. yearly on AuthOnce\'s own pay page — not selectable here).', 'authonce-woocommerce' ),
				wc_price( $yearly_amt ), // phpcs:ignore -- trusted WC-generated HTML
				esc_html( $token ?: '' )
			);
		}
	} elseif ( $price ) {
		echo '<br>' . esc_html__( 'Not yet confirmed against AuthOnce — save this product to sync the real price now.', 'authonce-woocommerce' );
	}

	echo '</p>';
	echo '</div>';
}

/**
 * Shows the real crypto token (e.g. "USDC") instead of the store's default
 * currency symbol on shop/product pages, for AuthOnce-linked products only
 * — the store's default $ symbol is misleading here since the actual
 * charge is in a stablecoin, not fiat.
 *
 * KNOWN LIMITATION, stated plainly rather than overclaimed: this filter
 * covers the shop archive and single product pages, which is where
 * WooCommerce's own price-html rendering pipeline runs. The Cart/Checkout
 * blocks (confirmed elsewhere in this project to be the block-based
 * system, not the legacy shortcode cart/checkout) render prices through a
 * separate Store API formatting path that this filter does NOT reliably
 * reach — cart/checkout may still show the store's default currency
 * symbol until that's specifically tested and, if needed, addressed
 * separately.
 */
add_filter( 'woocommerce_get_price_html', 'authonce_wc_filter_price_html', 10, 2 );

function authonce_wc_filter_price_html( $price_html, $product ) {
	$slug = authonce_wc_get_product_slug( $product->get_id() );
	if ( empty( $slug ) ) {
		return $price_html;
	}

	$token = get_post_meta( $product->get_id(), '_authonce_price_token', true );
	if ( empty( $token ) ) {
		return $price_html; // Not synced yet — fall back to WooCommerce's default rather than show a broken/blank label.
	}

	$price = $product->get_price();
	if ( '' === $price ) {
		return $price_html;
	}

	$interval    = get_post_meta( $product->get_id(), '_authonce_price_interval', true );
	$intro_amt   = get_post_meta( $product->get_id(), '_authonce_intro_amount', true );
	$intro_pulls = get_post_meta( $product->get_id(), '_authonce_intro_pulls', true );

	$base = number_format( (float) $price, 2 ) . ' ' . esc_html( $token );

	// Intro pricing shown to the actual shopper, not just admin — a
	// subscriber deciding whether to buy shouldn't have to click through
	// to AuthOnce's own pay page just to learn the real first-cycle price.
	if ( $intro_amt && $intro_pulls ) {
		return sprintf(
			/* translators: 1: intro price, 2: cycle count, 3: regular price, both already formatted with token */
			esc_html__( '%1$s for first %2$s cycle(s), then %3$s', 'authonce-woocommerce' ),
			number_format( (float) $intro_amt, 2 ) . ' ' . esc_html( $token ),
			esc_html( $intro_pulls ),
			$base
		);
	}

	return $base;
}

/**
 * Cart/Checkout price display, take two: woocommerce_get_price_html above
 * only reaches the shop/product-page rendering path. WooCommerce's
 * Cart/Checkout BLOCKS (confirmed elsewhere in this project to be what
 * this store actually uses) format currency for the whole cart at once
 * through a separate internal system — there is no equally simple,
 * reliable filter to swap just one product's currency SYMBOL there.
 *
 * Rather than fake a fix that might silently not apply, this adds a small
 * annotation next to the product name instead — same information, honest
 * about what's actually happening, and this specific filter IS one
 * WooCommerce Blocks still honors for backward compatibility (it reuses
 * the classic item-name rendering path). If it turns out not to render in
 * a specific WooCommerce version, that will be visibly obvious (name just
 * won't have the note) rather than silently wrong.
 */
add_filter( 'woocommerce_cart_item_name', 'authonce_wc_annotate_cart_item_name', 10, 3 );

function authonce_wc_annotate_cart_item_name( $name_html, $cart_item, $cart_item_key ) {
	$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
	if ( ! $product_id ) {
		return $name_html;
	}

	$slug = authonce_wc_get_product_slug( $product_id );
	if ( empty( $slug ) ) {
		return $name_html;
	}

	$token = get_post_meta( $product_id, '_authonce_price_token', true );
	if ( empty( $token ) ) {
		return $name_html;
	}

	$note = sprintf(
		/* translators: %s: crypto token, e.g. USDC */
		esc_html__( 'Paid in %s via AuthOnce', 'authonce-woocommerce' ),
		esc_html( $token )
	);

	$intro_amt   = get_post_meta( $product_id, '_authonce_intro_amount', true );
	$intro_pulls = get_post_meta( $product_id, '_authonce_intro_pulls', true );
	$price       = get_post_meta( $product_id, '_regular_price', true );

	if ( $intro_amt && $intro_pulls ) {
		$note .= ' · ' . sprintf(
			/* translators: 1: intro price, 2: cycle count, 3: regular price */
			esc_html__( 'Intro: %1$s %3$s for %2$s cycle(s), then %4$s %3$s', 'authonce-woocommerce' ),
			number_format( (float) $intro_amt, 2 ),
			esc_html( $intro_pulls ),
			esc_html( $token ),
			number_format( (float) $price, 2 )
		);
	}

	return $name_html . '<br><small style="color:#666;">' . $note . '</small>';
}
