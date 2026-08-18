<?php
/**
 * My Account integration: district / sub-district cascade on the classic
 * my-account/edit-address/{billing,shipping}/ forms.
 *
 * The actual fields are already registered as Additional Checkout Fields
 * (location "address") by Kodepos_Checkout, and WooCommerce Blocks' own
 * `CheckoutFieldsFrontend::edit_address_fields()` — hooked to
 * `woocommerce_address_to_edit` — auto-injects and auto-saves them on this
 * page for us; no separate save handling is needed here.
 *
 * That bridge runs *after* `get_address_fields()` (and its
 * `woocommerce_billing_fields` / `woocommerce_shipping_fields` filters)
 * inside `WC_Shortcode_My_Account::edit_address()`, so the only thing left
 * to control from here is sort position: PHP array-key assignment updates a
 * key's value in place without moving it, so reserving
 * "_wc_billing/kodepos-indonesia/district" here at priority 71 (between
 * city's 70 and state's 80) is what the bridge's later overwrite of that
 * same key inherits. The label/type below are a fallback only, in the
 * unlikely case the additional field somehow isn't registered.
 *
 * @package Kodepos_Indonesia
 */

defined( 'ABSPATH' ) || exit;

class Kodepos_Account {

	/** @var Kodepos_DB */
	private $db;

	public function __construct( Kodepos_DB $db ) {
		$this->db = $db;

		add_filter( 'woocommerce_billing_fields', array( $this, 'billing_fields' ) );
		add_filter( 'woocommerce_shipping_fields', array( $this, 'shipping_fields' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function billing_fields( $fields ) {
		return $this->reserve_position(
			$fields,
			'billing_city',
			Kodepos_Admin::META_BILLING_DISTRICT,
			Kodepos_Admin::META_BILLING_SUB_DISTRICT
		);
	}

	public function shipping_fields( $fields ) {
		return $this->reserve_position(
			$fields,
			'shipping_city',
			Kodepos_Admin::META_SHIPPING_DISTRICT,
			Kodepos_Admin::META_SHIPPING_SUB_DISTRICT
		);
	}

	/**
	 * @param array  $fields            Address fields keyed by e.g. "billing_city".
	 * @param string $city_key          "billing_city" or "shipping_city".
	 * @param string $district_meta     Canonical district meta key.
	 * @param string $sub_district_meta Canonical sub-district meta key.
	 * @return array
	 */
	private function reserve_position( $fields, $city_key, $district_meta, $sub_district_meta ) {
		if ( ! isset( $fields[ $city_key ] ) ) {
			return $fields;
		}

		// WooCommerce's default order puts City (priority 70) above State
		// (80); the cascade reads top-to-bottom as Province → City →
		// District → Sub-district, so pull State above City here.
		$state_key = str_replace( '_city', '_state', $city_key );
		if ( isset( $fields[ $state_key ] ) ) {
			$fields[ $state_key ]['priority'] = 65;
		}

		$fields[ $district_meta ] = array(
			'label'    => __( 'Kecamatan', 'kodepos-indonesia-free' ),
			'type'     => 'text',
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 71,
		);

		$fields[ $sub_district_meta ] = array(
			'label'    => __( 'Kelurahan / Desa', 'kodepos-indonesia-free' ),
			'type'     => 'text',
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 72,
		);

		return $fields;
	}

	/**
	 * @return bool True on my-account/edit-address/{billing,shipping}/.
	 */
	private function is_edit_address_context() {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
	}

	public function enqueue_assets() {
		if ( ! $this->is_edit_address_context() || ! $this->db->is_available() ) {
			return;
		}

		wp_enqueue_style( 'kodepos-indonesia', KODEPOS_ID_URL . 'assets/css/kodepos.css', array(), KODEPOS_ID_VERSION );

		wp_enqueue_script(
			'kodepos-indonesia-cascade',
			KODEPOS_ID_URL . 'assets/js/cascade-core.js',
			array(),
			KODEPOS_ID_VERSION,
			true
		);

		wp_enqueue_script(
			'kodepos-indonesia-account',
			KODEPOS_ID_URL . 'assets/js/admin-address.js',
			array( 'kodepos-indonesia-cascade', 'jquery' ),
			KODEPOS_ID_VERSION,
			true
		);

		wp_localize_script(
			'kodepos-indonesia-cascade',
			'kodeposIndonesia',
			array(
				'restUrl' => esc_url_raw( rest_url( Kodepos_Rest_Proxy::REST_NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'version' => KODEPOS_ID_VERSION,
				'screen'  => 'account',
				// Bare Additional Checkout Field ids: WooCommerce Blocks'
				// edit_address_fields() bridge renders these fields with
				// this raw id as the HTML id (only the POSTed "name" carries
				// the "_wc_billing/" / "_wc_shipping/" storage prefix).
				'fields'  => array(
					'district'    => Kodepos_Checkout::FIELD_DISTRICT,
					'subDistrict' => Kodepos_Checkout::FIELD_SUB_DISTRICT,
				),
				'i18n'    => array(
					'select'  => __( 'Pilih…', 'kodepos-indonesia-free' ),
					'loading' => __( 'Memuat…', 'kodepos-indonesia-free' ),
				),
			)
		);
	}
}
