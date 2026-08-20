/**
 * Registers Marvin Pay in the WooCommerce checkout block.
 * Plain JS — no build step; wp.element.createElement only.
 */
( function () {
	'use strict';
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}
	var el = window.wp.element.createElement;
	var settings = window.wc.wcSettings.getSetting( 'marvinpay_data', {} );
	var label = settings.title || 'Mobile Money (Marvin Pay)';

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'marvinpay',
		label: el( 'span', null, label ),
		ariaLabel: label,
		content: el( 'div', null, settings.description || '' ),
		edit: el( 'div', null, settings.description || '' ),
		canMakePayment: function () { return true; },
		supports: { features: ( settings.supports || [ 'products' ] ) }
	} );
} )();
