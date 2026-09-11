( function () {
	'use strict';

	const body      = document.getElementById( 'quotify-tiers' );
	const indexBox  = document.getElementById( 'quotify-tier-index' );
	const addButton = document.getElementById( 'quotify-add-tier' );

	const removeLabel = window.quotifyAdmin && window.quotifyAdmin.remove ? window.quotifyAdmin.remove : 'Remove';

	if ( ! body || ! indexBox || ! addButton ) {
		return;
	}

	/**
	 * Build the inner HTML of one tier row for the given index.
	 *
	 * @param {number} index Row index.
	 * @return {string} Row HTML.
	 */
	function rowHtml( index ) {
		return '<tr class="quotify-tier-row">' +
			'<td><input type="number" class="small-text" min="0" name="quotify_settings[tiers][' + index + '][min]" value=""></td>' +
			'<td><input type="number" class="small-text" min="0" name="quotify_settings[tiers][' + index + '][max]" value=""></td>' +
			'<td><input type="number" class="small-text" min="0" step="0.01" name="quotify_settings[tiers][' + index + '][price]" value=""></td>' +
			'<td><input type="number" class="small-text" min="0" name="quotify_settings[tiers][' + index + '][variation_id]" value=""></td>' +
			'<td><input type="text" class="regular-text code" name="quotify_settings[tiers][' + index + '][url]" value="" placeholder="https://…/?pages={page_count}&t={total_price}"></td>' +
			'<td><button type="button" class="button-link-delete quotify-remove-tier">' +
			removeLabel + '</button></td>' +
			'</tr>';
	}

	/**
	 * Re-index row names after a deletion so they stay contiguous.
	 */
	function renumber() {
		body.querySelectorAll( 'tr.quotify-tier-row' ).forEach( function ( row, index ) {
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.name = input.name.replace( /\[\d+\]\[/, '[' + index + '][' );
			} );
		} );

		indexBox.value = String( body.querySelectorAll( 'tr.quotify-tier-row' ).length );
	}

	/**
	 * Appends a new empty tier row.
	 */
	function addRow() {
		const index = Number( indexBox.value );
		const frag  = document.createElement( 'tbody' );
		frag.innerHTML = rowHtml( index );
		body.appendChild( frag.firstElementChild );
		indexBox.value = String( index + 1 );
	}

	body.addEventListener( 'click', function ( event ) {
		const target = event.target;

		if ( target.matches( '.quotify-remove-tier' ) ) {
			const row = target.closest( 'tr.quotify-tier-row' );
			if ( row ) {
				row.remove();
				renumber();
			}
		}
	} );

	addButton.addEventListener( 'click', addRow );
}() );