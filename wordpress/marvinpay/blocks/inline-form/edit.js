( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var reg = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var SSR = wp.serverSideRender;

	reg( 'marvinpay/inline-form', {
		edit: function ( props ) {
			var a = props.attributes;
			function set( key ) { return function ( v ) { var patch = {}; patch[ key ] = v; props.setAttributes( patch ); }; }
			return el( 'div', useBlockProps(),
				el( InspectorControls, {},
					el( PanelBody, { title: 'Marvin Pay' },
						el( TextControl, { label: 'Fixed amount (empty = payer enters it)', value: a.amount, onChange: set( 'amount' ) } ),
						el( TextControl, { label: 'Description', value: a.description, onChange: set( 'description' ) } ),
						el( ToggleControl, { label: 'Ask for name', checked: !! a.showName, onChange: set( 'showName' ) } ),
						el( ToggleControl, { label: 'Ask for email (receipt)', checked: !! a.showEmail, onChange: set( 'showEmail' ) } )
					)
				),
				el( SSR, { block: 'marvinpay/inline-form', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
