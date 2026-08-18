<?php
/**
 * Checkout integration: district/sub-district additional checkout fields,
 * Indonesian locale labels and the block checkout cascade script.
 *
 * The Province/State field is left as WooCommerce's native Indonesia state
 * list (AC, JK, KB…) rather than overridden, cities are looked up from that
 * native code via the cities-by-state REST route, so tax rates, shipping
 * zones and any existing saved addresses that reference the native codes
 * keep working unchanged.
 *
 * @package Kodepos_Indonesia
 */

defined( 'ABSPATH' ) || exit;

class Kodepos_Checkout {

	const FIELD_DISTRICT     = 'kodepos-indonesia/district';
	const FIELD_SUB_DISTRICT = 'kodepos-indonesia/sub-district';

	/** @var Kodepos_DB */
	private $db;

	public function __construct( Kodepos_DB $db ) {
		$this->db = $db;

		add_filter( 'woocommerce_get_country_locale', array( $this, 'filter_locale' ) );
		add_action( 'woocommerce_init', array( $this, 'register_additional_fields' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
	}

	/**
	 * Indonesian labels and requirements for address fields.
	 *
	 * @param array $locale Country locale config.
	 * @return array
	 */
	public function filter_locale( $locale ) {
		$locale['ID'] = array_replace_recursive(
			isset( $locale['ID'] ) ? $locale['ID'] : array(),
			array(
				'state'    => array(
					'label'    => __( 'Provinsi', 'alamat-cascade-woocommerce' ),
					'required' => true,
				),
				'city'     => array(
					'label'    => __( 'Kota / Kabupaten', 'alamat-cascade-woocommerce' ),
					'required' => true,
				),
				'postcode' => array(
					'label'    => __( 'Kode Pos', 'alamat-cascade-woocommerce' ),
					'required' => true,
				),
			)
		);

		return $locale;
	}

	/**
	 * District and sub-district as block checkout "additional fields" in the
	 * address location, so WooCommerce persists them per billing/shipping and
	 * shows them on orders, emails and My Account automatically.
	 */
	public function register_additional_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::FIELD_DISTRICT,
				'label'    => __( 'Kecamatan', 'alamat-cascade-woocommerce' ),
				'location' => 'address',
				'type'     => 'text',
				'required' => false,
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::FIELD_SUB_DISTRICT,
				'label'    => __( 'Kelurahan / Desa', 'alamat-cascade-woocommerce' ),
				'location' => 'address',
				'type'     => 'text',
				'required' => false,
			)
		);
	}

	/**
	 * True when the current request renders the checkout.
	 *
	 * is_checkout() alone misses pages containing the checkout block when no
	 * checkout page ID is configured in WooCommerce settings, so also detect
	 * the block directly on the current post.
	 *
	 * @return bool
	 */
	private function is_checkout_context() {
		if ( is_checkout() ) {
			return true;
		}

		return is_singular() && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' );
	}

	public function enqueue_checkout_assets() {
		if ( ! $this->is_checkout_context() || ! $this->db->is_available() ) {
			return;
		}

		wp_enqueue_style(
			'kodepos-indonesia',
			KODEPOS_ID_URL . 'assets/css/kodepos.css',
			array(),
			KODEPOS_ID_VERSION
		);

		wp_enqueue_script(
			'kodepos-indonesia-cascade',
			KODEPOS_ID_URL . 'assets/js/cascade-core.js',
			array(),
			KODEPOS_ID_VERSION,
			true
		);

		wp_enqueue_script(
			'kodepos-indonesia-checkout',
			KODEPOS_ID_URL . 'assets/js/checkout-blocks.js',
			array( 'kodepos-indonesia-cascade', 'wp-data' ),
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
				'fields'  => array(
					'district'    => self::FIELD_DISTRICT,
					'subDistrict' => self::FIELD_SUB_DISTRICT,
				),
				'i18n'    => array(
					'select'            => __( 'Select…', 'alamat-cascade-woocommerce' ),
					'loading'           => __( 'Loading…', 'alamat-cascade-woocommerce' ),
					'selectCity'        => __( 'Select City', 'alamat-cascade-woocommerce' ),
					'selectDistrict'    => __( 'Select District', 'alamat-cascade-woocommerce' ),
					'selectSubDistrict' => __( 'Select Sub District', 'alamat-cascade-woocommerce' ),
					'selectPostcode'    => __( 'Select Postcode', 'alamat-cascade-woocommerce' ),
				),
			)
		);
	}
}
