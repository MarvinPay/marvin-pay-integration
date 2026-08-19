/**
 * Marvin Pay widgets: payment form (modal or inline), initiate + status polling.
 * Poll schedule per the API contract: first poll after 5s, backoff x2 capped
 * at 60s, give up after 600s. No dependencies.
 */
( function () {
	'use strict';

	if ( typeof window.MarvinPayData === 'undefined' ) {
		return;
	}
	var D = window.MarvinPayData;
	var T = D.i18n || {};

	// ── tiny DOM helper ─────────────────────────────────────────────────
	function h( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) { node.textContent = attrs[ k ]; }
			else { node.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { node.appendChild( c ); } );
		return node;
	}

	function post( fields ) {
		var body = new URLSearchParams();
		Object.keys( fields ).forEach( function ( k ) { body.append( k, fields[ k ] ); } );
		return fetch( D.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	// ── payment form ────────────────────────────────────────────────────
	function buildForm( config, container, onClose ) {
		var fixed = config.amount > 0;
		var form = h( 'form', { 'class': 'marvinpay-form', novalidate: 'novalidate' } );

		var amountInput;
		if ( fixed ) {
			form.appendChild( h( 'div', { 'class': 'marvinpay-amount-fixed' }, [
				h( 'span', { 'class': 'marvinpay-label', text: T.amountLabel } ),
				h( 'strong', { text: Number( config.amount ).toLocaleString() + ' ' + D.currency } )
			] ) );
		} else {
			amountInput = h( 'input', { type: 'number', step: '1', inputmode: 'numeric', 'class': 'marvinpay-input', required: 'required' } );
			if ( config.min > 0 ) { amountInput.min = config.min; }
			if ( config.max > 0 ) { amountInput.max = config.max; }
			var amountField = h( 'label', { 'class': 'marvinpay-field' }, [
				h( 'span', { 'class': 'marvinpay-label', text: T.amountLabel + ' (' + D.currency + ')' } ),
				amountInput
			] );
			form.appendChild( amountField );
			if ( Array.isArray( config.presets ) && config.presets.length ) {
				var presets = h( 'div', { 'class': 'marvinpay-presets' } );
				config.presets.forEach( function ( p ) {
					var b = h( 'button', { type: 'button', 'class': 'marvinpay-preset', text: Number( p ).toLocaleString() } );
					b.addEventListener( 'click', function () { amountInput.value = p; } );
					presets.appendChild( b );
				} );
				form.appendChild( presets );
			}
		}

		var nameInput, emailInput;
		if ( config.show_name ) {
			nameInput = h( 'input', { type: 'text', 'class': 'marvinpay-input', autocomplete: 'name' } );
			form.appendChild( h( 'label', { 'class': 'marvinpay-field' }, [ h( 'span', { 'class': 'marvinpay-label', text: T.nameLabel } ), nameInput ] ) );
		}
		if ( config.show_email ) {
			emailInput = h( 'input', { type: 'email', 'class': 'marvinpay-input', autocomplete: 'email' } );
			form.appendChild( h( 'label', { 'class': 'marvinpay-field' }, [ h( 'span', { 'class': 'marvinpay-label', text: T.emailLabel } ), emailInput ] ) );
		}

		var mobileInput = h( 'input', { type: 'tel', 'class': 'marvinpay-input', required: 'required', autocomplete: 'tel', placeholder: '2376XXXXXXXX' } );
		form.appendChild( h( 'label', { 'class': 'marvinpay-field' }, [ h( 'span', { 'class': 'marvinpay-label', text: T.mobileLabel } ), mobileInput ] ) );

		var methodSelect = h( 'select', { 'class': 'marvinpay-input', required: 'required' } );
		( D.methods || [] ).forEach( function ( m ) {
			methodSelect.appendChild( h( 'option', { value: m, text: m.replace( /_/g, ' ' ).toUpperCase() } ) );
		} );
		form.appendChild( h( 'label', { 'class': 'marvinpay-field' }, [ h( 'span', { 'class': 'marvinpay-label', text: T.methodLabel } ), methodSelect ] ) );

		var errorBox = h( 'div', { 'class': 'marvinpay-error', role: 'alert' } );
		form.appendChild( errorBox );

		var payBtn = h( 'button', { type: 'submit', 'class': 'marvinpay-btn marvinpay-btn-primary', text: T.payNow } );
		var actions = h( 'div', { 'class': 'marvinpay-actions' }, [ payBtn ] );
		if ( onClose ) {
			var cancelBtn = h( 'button', { type: 'button', 'class': 'marvinpay-btn marvinpay-btn-ghost', text: T.cancel } );
			cancelBtn.addEventListener( 'click', onClose );
			actions.appendChild( cancelBtn );
		}
		form.appendChild( actions );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			errorBox.textContent = '';

			var amount = fixed ? String( config.amount ) : String( amountInput.value || '' ).trim();
			if ( ! /^\d+$/.test( amount ) || Number( amount ) < D.minAmount || Number( amount ) > D.maxAmount ) {
				errorBox.textContent = T.invalidAmount;
				return;
			}
			var mobile = String( mobileInput.value || '' ).replace( /\D/g, '' );
			if ( mobile.length < 8 || mobile.length > 15 ) {
				errorBox.textContent = T.invalidMobile;
				return;
			}

			payBtn.disabled = true;
			post( {
				action: 'marvinpay_initiate',
				nonce: D.nonce,
				config: container.__mpConfigRaw,
				sig: container.__mpSig,
				amount: amount,
				mobile_number: mobile,
				payment_method: methodSelect.value,
				payer_name: nameInput ? nameInput.value : '',
				payer_email: emailInput ? emailInput.value : ''
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					payBtn.disabled = false;
					errorBox.textContent = ( res && res.data && res.data.message ) ? res.data.message : T.genericError;
					return;
				}
				var tx = res.data.transaction_id;
				if ( res.data.status === 'SUCCESSFUL' ) {
					showSuccess( container, tx, config, onClose );
				} else if ( res.data.status === 'FAILED' ) {
					showFailure( container, tx, '', config, onClose );
				} else {
					showWaiting( container, tx, config, onClose );
				}
			} ).catch( function () {
				payBtn.disabled = false;
				errorBox.textContent = T.genericError;
			} );
		} );

		return form;
	}

	// ── status panes ────────────────────────────────────────────────────
	function pane( container, children ) {
		var body = container.querySelector( '.marvinpay-body' );
		body.innerHTML = '';
		children.forEach( function ( c ) { body.appendChild( c ); } );
	}

	function refLine( tx ) {
		return h( 'p', { 'class': 'marvinpay-ref' }, [
			h( 'span', { text: T.reference + ': ' } ),
			h( 'code', { text: tx } )
		] );
	}

	function showWaiting( container, tx, config, onClose ) {
		pane( container, [
			h( 'div', { 'class': 'marvinpay-spinner', 'aria-hidden': 'true' } ),
			h( 'h3', { text: T.confirmPrompt } ),
			h( 'p', { text: T.waiting } ),
			refLine( tx )
		] );
		poll( container, tx, config, onClose );
	}

	function showSuccess( container, tx, config, onClose ) {
		if ( D.successUrl && config.type !== 'woocommerce' ) {
			window.location.href = D.successUrl + ( D.successUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'mp_ref=' + encodeURIComponent( tx );
			return;
		}
		var closeBtn = h( 'button', { type: 'button', 'class': 'marvinpay-btn marvinpay-btn-primary', text: T.close } );
		closeBtn.addEventListener( 'click', onClose || function () { window.location.reload(); } );
		pane( container, [
			h( 'div', { 'class': 'marvinpay-icon marvinpay-icon-success', text: '✓' } ),
			h( 'h3', { text: T.successTitle } ),
			h( 'p', { text: T.successBody } ),
			refLine( tx ),
			h( 'div', { 'class': 'marvinpay-actions' }, [ closeBtn ] )
		] );
	}

	function showFailure( container, tx, message, config, onClose ) {
		if ( D.failureUrl && config.type !== 'woocommerce' ) {
			window.location.href = D.failureUrl + ( D.failureUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'mp_ref=' + encodeURIComponent( tx );
			return;
		}
		var retry = h( 'button', { type: 'button', 'class': 'marvinpay-btn marvinpay-btn-primary', text: T.tryAgain } );
		retry.addEventListener( 'click', function () {
			// A retry is a brand-new transaction — rebuild the form.
			pane( container, [ buildForm( config, container, onClose ) ] );
		} );
		pane( container, [
			h( 'div', { 'class': 'marvinpay-icon marvinpay-icon-failed', text: '✕' } ),
			h( 'h3', { text: T.failedTitle } ),
			message ? h( 'p', { text: message } ) : h( 'p', { text: '' } ),
			tx ? refLine( tx ) : h( 'span', {} ),
			h( 'div', { 'class': 'marvinpay-actions' }, [ retry ] )
		] );
	}

	function showTimeout( container, tx ) {
		pane( container, [
			h( 'div', { 'class': 'marvinpay-icon', text: '…' } ),
			h( 'h3', { text: T.waiting } ),
			h( 'p', { text: T.timeoutBody } ),
			refLine( tx )
		] );
	}

	// Contract schedule: 5s first, x2 backoff capped 60s, 600s budget.
	function poll( container, tx, config, onClose ) {
		var delay = 5000;
		var deadline = Date.now() + 600000;

		function tick() {
			if ( Date.now() >= deadline ) {
				showTimeout( container, tx );
				return;
			}
			post( { action: 'marvinpay_poll', nonce: D.nonce, tx: tx } ).then( function ( res ) {
				var status = res && res.success && res.data ? res.data.status : 'PENDING';
				if ( status === 'SUCCESSFUL' ) {
					showSuccess( container, tx, config, onClose );
				} else if ( status === 'FAILED' ) {
					showFailure( container, tx, ( res.data && res.data.message ) || '', config, onClose );
				} else {
					delay = Math.min( delay * 2, 60000 );
					window.setTimeout( tick, delay );
				}
			} ).catch( function () {
				delay = Math.min( delay * 2, 60000 );
				window.setTimeout( tick, delay );
			} );
		}
		window.setTimeout( tick, delay );
	}

	// ── modal shell ─────────────────────────────────────────────────────
	var currentOverlay = null;

	function openModal( widget ) {
		// Replace any open modal — a double-click must never stack two live payment forms.
		if ( currentOverlay ) {
			currentOverlay.remove();
			currentOverlay = null;
		}
		var overlay = h( 'div', { 'class': 'marvinpay-overlay', role: 'dialog', 'aria-modal': 'true' } );
		var box = h( 'div', { 'class': 'marvinpay-modal' } );
		var head = h( 'div', { 'class': 'marvinpay-head' }, [
			h( 'strong', { text: T.payTitle } )
		] );
		var x = h( 'button', { type: 'button', 'class': 'marvinpay-x', 'aria-label': T.close, text: '✕' } );
		head.appendChild( x );
		var bodyBox = h( 'div', { 'class': 'marvinpay-body' } );
		box.appendChild( head );
		box.appendChild( bodyBox );
		overlay.appendChild( box );
		document.body.appendChild( overlay );
		currentOverlay = overlay;

		box.__mpConfigRaw = widget.__mpConfigRaw;
		box.__mpSig = widget.__mpSig;

		function close() {
			overlay.remove();
			if ( currentOverlay === overlay ) {
				currentOverlay = null;
			}
		}
		x.addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { close(); } } );

		var config = widget.__mpConfig;
		if ( config.description ) {
			bodyBox.appendChild( h( 'p', { 'class': 'marvinpay-desc', text: config.description } ) );
		}
		bodyBox.appendChild( buildForm( config, box, close ) );
	}

	// ── widget binding ──────────────────────────────────────────────────
	function bind( widget ) {
		var raw = widget.getAttribute( 'data-config' ) || '';
		var sig = widget.getAttribute( 'data-sig' ) || '';
		var config;
		try { config = JSON.parse( raw ); } catch ( e ) { return; }
		widget.__mpConfigRaw = raw;
		widget.__mpSig = sig;
		widget.__mpConfig = config;

		var mode = widget.getAttribute( 'data-widget' );
		if ( mode === 'inline' ) {
			var bodyBox = h( 'div', { 'class': 'marvinpay-body' } );
			widget.appendChild( bodyBox );
			widget.__mpIsInline = true;
			if ( config.description ) {
				bodyBox.appendChild( h( 'p', { 'class': 'marvinpay-desc', text: config.description } ) );
			}
			bodyBox.appendChild( buildForm( config, widget, null ) );
		} else if ( mode === 'auto' ) {
			openModal( widget );
			var btn = widget.querySelector( '.marvinpay-open' );
			if ( btn ) { btn.addEventListener( 'click', function () { openModal( widget ); } ); }
		} else {
			var openBtn = widget.querySelector( '.marvinpay-open' );
			if ( openBtn ) { openBtn.addEventListener( 'click', function () { openModal( widget ); } ); }
		}
	}

	function initAll() {
		Array.prototype.forEach.call( document.querySelectorAll( '.marvinpay-widget' ), bind );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
