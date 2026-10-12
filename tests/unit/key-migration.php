<?php
/**
 * Where a bank account's countries end up when the bank accounts themselves are saved.
 *
 * Needs the test bank accounts, so run bin/setup-site.php first. From the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/unit/key-migration.php
 *
 * The countries are stored under a hash of the account details, so editing an account changes
 * the key they belong to. Every case here goes through the hook the Direct bank transfer
 * settings screen fires, with the $_POST that screen submits, and then asks the plugin which
 * countries each stored account has - the same question the order confirmation page asks.
 *
 * Both ways of pairing a saved account up with the one it was edited from are covered: the key
 * each row carries (assets/js/bacs-settings.js puts it there), and the position of the row for a
 * screen without JavaScript. The cases named "WooCommerce reuses" are the ones where positions
 * alone would hand the countries to a bank account they were never assigned to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

// The hooks under test are the ones the plugin only registers for the admin, where the settings
// screen that fires them lives. WP-CLI is not an admin request, so say it is one and let the
// plugin's own constructor do the wiring - that way the priorities around the gateway's save are
// the plugin's, not this file's idea of them.
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );
$base   = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wccbba-tests';
$state  = json_decode( (string) file_get_contents( $base . '/site-state.json' ), true );

if ( ! $state || empty( $state['accountOptions'] ) ) {
	echo "No site-state.json - run bin/setup-site.php first.\n";
	exit( 1 );
}

// wp eval-file includes this inside a function scope, so file level variables are not globals.
$GLOBALS['wccbba_pass']   = 0;
$GLOBALS['wccbba_fail']   = 0;
$GLOBALS['wccbba_keys']   = array(); // every option this run has written, for the cleanup
$GLOBALS['wccbba_notice'] = '';      // whatever the save printed

function wccbba_check( $name, $got, $expected ) {
	$ok = $got === $expected;
	$GLOBALS[ $ok ? 'wccbba_pass' : 'wccbba_fail' ]++;
	printf(
		"%-5s %-62s %s\n",
		$ok ? 'OK' : 'FAIL',
		$name,
		$ok ? '' : 'got ' . wp_json_encode( $got ) . ' expected ' . wp_json_encode( $expected )
	);
}

/** A bank account in the field order WC_Gateway_BACS::save_account_details() stores. */
function wccbba_account( $name, $number, $bank, $sort, $iban, $bic ) {
	return array(
		'account_name'   => $name,
		'account_number' => $number,
		'bank_name'      => $bank,
		'sort_code'      => $sort,
		'iban'           => $iban,
		'bic'            => $bic,
	);
}

/** The same account with one field changed, which is what an edit on the screen amounts to. */
function wccbba_edited( $account, $field, $value ) {
	$account[ $field ] = $value;

	return $account;
}

function wccbba_key( $account ) {
	return WC_Country_Based_Bank_Accounts::account_key( $account );
}

/** The countries the plugin reads for an account, which is all the rest of it ever asks for. */
function wccbba_countries( $account ) {
	return WC_Country_Based_Bank_Accounts::get_account_countries( $account );
}

/**
 * The state a case starts from: these accounts stored, with these countries.
 *
 * @param array $accounts  Bank accounts to store.
 * @param array $countries Parallel list of country lists, null for an account with none.
 */
function wccbba_given( $accounts, $countries ) {
	foreach ( $GLOBALS['wccbba_keys'] as $key ) {
		delete_option( $key );
	}

	// Writing the accounts is a change like any other, so the plugin prints its "you should
	// now adjust the settings" notice. A case asserts on that where it belongs; here it is
	// just the fixture being laid out, so keep it out of the report.
	ob_start();
	update_option( 'woocommerce_bacs_accounts', $accounts );
	ob_end_clean();

	// Everything that reads the accounts goes through the gateway, which reads them once per
	// request. This process has had the same gateway object since it started, so hand it what
	// has just been stored - a real request would have read exactly that.
	$gateways                          = WC()->payment_gateways->payment_gateways();
	$gateways['bacs']->account_details = $accounts;

	foreach ( $accounts as $i => $account ) {
		$key                      = wccbba_key( $account );
		$GLOBALS['wccbba_keys'][] = $key;

		if ( isset( $countries[ $i ] ) && null !== $countries[ $i ] ) {
			update_option( $key, $countries[ $i ] );
		}
	}
}

/**
 * Saves bank accounts the way the Direct bank transfer settings screen does.
 *
 * @param array      $rows position => account, the positions being the ones the rendered rows
 *                         were given, as the browser submits them.
 * @param array|null $keys position => the key that row carries, null to submit none at all,
 *                         which is a screen without JavaScript.
 * @return array the accounts as they are stored afterwards
 */
function wccbba_save( $rows, $keys = null ) {
	$_POST = array();

	foreach ( $rows as $position => $account ) {
		foreach ( $account as $field => $value ) {
			$_POST[ 'bacs_' . $field ][ $position ] = $value;
		}
	}

	if ( null !== $keys ) {
		$_POST['wccbba_account_key'] = $keys;
	}

	// Same as in wccbba_given(): a save runs in a request of its own, where the gateway has
	// just read what is stored, rather than from the table a previous case left behind.
	$gateways                          = WC()->payment_gateways->payment_gateways();
	$gateways['bacs']->account_details = get_option( 'woocommerce_bacs_accounts' );

	ob_start();
	do_action( 'woocommerce_update_options_payment_gateways_bacs' );
	$GLOBALS['wccbba_notice'] = ob_get_clean();

	$_POST = array();

	$stored = (array) get_option( 'woocommerce_bacs_accounts' );

	foreach ( $stored as $account ) {
		$GLOBALS['wccbba_keys'][] = wccbba_key( $account );
	}

	return $stored;
}

// --- the accounts the cases are played out with ------------------------------------------------
$a = wccbba_account( 'Account A', '1111111111', 'Bank A', '1111', 'WCCBBA-MIGRATION-AAA', 'AAAATEST' );
$b = wccbba_account( 'Account B', '2222222222', 'Bank B', '2222', 'WCCBBA-MIGRATION-BBB', 'BBBBTEST' );
$c = wccbba_account( 'Account C', '3333333333', 'Bank C', '3333', 'WCCBBA-MIGRATION-CCC', 'CCCCTEST' );
$d = wccbba_account( 'Account D', '4444444444', 'Bank D', '4444', 'WCCBBA-MIGRATION-DDD', 'DDDDTEST' );

$three     = array( $a, $b, $c );
$countries = array( array( 'SK' ), array( 'CZ' ), array( 'DE' ) );

// An edit of the one field a typo is most likely in, and the one the account is identified by:
// nothing else about the account says it is still the same bank account.
$a_fixed = wccbba_edited( $a, 'iban', 'WCCBBA-MIGRATION-AAA-FIXED' );
$b_fixed = wccbba_edited( $b, 'iban', 'WCCBBA-MIGRATION-BBB-FIXED' );
$c_fixed = wccbba_edited( $c, 'iban', 'WCCBBA-MIGRATION-CCC-FIXED' );

$keys_of_three = array( 0 => wccbba_key( $a ), 1 => wccbba_key( $b ), 2 => wccbba_key( $c ) );

echo "=== the gateway's save is hooked around ===\n";

// The plugin is already loaded, without its admin half. Running its constructor as an admin
// request registers it, and proves this is the wiring the settings screen gets.
$plugin = new WC_Country_Based_Bank_Accounts();

$gateways = WC()->payment_gateways->payment_gateways();
$bacs     = $gateways['bacs'];

// The gateway's own settings are saved by the same hook, from $_POST fields the cases below do
// not submit, which would wipe the title and instructions the rest of the suite asserts on.
remove_action( 'woocommerce_update_options_payment_gateways_bacs', array( $bacs, 'process_admin_options' ) );

$hooked = array();
foreach ( $GLOBALS['wp_filter']['woocommerce_update_options_payment_gateways_bacs'] as $priority => $callbacks ) {
	foreach ( $callbacks as $callback ) {
		if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WC_Country_Based_Bank_Accounts ) {
			$hooked[ $callback['function'][1] ] = $priority;
		}
	}
}

wccbba_check( 'the accounts are captured before the gateway saves', isset( $hooked['capture_bank_accounts'] ) && $hooked['capture_bank_accounts'] < 10, true );
wccbba_check( 'the countries are moved after the gateway saves', isset( $hooked['move_account_countries'] ) && $hooked['move_account_countries'] > 10, true );

echo "\n=== an account is edited ===\n";

foreach ( array( 'the row says which account it is' => $keys_of_three, 'no JavaScript, so by position' => null ) as $how => $keys ) {
	wccbba_given( $three, $countries );
	$stored = wccbba_save( array( 0 => $a, 1 => $b_fixed, 2 => $c ), $keys );

	wccbba_check( "$how: the edited account keeps its countries", wccbba_countries( $b_fixed ), array( 'CZ' ) );
	wccbba_check( "$how: nothing is left under the old details", get_option( wccbba_key( $b ) ), false );
	wccbba_check( "$how: the other accounts are untouched", array( wccbba_countries( $a ), wccbba_countries( $c ) ), array( array( 'SK' ), array( 'DE' ) ) );
	wccbba_check( "$how: the accounts are stored as submitted", count( $stored ), 3 );
}

echo "\n=== the accounts are reordered ===\n";

// Dragging rows around changes no account, so there is nothing to move - and nothing may be
// lost either. The rows are submitted in their new order, each under the position it was
// rendered at.
wccbba_given( $three, $countries );
$stored = wccbba_save( array( 2 => $c, 0 => $a, 1 => $b ), $keys_of_three );

wccbba_check( 'reordered: stored in the new order', array( $stored[0]['iban'], $stored[1]['iban'], $stored[2]['iban'] ), array( $c['iban'], $a['iban'], $b['iban'] ) );
wccbba_check( 'reordered: every account keeps its countries', array( wccbba_countries( $a ), wccbba_countries( $b ), wccbba_countries( $c ) ), $countries );

// Reordered and edited in the same save: the position a row was rendered at is what pairs it up,
// not where it ended up.
wccbba_given( $three, $countries );
wccbba_save( array( 1 => $b, 0 => $a_fixed, 2 => $c ), $keys_of_three );

wccbba_check( 'reordered and edited: the countries follow the edit', wccbba_countries( $a_fixed ), array( 'SK' ) );
wccbba_check( 'reordered and edited: the old details are gone', get_option( wccbba_key( $a ) ), false );
wccbba_check( 'reordered and edited: the others are untouched', array( wccbba_countries( $b ), wccbba_countries( $c ) ), array( array( 'CZ' ), array( 'DE' ) ) );

echo "\n=== an account is removed ===\n";

wccbba_given( $three, $countries );
$stored = wccbba_save( array( 0 => $a, 2 => $c ), array( 0 => $keys_of_three[0], 2 => $keys_of_three[2] ) );

wccbba_check( 'removed: the remaining accounts keep their countries', array( wccbba_countries( $a ), wccbba_countries( $c ) ), array( array( 'SK' ), array( 'DE' ) ) );
wccbba_check( 'removed: two accounts are left', count( $stored ), 2 );

// Removing one account and editing another in the same save. The positions no longer describe
// what was rendered, so only the keys the rows carry can get this right.
wccbba_given( $three, $countries );
wccbba_save( array( 0 => $a, 2 => $c_fixed ), array( 0 => $keys_of_three[0], 2 => $keys_of_three[2] ) );

wccbba_check( 'removed and edited: the countries follow the edit', wccbba_countries( $c_fixed ), array( 'DE' ) );
wccbba_check( 'removed and edited: the untouched account is untouched', wccbba_countries( $a ), array( 'SK' ) );

echo "\n=== an account is added ===\n";

wccbba_given( $three, $countries );
$stored = wccbba_save( array( 0 => $a, 1 => $b, 2 => $c, 3 => $d ), $keys_of_three + array( 3 => '' ) );

wccbba_check( 'added: the existing accounts keep their countries', array( wccbba_countries( $a ), wccbba_countries( $b ), wccbba_countries( $c ) ), $countries );
wccbba_check( 'added: the new account has no country yet', wccbba_countries( $d ), array() );
wccbba_check( 'added: four accounts are stored', count( $stored ), 4 );

echo "\n=== WooCommerce reuses the position of a removed account ===\n";

// Remove the last two of three accounts and add one, and WooCommerce gives the added row the
// position the second account had. Its countries must not follow a position into a bank account
// nobody assigned them to - the row carries no key, so there is nothing to move.
wccbba_given( $three, $countries );
$stored = wccbba_save( array( 0 => $a, 1 => $d ), array( 0 => $keys_of_three[0], 1 => '' ) );

wccbba_check( 'reused position: the added account has no country', wccbba_countries( $d ), array() );
wccbba_check( 'reused position: the removed account keeps its own', get_option( wccbba_key( $b ) ), array( 'CZ' ) );
wccbba_check( 'reused position: the kept account is untouched', wccbba_countries( $a ), array( 'SK' ) );
wccbba_check( 'reused position: two accounts are stored', count( $stored ), 2 );

// The same submission without the keys. Positions alone cannot tell this apart from an edit, so
// nothing is moved at all and the countries stay where they are.
wccbba_given( $three, $countries );
wccbba_save( array( 0 => $a, 1 => $d ), null );

wccbba_check( 'reused position, no JavaScript: the added account has no country', wccbba_countries( $d ), array() );
wccbba_check( 'reused position, no JavaScript: nothing is moved', array( get_option( wccbba_key( $b ) ), get_option( wccbba_key( $c ) ) ), array( array( 'CZ' ), array( 'DE' ) ) );

// An edit submitted alongside positions that are not the ones that were rendered is left alone
// for the same reason. The countries are still there under the details they were saved for.
wccbba_given( $three, $countries );
wccbba_save( array( 0 => $a, 2 => $c_fixed ), null );

wccbba_check( 'positions not as rendered: the edit gets no countries', wccbba_countries( $c_fixed ), array() );
wccbba_check( 'positions not as rendered: they stay where they were', get_option( wccbba_key( $c ) ), array( 'DE' ) );

echo "\n=== two accounts with the same details ===\n";

// Identical accounts share one key, so they share their countries and no save can tell which of
// them a row was edited from. Leaving them put is the only answer that cannot be wrong.
wccbba_given( array( $a, $a, $c ), array( array( 'SK' ), null, array( 'DE' ) ) );
wccbba_save( array( 0 => $a, 1 => $a_fixed, 2 => $c ), array( 0 => wccbba_key( $a ), 1 => wccbba_key( $a ), 2 => wccbba_key( $c ) ) );

wccbba_check( 'identical accounts: the edited one gets nothing', wccbba_countries( $a_fixed ), array() );
wccbba_check( 'identical accounts: the shared countries stay', get_option( wccbba_key( $a ) ), array( 'SK' ) );

echo "\n=== the edited details already have countries of their own ===\n";

wccbba_given( $three, $countries );
update_option( wccbba_key( $b_fixed ), array( 'PL' ) );
$GLOBALS['wccbba_keys'][] = wccbba_key( $b_fixed );

wccbba_save( array( 0 => $a, 1 => $b_fixed, 2 => $c ), $keys_of_three );

wccbba_check( 'already assigned: the stored countries win', wccbba_countries( $b_fixed ), array( 'PL' ) );
wccbba_check( 'already assigned: nothing is thrown away', get_option( wccbba_key( $b ) ), array( 'CZ' ) );

echo "\n=== two accounts swap their details ===\n";

// Typing A's details into B's row and B's into A's row. The countries belong to the rows, so
// they stay where they are - and neither of them may overwrite the other on the way.
wccbba_given( array( $a, $b ), array( array( 'SK' ), array( 'CZ' ) ) );
wccbba_save( array( 0 => $b, 1 => $a ), array( 0 => wccbba_key( $a ), 1 => wccbba_key( $b ) ) );

wccbba_check( 'swapped: the first row keeps its countries', get_option( wccbba_key( $b ) ), array( 'SK' ) );
wccbba_check( 'swapped: the second row keeps its countries', get_option( wccbba_key( $a ) ), array( 'CZ' ) );

echo "\n=== the administrator is still told the accounts changed ===\n";

// Moving the countries along does not cover every way the accounts can change, so the notice
// pointing at the plugin's settings has to keep showing up whenever they do.
wccbba_given( $three, $countries );
wccbba_save( array( 0 => $a, 1 => $b_fixed, 2 => $c ), $keys_of_three );
wccbba_check( 'nothing is printed while the accounts are being saved', trim( $GLOBALS['wccbba_notice'] ), '' );

// The notice belongs inside the settings page, which is rendered after the save.
ob_start();
$plugin->print_accounts_changed_notice();
$printed = ob_get_clean();
wccbba_check( 'the settings page prints the notice', false !== strpos( $printed, 'tab=wccbba' ), true );

wccbba_save( array( 0 => $a, 1 => $b_fixed, 2 => $c ), $keys_of_three );
ob_start();
$plugin->print_accounts_changed_notice();
$printed = ob_get_clean();
wccbba_check( 'it keeps printing for the rest of that request', false !== strpos( $printed, 'tab=wccbba' ), true );

echo "\n=== the block based settings screen saves over REST ===\n";

// That screen writes the accounts straight to the option through the REST API: no settings
// form, no $_POST, no gateway save. All the plugin gets is the header its script sends.
wp_set_current_user( 1 );

/**
 * Saves bank accounts the way the block based payment settings screen does.
 *
 * @param array      $accounts Accounts to store, in the order the rows are listed in.
 * @param array|null $header   Key each row carries, null to send no header at all.
 * @return int response status
 */
function wccbba_rest_save( $accounts, $header = null ) {
	if ( null === $header ) {
		unset( $_SERVER['HTTP_X_WCCBBA_ACCOUNT_KEYS'] );
	} else {
		$_SERVER['HTTP_X_WCCBBA_ACCOUNT_KEYS'] = implode( ',', $header );
	}

	$request = new WP_REST_Request( 'POST', '/wc-admin/options' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'woocommerce_bacs_accounts' => $accounts ) ) );

	ob_start();
	$response = rest_do_request( $request );
	ob_end_clean();

	unset( $_SERVER['HTTP_X_WCCBBA_ACCOUNT_KEYS'] );

	foreach ( (array) get_option( 'woocommerce_bacs_accounts' ) as $account ) {
		$GLOBALS['wccbba_keys'][] = wccbba_key( $account );
	}

	return $response->get_status();
}

$rest_keys = array( wccbba_key( $a ), wccbba_key( $b ), wccbba_key( $c ) );

wccbba_given( $three, $countries );
$status = wccbba_rest_save( array( $a, $b_fixed, $c ), $rest_keys );

wccbba_check( 'REST: the save goes through', $status, 200 );
wccbba_check( 'REST: the edited account keeps its countries', wccbba_countries( $b_fixed ), array( 'CZ' ) );
wccbba_check( 'REST: nothing is left under the old details', get_option( wccbba_key( $b ) ), false );
wccbba_check( 'REST: the other accounts are untouched', array( wccbba_countries( $a ), wccbba_countries( $c ) ), array( array( 'SK' ), array( 'DE' ) ) );

// The rows are listed in their new order and carry their keys with them.
wccbba_given( $three, $countries );
wccbba_rest_save( array( $c, $a, $b_fixed ), array( $rest_keys[2], $rest_keys[0], $rest_keys[1] ) );

wccbba_check( 'REST reordered and edited: the countries follow the edit', wccbba_countries( $b_fixed ), array( 'CZ' ) );
wccbba_check( 'REST reordered and edited: the others are untouched', array( wccbba_countries( $a ), wccbba_countries( $c ) ), array( array( 'SK' ), array( 'DE' ) ) );

// A row that was added carries no key, so there is nothing to hand it.
wccbba_given( $three, $countries );
wccbba_rest_save( array( $a, $d, $b_fixed, $c ), array( $rest_keys[0], '', $rest_keys[1], $rest_keys[2] ) );

wccbba_check( 'REST added: the new account has no country', wccbba_countries( $d ), array() );
wccbba_check( 'REST added: the edited account keeps its countries', wccbba_countries( $b_fixed ), array( 'CZ' ) );

// Everything below has to leave the countries exactly where they are.
$untouched = array( 'a' => array( 'SK' ), 'b' => array( 'CZ' ), 'c' => array( 'DE' ) );

function wccbba_stored_countries( $a, $b, $c ) {
	return array(
		'a' => get_option( wccbba_key( $a ) ),
		'b' => get_option( wccbba_key( $b ) ),
		'c' => get_option( wccbba_key( $c ) ),
	);
}

wccbba_given( $three, $countries );
wccbba_rest_save( array( $a, $b_fixed, $c ), null );
wccbba_check( 'REST without the header: nothing is moved', wccbba_stored_countries( $a, $b, $c ), $untouched );

wccbba_given( $three, $countries );
wccbba_rest_save( array( $a, $b_fixed, $c ), array( $rest_keys[0], 'wccbba_not_a_key_of_any_account', $rest_keys[2] ) );
wccbba_check( 'REST with an unknown key: that row is left alone', wccbba_countries( $b_fixed ), array() );
wccbba_check( 'REST with an unknown key: its countries stay put', get_option( wccbba_key( $b ) ), array( 'CZ' ) );

wccbba_given( $three, $countries );
wccbba_rest_save( array( $a, $b_fixed, $c ), array( $rest_keys[0], $rest_keys[1] ) );
wccbba_check( 'REST with fewer keys than accounts: nothing is moved', wccbba_stored_countries( $a, $b, $c ), $untouched );

// The screen cannot print the "accounts updated" notice itself, so it asks for it here. The
// answer clears it, so the warning is shown once per save and not on every later request.
wccbba_given( $three, $countries );
wccbba_rest_save( array( $a, $b_fixed, $c ), $rest_keys );

$answer = rest_do_request( new WP_REST_Request( 'GET', '/wccbba/v1/account-keys' ) )->get_data();
wccbba_check( 'a save that changed the accounts is reported', isset( $answer['changed'] ) ? $answer['changed'] : null, true );
wccbba_check( 'the warning text comes with it', isset( $answer['notice']['text'] ) && '' !== $answer['notice']['text'], true );
wccbba_check( 'and a link to the plugin settings', isset( $answer['notice']['url'] ) && false !== strpos( $answer['notice']['url'], 'tab=wccbba' ), true );

$answer = rest_do_request( new WP_REST_Request( 'GET', '/wccbba/v1/account-keys' ) )->get_data();
wccbba_check( 'asking again does not warn twice', $answer['changed'], false );

// Saving the same accounts stores nothing, so there is nothing to warn about either.
wccbba_rest_save( array( $a, $b_fixed, $c ), $rest_keys );
$answer = rest_do_request( new WP_REST_Request( 'GET', '/wccbba/v1/account-keys' ) )->get_data();
wccbba_check( 'a save that changed nothing does not warn', $answer['changed'], false );

// The script reads the keys back from here after a save, since the page is never reloaded.
wccbba_given( $three, $countries );
$response = rest_do_request( new WP_REST_Request( 'GET', '/wccbba/v1/account-keys' ) );
wccbba_check( 'the account keys route answers', $response->get_status(), 200 );
$data = $response->get_data();
wccbba_check( 'the account keys route lists the stored accounts in order', isset( $data['keys'] ) ? $data['keys'] : null, $rest_keys );

wp_set_current_user( 0 );
$response = rest_do_request( new WP_REST_Request( 'GET', '/wccbba/v1/account-keys' ) );
wccbba_check( 'the account keys route refuses a visitor', $response->get_status(), 401 );
wp_set_current_user( 1 );

echo "\n=== the settings screen is handed the keys of the rows it renders ===\n";

// The script can only pair a row up with the right account if the keys it is given are the keys
// of the accounts being rendered, in that order.
wccbba_given( $three, $countries );

$_GET = array( 'tab' => 'checkout', 'section' => 'bacs' );
$plugin->enqueue_bacs_settings_script( 'index.php' );
wccbba_check( 'not loaded outside the settings screen', wp_script_is( 'wccbba-bacs-settings', 'enqueued' ), false );

$_GET = array( 'tab' => 'wccbba' );
$plugin->enqueue_bacs_settings_script( 'woocommerce_page_wc-settings' );
wccbba_check( "not loaded on the plugin's own tab", wp_script_is( 'wccbba-bacs-settings', 'enqueued' ), false );

$_GET = array( 'tab' => 'checkout', 'section' => 'bacs' );
$plugin->enqueue_bacs_settings_script( 'woocommerce_page_wc-settings' );
wccbba_check( 'loaded on the Direct bank transfer screen', wp_script_is( 'wccbba-bacs-settings', 'enqueued' ), true );

wccbba_check( 'the block based script is loaded with it', wp_script_is( 'wccbba-bacs-settings-blocks', 'enqueued' ), true );

$data   = (string) wp_scripts()->get_data( 'wccbba-bacs-settings', 'data' );
$json   = substr( $data, (int) strpos( $data, '{' ), strrpos( $data, '}' ) - (int) strpos( $data, '{' ) + 1 );
$handed = json_decode( $json, true );

wccbba_check(
	'the keys handed over are the rendered accounts, in order',
	isset( $handed['keys'] ) ? $handed['keys'] : null,
	array( wccbba_key( $a ), wccbba_key( $b ), wccbba_key( $c ) )
);

// The block based screen has no section at all, it is addressed by its path. Enqueuing again
// here is this test calling the method twice; a request only ever fires it once.
wp_dequeue_script( 'wccbba-bacs-settings' );
wp_dequeue_script( 'wccbba-bacs-settings-blocks' );
$_GET = array( 'tab' => 'checkout', 'path' => '/offline/bacs' );
$plugin->enqueue_bacs_settings_script( 'woocommerce_page_wc-settings' );
wccbba_check( 'loaded on the block based screen too', wp_script_is( 'wccbba-bacs-settings-blocks', 'enqueued' ), true );

$_GET = array();

// --- put the bank accounts back ----------------------------------------------------------------
foreach ( $GLOBALS['wccbba_keys'] as $key ) {
	delete_option( $key );
}

$restored = array();
foreach ( $config['accounts'] as $account ) {
	$restored[] = wccbba_account( $account['account_name'], $account['account_number'], $account['bank_name'], $account['sort_code'], $account['iban'], $account['bic'] );
}
ob_start();
update_option( 'woocommerce_bacs_accounts', $restored );
ob_end_clean();

// The rest of the suite addresses the test accounts by the option names bin/setup-site.php
// recorded, so prove this run handed them back exactly as they were.
$recorded = array_values( $state['accountOptions'] );
$now      = array();
foreach ( (array) get_option( 'woocommerce_bacs_accounts' ) as $account ) {
	$now[] = wccbba_key( $account );
}
wccbba_check( 'the test bank accounts are back', $now, $recorded );

printf( "\nKEY MIGRATION: %d passed, %d failed\n", $GLOBALS['wccbba_pass'], $GLOBALS['wccbba_fail'] );
exit( $GLOBALS['wccbba_fail'] ? 1 : 0 );
