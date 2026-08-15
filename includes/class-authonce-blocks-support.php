<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges WC_Gateway_AuthOnce into the block-based Checkout.
 *
 * A classic WC_Payment_Gateway registered via the
 * `woocommerce_payment_gateways` filter is enough for the legacy
 * shortcode checkout, and some WooCommerce versions auto-bridge simple
 * legacy gateways into the Checkout block too — but that bridge isn't
 * guaranteed, isn't present on every version, and testing on this
 * project's own environment confirmed it does not happen automatically
 * here (Cash on delivery, a legacy gateway, showed at checkout; AuthOnce,
 * registered and "Active" in the same admin list, did not). This class
 * is the real, version-independent registration path the WooCommerce
 * Blocks package expects: a PHP class extending AbstractPaymentMethodType,
 * paired with a small JS file that calls registerPaymentMethod().
 *
 * has_fields = false on the gateway itself means there's no custom form
 * to render here — the JS side just shows the title/description and lets
 * "Place order" call process_payment() exactly as before. No behavior
 * change to the actual redirect logic, only to how the option becomes
 * visible in this specific checkout UI.
 */
class Authonce_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Must match WC_Gateway_AuthOnce::$id exactly — this is how WooCommerce
	 * Blocks correlates the block-side registration with the underlying
	 * classic gateway instance for settings, is_available(), and
	 * eventually process_payment().
	 */
	protected $name = 'authonce';

	/**
	 * @var WC_Gateway_AuthOnce
	 */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_authonce_settings', array() );

		$gateways = WC()->payment_gateways->payment_gateways();
		$this->gateway = isset( $gateways['authonce'] ) ? $gateways['authonce'] : null;
	}

	/**
	 * Whether this payment method should be offered at all. Delegates to
	 * the same is_available() the classic checkout already uses (enabled
	 * + merchant_handle configured) — one source of truth, not a second
	 * copy of that logic that could drift out of sync.
	 */
	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Registers the JS file that actually adds this method to the block
	 * checkout's payment method list. wp_register_script() call for this
	 * handle lives in the main plugin file, alongside the version/deps
	 * declaration, not duplicated here.
	 */
	public function get_payment_method_script_handles() {
		return array( 'authonce-blocks-integration' );
	}

	/**
	 * Data made available to the JS side via window.wc.wcSettings /
	 * getSetting( 'authonce_data' ). Title and description mirror the
	 * classic gateway's own settings so the two checkouts stay
	 * consistent without hand-syncing copy in two places.
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway ? $this->gateway->title : 'Pay with Crypto',
			'description' => $this->gateway ? $this->gateway->description : '',
			'supports'    => array( 'products' ),
		);
	}
}
