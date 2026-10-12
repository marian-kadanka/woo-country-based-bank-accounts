/**
 * Hide the BACS payment gateway on the block checkout as soon as the customer
 * picks a billing country none of the bank accounts are configured for.
 *
 * The same check runs on the server through woocommerce_available_payment_gateways,
 * this only saves the customer from waiting for the cart to be updated.
 */
( function ( registry, settings ) {
	'use strict';

	if ( ! registry || ! settings ) {
		return;
	}

	var data = settings.getSetting( 'wccbba_data', {} );

	if ( ! data.active ) {
		return;
	}

	var countries = data.countries || [];

	registry.registerPaymentMethodExtensionCallbacks( 'wccbba', {
		bacs: function ( args ) {
			var country = args && args.billingAddress ? args.billingAddress.country : '';

			// Leave the gateway alone until the customer has chosen a country.
			if ( ! country ) {
				return true;
			}

			return countries.indexOf( country ) !== -1;
		},
	} );
} )( window.wc && window.wc.wcBlocksRegistry, window.wc && window.wc.wcSettings );
