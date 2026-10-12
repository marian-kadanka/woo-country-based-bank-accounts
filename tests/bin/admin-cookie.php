<?php
/**
 * Prints the cookies an administrator's browser holds, so the end to end tests can drive wp-admin.
 *
 * Run from the WordPress root:
 *   wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/bin/admin-cookie.php
 *
 * The block based payment settings screen lives behind the administrator login, so the only way
 * to test what the plugin does there is to be logged in. This mints the same cookies
 * wp_set_auth_cookie() would set - a session token the user can revoke, not a password - and
 * prints them for the test to hand to the browser. Nothing is written to disk.
 *
 * It refuses to run anywhere but a development host, and the cookies last 15 minutes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Only ever run inside WordPress, via `wp eval-file`. Never over HTTP.
}

$host   = (string) wp_parse_url( admin_url(), PHP_URL_HOST );
$scheme = (string) wp_parse_url( admin_url(), PHP_URL_SCHEME );

// A live site's administrator session must never be mintable from a test helper.
$is_development = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
	|| (bool) preg_match( '/\.(localhost|test|local)$/', $host );

if ( ! $is_development ) {
	echo wp_json_encode( array( 'error' => "refusing to mint a session for \"$host\": not a development host" ) ) . "\n";
	exit( 1 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );

if ( ! $admins ) {
	echo wp_json_encode( array( 'error' => 'no administrator on this site' ) ) . "\n";
	exit( 1 );
}

$user       = $admins[0];
$expiration = time() + ( 15 * MINUTE_IN_SECONDS );

// Same as wp_set_auth_cookie(): the cookie is only valid while this session token is, and
// `wp user session destroy` ends it.
$token = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );
$https = 'https' === $scheme;

echo wp_json_encode(
	array(
		'host'    => $host,
		'path'    => '/',
		'user'    => $user->user_login,
		'expires' => $expiration,
		'cookies' => array(
			array(
				'name'   => $https ? SECURE_AUTH_COOKIE : AUTH_COOKIE,
				'value'  => wp_generate_auth_cookie( $user->ID, $expiration, $https ? 'secure_auth' : 'auth', $token ),
				'secure' => $https,
			),
			array(
				'name'   => LOGGED_IN_COOKIE,
				'value'  => wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token ),
				'secure' => $https,
			),
		),
	)
) . "\n";
