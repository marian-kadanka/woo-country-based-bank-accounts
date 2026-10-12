<?php

/**
 * Admin settings in WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Country_Based_Bank_Accounts_Settings {

	private $id;

	public function __construct()	{
		$this->id = 'wccbba';

		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 40 );

		add_action( 'woocommerce_settings_tabs_' . $this->id, array( $this, 'add_section_to_tab' ) );

		add_action( 'woocommerce_update_options_' . $this->id, array( $this, 'update_options' ) );
	}

	/**
	 * Create settings tab for WooCommerce settings
	 */
	public function add_settings_tab( $tabs ) {
		$tabs[ $this->id ] = __( 'Country Based Bank Accounts', 'wccbba' );

		return $tabs;
	}

	/**
	 * Create input field for every available bank account
	 *
	 * @return $fields array
	 */
	public function create_fields() {
		$accounts = WC_Country_Based_Bank_Accounts::get_bacs_accounts();

		$fields = array();

		if ( ! empty( $accounts ) ) {

			foreach ( $accounts as $account ) {
				$account_countries = WC_Country_Based_Bank_Accounts::get_account_countries( $account );

				$fields[] = array(
					'title'   => implode( ', ', array_unique( array_filter( $account ) ) ),
					'desc'    => $account_countries
						? __( 'Printed only for orders billed to these countries.', 'wccbba' )
						: __( 'No country selected, printed for orders billed to any country.', 'wccbba' ),
					'type'    => 'multi_select_countries',
					'id'      => WC_Country_Based_Bank_Accounts::account_key( $account ),
				);
			}

			$fields[] = array(
				'title'   => __( 'Direct bank transfer behavior on checkout', 'wccbba' ),
				'desc'    => __( 'Decides whether a customer whose billing country appears in none of the country lists above is offered direct bank transfer at checkout. It changes nothing about printing: the bank accounts printed on the order confirmation page and in the order email always follow the billing country of the order.', 'wccbba' ),
				'type'    => 'radio',
				'id'      => $this->id . '_country_disable_bacs',
				'default' => 'no',
				'options' => array(
					'no'  => __( 'Offer it to everyone. A customer whose billing country appears in none of the country lists above can still pay by bank transfer, and in this case all of your bank accounts are printed for them to choose from.', 'wccbba' ),
					'yes' => __( 'Do not offer it to customers whose billing country appears in none of the country lists above, so they have to pay another way. A bank account whose country list above is left empty counts for every country. Direct bank transfer is then offered to every customer at checkout, and this setting has no effect.', 'wccbba' ),
				),
			);

		} else {
			$fields[] = array(
				'title' => __( 'No bank accounts found', 'wccbba' ),
				'desc'  => __( 'Please, first set up bank account details', 'wccbba' ) . ' <a href="' . network_admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' ) . '">' . esc_html__( 'here', 'wccbba' ) . '</a>',
				'type'  => 'title',
				'id'    => $this->id . '_no_accounts_notice',
			);
		}

		return $fields;
	}

	/**
	 * Create section and include input fields in section
	 *
	 * @return array
	 */
	public function create_tab_section() {
		$section = array();

		$section[] = array(
				'title' => __( 'Country Based Bank Accounts', 'wccbba' ),
				'desc'  => sprintf(
					/* translators: %s: link to the Direct bank transfer gateway settings */
					__( 'Select the countries each of your bank accounts is meant for. Only the accounts selected for the billing country of an order are then printed on the order confirmation page and in the order email, instead of all of them. A bank account left with no country selected below is printed for every customer, regardless of their billing country. The accounts themselves are set up in the %s settings.', 'wccbba' ),
					'<a href="' . network_admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bacs' ) . '">' . esc_html__( 'Direct bank transfer', 'wccbba' ) . '</a>'
				),
				'type'  => 'title',
				'id'    => $this->id,
			);

		$section = array_merge( $section, $this->create_fields() );

		$section[] = array( 'type' => 'sectionend', 'id' => $this->id );

		return $section;
	}

	/**
	 * Add section to tab
	 */
	public function add_section_to_tab() {
		$this->hiding_has_no_effect_notice();
		$this->radio_description_spacing();

		woocommerce_admin_fields( $this->create_tab_section() );
	}

	/**
	 * Set the description of the radio field apart from the options below it
	 *
	 * WooCommerce prints it as a paragraph with no bottom margin directly above the list, so
	 * the two run into each other. The options themselves sit 6px apart.
	 */
	private function radio_description_spacing() {
		printf(
			'<style>fieldset[aria-labelledby="%s-title"] > p{margin-bottom:8px}</style>',
			esc_attr( $this->id . '_country_disable_bacs' )
		);
	}

	/**
	 * Warn that the setting cannot do anything
	 *
	 * Direct bank transfer is set to be offered only to customers whose billing country
	 * matches a bank account, but an account with an empty country list counts for every
	 * country, so every customer matches one and the payment method is offered to everybody.
	 */
	private function hiding_has_no_effect_notice() {
		if ( ! WC_Country_Based_Bank_Accounts::hide_bacs_for_other_countries() ) {
			return;
		}

		if ( ! WC_Country_Based_Bank_Accounts::has_account_without_country() ) {
			return;
		} ?>

		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Direct bank transfer payment method is set to be offered only to customers whose billing country matches a bank account, but at least one account below has an empty country list. An account like that counts for every country, so every customer matches one and the payment method is offered to everybody anyway. Select at least one country for every bank account below to make this setting work.', 'wccbba' ); ?></p>
		</div>

	<?php }

	/**
	 *  Update setting fields
	 */
	public function update_options() {
		woocommerce_update_options( $this->create_fields() );
	}
}
