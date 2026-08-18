<?php
/**
 * Admin integrations: order edit screen, user profile addresses and the
 * WooCommerce Store Address settings.
 *
 * @package Kodepos_Indonesia
 */

defined( 'ABSPATH' ) || exit;

class Kodepos_Admin {

	/** Canonical meta keys shared with the block checkout additional fields. */
	const META_BILLING_DISTRICT      = '_wc_billing/kodepos-indonesia/district';
	const META_BILLING_SUB_DISTRICT  = '_wc_billing/kodepos-indonesia/sub-district';
	const META_SHIPPING_DISTRICT     = '_wc_shipping/kodepos-indonesia/district';
	const META_SHIPPING_SUB_DISTRICT = '_wc_shipping/kodepos-indonesia/sub-district';

	/** @var Kodepos_DB */
	private $db;

	public function __construct( Kodepos_DB $db ) {
		$this->db = $db;

		// Order edit screen.
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'order_billing_fields' ), 10, 2 );
		add_filter( 'woocommerce_admin_shipping_fields', array( $this, 'order_shipping_fields' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_order_fields' ), 60 );

		// User profile.
		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'customer_meta_fields' ) );

		// Store address settings.
		add_filter( 'woocommerce_general_settings', array( $this, 'store_address_settings' ) );

		// WooCommerce 10.4's React settings screen ("settings-ui" feature)
		// saves from its own internal state, which never sees the cascading
		// selects, selections silently fail to persist. Revert to the classic
		// PHP settings renderer, which the cascade fully supports.
		add_filter( 'woocommerce_admin_features', array( $this, 'disable_react_settings_ui' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_notices', array( $this, 'maybe_render_unavailable_notice' ) );
	}

	/**
	 * Warn admins when the bundled Kodepos data can't be read, instead of
	 * failing silently. Checkout and admin screens still work, they just
	 * fall back to WooCommerce's plain address fields.
	 */
	public function maybe_render_unavailable_notice() {
		if ( $this->db->is_available() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$message = extension_loaded( 'pdo_sqlite' )
			? __( 'Kodepos Indonesia: the bundled postal code database is missing or unreadable. Cascading address dropdowns are disabled; plain WooCommerce address fields are used instead.', 'kodepos-indonesia-free' )
			: __( 'Kodepos Indonesia: the PHP "pdo_sqlite" extension is not enabled on this server. Cascading address dropdowns are disabled; plain WooCommerce address fields are used instead.', 'kodepos-indonesia-free' );

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	/* -------------------------------------------------------------------- */
	/* Order edit screen                                                     */
	/* -------------------------------------------------------------------- */

	public function order_billing_fields( $fields, $order = null ) {
		return $this->insert_order_fields( $fields, $order, self::META_BILLING_DISTRICT, self::META_BILLING_SUB_DISTRICT );
	}

	public function order_shipping_fields( $fields, $order = null ) {
		return $this->insert_order_fields( $fields, $order, self::META_SHIPPING_DISTRICT, self::META_SHIPPING_SUB_DISTRICT );
	}

	/**
	 * Add district / sub-district inputs right after the city field.
	 *
	 * @param array         $fields            Admin address fields.
	 * @param WC_Order|null $order             Order being edited.
	 * @param string        $district_meta     Canonical district meta key.
	 * @param string        $sub_district_meta Canonical sub-district meta key.
	 * @return array
	 */
	private function insert_order_fields( $fields, $order, $district_meta, $sub_district_meta ) {
		$extra = array(
			'kodepos_district'     => array(
				'label' => __( 'Kecamatan', 'kodepos-indonesia-free' ),
				'show'  => false,
				'value' => $order instanceof WC_Order ? (string) $order->get_meta( $district_meta ) : '',
			),
			'kodepos_sub_district' => array(
				'label' => __( 'Kelurahan / Desa', 'kodepos-indonesia-free' ),
				'show'  => false,
				'value' => $order instanceof WC_Order ? (string) $order->get_meta( $sub_district_meta ) : '',
			),
		);

		$result = array();

		foreach ( $fields as $key => $field ) {
			$result[ $key ] = $field;
			if ( 'city' === $key ) {
				$result = array_merge( $result, $extra );
			}
		}

		// City field missing from the filter output? Append at the end.
		if ( ! isset( $result['kodepos_district'] ) ) {
			$result = array_merge( $result, $extra );
		}

		return $result;
	}

	/**
	 * Persist admin-edited district/sub-district into the same meta keys the
	 * block checkout writes, and drop WooCommerce's auto-saved duplicates.
	 *
	 * @param int $order_id Order ID.
	 */
	public function save_order_fields( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$map = array(
			'_billing_kodepos_district'      => self::META_BILLING_DISTRICT,
			'_billing_kodepos_sub_district'  => self::META_BILLING_SUB_DISTRICT,
			'_shipping_kodepos_district'     => self::META_SHIPPING_DISTRICT,
			'_shipping_kodepos_sub_district' => self::META_SHIPPING_SUB_DISTRICT,
		);

		$changed = false;

		foreach ( $map as $posted_key => $meta_key ) {
			if ( ! isset( $_POST[ $posted_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verified the order nonce before this hook.
				continue;
			}

			$value = wc_clean( wp_unslash( $_POST[ $posted_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean() sanitizes via sanitize_text_field(); the sniff doesn't recognize WooCommerce's sanitizer.

			$order->update_meta_data( $meta_key, $value );
			$order->delete_meta_data( $posted_key );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}
	}

	/* -------------------------------------------------------------------- */
	/* User profile                                                          */
	/* -------------------------------------------------------------------- */

	/**
	 * Add district / sub-district to the profile billing & shipping sections,
	 * stored under the same user meta keys the block checkout syncs.
	 *
	 * @param array $fields Customer meta fields.
	 * @return array
	 */
	public function customer_meta_fields( $fields ) {
		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields'] = $this->insert_profile_fields(
				$fields['billing']['fields'],
				'billing_city',
				self::META_BILLING_DISTRICT,
				self::META_BILLING_SUB_DISTRICT
			);
		}

		if ( isset( $fields['shipping']['fields'] ) ) {
			$fields['shipping']['fields'] = $this->insert_profile_fields(
				$fields['shipping']['fields'],
				'shipping_city',
				self::META_SHIPPING_DISTRICT,
				self::META_SHIPPING_SUB_DISTRICT
			);
		}

		return $fields;
	}

	private function insert_profile_fields( $fields, $city_key, $district_meta, $sub_district_meta ) {
		$extra = array(
			$district_meta     => array(
				'label'       => __( 'Kecamatan', 'kodepos-indonesia-free' ),
				'description' => '',
			),
			$sub_district_meta => array(
				'label'       => __( 'Kelurahan / Desa', 'kodepos-indonesia-free' ),
				'description' => '',
			),
		);

		$result = array();

		foreach ( $fields as $key => $field ) {
			$result[ $key ] = $field;
			if ( $city_key === $key ) {
				$result = array_merge( $result, $extra );
			}
		}

		if ( ! isset( $result[ $district_meta ] ) ) {
			$result = array_merge( $result, $extra );
		}

		return $result;
	}

	/**
	 * Remove the React settings UI feature so settings render classically.
	 *
	 * @param array $features Enabled admin features.
	 * @return array
	 */
	public function disable_react_settings_ui( $features ) {
		return array_values( array_diff( (array) $features, array( 'settings-ui' ) ) );
	}

	/* -------------------------------------------------------------------- */
	/* Store address settings                                                */
	/* -------------------------------------------------------------------- */

	/**
	 * Rearrange the Store Address settings for the cascade flow and add the
	 * district / sub-district fields.
	 *
	 * WooCommerce orders the fields address → city → country/state →
	 * postcode; the cascade needs the Province (country/state) select above
	 * the city, so that row is moved up.
	 *
	 * @param array $settings General settings.
	 * @return array
	 */
	public function store_address_settings( $settings ) {
		// Pull the combined Country/State row out so it can be re-inserted
		// above the city field.
		$country_setting = null;

		foreach ( $settings as $index => $setting ) {
			if ( isset( $setting['id'] ) && 'woocommerce_default_country' === $setting['id'] ) {
				$country_setting = $setting;
				unset( $settings[ $index ] );
				break;
			}
		}

		$result       = array();
		$country_used = false;

		foreach ( $settings as $setting ) {
			$is_city = isset( $setting['id'] ) && 'woocommerce_store_city' === $setting['id'];

			if ( $is_city && $country_setting ) {
				$result[]     = $country_setting;
				$country_used = true;
			}

			$result[] = $setting;
		}

		// The city row was not found, keep the country field rather than losing it.
		if ( $country_setting && ! $country_used ) {
			$result[] = $country_setting;
		}

		return $result;
	}

	/* -------------------------------------------------------------------- */
	/* Assets                                                                */
	/* -------------------------------------------------------------------- */

	public function enqueue_assets( $hook ) {
		if ( ! $this->db->is_available() ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';

		$is_order_screen    = in_array( $screen_id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		$is_profile_screen  = in_array( $hook, array( 'profile.php', 'user-edit.php' ), true );
		$is_settings_screen = 'woocommerce_page_wc-settings' === $screen_id
			&& ( ! isset( $_GET['tab'] ) || 'general' === $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $is_order_screen && ! $is_profile_screen && ! $is_settings_screen ) {
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
			'kodepos-indonesia-admin',
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
				'screen'  => $is_order_screen ? 'order' : ( $is_profile_screen ? 'profile' : 'settings' ),
				'i18n'    => array(
					'select'  => __( 'Pilih…', 'kodepos-indonesia-free' ),
					'loading' => __( 'Memuat…', 'kodepos-indonesia-free' ),
				),
			)
		);
	}
}
