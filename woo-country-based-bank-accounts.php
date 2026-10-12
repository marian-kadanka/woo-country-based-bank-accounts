<?php
/*
Plugin Name: Country Based Bank Accounts for WooCommerce
Plugin URI:  https://wordpress.org/plugins/woo-country-based-bank-accounts/
Description: Assign Direct bank transfer accounts to billing countries. Only matching accounts are printed, and the BACS gateway can be hidden when none match.
Version:     3.0
Author:      Marian Kadanka
Author URI:  https://kadanka.net/
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: wccbba
GitHub Plugin URI: marian-kadanka/woo-country-based-bank-accounts
WC requires at least: 3.4
WC tested up to: 11.2
*/

/**
 * Country Based Bank Accounts for WooCommerce
 * Copyright (C) 2021-2026 Marian Kadanka. All rights reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.

 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Country_Based_Bank_Accounts {

	const VERSION = '3.0';

	const ID = 'wccbba';

	private $id;

	protected $selected_country;

	protected $no_match_hide;

	protected $accounts_before_save;

	protected $accounts_changed = false;

	public function __construct() {

		$this->id = self::ID;
		$this->no_match_hide = self::hide_bacs_for_other_countries();

		add_action( 'before_woocommerce_init', function() {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
			}
		} );

		// A save made by the block based payment settings screen arrives as a REST request, which
		// is not an admin request, so these two are registered whatever the request is
		add_action( 'update_option_woocommerce_bacs_accounts', array( $this, 'move_account_countries_from_request' ), 10, 2 );
		add_action( 'update_option_woocommerce_bacs_accounts', array( $this, 'remember_accounts_changed' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( is_admin() ) {
			add_action( 'woocommerce_loaded', array( $this, 'load_settings' ) );
			add_action( 'update_option_woocommerce_bacs_accounts', array( $this, 'bank_accounts_changed' ) );
			add_action( 'woocommerce_sections_checkout', array( $this, 'print_accounts_changed_notice' ), 99 );
			add_action( 'woocommerce_update_options_payment_gateways_bacs', array( $this, 'capture_bank_accounts' ), 9 );
			add_action( 'woocommerce_update_options_payment_gateways_bacs', array( $this, 'move_account_countries' ), 11 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_bacs_settings_script' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_action_links' ) );
		} else {
			add_action( 'woocommerce_thankyou_bacs', array( $this, 'set_selected_country' ), 1 );
			add_action( 'woocommerce_email_before_order_table', array( $this, 'set_selected_country' ), 1 );
			add_filter( 'woocommerce_bacs_accounts', array( $this, 'available_bank_accounts' ) );

			if ( $this->no_match_hide ) {
				add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_disable_bacs' ) );
				add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_blocks_integration' ) );
			}
		}
	}

	/**
	 * Register the Checkout block integration, it hides the BACS gateway client side
	 *
	 * @param Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $integration_registry
	 */
	public function register_blocks_integration( $integration_registry ) {
		if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			return;
		}

		require_once 'class-wc-country-based-bank-accounts-blocks.php';

		$integration_registry->register( new WC_Country_Based_Bank_Accounts_Blocks( self::VERSION ) );
	}

	/**
	 * Is the BACS gateway hidden when the billing country is not assigned to any bank account?
	 *
	 * @return bool
	 */
	public static function hide_bacs_for_other_countries() {
		return 'yes' === get_option( self::ID . '_country_disable_bacs', 'no' );
	}

	/**
	 * Bank accounts set up in the BACS gateway
	 *
	 * @return array
	 */
	public static function get_bacs_accounts() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		$gateways = WC()->payment_gateways->payment_gateways();

		return isset( $gateways['bacs'] ) ? (array) $gateways['bacs']->account_details : array();
	}

	/**
	 * Option a bank account's countries are stored under
	 *
	 * Bank accounts have no id of their own, so the account details themselves are the key.
	 * Editing an account therefore changes its key, which move_account_countries() follows.
	 *
	 * @param array $account Bank account as stored by the BACS gateway.
	 * @return string option name
	 */
	public static function account_key( $account ) {
		return self::ID . '_' . md5( serialize( $account ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Keys of a list of bank accounts, in the order the accounts are stored in
	 *
	 * @param array $accounts Bank accounts as stored by the BACS gateway.
	 * @return array of option names
	 */
	private static function account_keys( $accounts ) {
		return array_map( array( __CLASS__, 'account_key' ), array_values( $accounts ) );
	}

	/**
	 * Countries a bank account is assigned to, no country means all of them
	 *
	 * @param array $account Bank account as stored by the BACS gateway.
	 * @return array of country codes
	 */
	public static function get_account_countries( $account ) {
		$countries = get_option( self::account_key( $account ) );

		return is_array( $countries ) ? $countries : array();
	}

	/**
	 * Is any bank account left without a country assigned?
	 *
	 * Such an account matches every billing country, so it is always printed, and
	 * it also keeps the BACS gateway available whatever the setting says.
	 *
	 * @return bool
	 */
	public static function has_account_without_country() {
		foreach ( self::get_bacs_accounts() as $account ) {
			if ( ! self::get_account_countries( $account ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load admin settings
	 */
	public function load_settings() {
		require 'class-wc-country-based-bank-accounts-settings.php';

		new WC_Country_Based_Bank_Accounts_Settings();
	}

	/**
	 * What to tell the administrator when the bank accounts have been saved
	 *
	 * The same wording wherever it is shown: printed below as a notice on the settings form, and
	 * handed to the block based payment settings screen to show in its own notices.
	 *
	 * @return array with text, link label and link
	 */
	public function accounts_changed_notice() {
		return array(
			'text'  => __( 'Your bank accounts details have been updated. You should now adjust Country Based Bank Accounts settings', 'wccbba' ),
			'label' => __( 'here', 'wccbba' ),
			'url'   => network_admin_url( 'admin.php?page=wc-settings&tab=' . self::ID ),
		);
	}

	/**
	 * Bank accounts settings changed hook
	 *
	 * The accounts are saved while the settings page is still being put together, long before
	 * there is anywhere sensible to print into, so this only remembers that it happened.
	 */
	public function bank_accounts_changed() {
		$this->accounts_changed = true;
	}

	/**
	 * Tell the administrator the bank accounts have changed
	 *
	 * Printed where WooCommerce prints its own settings messages, which is inside the form and
	 * therefore lined up with the rest of the page. Printing it as the accounts are saved put it
	 * above the whole page instead, and WordPress then moved it into place once its scripts ran,
	 * which the administrator saw as the page jumping about.
	 */
	public function print_accounts_changed_notice() {
		if ( empty( $this->accounts_changed ) ) {
			return;
		}

		$notice = $this->accounts_changed_notice(); ?>

		<div class="notice notice-warning inline">
			<p><?php echo esc_html( $notice['text'] ); ?>
				<a href="<?php echo esc_url( $notice['url'] ); ?>"><?php echo esc_html( $notice['label'] ); ?></a>
			</p>
		</div>

	<?php }

	/**
	 * Note that the bank accounts have just been saved, for the screen that cannot print a notice
	 *
	 * The block based payment settings screen saves over the REST API and never reloads, so there
	 * is no page for bank_accounts_changed() to print into. It asks for this when its save is
	 * through, and shows the same warning in its own notices.
	 */
	public function remember_accounts_changed() {
		set_transient( self::ID . '_accounts_changed_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Have the bank accounts been saved since this was last asked?
	 *
	 * Answering clears it, so the warning is shown once per save.
	 *
	 * @return bool
	 */
	private function take_accounts_changed() {
		$key     = self::ID . '_accounts_changed_' . get_current_user_id();
		$changed = (bool) get_transient( $key );

		if ( $changed ) {
			delete_transient( $key );
		}

		return $changed;
	}

	/**
	 * Tell the bank account rows apart on the Direct bank transfer settings screen
	 *
	 * Every row is handed the key of the account it was rendered from and submits it back from
	 * wherever it has been dragged to, so that move_account_countries() can pair an edited
	 * account up with the one it was edited from.
	 *
	 * @param string $hook_suffix Admin screen being loaded.
	 */
	public function enqueue_bacs_settings_script( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which settings screen is open, nothing is acted on
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : '';
		$path    = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable

		// WooCommerce renders the Direct bank transfer settings either as its own form or as the
		// block based screen, and the same section can turn out to be either, so both scripts are
		// loaded and each one leaves the page alone unless it finds the table it knows.
		if ( 'checkout' !== $tab || ( 'bacs' !== $section && 0 !== strpos( $path, '/offline/bacs' ) ) ) {
			return;
		}

		$accounts = self::get_bacs_accounts();

		if ( empty( $accounts ) ) {
			return;
		}

		wp_enqueue_script( 'wccbba-bacs-settings', plugins_url( 'assets/js/bacs-settings.js', __FILE__ ), array(), self::VERSION, true );
		wp_localize_script( 'wccbba-bacs-settings', 'wccbbaBacsSettings', array( 'keys' => self::account_keys( $accounts ) ) );

		// Carries the keys of the other script, which is why it depends on it
		wp_enqueue_script( 'wccbba-bacs-settings-blocks', plugins_url( 'assets/js/bacs-settings-blocks.js', __FILE__ ), array( 'wccbba-bacs-settings', 'wp-api-fetch', 'wp-data' ), self::VERSION, true );
	}

	/**
	 * The keys of the bank accounts as they are stored now
	 *
	 * The block based settings screen saves without reloading the page, which leaves every key
	 * it was given out of date. It reads them back from here instead.
	 */
	public function register_rest_routes() {
		register_rest_route(
			self::ID . '/v1',
			'/account-keys',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function() {
					return array(
						'keys'    => self::account_keys( self::get_bacs_accounts() ),
						'changed' => $this->take_accounts_changed(),
						'notice'  => $this->accounts_changed_notice(),
					);
				},
				'permission_callback' => function() {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Hand the countries over when the request says which account each saved row holds
	 *
	 * The block based payment settings screen writes the accounts straight to the option over the
	 * REST API, so there is no settings form, no $_POST and no gateway save to hook. The rows of
	 * that screen carry the key of the account they were rendered from the same way the rows of
	 * the settings form do, and assets/js/bacs-settings-blocks.js sends them along with the save.
	 *
	 * Without that header nothing happens here at all: a save from anywhere else says nothing
	 * about which account is which, and guessing would risk handing an account countries that
	 * were never selected for it.
	 *
	 * @param array $old_value Accounts before the save.
	 * @param array $value     Accounts as they are stored now.
	 */
	public function move_account_countries_from_request( $old_value, $value ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- split and matched against the stored keys below
		$header = isset( $_SERVER['HTTP_X_WCCBBA_ACCOUNT_KEYS'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_WCCBBA_ACCOUNT_KEYS'] ) : '';

		if ( '' === $header || ! is_array( $old_value ) || ! is_array( $value ) || empty( $old_value ) || empty( $value ) ) {
			return;
		}

		$submitted = array_map( 'trim', explode( ',', $header ) );

		if ( count( $submitted ) !== count( $value ) ) {
			return;
		}

		$keys_before = self::account_keys( $old_value );
		$keys_after  = self::account_keys( $value );

		$this->move_countries( $this->moves_between( $this->origin_by_key( array_keys( $submitted ), $submitted, $keys_before ), $keys_before, $keys_after ), $keys_after );
	}

	/**
	 * Remember the bank accounts as they are before the gateway overwrites them
	 *
	 * Hooked ahead of WC_Gateway_BACS::save_account_details().
	 */
	public function capture_bank_accounts() {
		$this->accounts_before_save = self::get_bacs_accounts();
	}

	/**
	 * Hand each account's countries over to the key its edited details now have
	 *
	 * A bank account has no id, so its countries are stored under a hash of the account details
	 * and editing an account would leave them behind under the hash of the details it had. The
	 * submitted form says which account each saved one was edited from, and the countries follow.
	 *
	 * Hooked after WC_Gateway_BACS::save_account_details() has stored the new accounts.
	 */
	public function move_account_countries() {
		if ( ! isset( $this->accounts_before_save ) ) {
			return;
		}

		$before = $this->accounts_before_save;
		$after  = (array) get_option( 'woocommerce_bacs_accounts', array() );

		$this->accounts_before_save = null;

		if ( empty( $before ) || empty( $after ) ) {
			return;
		}

		$keys_before = self::account_keys( $before );
		$keys_after  = self::account_keys( $after );

		$origin = $this->saved_accounts_origin( $keys_before, count( $after ) );

		$this->move_countries( $this->moves_between( $origin, $keys_before, $keys_after ), $keys_after );
	}

	/**
	 * Which country option has to be handed over to which, for the accounts that were paired up
	 *
	 * @param array $origin      Saved account index => index before the save.
	 * @param array $keys_before Keys of the accounts before the save, in order.
	 * @param array $keys_after  Keys of the accounts as they are stored now, in order.
	 * @return array of old key => new key
	 */
	private function moves_between( $origin, $keys_before, $keys_after ) {
		$moves = array();

		foreach ( $origin as $saved => $before ) {
			if ( ! isset( $keys_before[ $before ] ) || ! isset( $keys_after[ $saved ] ) ) {
				continue;
			}

			$from = $keys_before[ $before ];
			$to   = $keys_after[ $saved ];

			if ( $from === $to ) {
				continue; // the details are unchanged, the countries are already in place
			}

			// Accounts with the same details share their countries as well, so there is no
			// telling which of them a row was edited from
			if ( count( array_keys( $keys_before, $from, true ) ) > 1 || count( array_keys( $keys_after, $to, true ) ) > 1 ) {
				continue;
			}

			$moves[ $from ] = $to;
		}

		return $moves;
	}

	/**
	 * Which account before the save each saved account was edited from
	 *
	 * @param array $keys_before Keys of the accounts before the save, in order.
	 * @param int   $saved_count Number of accounts the gateway has just stored.
	 * @return array of saved account index => index before the save
	 */
	private function saved_accounts_origin( $keys_before, $saved_count ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC_Admin_Settings::save() verified the nonce before firing this
		if ( empty( $_POST['bacs_account_name'] ) || ! is_array( $_POST['bacs_account_name'] ) ) {
			return array();
		}

		$positions = array_map( 'intval', array_keys( $_POST['bacs_account_name'] ) );

		// The gateway stores one account per submitted row. Anything else was not this form
		if ( count( $positions ) !== $saved_count ) {
			return array();
		}

		if ( isset( $_POST['wccbba_account_key'] ) && is_array( $_POST['wccbba_account_key'] ) ) {
			return $this->origin_by_key( $positions, array_map( 'strval', wp_unslash( $_POST['wccbba_account_key'] ) ), $keys_before );
		}

		return $this->origin_by_position( $positions, count( $keys_before ) );
		// phpcs:enable
	}

	/**
	 * Pair the rows up by the key each one carries
	 *
	 * @param array $positions   Position each saved account was submitted under.
	 * @param array $keys        Key submitted for each position.
	 * @param array $keys_before Keys of the accounts before the save, in order.
	 * @return array of saved account index => index before the save
	 */
	private function origin_by_key( $positions, $keys, $keys_before ) {
		$origin = array();

		foreach ( $positions as $saved => $position ) {
			$key   = isset( $keys[ $position ] ) ? $keys[ $position ] : '';
			$found = array_search( $key, $keys_before, true );

			// A row that was added carries no key, and one naming an account that is not there
			// any more says nothing about where its countries should go
			if ( false !== $found ) {
				$origin[ $saved ] = $found;
			}
		}

		return $origin;
	}

	/**
	 * Pair the rows up by position, as long as they are the positions that were rendered
	 *
	 * The settings screen renders the accounts at the positions 0 to n - 1 and only JavaScript
	 * can change that, which is also what fills the keys in. Any other set of positions means
	 * rows were added or removed without saying which account they hold, and WooCommerce hands
	 * an added row the position of a removed one, so the countries could land on an account they
	 * were never meant for. They are left where they are instead.
	 *
	 * @param array $positions    Position each saved account was submitted under.
	 * @param int   $before_count Number of accounts before the save.
	 * @return array of saved account index => index before the save
	 */
	private function origin_by_position( $positions, $before_count ) {
		$rendered = $positions;
		sort( $rendered );

		if ( $rendered !== range( 0, $before_count - 1 ) ) {
			return array();
		}

		$origin = array();

		foreach ( $positions as $saved => $position ) {
			$origin[ $saved ] = $position;
		}

		return $origin;
	}

	/**
	 * Store each account's countries under its new key and drop the old one
	 *
	 * Every value is read before any is written, so that two accounts swapping their details
	 * keep their countries instead of overwriting each other.
	 *
	 * @param array $moves      Old key => new key.
	 * @param array $keys_after Keys of the accounts as they are stored now.
	 */
	private function move_countries( $moves, $keys_after ) {
		$countries = array();

		foreach ( $moves as $from => $to ) {
			$stored = get_option( $from );

			if ( is_array( $stored ) ) {
				$countries[ $from ] = $stored;
			}
		}

		$moved = array();

		foreach ( $countries as $from => $stored ) {
			$to = $moves[ $from ];

			// Countries already stored for the edited details stay, unless they are on their
			// way to an account of their own
			if ( ! isset( $moves[ $to ] ) && is_array( get_option( $to ) ) ) {
				continue;
			}

			update_option( $to, $stored );

			$moved[] = $from;
		}

		foreach ( $moved as $from ) {
			// Another account still has the details these countries were stored for
			if ( in_array( $from, $keys_after, true ) ) {
				continue;
			}

			delete_option( $from );
		}
	}

	/**
	 * Set selected country from order or order_id
	 */
	public function set_selected_country( $order ) {
		if ( ! is_object ( $order ) ) {
			$order = wc_get_order( $order );
		}

		$billing_address = $order->get_address();

		$this->selected_country = $billing_address['country'];
	}

	/**
	 * Keep only the bank accounts assigned to the billing country
	 *
	 * Bank accounts without any country are assigned to every country. When the
	 * billing country matches no bank account at all, every account is printed
	 * instead of none, printing none would leave the customer with nowhere to
	 * send the payment.
	 *
	 * @param array $bacs_accounts Bank accounts to filter.
	 * @return array with updated list of available bank accounts
	 */
	public function available_bank_accounts( $bacs_accounts ) {
		if ( ! isset( $this->selected_country ) ) {
			return $bacs_accounts;
		}

		$matching = array();

		foreach ( $bacs_accounts as $i => $account ) {
			$account_countries = self::get_account_countries( $account );

			if ( ! $account_countries || in_array( $this->selected_country, $account_countries ) ) {
				$matching[ $i ] = $account;
			}
		}

		return $matching ? $matching : $bacs_accounts;
	}

	/**
	 * Show action links on the plugin screen
	 */
	public function add_action_links( $links ) {
		// Donate link
		array_unshift( $links, '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=marian.kadanka@gmail.com&item_name=Donation+for+Marian+Kadanka" title="' . esc_attr__( 'Donate', 'wccbba' ) . '" target="_blank">' . esc_html__( 'Donate', 'wccbba' ) . '</a>' );
		// Settings link
		array_unshift( $links, '<a href="' . network_admin_url( 'admin.php?page=wc-settings&tab=' . $this->id ) . '" title="' . esc_attr__( 'Settings', 'woocommerce' ) . '">' . esc_html__( 'Settings', 'woocommerce' ) . '</a>' );

		return $links;
	}

	/**
	 * Disable BACS payment gateway if the billing country is not assigned to any bank account
	 *
	 * Only hooked when the no match setting is set to hide the gateway.
	 *
	 * @param array $gateways Payment methods to filter.
	 * @return array of filtered methods
	 */
	public function maybe_disable_bacs( $gateways ) {

		if ( ! WC()->customer || ! isset( $gateways['bacs'] ) ) {
			return $gateways;
		}

		$accounts = (array) $gateways['bacs']->account_details;
		$billing_country = WC()->customer->get_billing_country();

		if ( empty( $accounts ) || empty( $billing_country ) ) {
			return $gateways;
		}

		foreach ( $accounts as $account ) {
			$account_countries = self::get_account_countries( $account );

			if ( ! $account_countries || in_array( $billing_country, $account_countries ) ) {
				return $gateways;
			}
		}

		unset( $gateways['bacs'] );

		return $gateways;
	}

}

new WC_Country_Based_Bank_Accounts();
