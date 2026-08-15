<?php
/**
 * Plugin Name: AuthOnce for WooCommerce
 * Description: Accept non-custodial stablecoin subscriptions via AuthOnce. Customers pay with their own wallet — this plugin never touches funds or private keys.
 * Version: 0.6.3
 * Author: AuthOnce
 * Text Domain: authonce-woocommerce
 * Requires Plugins: woocommerce
 *
 * Build progress: 0.6.0 built the Store API + JS fix for cart/checkout
 * display; live test showed cart-item-meta.js never loads at all. Ruled
 * out the 'wc-blocks-checkout' handle name (confirmed correct against
 * official docs). 0.6.2: found a real, documented risk matching this
 * exact symptom — WooCommerce's own theming docs warn that block themes
 * placing Cart/Checkout blocks directly in FSE templates (not page
 * content) can break page-content-based detection, and there are
 * confirmed WooCommerce core bugs (#56041, #61267) where is_cart()/
 * is_checkout() themselves misbehave on block-theme setups. Added
 * has_block() as a second, independent detection method alongside the
 * conditional tags. Still logging both results either way — the actual
 * log output is still the deciding evidence, this isn't assumed fixed.
 * 0.6.3: fixed cart-item-meta.js reading cart.items instead of the real
 * cart.cartItems prop (root cause of the annotation never rendering
 * despite loading correctly). class-authonce-price-sync.php now sets
 * sold_individually on synced products — AuthOnce subscriptions have no
 * quantity concept, so this closes a real mismatch where WooCommerce
 * would show "x2" while the AuthOnce pay page can only ever create one
 * subscription. Declared HPOS (custom_order_tables) compatibility below
 * — the order-sync code was already using the modern CRUD API throughout,
 * this just makes that explicit to WooCommerce and the Marketplace review.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'AUTHONCE_WC_VERSION', '0.6.3' );
define( 'AUTHONCE_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Declare compatibility with the block-based Cart/Checkout.
 *
 * WC_Gateway_AuthOnce is a legacy (shortcode-era) gateway with no custom
 * fields at checkout (has_fields = false, just a redirect on submit), so
 * WooCommerce's built-in legacy-gateway bridge should render it inside
 * the Checkout block automatically. But newer WooCommerce versions can
 * still hide a gateway that hasn't explicitly declared itself compatible
 * with the block checkout — this declaration removes that ambiguity
 * rather than relying on the bridge's default behavior.
 *
 * Must run on before_woocommerce_init, not plugins_loaded — this is
 * WooCommerce's own documented requirement for feature-compatibility
 * declarations, and it fires earlier than plugins_loaded.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
			// HPOS (High-Performance Order Storage): class-authonce-order-sync.php
			// already uses only wc_get_order()/$order->get_meta()/update_meta_data()/
			// save()/payment_complete() and wc_get_orders() — the modern CRUD API,
			// never a direct get_post_meta()/wp_update_post() on order data. So the
			// code itself is already HPOS-safe; this declaration just tells
			// WooCommerce (and the Marketplace review) that explicitly, instead of
			// leaving the plugin flagged as unverified by default.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Bail early and show an admin notice if WooCommerce isn't active, rather
 * than fatal-erroring the whole site — this plugin is useless without it,
 * but a broken site is worse than a plugin that politely declines to load.
 */
function authonce_wc_missing_woocommerce_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'AuthOnce for WooCommerce requires WooCommerce to be installed and active.', 'authonce-woocommerce' ); ?></p>
	</div>
	<?php
}

function authonce_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'authonce_wc_missing_woocommerce_notice' );
		return;
	}

	require_once AUTHONCE_WC_PLUGIN_DIR . 'includes/class-authonce-gateway.php';
	require_once AUTHONCE_WC_PLUGIN_DIR . 'includes/class-authonce-product-fields.php';
	require_once AUTHONCE_WC_PLUGIN_DIR . 'includes/class-authonce-order-sync.php';
	require_once AUTHONCE_WC_PLUGIN_DIR . 'includes/class-authonce-price-sync.php';
	require_once AUTHONCE_WC_PLUGIN_DIR . 'includes/class-authonce-cart-item-data.php';

	add_filter( 'woocommerce_payment_gateways', 'authonce_wc_register_gateway' );
}
add_action( 'plugins_loaded', 'authonce_wc_init' );

/**
 * Register our gateway class with WooCommerce's list of available gateways.
 * WooCommerce calls this filter itself when it needs the list — we just add
 * our class name to whatever's already there. This alone is enough for the
 * legacy shortcode checkout; the Checkout block needs the separate
 * registration below as well.
 */
function authonce_wc_register_gateway( $gateways ) {
	$gateways[] = 'WC_Gateway_AuthOnce';
	return $gateways;
}

/**
 * Registers the JS asset the Checkout block loads to render this payment
 * method. Registered (not enqueued) here — WooCommerce Blocks enqueues it
 * itself, only on pages that actually contain the block, via the
 * get_payment_method_script_handles() call in Authonce_Blocks_Support.
 */
function authonce_wc_register_blocks_script() {
	wp_register_script(
		'authonce-blocks-integration',
		plugins_url( 'assets/js/blocks-integration.js', __FILE__ ),
		array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
		AUTHONCE_WC_VERSION,
		true
	);

	// NOT auto-loaded via the payment-method-script mechanism like the
	// script above — this one needs to run on Cart/Checkout regardless of
	// which payment method is selected, so it's enqueued directly below
	// rather than tied to Authonce_Blocks_Support's script handle list.
	wp_register_script(
		'authonce-cart-item-meta',
		plugins_url( 'assets/js/cart-item-meta.js', __FILE__ ),
		array( 'wp-element', 'wp-plugins', 'wc-blocks-checkout' ),
		AUTHONCE_WC_VERSION,
		true
	);
}
add_action( 'init', 'authonce_wc_register_blocks_script' );

/**
 * Enqueues the cart-item annotation script only on Cart/Checkout pages —
 * no point loading it site-wide. is_cart()/is_checkout() still correctly
 * identify these pages even though they render as Blocks, since that's
 * based on which page WooCommerce Settings has assigned as Cart/Checkout,
 * not the block type itself.
 */
function authonce_wc_enqueue_cart_item_meta() {
	// Two independent detection methods, OR'd together — belt and
	// suspenders. is_cart()/is_checkout() have confirmed, acknowledged
	// bugs in recent WooCommerce versions on block-theme setups
	// (WooCommerce GitHub issues #56041, #61267), and WooCommerce's own
	// theming docs warn that if a block theme places the Cart/Checkout
	// block directly in its FSE template rather than the page's actual
	// content, page-content-based detection can miss it too. Neither
	// method is unconditionally reliable on its own, so both are checked.
	$has_block_match = function_exists( 'has_block' )
		&& ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) );

	$is_cart_page     = function_exists( 'is_cart' ) ? is_cart() : false;
	$is_checkout_page = function_exists( 'is_checkout' ) ? is_checkout() : false;

	error_log( '[AuthOnce] enqueue check: has_block=' . var_export( $has_block_match, true ) . ' is_cart=' . var_export( $is_cart_page, true ) . ' is_checkout=' . var_export( $is_checkout_page, true ) );

	if ( $has_block_match || $is_cart_page || $is_checkout_page ) {
		$result = wp_enqueue_script( 'authonce-cart-item-meta' );
		error_log( '[AuthOnce] wp_enqueue_script(authonce-cart-item-meta) called. Registered? ' . var_export( wp_script_is( 'authonce-cart-item-meta', 'registered' ), true ) . '. Enqueued? ' . var_export( wp_script_is( 'authonce-cart-item-meta', 'enqueued' ), true ) );
	}
}
add_action( 'wp_enqueue_scripts', 'authonce_wc_enqueue_cart_item_meta' );

/**
 * Tells WooCommerce Blocks that Authonce_Blocks_Support exists and should
 * be added to the Checkout block's payment method registry. This is the
 * documented hook for this specific job — separate from the
 * `woocommerce_payment_gateways` filter above, and separate from the
 * before_woocommerce_init compatibility declaration further up this file.
 * All three are required together; each does a different, non-overlapping
 * part of making this gateway work in both checkout types.
 */
function authonce_wc_register_blocks_support( $payment_method_registry ) {
	// Guards against two different load-order edge cases: WC_Gateway_AuthOnce
	// not yet defined (plugins_loaded hasn't run), and the WooCommerce Blocks
	// base class itself not existing (an old WooCommerce Blocks/WooCommerce
	// core version without it). Either way, fail quietly rather than
	// fatal-erroring the whole site over one payment method not registering.
	if ( ! class_exists( 'WC_Gateway_AuthOnce' ) ) {
		return;
	}
	if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
		return;
	}
	require_once AUTHONCE_WC_PLUGIN_DIR . 'includes/class-authonce-blocks-support.php';
	$payment_method_registry->register( new Authonce_Blocks_Support() );
}
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	'authonce_wc_register_blocks_support'
);

/**
 * WordPress's built-in cron intervals only go as fine as hourly — nothing
 * built-in fits "check pending crypto payments every few minutes." This
 * filter adds one custom interval used only by our own scheduled event
 * below.
 */
function authonce_wc_cron_interval( $schedules ) {
	$schedules['authonce_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes (AuthOnce)', 'authonce-woocommerce' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'authonce_wc_cron_interval' );

/**
 * Schedule the recurring pending-order check on activation, clear it on
 * deactivation. Without the deactivation half, disabling the plugin would
 * leave an orphaned cron job trying to call a hook whose handler function
 * no longer loads — WordPress cron doesn't automatically clean these up.
 *
 * register_activation_hook/register_deactivation_hook need the exact
 * primary plugin file path — plugin_basename(__FILE__) here, not a
 * function reference inside authonce_wc_init(), since activation happens
 * before plugins_loaded ever fires.
 */
function authonce_wc_activate() {
	if ( ! wp_next_scheduled( 'authonce_check_pending_orders' ) ) {
		wp_schedule_event( time(), 'authonce_five_minutes', 'authonce_check_pending_orders' );
	}
	if ( ! wp_next_scheduled( 'authonce_sync_product_prices' ) ) {
		wp_schedule_event( time(), 'hourly', 'authonce_sync_product_prices' );
	}
}
register_activation_hook( __FILE__, 'authonce_wc_activate' );

function authonce_wc_deactivate() {
	wp_clear_scheduled_hook( 'authonce_check_pending_orders' );
	wp_clear_scheduled_hook( 'authonce_sync_product_prices' );
}
register_deactivation_hook( __FILE__, 'authonce_wc_deactivate' );
