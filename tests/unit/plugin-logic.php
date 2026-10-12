<?php
/**
 * The plugin's rules, asserted against the live plugin instance.
 *
 * Needs the test bank accounts, so run bin/setup-site.php first. From the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/unit/plugin-logic.php
 *
 * Covers the two rules and how they interact:
 *
 *   printing  available_bank_accounts(), plus the markup WC_Gateway_BACS really renders through
 *             woocommerce_thankyou_bacs and woocommerce_email_before_order_table
 *   checkout  maybe_disable_bacs() against the customer's billing country, and the data the
 *             block checkout integration hands to the browser
 *
 * The account shapes and expectations mirror tests/lib/expected.js, derived independently here
 * so that a wrong rule has to be written down twice to pass.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

// Creating and closing orders below fires WooCommerce's transactional emails. The assertions
// render the email body themselves, so nothing here needs mail to actually go out - and a test
// run must not send anything to a real address.
add_filter( 'pre_wp_mail', '__return_false' );

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );
$base   = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wccbba-tests';
$state  = json_decode( (string) file_get_contents( $base . '/site-state.json' ), true );

if ( ! $state || empty( $state['accountOptions'] ) ) {
	echo "No site-state.json - run bin/setup-site.php first.\n";
	exit( 1 );
}

// wp eval-file includes this inside a function scope, so file level variables are not globals -
// the counters have to live in $GLOBALS explicitly or every result is silently discarded.
$GLOBALS['wccbba_pass'] = 0;
$GLOBALS['wccbba_fail'] = 0;

function wccbba_check( $name, $got, $expected ) {
	$ok = $got === $expected;
	$GLOBALS[ $ok ? 'wccbba_pass' : 'wccbba_fail' ]++;
	printf(
		"%-5s %-58s %s\n",
		$ok ? 'OK' : 'FAIL',
		$name,
		$ok ? '' : 'got ' . wp_json_encode( $got ) . ' expected ' . wp_json_encode( $expected )
	);
}

/**
 * The live plugin instance, taken from the filter it registered.
 *
 * The plugin instantiates itself and keeps no reference anywhere, and the methods under test are
 * instance methods. Reaching for the object the hook holds is the only way to call them as the
 * running site does - and it also proves the hook is really registered.
 */
function wccbba_plugin_instance() {
	global $wp_filter;

	if ( isset( $wp_filter['woocommerce_bacs_accounts'] ) ) {
		foreach ( $wp_filter['woocommerce_bacs_accounts'] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WC_Country_Based_Bank_Accounts ) {
					return $callback['function'][0];
				}
			}
		}
	}

	return null;
}

$plugin = wccbba_plugin_instance();
if ( ! $plugin ) {
	echo "FAIL: the plugin has not hooked woocommerce_bacs_accounts - is it active?\n";
	exit( 1 );
}

$accounts = WC_Country_Based_Bank_Accounts::get_bacs_accounts();
if ( count( $accounts ) !== count( $config['accounts'] ) ) {
	echo 'FAIL: expected ' . count( $config['accounts'] ) . ' test bank accounts, the gateway has ' . count( $accounts ) . "\n";
	exit( 1 );
}

// ref => account, and ref => the option holding its countries.
$by_ref  = array();
$options = $state['accountOptions'];
foreach ( array_values( $accounts ) as $i => $account ) {
	$by_ref[ $config['accounts'][ $i ]['ref'] ] = $account;
}

/** Marker of each account, which is what the rendered markup is searched for. */
function wccbba_marker( $ref, $config ) {
	foreach ( $config['accounts'] as $account ) {
		if ( $account['ref'] === $ref ) {
			return $account['iban'];
		}
	}
	return '';
}

/** Applies one shape: an array of ref => countries, where null deletes the option. */
function wccbba_apply_shape( $shape, $options ) {
	foreach ( $shape as $ref => $countries ) {
		if ( null === $countries ) {
			delete_option( $options[ $ref ] );
		} else {
			update_option( $options[ $ref ], $countries );
		}
	}
}

$shapes = array(
	'all-assigned'   => array( 'a' => array( 'SK' ), 'b' => array( 'CZ' ), 'c' => array( 'SK' ) ),
	'one-unassigned' => array( 'a' => array( 'SK' ), 'b' => array( 'CZ' ), 'c' => null ),
);

echo "=== the switch ===\n";

update_option( 'wccbba_country_disable_bacs', 'yes' );
wccbba_check( 'stored "yes" hides the gateway', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), true );
update_option( 'wccbba_country_disable_bacs', 'no' );
wccbba_check( 'stored "no" offers it to everyone', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), false );
update_option( 'wccbba_country_disable_bacs', 'whatever' );
wccbba_check( 'an unknown value offers it to everyone', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), false );
delete_option( 'wccbba_country_disable_bacs' );
wccbba_check( 'no stored value offers it to everyone', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), false );

echo "\n=== a bank account's countries ===\n";

$account_a = $by_ref['a'];
update_option( $options['a'], array( 'SK', 'CZ' ) );
wccbba_check( 'stored countries are returned', WC_Country_Based_Bank_Accounts::get_account_countries( $account_a ), array( 'SK', 'CZ' ) );
update_option( $options['a'], array() );
wccbba_check( 'an empty list means no country', WC_Country_Based_Bank_Accounts::get_account_countries( $account_a ), array() );
update_option( $options['a'], 'SK' );
wccbba_check( 'a non array value is ignored', WC_Country_Based_Bank_Accounts::get_account_countries( $account_a ), array() );
delete_option( $options['a'] );
wccbba_check( 'no stored value means no country', WC_Country_Based_Bank_Accounts::get_account_countries( $account_a ), array() );

// Both ways of saying "every country" have to read the same, because the settings screen writes
// an empty array while an account the administrator never touched has no option at all.
update_option( $options['a'], array() );
wccbba_check( 'an empty list counts as unassigned', WC_Country_Based_Bank_Accounts::has_account_without_country(), true );
wccbba_apply_shape( $shapes['all-assigned'], $options );
wccbba_check( 'every account assigned', WC_Country_Based_Bank_Accounts::has_account_without_country(), false );
wccbba_apply_shape( $shapes['one-unassigned'], $options );
wccbba_check( 'one account unassigned', WC_Country_Based_Bank_Accounts::has_account_without_country(), true );

echo "\n=== which bank accounts are printed ===\n";

// An order per billing country, which is what the plugin reads the country from.
$product = wc_get_product( (int) $config['productId'] );
$orders  = array();
foreach ( array( 'SK', 'CZ', 'DE', '' ) as $country ) {
	$order = wc_create_order();
	$order->set_payment_method( 'bacs' );
	$order->set_billing_country( $country );
	$order->add_product( $product, 1 );
	$order->calculate_totals();
	$order->set_status( 'on-hold' );
	$order->save();
	$orders[ $country ] = $order;
}

/** Refs of the accounts available_bank_accounts() keeps for an order. */
function wccbba_printed_refs( $plugin, $order, $accounts, $config ) {
	$plugin->set_selected_country( $order );
	$kept = $plugin->available_bank_accounts( $accounts );
	$refs = array();
	foreach ( $kept as $account ) {
		foreach ( $config['accounts'] as $i => $defined ) {
			if ( $defined['iban'] === $account['iban'] ) {
				$refs[] = $defined['ref'];
			}
		}
	}
	sort( $refs );
	return $refs;
}

$printing_cases = array(
	// shape, billing country, refs expected
	array( 'all-assigned', 'SK', array( 'a', 'c' ) ),
	array( 'all-assigned', 'CZ', array( 'b' ) ),
	array( 'all-assigned', 'DE', array( 'a', 'b', 'c' ) ), // no match: all of them, never none
	array( 'all-assigned', '', array( 'a', 'b', 'c' ) ),   // no country given either
	array( 'one-unassigned', 'SK', array( 'a', 'c' ) ),
	array( 'one-unassigned', 'CZ', array( 'b', 'c' ) ),
	array( 'one-unassigned', 'DE', array( 'c' ) ),
	array( 'one-unassigned', '', array( 'c' ) ),
);

foreach ( $printing_cases as $case ) {
	list( $shape, $country, $expected ) = $case;
	wccbba_apply_shape( $shapes[ $shape ], $options );
	wccbba_check(
		sprintf( '%s, billed to %s', $shape, $country ? $country : 'nowhere' ),
		wccbba_printed_refs( $plugin, $orders[ $country ], $accounts, $config ),
		$expected
	);
}

echo "\n=== what the customer really sees ===\n";

/**
 * Markers found in what BACS renders, through the hooks the running site fires.
 *
 * @param string    $hook  woocommerce_thankyou_bacs or woocommerce_email_before_order_table.
 * @param WC_Order  $order Order to render for.
 * @return array of refs
 */
function wccbba_rendered_refs( $hook, $order, $config ) {
	ob_start();
	if ( 'woocommerce_thankyou_bacs' === $hook ) {
		do_action( $hook, $order->get_id() );
	} else {
		do_action( $hook, $order, false, false );
	}
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

foreach ( array( 'woocommerce_thankyou_bacs', 'woocommerce_email_before_order_table' ) as $hook ) {
	$where = 'woocommerce_thankyou_bacs' === $hook ? 'order confirmation' : 'customer email';
	foreach ( $printing_cases as $case ) {
		list( $shape, $country, $expected ) = $case;
		if ( '' === $country ) {
			continue; // a real order always has a billing country; covered above at unit level
		}
		wccbba_apply_shape( $shapes[ $shape ], $options );
		wccbba_check(
			sprintf( '%s: %s, billed to %s', $where, $shape, $country ),
			wccbba_rendered_refs( $hook, $orders[ $country ], $config ),
			$expected
		);
	}
}

// The instructions are the gateway's own text and must show whatever the countries say.
wccbba_apply_shape( $shapes['all-assigned'], $options );
ob_start();
do_action( 'woocommerce_thankyou_bacs', $orders['DE']->get_id() );
$html = ob_get_clean();
wccbba_check( 'the gateway instructions are printed too', false !== strpos( $html, $config['instructionsMarker'] ), true );
wccbba_check( 'the bank details heading is printed', false !== strpos( $html, 'wc-bacs-bank-details' ), true );

echo "\n=== is the gateway offered on checkout? ===\n";

$gateways = WC()->payment_gateways->payment_gateways();
$bacs     = $gateways['bacs'];

/** Does maybe_disable_bacs() keep BACS for this billing country? */
function wccbba_bacs_offered( $plugin, $bacs, $country ) {
	WC()->customer->set_billing_country( $country );
	$filtered = $plugin->maybe_disable_bacs( array( 'bacs' => $bacs, 'cheque' => true ) );

	return isset( $filtered['bacs'] );
}

if ( ! WC()->session ) {
	WC()->initialize_session();
}

// maybe_disable_bacs() is only hooked when the switch is on, so it is called directly here and
// the switch is asserted separately above.
$checkout_cases = array(
	array( 'all-assigned', 'SK', true ),
	array( 'all-assigned', 'CZ', true ),
	array( 'all-assigned', 'DE', false ),
	array( 'all-assigned', '', true ), // no country yet: leave the gateway alone
	array( 'one-unassigned', 'SK', true ),
	array( 'one-unassigned', 'CZ', true ),
	array( 'one-unassigned', 'DE', true ), // the unassigned account matches every country
	array( 'one-unassigned', '', true ),
);

foreach ( $checkout_cases as $case ) {
	list( $shape, $country, $expected ) = $case;
	wccbba_apply_shape( $shapes[ $shape ], $options );
	wccbba_check(
		sprintf( '%s, billed to %s', $shape, $country ? $country : 'nowhere' ),
		wccbba_bacs_offered( $plugin, $bacs, $country ),
		$expected
	);
}

echo "\n=== what the block checkout gets handed ===\n";

require_once dirname( $dir ) . '/class-wc-country-based-bank-accounts-blocks.php';

foreach ( array(
	array( 'all-assigned', true, array( 'SK', 'CZ' ) ),
	array( 'one-unassigned', false, null ),
) as $case ) {
	list( $shape, $active, $countries ) = $case;
	wccbba_apply_shape( $shapes[ $shape ], $options );

	$integration = new WC_Country_Based_Bank_Accounts_Blocks( '0' );
	$data        = $integration->get_script_data();

	wccbba_check( "$shape: active", $data['active'], $active );
	if ( $active ) {
		$got = $data['countries'];
		sort( $got );
		$want = $countries;
		sort( $want );
		wccbba_check( "$shape: countries handed over", $got, $want );
		wccbba_check( "$shape: the script is enqueued", count( $integration->get_script_handles() ), 1 );
	} else {
		wccbba_check( "$shape: no countries handed over", isset( $data['countries'] ), false );
		wccbba_check( "$shape: the script is not enqueued", count( $integration->get_script_handles() ), 0 );
	}

	// Whatever happens, the browser must never be handed the account details themselves.
	$json = wp_json_encode( $data );
	$leak = false;
	foreach ( $config['accounts'] as $account ) {
		foreach ( array( 'account_number', 'iban', 'bic', 'account_name' ) as $field ) {
			if ( false !== strpos( $json, $account[ $field ] ) ) {
				$leak = true;
			}
		}
	}
	wccbba_check( "$shape: no bank account details in the script data", $leak, false );
}

foreach ( $orders as $order ) {
	$order->delete( true );
}

printf( "\nPLUGIN LOGIC: %d passed, %d failed\n", $GLOBALS['wccbba_pass'], $GLOBALS['wccbba_fail'] );
exit( $GLOBALS['wccbba_fail'] ? 1 : 0 );
