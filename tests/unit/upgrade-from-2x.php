<?php
/**
 * A store upgraded from 2.x, with nobody touching the settings afterwards.
 *
 * Needs the test bank accounts, so run bin/setup-site.php first. From the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/unit/upgrade-from-2x.php
 *
 * 2.x stored the switch as a checkbox and 3.0 shows it as two radio buttons, both under the
 * option wccbba_country_disable_bacs with the same yes/no values, and both versions key an
 * account's countries by md5( serialize( $account ) ). So an upgrade is supposed to be a file
 * swap: nothing runs, nothing is rewritten, and the administrator finds the store set the way
 * they left it. These cases assert exactly that, from values written the way 2.x wrote them.
 *
 * What happens at checkout for either setting is the classic and block matrices' job, in
 * e2e/classic-checkout.test.js and e2e/block-checkout.test.js; this file is about the upgrade.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

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
	printf( "%-5s %-60s %s\n", $ok ? 'OK' : 'FAIL', $name, $ok ? '' : 'got ' . wp_json_encode( $got ) . ' expected ' . wp_json_encode( $expected ) );
}

// The settings screen is an admin thing; this runs under WP-CLI, so load what it needs.
if ( ! function_exists( 'woocommerce_admin_fields' ) ) {
	require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
}
if ( ! class_exists( 'WC_Admin_Settings' ) ) {
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
}
require_once dirname( $dir ) . '/class-wc-country-based-bank-accounts-settings.php';

$settings = new WC_Country_Based_Bank_Accounts_Settings();

/** The settings screen as the administrator opens it, with no input of their own. */
function wccbba_rendered_settings( $settings ) {
	ob_start();
	woocommerce_admin_fields( $settings->create_fields() );

	return ob_get_clean();
}

/** Is the radio for this stored value the selected one? null when it is not on the screen. */
function wccbba_radio_checked( $html, $value ) {
	if ( ! preg_match( '/<input[^>]*name="wccbba_country_disable_bacs"[^>]*value="' . preg_quote( $value, '/' ) . '"[^>]*>/', $html, $m ) ) {
		return null;
	}

	return false !== strpos( $m[0], 'checked' );
}

echo "=== countries assigned under 2.x are still found ===\n";

// 2.x computed the option name inline, exactly like this, and 3.0 does it in account_key().
$accounts = WC_Country_Based_Bank_Accounts::get_bacs_accounts();
$first    = reset( $accounts );
$legacy   = 'wccbba_' . md5( serialize( $first ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

update_option( $legacy, array( 'SK', 'CZ' ) );

wccbba_check( 'the option name 2.x wrote is the one 3.0 reads', WC_Country_Based_Bank_Accounts::account_key( $first ), $legacy );
wccbba_check( 'its countries are read back', WC_Country_Based_Bank_Accounts::get_account_countries( $first ), array( 'SK', 'CZ' ) );

echo "\n=== the checkbox value becomes the selected radio ===\n";

// A 2.x administrator who ticked the box: the option holds "yes".
update_option( 'wccbba_country_disable_bacs', 'yes' );
$html = wccbba_rendered_settings( $settings );

wccbba_check( 'ticked in 2.x: the gateway is still hidden', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), true );
wccbba_check( 'ticked in 2.x: "do not offer it" is selected', wccbba_radio_checked( $html, 'yes' ), true );
wccbba_check( 'ticked in 2.x: "offer it to everyone" is not', wccbba_radio_checked( $html, 'no' ), false );
wccbba_check( 'rendering the screen does not rewrite the setting', get_option( 'wccbba_country_disable_bacs' ), 'yes' );

// An administrator who saved the tab with the box unticked: the option holds "no".
update_option( 'wccbba_country_disable_bacs', 'no' );
$html = wccbba_rendered_settings( $settings );

wccbba_check( 'unticked in 2.x: the gateway is offered to everyone', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), false );
wccbba_check( 'unticked in 2.x: "offer it to everyone" is selected', wccbba_radio_checked( $html, 'no' ), true );
wccbba_check( 'unticked in 2.x: "do not offer it" is not', wccbba_radio_checked( $html, 'yes' ), false );
wccbba_check( 'rendering the screen does not rewrite the setting', get_option( 'wccbba_country_disable_bacs' ), 'no' );

// A store that never opened the settings tab under 2.x has no option at all.
delete_option( 'wccbba_country_disable_bacs' );
$html = wccbba_rendered_settings( $settings );

wccbba_check( 'never saved in 2.x: the gateway is offered to everyone', WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries(), false );
wccbba_check( 'never saved in 2.x: "offer it to everyone" is selected', wccbba_radio_checked( $html, 'no' ), true );
wccbba_check( 'never saved in 2.x: nothing is written for it', get_option( 'wccbba_country_disable_bacs' ), false );

echo "\n=== the upgrade itself does nothing ===\n";

// Nothing is hooked to activation, so swapping the files in cannot run or migrate anything.
wccbba_check(
	'no activation routine',
	has_action( 'activate_woo-country-based-bank-accounts/woo-country-based-bank-accounts.php' ),
	false
);
wccbba_check( 'the settings tab is the same one 2.x registered', isset( apply_filters( 'woocommerce_settings_tabs_array', array() )['wccbba'] ), true );

delete_option( $legacy );

printf( "\nUPGRADE FROM 2.x: %d passed, %d failed\n", $GLOBALS['wccbba_pass'], $GLOBALS['wccbba_fail'] );
exit( $GLOBALS['wccbba_fail'] ? 1 : 0 );
