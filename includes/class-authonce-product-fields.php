<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a single field to each product's "General" tab in Product Data,
 * where the merchant pastes the matching AuthOnce product slug (the one
 * they already created on their AuthOnce merchant dashboard).
 *
 * This is the only link between a WooCommerce product and its AuthOnce
 * counterpart — everything else (price, billing interval, grace period)
 * stays defined on AuthOnce's side, not duplicated here. This used to be
 * true only in intent ("WooCommerce's own price is just for display");
 * class-authonce-price-sync.php now actively enforces it — see that file
 * for how the price field itself gets kept in sync automatically.
 */

add_action( 'woocommerce_product_options_general_product_data', 'authonce_wc_add_product_slug_field' );

function authonce_wc_add_product_slug_field() {
	echo '<div class="options_group">';

	woocommerce_wp_text_input(
		array(
			'id'          => '_authonce_product_slug',
			'label'       => __( 'AuthOnce Product Slug', 'authonce-woocommerce' ),
			'placeholder' => 'e.g. pro-plan-monthly',
			'desc_tip'    => true,
			'description' => __( 'The product slug from your AuthOnce merchant dashboard. Leave blank if this product isn\'t sold via AuthOnce.', 'authonce-woocommerce' ),
		)
	);

	echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'authonce_wc_save_product_slug_field' );

function authonce_wc_save_product_slug_field( $post_id ) {
	// Nonce for this save was already verified by WooCommerce itself before
	// this hook fires — woocommerce_process_product_meta only runs after
	// WooCommerce's own product-save nonce check passes.
	if ( ! isset( $_POST['_authonce_product_slug'] ) ) {
		return;
	}

	$slug = sanitize_text_field( wp_unslash( $_POST['_authonce_product_slug'] ) );
	$slug = trim( $slug );

	update_post_meta( $post_id, '_authonce_product_slug', $slug );
}

/**
 * Small helper other files can use to read this value without repeating
 * the meta key everywhere.
 */
function authonce_wc_get_product_slug( $product_id ) {
	return get_post_meta( $product_id, '_authonce_product_slug', true );
}
