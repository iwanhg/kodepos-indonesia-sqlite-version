<?php
/**
 * Read-only PDO_SQLITE wrapper around the bundled Kodepos dataset.
 *
 * @package Kodepos_Indonesia
 */

// phpcs:disable WordPress.DB.RestrictedClasses -- $wpdb only connects to the
// WordPress MySQL/MariaDB database; it has no SQLite driver and can't open
// the bundled, offline data/kodepos.sqlite file this class reads.

defined( 'ABSPATH' ) || exit;

class Kodepos_DB {

	const DB_FILE = 'data/kodepos.sqlite';

	/** @var PDO|null|false False once a connection attempt has failed. */
	private $pdo = null;

	/**
	 * Whether the pdo_sqlite extension is loaded and the bundled data file
	 * can be opened.
	 *
	 * @return bool
	 */
	public function is_available() {
		return null !== $this->connection();
	}

	/**
	 * @return string Data version from the bundled meta table, or ''.
	 */
	public function get_data_version() {
		return (string) $this->meta( 'data_version' );
	}

	/**
	 * @return string Row count from the bundled meta table, or ''.
	 */
	public function get_row_count() {
		return (string) $this->meta( 'row_count' );
	}

	/**
	 * @return array[] {code, name} list of all provinces.
	 */
	public function get_provinces() {
		return $this->list_query(
			'SELECT DISTINCT province_code AS code, province AS name
			 FROM kodepos_indonesia ORDER BY province'
		);
	}

	/**
	 * @param string $province_code
	 * @return array[] {code, name} list of cities in a province (API province code).
	 */
	public function get_cities_by_province_code( $province_code ) {
		return $this->list_query(
			'SELECT DISTINCT city_code AS code, city AS name
			 FROM kodepos_indonesia WHERE province_code = :value ORDER BY city',
			$province_code
		);
	}

	/**
	 * @param string $wp_state_code WooCommerce/WordPress Indonesia state code (e.g. "JK").
	 * @return array[] {code, name} list of cities.
	 */
	public function get_cities_by_wp_state_code( $wp_state_code ) {
		return $this->list_query(
			'SELECT DISTINCT city_code AS code, city AS name
			 FROM kodepos_indonesia WHERE wp_state_code = :value ORDER BY city',
			$wp_state_code
		);
	}

	/**
	 * @param string $city_code
	 * @return array[] {code, name} list of districts.
	 */
	public function get_districts( $city_code ) {
		return $this->list_query(
			'SELECT DISTINCT district_code AS code, district AS name
			 FROM kodepos_indonesia WHERE city_code = :value ORDER BY district',
			$city_code
		);
	}

	/**
	 * @param string $district_code
	 * @return array[] {code, name} list of sub-districts.
	 */
	public function get_sub_districts( $district_code ) {
		return $this->list_query(
			'SELECT DISTINCT sub_district_code AS code, sub_district AS name
			 FROM kodepos_indonesia WHERE district_code = :value ORDER BY sub_district',
			$district_code
		);
	}

	/**
	 * @param string $sub_district_code
	 * @return array[] {code, name, postal_code} list of postal codes.
	 */
	public function get_postal_codes( $sub_district_code ) {
		$rows = $this->list_query(
			'SELECT DISTINCT postal_code AS code, postal_code AS name
			 FROM kodepos_indonesia WHERE sub_district_code = :value ORDER BY postal_code',
			$sub_district_code
		);

		foreach ( $rows as &$row ) {
			$row['postal_code'] = $row['name'];
		}

		return $rows;
	}

	/**
	 * Run a SELECT and return every row as an associative array, or an empty
	 * array if the database is unavailable or the query fails.
	 *
	 * @param string      $sql
	 * @param string|null $value Bound as :value when the query has a placeholder.
	 * @return array[]
	 */
	private function list_query( $sql, $value = null ) {
		$pdo = $this->connection();

		if ( null === $pdo ) {
			return array();
		}

		try {
			$stmt = $pdo->prepare( $sql );
			$stmt->execute( null !== $value ? array( ':value' => (string) $value ) : array() );

			return $stmt->fetchAll( PDO::FETCH_ASSOC );
		} catch ( PDOException $e ) {
			return array();
		}
	}

	/**
	 * @param string $key
	 * @return string|null
	 */
	private function meta( $key ) {
		$pdo = $this->connection();

		if ( null === $pdo ) {
			return null;
		}

		try {
			$stmt = $pdo->prepare( 'SELECT value FROM meta WHERE key = :key' );
			$stmt->execute( array( ':key' => $key ) );

			$value = $stmt->fetchColumn();

			return false !== $value ? (string) $value : null;
		} catch ( PDOException $e ) {
			return null;
		}
	}

	/**
	 * Lazily open (and memoize) the read-only PDO connection.
	 *
	 * @return PDO|null Null when the extension or data file is unavailable.
	 */
	private function connection() {
		if ( $this->pdo instanceof PDO ) {
			return $this->pdo;
		}

		if ( false === $this->pdo ) {
			return null;
		}

		$file = KODEPOS_ID_DIR . self::DB_FILE;

		if ( ! extension_loaded( 'pdo_sqlite' ) || ! is_readable( $file ) ) {
			$this->pdo = false;
			return null;
		}

		try {
			$pdo = new PDO( 'sqlite:' . $file );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$pdo->exec( 'PRAGMA query_only = 1' );
		} catch ( PDOException $e ) {
			$this->pdo = false;
			return null;
		}

		$this->pdo = $pdo;

		return $this->pdo;
	}
}
