( function () {
	'use strict';

	const config       = window.quotifyFront || {};
	const FIELD_SELECTOR = '[data-quotify-field]';

	function fieldType( el ) {
		return el.getAttribute( 'data-quotify-field' );
	}

	function getPlaceholder( el ) {
		return el.hasAttribute( 'data-quotify-placeholder' ) ? el.getAttribute( 'data-quotify-placeholder' ) : '';
	}

	function getQuoteLabel( el ) {
		const label = el.getAttribute( 'data-quotify-quote-label' );
		return label ? label : ( config.quote || 'Get a Quote' );
	}

	function resetField( el ) {
		const placeholder = getPlaceholder( el );

		if ( 'quote' === fieldType( el ) ) {
			const label = getQuoteLabel( el );
			const span  = document.createElement( 'span' );
			span.classList.add( 'quotify-field', 'quotify-quote-link' );
			span.setAttribute( 'data-quotify-field', 'quote' );
			span.setAttribute( 'data-quotify-quote-label', label );
			if ( el.hasAttribute( 'data-quotify-placeholder' ) ) {
				span.setAttribute( 'data-quotify-placeholder', placeholder );
			}
			span.textContent = placeholder;
			el.replaceWith( span );
			return span;
		}

		el.textContent = placeholder;
		return el;
	}

	function resetAllFields() {
		document.querySelectorAll( FIELD_SELECTOR ).forEach( resetField );

		document.querySelectorAll( '.quotify-fluentcart-wrap' ).forEach( function ( wrap ) {
			wrap.style.display = 'none';
		} );
	}

	function sealFluentCartButtonUrl( btn, variationId, baseUrl ) {
		const url = new URL( baseUrl || window.location.origin );
		url.searchParams.set( 'fluent-cart', 'modal_checkout' );
		url.searchParams.set( 'item_id', String( variationId ) );
		url.searchParams.set( 'quantity', '1' );

		btn.href = url.toString();
		btn.setAttribute( 'data-url', url.toString() );
		btn.setAttribute( 'data-cart-id', String( variationId ) );
	}

	function setupFluentCartButtons() {
		document.querySelectorAll( '.quotify-fluentcart-wrap[data-quotify-fluentcart-seed]' ).forEach( function ( wrap ) {
			if ( wrap.querySelector( 'a[data-fct-instant-checkout-button]' ) ) {
				return;
			}

			const seed  = wrap.getAttribute( 'data-quotify-fluentcart-seed' );
			const label = wrap.getAttribute( 'data-quotify-fluentcart-label' ) || config.quote || 'Get a Quote';
			const btn   = document.createElement( 'a' );

			btn.className = config.fluentcart_class || 'wp-block-button__link wp-element-button';
			btn.textContent = label;
			btn.setAttribute( 'data-fct-instant-checkout-button', '' );
			btn.setAttribute( 'data-enable-modal-checkout', 'yes' );
			sealFluentCartButtonUrl( btn, seed, config.fluentcart_home );

			wrap.appendChild( btn );
		} );
	}

	function revealFluentCart( data ) {
		if ( ! data.fluentcart_url || ! document.querySelector( '.quotify-fluentcart-wrap[data-quotify-fluentcart-seed]' ) ) {
			return;
		}

		document.querySelectorAll( '.quotify-fluentcart-wrap' ).forEach( function ( wrap ) {
			const btn = wrap.querySelector( 'a[data-fct-instant-checkout-button]' );

			if ( ! btn ) {
				return;
			}

			if ( data.quote_label ) {
				btn.textContent = data.quote_label;
			}

			btn.href = data.fluentcart_url;
			btn.setAttribute( 'data-url', data.fluentcart_url );
			btn.setAttribute( 'data-cart-id', data.fluentcart_variation_id || '' );
			wrap.style.display = '';
		} );
	}

	function fillQuote( el, data ) {
		const slot = resetField( el );

		if ( ! data.checkout_url ) {
			return;
		}

		const label       = data.quote_label || getQuoteLabel( slot );
		const placeholder = getPlaceholder( slot );
		const link        = document.createElement( 'a' );

		link.href = data.checkout_url;
		link.rel = 'noopener';
		link.textContent = label;
		link.classList.add( 'quotify-field', 'quotify-quote-link' );
		link.setAttribute( 'data-quotify-field', 'quote' );
		link.setAttribute( 'data-quotify-quote-label', label );
		if ( slot.hasAttribute( 'data-quotify-placeholder' ) ) {
			link.setAttribute( 'data-quotify-placeholder', placeholder );
		}

		slot.replaceWith( link );
	}

	function fillAllFields( data ) {
		document.querySelectorAll( FIELD_SELECTOR ).forEach( function ( el ) {
			const type = fieldType( el );

			if ( 'count' === type ) {
				el.textContent = data.count_display || '';
			} else if ( 'price' === type ) {
				el.textContent = data.formatted_price || '';
			} else if ( 'quote' === type ) {
				fillQuote( el, data );
			}
		} );
	}

	document.querySelectorAll( '.quotify-form' ).forEach( function ( form ) {
		const urlInput = form.querySelector( '#quotify-url' );
		const button   = form.querySelector( '.quotify-estimate' );
		const spinner  = form.querySelector( '.quotify-spinner' );
		const status   = form.querySelector( '.quotify-status' );

		if ( ! urlInput || ! button || ! spinner || ! status ) {
			return;
		}

		function showMessage( text ) {
			status.textContent = '';
			status.appendChild( document.createTextNode( text ) );
		}

		function syncButtonState() {
			button.disabled = ! ( urlInput.value && urlInput.value.trim() );
		}

		function finish( error ) {
			spinner.style.display = 'none';
			syncButtonState();
			if ( error ) {
				showMessage( error );
			}
		}

		function estimate( attempt ) {
			const body = new FormData();
			body.append( 'action', 'quotify_estimate' );
			body.append( 'nonce', config.nonce );
			body.append( 'url', urlInput.value.trim() );
			body.append( 'instant_checkout', document.querySelector( '.quotify-fluentcart-wrap[data-quotify-fluentcart-seed]' ) ? '1' : '' );

			fetch( config.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( response ) {
					if ( response.success ) {
						finish( false );
						fillAllFields( response.data );
						revealFluentCart( response.data );
						document.dispatchEvent( new CustomEvent( 'quotify:scanned', { detail: response.data } ) );
						return;
					}

					const code    = response.data && response.data.code ? response.data.code : '';
					const message = response.data && response.data.message ? response.data.message : config.error;

					if ( 'processing' === code && attempt < 3 ) {
						setTimeout( function () {
							estimate( attempt + 1 );
						}, 1000 );
						return;
					}

					finish( message );
					document.dispatchEvent( new CustomEvent( 'quotify:error', { detail: { code: code, message: message } } ) );
				} )
				.catch( function () {
					finish( config.error );
					document.dispatchEvent( new CustomEvent( 'quotify:error', { detail: { code: 'network', message: config.error } } ) );
				} );
		}

		syncButtonState();
		urlInput.addEventListener( 'input', syncButtonState );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( ! urlInput.value || ! urlInput.value.trim() ) {
				showMessage( config.empty );
				return;
			}

			status.textContent = '';
			resetAllFields();
			button.disabled = true;
			spinner.style.display = 'inline-block';

			estimate( 0 );
		} );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', setupFluentCartButtons );
	} else {
		setupFluentCartButtons();
	}
}() );