<?php
/**
 * Prepares the development site for the tests and records what it changed.
 *
 * Run from the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/bin/setup-site.php
 *
 * Nothing here is tied to a particular site. The checkout pages are discovered from
 * WooCommerce's own page settings, and the bank accounts the tests assert on are written here
 * rather than inherited, so the suite runs anywhere without editing config.json.
 *
 * The plugin keys a bank account's countries by md5( serialize( $account ) ), which can only be
 * computed from the value actually stored, so the option names are read back and written to
 * site-state.json for the tests to use.
 *
 * Restore afterwards with bin/restore-site.php - it puts every option back, deletes any page it
 * created and removes the country options of the test accounts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

/**
 * Where a test run keeps its files.
 *
 * Deliberately outside the plugin directory: the plugin lives in the web root, and these files
 * hold the store's own option values. Mirrors tests/lib/artifacts.js.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wccbba_tests_artifacts_dir( $config ) {
	$base = ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wccbba-tests';
	if ( ! is_dir( $base ) ) {
		mkdir( $base, 0700, true );
	}
	return $base;
}

function wccbba_tests_backup_file( $config ) {
	return wccbba_tests_artifacts_dir( $config ) . '/site-backup.json';
}

function wccbba_tests_state_file( $config ) {
	return wccbba_tests_artifacts_dir( $config ) . '/site-state.json';
}

/**
 * Does a page hold the markup we are looking for?
 *
 * @param int    $page_id Page to inspect.
 * @param string $needle  Shortcode or block comment.
 * @return bool
 */
function wccbba_tests_page_contains( $page_id, $needle ) {
	if ( ! $page_id ) {
		return false;
	}
	$page = get_post( $page_id );

	return $page && 'page' === $page->post_type && false !== strpos( $page->post_content, $needle );
}

/**
 * The oldest published page holding $needle, skipping one id.
 *
 * @param string $needle Shortcode or block comment.
 * @param int    $skip   Page id to ignore.
 * @return int
 */
function wccbba_tests_find_page_containing( $needle, $skip = 0 ) {
	$pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);

	foreach ( $pages as $page ) {
		if ( (int) $page->ID !== (int) $skip && false !== strpos( $page->post_content, $needle ) ) {
			return (int) $page->ID;
		}
	}

	return 0;
}

/**
 * A page a previous run created, so repeated runs reuse it instead of piling pages up.
 *
 * @param string $kind "classic" or "block".
 * @return int
 */
function wccbba_tests_find_marked_page( $kind ) {
	$pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_key'    => '_wccbba_test_page',
			'meta_value'  => $kind,
		)
	);

	return $pages ? (int) $pages[0]->ID : 0;
}

/**
 * @param string $title   Page title.
 * @param string $content Page content.
 * @param string $kind    "classic" or "block", stored as meta so restore can find it.
 * @return int
 */
function wccbba_tests_create_page( $title, $content, $kind ) {
	$page_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_name'    => sanitize_title( $title ),
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);

	update_post_meta( $page_id, '_wccbba_test_page', $kind );

	return (int) $page_id;
}

/** WooCommerce's own default Checkout block markup, so the page behaves like a real one. */
function wccbba_tests_block_checkout_content() {
	return "<!-- wp:woocommerce/checkout -->\n<div class=\"wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading\"></div>\n<!-- /wp:woocommerce/checkout -->";
}

$dir    = dirname( __DIR__ );
$config = json_decode( file_get_contents( $dir . '/config.json' ), true );

$backup_file = wccbba_tests_backup_file( $config );
$state_file  = wccbba_tests_state_file( $config );

if ( file_exists( $backup_file ) ) {
	echo "A backup already exists at $backup_file - restore it before setting up again.\n";
	exit( 1 );
}

$options = array(
	'woocommerce_bacs_accounts',
	'woocommerce_bacs_settings',
	'wccbba_country_disable_bacs',
	'woocommerce_checkout_page_id',
	'woocommerce_enable_guest_checkout',
	'woocommerce_enable_signup_and_login_from_checkout',
);

$backup = array();
foreach ( $options as $option ) {
	$backup[ $option ] = get_option( $option );
}
file_put_contents( $backup_file, wp_json_encode( $backup, JSON_PRETTY_PRINT ) );
chmod( $backup_file, 0600 );
echo "backed up current values to $backup_file\n";

// --- the bank accounts the assertions are made against ---------------------------------------
// Written in the field order WC_Gateway_BACS::save_account_details() uses, so the stored value -
// and therefore the md5 the plugin keys its country options by - is the one a real store has.
$accounts = array();
foreach ( $config['accounts'] as $account ) {
	$accounts[] = array(
		'account_name'   => $account['account_name'],
		'account_number' => $account['account_number'],
		'bank_name'      => $account['bank_name'],
		'sort_code'      => $account['sort_code'],
		'iban'           => $account['iban'],
		'bic'            => $account['bic'],
	);
}
update_option( 'woocommerce_bacs_accounts', $accounts );

$bacs_settings = array_merge( (array) get_option( 'woocommerce_bacs_settings', array() ), $config['bacsSettings'] );
update_option( 'woocommerce_bacs_settings', $bacs_settings );

// Read back, so the option names are computed from what is really stored rather than from what
// we meant to store.
$stored          = (array) get_option( 'woocommerce_bacs_accounts' );
$account_options = array();
foreach ( $stored as $i => $account ) {
	$ref                     = $config['accounts'][ $i ]['ref'];
	$option                  = 'wccbba_' . md5( serialize( $account ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	$account_options[ $ref ] = $option;

	// Leftovers from an earlier run would silently become the starting state of the next one.
	delete_option( $option );
}

// Each test assigns the countries it needs; start from "no account has any".
delete_option( 'wccbba_country_disable_bacs' );

echo "wrote " . count( $stored ) . " test bank accounts:\n";
foreach ( $account_options as $ref => $option ) {
	echo '  ' . $ref . '  ' . str_pad( $config['accounts'][ array_search( $ref, array_column( $config['accounts'], 'ref' ), true ) ]['iban'], 20 ) . $option . "\n";
}

// --- the two checkout pages -------------------------------------------------------------------
$configured = (int) $backup['woocommerce_checkout_page_id'];
$created    = array();

$classic_id = wccbba_tests_page_contains( $configured, '[woocommerce_checkout]' ) ? $configured : 0;
if ( ! $classic_id ) {
	$classic_id = wccbba_tests_find_marked_page( 'classic' );
}
if ( ! $classic_id ) {
	$classic_id = wccbba_tests_find_page_containing( '[woocommerce_checkout]' );
}
if ( ! $classic_id ) {
	$classic_id = wccbba_tests_create_page(
		'WCCBBA classic checkout test',
		"<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->",
		'classic'
	);
	$created[] = $classic_id;
}

$block_id = wccbba_tests_find_marked_page( 'block' );
if ( ! $block_id && wccbba_tests_page_contains( $configured, 'wp:woocommerce/checkout' ) ) {
	$block_id = $configured;
}
if ( ! $block_id ) {
	$block_id = wccbba_tests_find_page_containing( '<!-- wp:woocommerce/checkout', $classic_id );
}
if ( ! $block_id || $block_id === $classic_id ) {
	$block_id  = wccbba_tests_create_page( 'WCCBBA block checkout test', wccbba_tests_block_checkout_content(), 'block' );
	$created[] = $block_id;
}

// run-all.sh points this at whichever page the phase it is about to run needs.
update_option( 'woocommerce_checkout_page_id', $block_id );

// Orders are placed as a guest, so the runs do not create user accounts.
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

$state = array(
	'classicCheckoutPageId' => $classic_id,
	'classicCheckoutPath'   => wp_make_link_relative( get_permalink( $classic_id ) ),
	'blockCheckoutPageId'   => $block_id,
	'blockCheckoutPath'     => wp_make_link_relative( get_permalink( $block_id ) ),
	'accountOptions'        => $account_options,
	'createdPages'          => $created,
);
file_put_contents( $state_file, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
chmod( $state_file, 0600 );

echo "classic checkout: $classic_id " . $state['classicCheckoutPath'] . ( in_array( $classic_id, $created, true ) ? " (created)\n" : "\n" );
echo "block checkout:   $block_id " . $state['blockCheckoutPath'] . ( in_array( $block_id, $created, true ) ? " (created)\n" : "\n" );
echo "guest checkout enabled, state written to $state_file\n";
