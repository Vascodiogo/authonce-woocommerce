/**
 * Renders AuthOnce pricing info (token, intro pricing) in the Cart and
 * Checkout blocks, using WooCommerce's officially documented
 * ExperimentalOrderMeta slot — confirmed against WooCommerce's own
 * current developer docs, not guessed.
 *
 * NOTE on placement, confirmed from the docs: ExperimentalOrderMeta
 * renders in the general order-summary area, not literally attached
 * under each product's name the way the old (broken)
 * woocommerce_cart_item_name filter attempt tried to. This shows a
 * combined note per AuthOnce item in that summary area instead — same
 * information, using a real supported extension point.
 *
 * ExperimentalOrderMeta automatically passes props (including `cart`) to
 * its top-level child — no separate data-fetching hook needed here.
 */
( function () {
	if ( ! window.wc || ! window.wc.blocksCheckout || ! window.wp ) {
		return; // Blocks checkout assets not loaded on this page — nothing to do.
	}

	const { registerPlugin } = window.wp.plugins;
	const { ExperimentalOrderMeta } = window.wc.blocksCheckout;
	const el = window.wp.element.createElement;

	const AuthonceCartNotes = ( props ) => {
		const cart = props && props.cart ? props.cart : null;
		const items = cart && cart.cartItems ? cart.cartItems : []

		const authonceItems = items.filter(
			( item ) => item.extensions && item.extensions.authonce && item.extensions.authonce.token
		);

		if ( authonceItems.length === 0 ) {
			return null;
		}

		const lines = authonceItems.map( ( item ) => {
			const data = item.extensions.authonce;
			const token = data.token;
			const introAmount = parseFloat( data.intro_amount );
			const introPulls = parseInt( data.intro_pulls, 10 );
			const regularPrice = parseFloat( data.regular_price );

			let text = item.name + ' — paid in ' + token + ' via AuthOnce';

			if ( introAmount && introPulls ) {
				text +=
					' (Intro: ' +
					introAmount.toFixed( 2 ) +
					' ' +
					token +
					' for ' +
					introPulls +
					' cycle(s), then ' +
					regularPrice.toFixed( 2 ) +
					' ' +
					token +
					')';
			}

			return text;
		} );

		return el(
			'div',
			{ style: { fontSize: '0.9em', color: '#059669', fontWeight: '600', marginTop: '8px' } },
			lines.map( ( text, i ) => el( 'div', { key: i }, text ) )
		);
	};

	const render = () => el( ExperimentalOrderMeta, {}, el( AuthonceCartNotes ) );

	registerPlugin( 'authonce-cart-item-meta', {
		render,
		scope: 'woocommerce-checkout', // Per docs, this scope covers both Cart and Checkout blocks.
	} );
} )();
