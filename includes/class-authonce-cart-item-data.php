<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attaches AuthOnce pricing data (token, intro pricing) to each cart item
 * via WooCommerce's Store API — this is what makes the data available to
 * the Cart/Checkout Blocks' JavaScript at all. The JS half (see
 * assets/js/cart-item-meta.js) reads this back out and actually renders
 * it; this file only exposes the data, it doesn't render anything itself.
 *
 * Confirmed against WooCommerce's own current developer docs
 * (developer.woocommerce.com/docs/apis/store-api/extending-store-api/) —
 * CartItemSchema::IDENTIFIER is the correct endpoint for PER-ITEM data
 * (as opposed to CartSchema::IDENTIFIER, which is whole-cart data with no
 * per-product granularity — wrong one for this use case).
 */
add_action( 'woocommerce_blocks_loaded', 'authonce_wc_register_cart_item_store_data' );

function authonce_wc_register_cart_item_store_data() {
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		// Older WooCommerce without Store API extend support — nothing to
		// hook, fail quietly rather than fatal.
		return;
	}

	woocommerce_store_api_register_endpoint_data(
		array(
			'endpoint'        => Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
			'namespace'       => 'authonce',
			'data_callback'   => 'authonce_wc_cart_item_store_data',
			'schema_callback' => 'authonce_wc_cart_item_store_schema',
			'schema_type'     => ARRAY_A,
		)
	);
}

/**
 * $cart_item here is the raw WC cart item array (not just a product ID) —
 * confirmed from the docs' own example: $product = $cart_item['data'].
 */
function authonce_wc_cart_item_store_data( $cart_item ) {
	$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
	if ( ! $product_id ) {
		return array();
	}

	$slug = authonce_wc_get_product_slug( $product_id );
	if ( empty( $slug ) ) {
		return array(); // Not an AuthOnce product — expose nothing, keeps the response clean for everyone else's products too.
	}

	return array(
		'token'         => (string) get_post_meta( $product_id, '_authonce_price_token', true ),
		'interval'      => (string) get_post_meta( $product_id, '_authonce_price_interval', true ),
		'intro_amount'  => (string) get_post_meta( $product_id, '_authonce_intro_amount', true ),
		'intro_pulls'   => (string) get_post_meta( $product_id, '_authonce_intro_pulls', true ),
		'regular_price' => (string) get_post_meta( $product_id, '_regular_price', true ),
	);
}

function authonce_wc_cart_item_store_schema() {
	return array(
		'properties' => array(
			'token'         => array( 'type' => 'string' ),
			'interval'      => array( 'type' => 'string' ),
			'intro_amount'  => array( 'type' => 'string' ),
			'intro_pulls'   => array( 'type' => 'string' ),
			'regular_price' => array( 'type' => 'string' ),
		),
	);
}
