( function () {
	'use strict';

	const form = document.querySelector( '.quotify-form' );

	if ( ! form ) {
		return;
	}

	const config  = window.quotifyFront || {};
	const urlInput = form.querySelector( '#quotify-url' );
	const button   = form.querySelector( '.quotify-estimate' );
	const spinner  = form.querySelector( '.quotify-spinner' );
	const result   = form.querySelector( '.quotify-result' );

	function showMessage( text ) {
		result.textContent = '';
		result.appendChild( document.createTextNode( text ) );
	}

	function showSuccess( data ) {
		result.textContent = '';

		const countLine = config.pages.replace( '%s', data.count_display ) + ' \u2014 ' + data.formatted_price;
		result.appendChild( document.createTextNode( countLine ) );

		if ( data.checkout_url ) {
			const link = document.createElement( 'a' );
			link.href = data.checkout_url;
			link.rel = 'noopener';
			link.textContent = config.quote || 'Get a Quote';
			link.classList.add( 'quotify-quote-link' );
			result.appendChild( document.createTextNode( ' ' ) );
			result.appendChild( link );
		}
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
					showSuccess( response.data );
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

		result.textContent = '';
		button.disabled = true;
		spinner.style.display = 'inline-block';

		estimate( 0 );
	} );
}() );