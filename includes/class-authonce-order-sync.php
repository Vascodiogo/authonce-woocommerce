<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closes the gap left after checkout: a completed AuthOnce payment redirects
 * the customer back here correctly (step 3), but until this file, nothing
 * ever told WooCommerce the order was actually paid — it just sat as
 * "Pending payment" forever.
 *
 * Two triggers call the same core check function:
 *   1. woocommerce_thankyou — fires the moment the customer's browser loads
 *      the order-received page. Good odds this succeeds immediately: the
 *      backend's own /link retry logic (PayPage.jsx) already finishes BEFORE
 *      redirecting the customer back here, so by the time this page loads,
 *      the subscription is very likely already linked and queryable.
 *   2. A 5-minute cron job — catches everything the immediate check missed
 *      (customer closed the tab before the redirect completed, a slow
 *      network, etc.). This is the real safety net, not the fast path.
 *
 * Give-up condition: an order untouched for 24+ hours stops being polled
 * and gets one clear note added for manual review — an abandoned/failed
 * checkout should not be queried forever.
 */

const AUTHONCE_ORDER_SYNC_MAX_AGE_HOURS = 24;

/**
 * Core check — safe to call repeatedly on the same order. Does nothing if
 * the order isn't an AuthOnce order, isn't still pending, or has already
 * given up.
 */
function authonce_wc_check_order_payment( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	// Only ever touch our own gateway's orders, and only while they're
	// still genuinely waiting — if a merchant already manually marked an
	// order Completed/Processing/Failed, this must never override that.
	if ( 'authonce' !== $order->get_payment_method() ) {
		return;
	}
	if ( ! $order->has_status( 'pending' ) ) {
		return;
	}
	if ( 'yes' === $order->get_meta( '_authonce_gave_up' ) ) {
		return;
	}

	$ref = $order->get_meta( '_authonce_ref' );
	if ( empty( $ref ) ) {
		// Shouldn't happen — process_payment() always sets this — but an
		// order with no ref has nothing to look up, so there's nothing
		// further to do here rather than erroring.
		return;
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = isset( $gateways['authonce'] ) ? $gateways['authonce'] : null;
	if ( ! $gateway || empty( $gateway->api_key ) ) {
		// Misconfigured store — leave exactly one note, not one per cron
		// tick, so the merchant notices without the order notes flooding.
		if ( 'yes' !== $order->get_meta( '_authonce_apikey_missing_notified' ) ) {
			$order->add_order_note( __( 'AuthOnce: cannot check payment status — no API key configured. Add one under WooCommerce → Settings → Payments → AuthOnce.', 'authonce-woocommerce' ) );
			$order->update_meta_data( '_authonce_apikey_missing_notified', 'yes' );
			$order->save();
		}
		return;
	}

	// 24h+ and still pending — stop polling, leave a note, let a human
	// decide. Checked before the network call so a permanently-abandoned
	// order doesn't keep costing an HTTP request every 5 minutes forever.
	$age_hours = ( time() - $order->get_date_created()->getTimestamp() ) / HOUR_IN_SECONDS;
	if ( $age_hours > AUTHONCE_ORDER_SYNC_MAX_AGE_HOURS ) {
		$order->add_order_note( __( 'AuthOnce: no payment detected after 24 hours. Stopped checking automatically — verify manually if the customer reports paying.', 'authonce-woocommerce' ) );
		$order->update_meta_data( '_authonce_gave_up', 'yes' );
		$order->save();
		return;
	}

	$url = WC_Gateway_AuthOnce::API_BASE_TESTNET . '/api/subscriptions/by-ref/' . rawurlencode( $ref );

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $gateway->api_key,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Network-level failure — transient, nothing to do but let the
		// next cron tick retry. Not logged to the order itself; a single
		// dropped connection isn't worth a customer/merchant-visible note.
		error_log( '[AuthOnce] by-ref request failed for order ' . $order_id . ': ' . $response->get_error_message() );
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 401 === $code ) {
		if ( 'yes' !== $order->get_meta( '_authonce_apikey_invalid_notified' ) ) {
			$order->add_order_note( __( 'AuthOnce: API key was rejected (401). Check WooCommerce → Settings → Payments → AuthOnce — it may need regenerating.', 'authonce-woocommerce' ) );
			$order->update_meta_data( '_authonce_apikey_invalid_notified', 'yes' );
			$order->save();
		}
		return;
	}

	if ( 404 === $code || empty( $body['found'] ) ) {
		// Not indexed/linked yet — completely normal, not an error. Leave
		// the order alone; the next check (thankyou hook already fired
		// once, cron will try again) may find it.
		return;
	}

	if ( 200 !== $code ) {
		// Anything else (429 rate-limited, 5xx) — transient, retry later.
		error_log( '[AuthOnce] by-ref unexpected status ' . $code . ' for order ' . $order_id );
		return;
	}

	// Found — a real on-chain subscription exists and is linked to this
	// order's ref. That's sufficient proof of payment regardless of the
	// subscription's current status value (active vs. later cancelled by
	// the subscriber is a separate, later event — creation itself is what
	// proves this specific order was paid).
	$tx_hash = isset( $body['tx_hash'] ) ? sanitize_text_field( $body['tx_hash'] ) : '';

	// WooCommerce's own official "this order was paid" method — correctly
	// transitions status (Completed for virtual/no-shipping products,
	// Processing otherwise) and fires the normal WooCommerce hooks/emails,
	// rather than this plugin guessing and hardcoding a status transition.
	$order->payment_complete( $tx_hash );

	$order->add_order_note(
		sprintf(
			/* translators: 1: AuthOnce subscription ID, 2: transaction hash */
			__( 'AuthOnce: payment confirmed. Subscription #%1$s created on-chain. Tx: %2$s', 'authonce-woocommerce' ),
			isset( $body['subscription_id'] ) ? absint( $body['subscription_id'] ) : '?',
			$tx_hash ? $tx_hash : '(none returned)'
		)
	);
	$order->update_meta_data( '_authonce_tx_hash', $tx_hash );
	$order->update_meta_data( '_authonce_subscription_id', isset( $body['subscription_id'] ) ? absint( $body['subscription_id'] ) : '' );
	$order->save();
}

/**
 * Fast path — check the instant the customer lands back here. Hooked at
 * default priority on WooCommerce's own thank-you page action, which
 * already receives $order_id as its argument.
 */
add_action( 'woocommerce_thankyou', 'authonce_wc_check_order_payment' );

/**
 * Cron fallback — the real safety net. Scans all still-pending AuthOnce
 * orders and re-checks each one. Deliberately queries by payment_method +
 * status rather than keeping a separate tracking list — WooCommerce's own
 * order data is already the source of truth for "which orders are we still
 * waiting on," so there's nothing else to keep in sync.
 */
function authonce_wc_cron_check_pending_orders() {
	$orders = wc_get_orders(
		array(
			'payment_method' => 'authonce',
			'status'         => 'pending',
			'limit'          => 100, // one polling batch — plenty for testnet/early volume; revisit if this ever needs pagination.
			'return'         => 'ids',
		)
	);

	foreach ( $orders as $order_id ) {
		authonce_wc_check_order_payment( $order_id );
	}
}
add_action( 'authonce_check_pending_orders', 'authonce_wc_cron_check_pending_orders' );
