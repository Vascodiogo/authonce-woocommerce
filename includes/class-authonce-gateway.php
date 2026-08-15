<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AuthOnce payment gateway.
 *
 * Non-custodial: this plugin never holds funds, private keys, or wallet
 * credentials. It only builds a link to AuthOnce's own pay page (where the
 * customer signs with their own wallet) and later verifies the resulting
 * transaction directly on-chain — nothing routes through AuthOnce's servers
 * as a trust boundary for the plugin itself.
 *
 * Step 1 (settings) and step 2 (product slug field) done. This step adds
 * the real checkout redirect. Verifying the returned transaction on-chain
 * and actually marking the order paid is the next step — until that's
 * built, a completed AuthOnce payment will redirect back here correctly,
 * but the order will still just sit as "pending."
 */
class WC_Gateway_AuthOnce extends WC_Payment_Gateway {

	/**
	 * Base Sepolia is the only network wired up right now — AuthOnce hasn't
	 * deployed to Base Mainnet yet. Keeping this as a constant (not a
	 * merchant-facing setting) since a wrong RPC/vault address here isn't
	 * something a store owner should ever need to type in by hand.
	 */
	const VAULT_ADDRESS_TESTNET = '0xDd41E5C83d000ff63d3e9E8cBBD79609b7029d3C';
	const PAY_PAGE_BASE_TESTNET = 'https://authonce.io/pay';

	/**
	 * The actual backend API host — NOT the same as PAY_PAGE_BASE_TESTNET
	 * above. authonce.io serves the customer-facing pay page (a separate
	 * frontend); the API that this plugin polls for order status lives on
	 * a different host entirely. Confirmed directly against PayPage.jsx's
	 * own API_BASE constant, not assumed.
	 */
	const API_BASE_TESTNET = 'https://the-opportunity-production.up.railway.app';

	/**
	 * @var string The merchant's AuthOnce handle, from settings. Not a
	 * wallet address — see init_form_fields() for why that distinction
	 * matters here.
	 *
	 * Deliberately PUBLIC, not protected — matches how WC_Payment_Gateway's
	 * own base properties (title, description) are read directly from
	 * outside the class (e.g. checkout templates). A protected property
	 * here caused a real, confirmed bug: class-authonce-price-sync.php and
	 * class-authonce-order-sync.php are plain functions outside this
	 * class, and PHP's empty()/isset() silently treat an inaccessible
	 * protected property as "doesn't exist" rather than erroring — so
	 * `empty($gateway->merchant_handle)` was always true regardless of the
	 * real saved value, with no warning or fatal to reveal why. Confirmed
	 * via direct logging: the gateway object itself was found correctly,
	 * only the property read was silently failing.
	 */
	public $merchant_handle;

	/**
	 * @var string Server-to-server API key (see class-authonce-order-sync.php).
	 * Completely separate credential from merchant_handle — the handle is
	 * public (it's in every pay link), the API key is a secret that proves
	 * this specific store is allowed to ask AuthOnce about this merchant's
	 * order statuses.
	 *
	 * Also public for the same reason as merchant_handle above.
	 */
	public $api_key;

	public function __construct() {
		$this->id                 = 'authonce';
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = 'AuthOnce';
		$this->method_description = 'Accept non-custodial USDC/EURC subscriptions. Customers pay with their own crypto wallet.';

		$this->init_form_fields();
		$this->init_settings();

		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled' );
		$this->merchant_handle = $this->get_option( 'merchant_handle' );
		$this->api_key         = $this->get_option( 'api_key' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'authonce-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable AuthOnce', 'authonce-woocommerce' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'authonce-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'authonce-woocommerce' ),
				'default'     => __( 'Pay with Crypto (USDC/EURC)', 'authonce-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'authonce-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to the customer at checkout, under the title.', 'authonce-woocommerce' ),
				'default'     => __( 'Connect your own wallet and subscribe with USDC or EURC. Non-custodial — your funds never pass through us.', 'authonce-woocommerce' ),
				'desc_tip'    => true,
			),
			'merchant_handle'  => array(
				'title'       => __( 'AuthOnce Merchant Handle', 'authonce-woocommerce' ),
				'type'        => 'text',
				// NOT the wallet address. AuthOnce pay links are
				// authonce.io/pay/{handle}/{slug} — {handle} is a separate
				// merchant handle (e.g. "promerchant"), confirmed against a
				// real generated pay link. An earlier version of this field
				// asked for the wallet address, which would have silently
				// sent every customer to a broken or wrong-merchant link.
				'description' => __( 'Your merchant handle from AuthOnce (the segment in your pay link right after /pay/ — e.g. "promerchant"). This is not your wallet address. Find it on your AuthOnce merchant dashboard, under any product\'s pay link.', 'authonce-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
				'placeholder' => 'e.g. promerchant',
			),
			'api_key'          => array(
				'title'       => __( 'AuthOnce API Key', 'authonce-woocommerce' ),
				'type'        => 'password',
				// A DIFFERENT secret from the merchant handle above. The
				// handle is public — it's visible in every pay link this
				// store generates. This key is private: it's what lets this
				// store ask AuthOnce "was order X actually paid?" and get a
				// real answer back, scoped only to this merchant's own
				// orders. Without it, orders will sit as "Pending payment"
				// forever after a successful crypto payment — someone would
				// have to mark every order Completed by hand.
				'description' => __( 'Generate this on your AuthOnce merchant dashboard, under Settings → Integration API Key. Required for orders to update automatically after payment — without it, you\'ll need to mark every AuthOnce order as paid manually.', 'authonce-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
				'placeholder' => 'ao_...',
			),
		);
	}

	/**
	 * Don't offer this payment option at checkout at all until the merchant
	 * has actually configured their handle — showing a broken option that
	 * can never succeed is worse than not showing it.
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( empty( $this->merchant_handle ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Builds a link to AuthOnce's own pay page and redirects the customer
	 * there. This plugin never sees or handles the customer's wallet or
	 * private key — the customer signs directly with AuthOnce, using their
	 * own wallet, on AuthOnce's own site.
	 *
	 * Scope for now: one AuthOnce product per order. A cart mixing an
	 * AuthOnce-linked product with other items, or with more than one
	 * AuthOnce-linked product, isn't supported yet — the customer gets a
	 * clear error instead of an ambiguous redirect.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Could not load this order. Please try again.', 'authonce-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $this->merchant_handle ) ) {
			// Shouldn't be reachable — is_available() already checks this —
			// but never trust that alone for something that would otherwise
			// send a customer to a broken or empty pay link.
			wc_add_notice( __( 'AuthOnce is not fully configured for this store yet.', 'authonce-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$items = $order->get_items();

		if ( count( $items ) !== 1 ) {
			wc_add_notice(
				__( 'AuthOnce currently supports one subscription product per order. Please check out each item separately.', 'authonce-woocommerce' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$item    = reset( $items );
		$product = $item->get_product();

		if ( ! $product ) {
			wc_add_notice( __( 'Could not find the product for this order.', 'authonce-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$slug = authonce_wc_get_product_slug( $product->get_id() );

		if ( empty( $slug ) ) {
			wc_add_notice(
				__( 'This product is not set up for AuthOnce payments yet. Please contact the store.', 'authonce-woocommerce' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'pending', __( 'Awaiting AuthOnce payment confirmation.', 'authonce-woocommerce' ) );

		$ref = 'wc_' . $order->get_id();
		$order->update_meta_data( '_authonce_ref', $ref );
		$order->save();

		$pay_link_base = self::PAY_PAGE_BASE_TESTNET
			. '/' . rawurlencode( $this->merchant_handle )
			. '/' . rawurlencode( $slug );

		// success_redirect uses WooCommerce's own "thank you" page URL,
		// which already embeds and checks the order key — reusing it means
		// we inherit that ownership check for free instead of
		// re-implementing our own return-URL verification.
		//
		// http_build_query, not add_query_arg — success_redirect is itself a
		// full URL with its own query string (?key=wc_order_...), and
		// http_build_query correctly encodes that whole value as one opaque
		// parameter. add_query_arg is known to handle nested query strings
		// unreliably.
		$query = http_build_query(
			array(
				'ref'              => $ref,
				'success_redirect' => $order->get_checkout_order_received_url(),
			)
		);

		$pay_link = $pay_link_base . '?' . $query;

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $pay_link,
		);
	}
}
