<?php
/**
 * Puts every option bin/setup-site.php touched back to the value it captured, deletes any
 * checkout page it created and removes the country options of the test bank accounts.
 *
 * Those country options are keyed by md5( serialize( $account ) ) of the test accounts, so they
 * can only be found through the names setup-site.php recorded - restoring the option map is not
 * enough on its own.
 *
 * Run from the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/bin/restore-site.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

/**
 * Where the backup of the store's settings is kept.
 *
 * Deliberately outside the plugin directory: the plugin lives in the web root, and this file
 * holds the store's own option values. Mirrors tests/lib/artifacts.js.
 *
 * @param array $config Parsed tests/config.json.
 * @return string
 */
function wccbba_tests_base_dir( $config ) {
	return ! empty( $config['artifactsDir'] ) ? $config['artifactsDir'] : sys_get_temp_dir() . '/wccbba-tests';
}

$dir         = dirname( __DIR__ );
$config      = json_decode( file_get_contents( $dir . '/config.json' ), true );
$base        = wccbba_tests_base_dir( $config );
$backup_file = $base . '/site-backup.json';
$state_file  = $base . '/site-state.json';

if ( ! file_exists( $backup_file ) ) {
	echo "No site backup found - nothing to restore.\n";
	exit( 1 );
}

// The country options first: they are found through the state file, which is deleted below.
if ( file_exists( $state_file ) ) {
	$state = json_decode( file_get_contents( $state_file ), true );

	foreach ( ( isset( $state['accountOptions'] ) ? $state['accountOptions'] : array() ) as $ref => $option ) {
		delete_option( $option );
		echo str_pad( 'deleted ' . $option, 52 ) . " (countries of test account $ref)\n";
	}

	// Pages the suite had to create because the store had none are not part of the store.
	foreach ( ( isset( $state['createdPages'] ) ? $state['createdPages'] : array() ) as $page_id ) {
		if ( get_post_meta( $page_id, '_wccbba_test_page', true ) ) {
			wp_delete_post( $page_id, true );
			echo str_pad( 'deleted page ' . $page_id, 52 ) . " (created by setup-site.php)\n";
		}
	}

	unlink( $state_file );
}

$backup = json_decode( file_get_contents( $backup_file ), true );
foreach ( $backup as $option => $value ) {
	if ( null === $value || false === $value ) {
		// The option did not exist before the run, so putting a value back would leave the
		// store with a setting it never had - the plugin's switch being the one that matters.
		delete_option( $option );
		echo str_pad( $option, 52 ) . " <- deleted (did not exist before)\n";
		continue;
	}

	update_option( $option, $value );
	echo str_pad( $option, 52 ) . ' <- ' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) . "\n";
}

unlink( $backup_file );

echo "restored, backup file removed.\n";
