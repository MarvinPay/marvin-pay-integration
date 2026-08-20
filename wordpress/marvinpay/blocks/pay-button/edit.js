( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var reg = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SSR = wp.serverSideRender;

	reg( 'marvinpay/pay-button', {
		edit: function ( props ) {
			var a = props.attributes;
			function set( key ) { return function ( v ) { var patch = {}; patch[ key ] = v; props.setAttributes( patch ); }; }
			return el( 'div', useBlockProps(),
				el( InspectorControls, {},
					el( PanelBody, { title: 'Marvin Pay' },
						el( TextControl, { label: 'Amount (whole units, 100–500,000)', value: a.amount, onChange: set( 'amount' ) } ),
						el( TextControl, { label: 'Description', value: a.description, onChange: set( 'description' ) } ),
						el( TextControl, { label: 'Button text', value: a.buttonText, onChange: set( 'buttonText' ) } )
					)
				),
				el( SSR, { block: 'marvinpay/pay-button', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
