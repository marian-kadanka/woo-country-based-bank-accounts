/**
 * Keeps track of which bank account each row of the block based payment settings screen holds.
 *
 * That screen writes the accounts straight to the option over the REST API, so the plugin has no
 * settings form to read and no way of telling an edited account from one that was added. The
 * rows are a React list keyed by an id of its own, which means the row of an account stays the
 * same element while it is dragged somewhere else or edited, and an added account is an element
 * that was never there before. Each row is therefore given the key of the account it was
 * rendered from, exactly as assets/js/bacs-settings.js does on the settings form, and the keys
 * ride along with the save as a header.
 *
 * The data comes from the wccbba-bacs-settings script, which is why that one is a dependency.
 */
( function () {
	'use strict';

	var data = window.wccbbaBacsSettings;

	if ( ! data || ! data.keys || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var tagged = false;

	// WooCommerce lists an account as the div its sortable item renders, and only the row that
	// adds an account is an li, so the element name is no part of this
	function rows() {
		return document.querySelectorAll( '.bank-accounts__list-item:not(.action)' );
	}

	/** Hands every row the key of the account at the same place in the stored accounts. */
	function tag( keys ) {
		var list = rows();

		if ( ! keys.length || list.length !== keys.length ) {
			return false;
		}

		for ( var i = 0; i < list.length; i++ ) {
			list[ i ].setAttribute( 'data-wccbba-key', keys[ i ] );
		}

		return true;
	}

	/** The key each row carries now, in the order the rows are listed in. */
	function submitted() {
		var list = rows();
		var keys = [];

		for ( var i = 0; i < list.length; i++ ) {
			keys.push( list[ i ].getAttribute( 'data-wccbba-key' ) || '' );
		}

		return keys;
	}

	// The accounts are fetched before they are listed, so the rows are not there yet
	if ( tag( data.keys ) ) {
		tagged = true;
	} else {
		var observer = new MutationObserver( function () {
			if ( tag( data.keys ) ) {
				tagged = true;
				observer.disconnect();
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		// A settings screen that never lists bank accounts is not this screen
		window.setTimeout( function () {
			observer.disconnect();
		}, 30000 );
	}

	// Nothing is reloaded after a save, so the keys the page was given no longer describe the
	// accounts that are now stored. Read the new ones back and tag the rows again.
	function retag() {
		window.wp.apiFetch( { path: '/wccbba/v1/account-keys' } ).then( function ( result ) {
			if ( ! result ) {
				return;
			}

			if ( result.keys && tag( result.keys ) ) {
				data.keys = result.keys;
			}

			if ( result.changed ) {
				warn( result.notice );
			}
		} ).catch( function () {
			// Leave the rows tagged as they are, the save simply cannot be followed again
		} );
	}

	/**
	 * The warning the settings form prints after a save, in this screen's own notices.
	 *
	 * Nothing is reloaded here, so there is no page for the notice to be printed into.
	 */
	function warn( notice ) {
		var notices = window.wp.data && window.wp.data.dispatch ? window.wp.data.dispatch( 'core/notices' ) : null;

		if ( ! notices || ! notices.createNotice || ! notice || ! notice.text ) {
			return;
		}

		notices.createNotice( 'warning', notice.text, {
			isDismissible: true,
			actions: notice.url && notice.label ? [ { url: notice.url, label: notice.label } ] : [],
		} );
	}

	window.wp.apiFetch.use( function ( options, next ) {
		var target = String( options.path || options.url || '' );
		var accounts = options.data && options.data.woocommerce_bacs_accounts;

		if ( ! tagged || 'POST' !== options.method || target.indexOf( '/wc-admin/options' ) === -1 || ! accounts ) {
			return next( options );
		}

		var keys = submitted();

		// The rows and the accounts being saved have to be the same list for their order to pair
		// them up; anything else is a screen this script has not kept up with
		if ( keys.length !== accounts.length ) {
			return next( options );
		}

		options.headers = options.headers || {};
		options.headers['X-WCCBBA-Account-Keys'] = keys.join( ',' );

		return next( options ).then( function ( result ) {
			retag();

			return result;
		} );
	} );
} )();
