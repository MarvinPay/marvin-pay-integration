( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var reg = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;

	reg( 'marvinpay/payment-status', {
		edit: function () {
			return el( 'div', useBlockProps( { style: { padding: '1em', border: '1px dashed #d59204', borderRadius: '8px' } } ),
				'Marvin Pay Status — shows the payment outcome for the ?mp_ref= parameter on this page.'
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
