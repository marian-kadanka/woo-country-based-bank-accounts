<?php

/**
 * Cart and Checkout blocks integration
 *
 * Hands the client a list of countries BACS is configured for, so the block
 * checkout can hide the gateway without waiting for a Store API round trip.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

class WC_Country_Based_Bank_Accounts_Blocks implements IntegrationInterface {

	private $id;

	private $version;

	private $script_handle;

	private $script_data;

	public function __construct( $version ) {
		$this->id            = 'wccbba';
		$this->version       = $version;
		$this->script_handle = $this->id . '-blocks-checkout';
	}

	/**
	 * Integration name, script data is exposed to the client as "{name}_data"
	 */
	public function get_name() {
		return $this->id;
	}

	/**
	 * Register the checkout script
	 */
	public function initialize() {
		wp_register_script(
			$this->script_handle,
			plugins_url( 'assets/js/blocks-checkout.js', __FILE__ ),
			array( 'wc-blocks-registry', 'wc-settings' ),
			$this->version,
			true
		);
	}

	/**
	 * Only load the script when there is something for it to hide
	 */
	public function get_script_handles() {
		$script_data = $this->get_script_data();

		return $script_data['active'] ? array( $this->script_handle ) : array();
	}

	/**
	 * Nothing to do in the editor, gateway availability isn't evaluated there
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data made available to the script as wccbba_data
	 *
	 * Only country codes are passed to the client, never any bank account details.
	 *
	 * @return array with a flag whether any country restricts BACS and the countries that don't
	 */
	public function get_script_data() {
		if ( ! isset( $this->script_data ) ) {
			$this->script_data = $this->build_script_data();
		}

		return $this->script_data;
	}

	/**
	 * Collect the countries configured for the BACS bank accounts
	 *
	 * BACS stays available for every country as soon as one bank account has no
	 * countries set, in which case there is nothing to hide and no data is needed.
	 * Only country codes are sent, never any bank account details.
	 *
	 * @return array
	 */
	private function build_script_data() {
		$accounts = WC_Country_Based_Bank_Accounts::get_bacs_accounts();

		if ( empty( $accounts ) || WC_Country_Based_Bank_Accounts::has_account_without_country() ) {
			return array( 'active' => false );
		}

		$countries = array();

		foreach ( $accounts as $account ) {
			$countries = array_merge( $countries, WC_Country_Based_Bank_Accounts::get_account_countries( $account ) );
		}

		return array(
			'active'    => true,
			'countries' => array_values( array_unique( $countries ) ),
		);
	}

}
