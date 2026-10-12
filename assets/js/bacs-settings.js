/**
 * Keeps track of which bank account each row of the Direct bank transfer account table holds.
 *
 * The plugin stores a bank account's countries under a hash of the account details, so an
 * account that has been edited needs pairing up with the one it was edited from. The position of
 * a row cannot do that on its own: WooCommerce hands a row that is added the position of one
 * that was removed. Every row therefore submits the key of the account it was rendered from,
 * under the position it is about to be saved as, and a row that was added submits nothing.
 */
( function () {
	'use strict';

	var data = window.wccbbaBacsSettings;
	var table = document.getElementById( 'bacs_accounts' );
	var form = table ? table.closest( 'form' ) : null;

	if ( ! data || ! data.keys || ! form ) {
		return;
	}

	var rendered = table.querySelectorAll( 'tbody tr.account' );

	// Taken while the rows are still the ones that were rendered. From here on the key travels
	// with its row, through being dragged somewhere else, and is gone once the row is removed
	for ( var i = 0; i < rendered.length; i++ ) {
		rendered[ i ].setAttribute( 'data-wccbba-key', data.keys[ i ] || '' );
	}

	form.addEventListener( 'submit', function () {
		var rows = table.querySelectorAll( 'tbody tr.account' );

		for ( var i = 0; i < rows.length; i++ ) {
			var row = rows[ i ];
			var input = row.querySelector( 'input[name^="bacs_account_name["]' );
			var cell = row.querySelector( 'td:last-child' );

			if ( ! input || ! cell ) {
				continue;
			}

			var field = row.querySelector( 'input.wccbba-account-key' );

			if ( ! field ) {
				field = document.createElement( 'input' );
				field.type = 'hidden';
				field.className = 'wccbba-account-key';
				cell.appendChild( field );
			}

			// The position the gateway is going to save this row as, whatever has been done to
			// the table in the meantime
			field.name = 'wccbba_account_key[' + input.name.replace( /^bacs_account_name\[|]$/g, '' ) + ']';
			field.value = row.getAttribute( 'data-wccbba-key' ) || '';
		}
	} );
} )();
