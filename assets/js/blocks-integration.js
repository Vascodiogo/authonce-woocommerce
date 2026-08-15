/**
 * Registers AuthOnce as a payment method inside the WooCommerce Checkout
 * block. Deliberately plain JS against WordPress/WooCommerce's own
 * globals (wp.element, wc.wcBlocksRegistry, wc.wcSettings) instead of a
 * bundled React component — this gateway has no custom checkout fields
 * (has_fields = false server-side), so there's nothing here that needs
 * JSX or a build step. If a real UI (e.g. a live QR code, wallet
 * picker) gets added later, this is the file that would need a proper
 * build pipeline — not before.
 */
( function () {
	var settings = window.wc.wcSettings.getSetting( 'authonce_data', {} );
	var decode = window.wp.htmlEntities.decodeEntities;
	var el = window.wp.element.createElement;

	var label = decode( settings.title || 'Pay with Crypto' );
	var description = decode( settings.description || '' );

	var Content = function () {
		return el( 'div', {}, description );
	};

	var Label = function () {
		return el( 'span', {}, label );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'authonce',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		ariaLabel: label,
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: ( settings.supports || [ 'products' ] ),
		},
	} );
} )();
