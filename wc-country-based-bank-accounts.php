<?php
/*
Plugin Name: WooCommerce Country Based Bank Accounts
Plugin URI:  https://wordpress.org/plugins/wc-country-based-bank-accounts/
Description: Choose in which countries certain BACS gateway bank accounts will be available
Version:     1.0
Author:      Marian Kadanka
Author URI:  https://github.com/marian-kadanka
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: wccbba
GitHub Plugin URI: marian-kadanka/wc-country-based-bank-accounts
*/

/**
 * WooCommerce Country Based Bank Accounts
 * Copyright (C) 2017 Marian Kadanka. All rights reserved.
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

	private $id;

	private $selected_country;

	public function __construct() {
		$this->id = 'wccbba';

		if ( is_admin() ) {
			add_action( 'woocommerce_loaded', array( $this, 'load_settings' ) );
			add_action( 'update_option_woocommerce_bacs_accounts', array( $this, 'bank_accounts_changed' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_action_links' ) );
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
	
	/**
	 * Show action links on the plugin screen
	 */
	public function add_action_links( $links ) {
		// Donate link
		array_unshift( $links, '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=marian.kadanka@gmail.com&item_name=Donation+for+Marian+Kadanka" title="' . esc_attr__( 'Donate', 'wccbba' ) . '" target="_blank">' . esc_html__( 'Donate', 'wccbba' ) . '</a>' );
		// Settings link
		array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=' . $this->id ) . '" title="' . esc_attr__( 'Settings', 'woocommerce' ) . '">' . esc_html__( 'Settings', 'woocommerce' ) . '</a>' );
		
		return $links;
	}
}

new WC_Country_Based_Bank_Accounts();
