<?php
/**
 * Public REST proxy exposing the bundled Kodepos SQLite data to the browser.
 *
 * @package Kodepos_Indonesia
 */

defined( 'ABSPATH' ) || exit;

class Kodepos_Rest_Proxy {

	const REST_NAMESPACE = 'kodepos-indonesia/v1';

	/** @var Kodepos_DB */
	private $db;

	public function __construct( Kodepos_DB $db ) {
		$this->db = $db;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		// route slug => [ Kodepos_DB method, required query params ].
		$routes = array(
			'provinces'       => array( 'get_provinces', array() ),
			'cities'          => array( 'get_cities_by_province_code', array( 'province_code' ) ),
			'cities-by-state' => array( 'get_cities_by_wp_state_code', array( 'wp_state_code' ) ),
			'districts'       => array( 'get_districts', array( 'city_code' ) ),
			'sub-districts'   => array( 'get_sub_districts', array( 'district_code' ) ),
			'postal-codes'    => array( 'get_postal_codes', array( 'sub_district_code' ) ),
		);

		foreach ( $routes as $route => $route_config ) {
			list( $method, $required_params ) = $route_config;
			$args = array();

			foreach ( $required_params as $param ) {
				$args[ $param ] = array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				);
			}

			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					// Postal data is public; the proxy only keeps the SQLite file off the open web.
					'permission_callback' => '__return_true',
					'args'                => $args,
					'callback'            => function ( WP_REST_Request $request ) use ( $method, $required_params ) {
						return $this->handle( $method, $required_params, $request );
					},
				)
			);
		}
	}

	/**
	 * Serve one route from the local Kodepos_DB.
	 *
	 * @param string          $method          Kodepos_DB method name.
	 * @param string[]        $required_params Query param names to forward, in method-argument order.
	 * @param WP_REST_Request $request         Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function handle( $method, array $required_params, WP_REST_Request $request ) {
		if ( ! $this->db->is_available() ) {
			return new WP_Error(
				'kodepos_not_configured',
				__( 'Kodepos Indonesia data is not available on this site.', 'kodepos-indonesia-free' ),
				array( 'status' => 503 )
			);
		}

		$params = array();
		foreach ( $required_params as $param ) {
			$params[] = (string) $request->get_param( $param );
		}

		$items = call_user_func_array( array( $this->db, $method ), $params );

		$response = rest_ensure_response( array( 'items' => $items ) );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}
}
