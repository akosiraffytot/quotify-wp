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
	}

	function fillQuote( el, data ) {
		const slot = resetField( el );

		if ( ! data.checkout_url ) {
			return;
		}

		const label       = getQuoteLabel( slot );
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

		function finish( error ) {
			spinner.style.display = 'none';
			button.disabled = false;
			if ( error ) {
				showMessage( error );
			}
		}

		function estimate( attempt ) {
			const body = new FormData();
			body.append( 'action', 'quotify_estimate' );
			body.append( 'nonce', config.nonce );
			body.append( 'url', urlInput.value.trim() );

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
				} )
				.catch( function () {
					finish( config.error );
				} );
		}

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
}() );