<?php
/**
 * Which order status the bank accounts are emailed on.
 *
 * Needs the test bank accounts, so run bin/setup-site.php first. From the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/unit/email-status.php
 *
 * WC_Gateway_BACS::email_instructions() prints the accounts only for a customer email about a
 * bacs order whose status matches woocommerce_bacs_email_instructions_order_status, which
 * defaults to on-hold and which a store can move anywhere. The plugin hooks the same email
 * action to decide which accounts those are, so whatever status a store prints on, the ones
 * printed still have to be the accounts selected for the billing country - and on a status the
 * store does not print on, nothing may be printed at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

// Creating and closing orders fires WooCommerce's transactional emails, and a test run must not
// send anything to a real address. The assertions render the email body themselves.
add_filter( 'pre_wp_mail', '__return_false' );

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );
$base   = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wccbba-tests';
$state  = json_decode( (string) file_get_contents( $base . '/site-state.json' ), true );

if ( ! $state || empty( $state['accountOptions'] ) ) {
	echo "No site-state.json - run bin/setup-site.php first.\n";
	exit( 1 );
}

$GLOBALS['wccbba_pass'] = 0;
$GLOBALS['wccbba_fail'] = 0;

function wccbba_check( $name, $got, $expected ) {
	$ok = $got === $expected;
	$GLOBALS[ $ok ? 'wccbba_pass' : 'wccbba_fail' ]++;
	printf( "%-5s %-62s %s\n", $ok ? 'OK' : 'FAIL', $name, $ok ? '' : 'got ' . wp_json_encode( $got ) . ' expected ' . wp_json_encode( $expected ) );
}

/** Refs of the accounts whose markers appear in a customer email for this order. */
function wccbba_emailed_refs( $order, $config ) {
	ob_start();
	do_action( 'woocommerce_email_before_order_table', $order, false, false );
	$html = ob_get_clean();

	$refs = array();
	foreach ( $config['accounts'] as $account ) {
		if ( false !== strpos( $html, $account['iban'] ) ) {
			$refs[] = $account['ref'];
		}
	}
	sort( $refs );

	return $refs;
}

/** An order of the given status, billed to the given country, paid by bank transfer. */
function wccbba_order( $status, $country, $config ) {
	$order = wc_create_order();
	$order->set_payment_method( 'bacs' );
	$order->set_billing_country( $country );
	$order->add_product( wc_get_product( (int) $config['productId'] ), 1 );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->save();

	return $order;
}

$options = $state['accountOptions'];

// A → SK, B → CZ, C → SK, which is the shape unit/plugin-logic.php calls "all-assigned".
update_option( $options['a'], array( 'SK' ) );
update_option( $options['b'], array( 'CZ' ) );
update_option( $options['c'], array( 'SK' ) );

$on_hold    = wccbba_order( 'on-hold', 'SK', $config );
$processing = wccbba_order( 'processing', 'SK', $config );
$other      = wccbba_order( 'processing', 'CZ', $config );

echo "=== the status WooCommerce prints on by default ===\n";

wccbba_check( 'on hold: the accounts for the billing country', wccbba_emailed_refs( $on_hold, $config ), array( 'a', 'c' ) );
wccbba_check( 'processing: nothing is printed', wccbba_emailed_refs( $processing, $config ), array() );

echo "\n=== a store that prints on processing instead ===\n";

add_filter( 'woocommerce_bacs_email_instructions_order_status', function() { return 'processing'; } );

wccbba_check( 'processing: the accounts for the billing country', wccbba_emailed_refs( $processing, $config ), array( 'a', 'c' ) );
wccbba_check( 'processing: another country gets its own account', wccbba_emailed_refs( $other, $config ), array( 'b' ) );
wccbba_check( 'on hold: nothing is printed any more', wccbba_emailed_refs( $on_hold, $config ), array() );

remove_all_filters( 'woocommerce_bacs_email_instructions_order_status' );

echo "\n=== the filter decides per order ===\n";

// The filter is handed the order, so a store can print on a different status for some of them.
add_filter(
	'woocommerce_bacs_email_instructions_order_status',
	function( $status, $order ) {
		return 'CZ' === $order->get_billing_country() ? 'processing' : $status;
	},
	10,
	2
);

wccbba_check( 'the order it is given decides: processing, CZ', wccbba_emailed_refs( $other, $config ), array( 'b' ) );
wccbba_check( 'the order it is given decides: processing, SK', wccbba_emailed_refs( $processing, $config ), array() );
wccbba_check( 'the order it is given decides: on hold, SK', wccbba_emailed_refs( $on_hold, $config ), array( 'a', 'c' ) );

remove_all_filters( 'woocommerce_bacs_email_instructions_order_status' );

foreach ( array( $on_hold, $processing, $other ) as $order ) {
	$order->delete( true );
}

printf( "\nEMAIL STATUS: %d passed, %d failed\n", $GLOBALS['wccbba_pass'], $GLOBALS['wccbba_fail'] );
exit( $GLOBALS['wccbba_fail'] ? 1 : 0 );
