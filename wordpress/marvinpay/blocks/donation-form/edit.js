( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var reg = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SSR = wp.serverSideRender;

	reg( 'marvinpay/donation-form', {
		edit: function ( props ) {
			var a = props.attributes;
			function set( key ) { return function ( v ) { var patch = {}; patch[ key ] = v; props.setAttributes( patch ); }; }
			return el( 'div', useBlockProps(),
				el( InspectorControls, {},
					el( PanelBody, { title: 'Marvin Pay' },
						el( TextControl, { label: 'Preset amounts (comma-separated)', value: a.presets, onChange: set( 'presets' ) } ),
						el( TextControl, { label: 'Minimum amount', value: a.min, onChange: set( 'min' ) } ),
						el( TextControl, { label: 'Maximum amount', value: a.max, onChange: set( 'max' ) } ),
						el( TextControl, { label: 'Description', value: a.description, onChange: set( 'description' ) } ),
						el( TextControl, { label: 'Button text', value: a.buttonText, onChange: set( 'buttonText' ) } )
					)
				),
				el( SSR, { block: 'marvinpay/donation-form', attributes: a } )
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
