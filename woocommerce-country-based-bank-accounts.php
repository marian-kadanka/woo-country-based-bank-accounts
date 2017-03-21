<?php
/*
Plugin Name: WooCommerce Country Based Bank Accounts
Plugin URI:  https://wordpress.org/plugins/woocommerce-country-based-bank-accounts/
Description: Choose in which countries certain bank account will be available
Version:     1.0
Author:      Marian Kadanka
Author URI:  https://github.com/marian-kadanka
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: wccbba
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Country_Based_Bank_Accounts {

	private $id;

	private $selected_country;

	public function __construct() {
		$this->id = 'wccbba';

		if ( is_admin() ) {
			add_action( 'woocommerce_loaded', array( $this, 'load_settings' ) );
			add_action( 'update_option_woocommerce_bacs_accounts', array( $this, 'bank_accounts_changed' ) );
		} else {
			add_action( 'woocommerce_thankyou_bacs', array( $this, 'set_selected_country' ), 1 );
			add_action( 'woocommerce_email_before_order_table', array( $this, 'set_selected_country' ), 1 );
			add_filter( 'woocommerce_bacs_accounts', array( $this, 'available_bank_accounts' ) );
		}
	}

	/**
	 * Load admin settings
	 */
	public function load_settings() {
		require 'class-wc-country-based-bank-accounts-settings.php';

		new WC_Country_Based_Bank_Accounts_Settings();
	}

	/**
	 * Bank accounts settings changed hook
	 */
	public function bank_accounts_changed() { ?>

		<div class="notice notice-warning">
			<p><?php _e( 'Your bank accounts details have been updated. You should now adjust Country Based Bank Accounts settings', 'wccbba' ); ?> <a href="?page=wc-settings&tab=<?php echo $this->id; ?>"><?php _e( 'here', 'wccbba' ); ?></a></p>
		</div>

	<?php }

	/**
	 * Set selected country from order or order_id
	 */
	public function set_selected_country( $order ) {
		if ( !is_object ( $order ) ) {
			$order = wc_get_order( $order );
		}

		$billing_address = $order->get_address();

		$this->selected_country = $billing_address['country'];
	}

	/**
	 * List through available bank accounts,
	 * check if certain bank account is enabled for country,
	 * if no, unset it from $bacs_accounts array
	 *
	 * @return array with updated list of available bank accounts
	 */
	public function available_bank_accounts( $bacs_accounts ) {
		if ( isset ( $this->selected_country ) ) {
			foreach ( $bacs_accounts as $i => $account ) {
				$account_countries = get_option( $this->id . '_' . md5( serialize( $account ) ) );
				if ( $account_countries && !in_array( $this->selected_country, $account_countries ) ) {
					unset( $bacs_accounts[$i] );
				}
			}
		}

		return $bacs_accounts;
	}
}

/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	new WC_Country_Based_Bank_Accounts();
}
