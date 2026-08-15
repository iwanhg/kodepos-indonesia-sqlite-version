<?php
/**
 * One-off CSV -> SQLite converter for the Kodepos Indonesia dataset.
 *
 * Run locally/CI, never on the user's server:
 *   php build/csv-to-sqlite.php [source.csv] [target.sqlite]
 *
 * Regenerates the bundled data/kodepos.sqlite from kodepos-indonesia.csv.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script is CLI-only.\n" );
	exit( 1 );
}

$root   = dirname( __DIR__ );
$source = $argv[1] ?? $root . '/kodepos-indonesia.csv';
$target = $argv[2] ?? $root . '/data/kodepos.sqlite';

if ( ! is_file( $source ) ) {
	fwrite( STDERR, "Source CSV not found: {$source}\n" );
	exit( 1 );
}

if ( is_file( $target ) ) {
	unlink( $target );
}

$pdo = new PDO( 'sqlite:' . $target );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

$pdo->exec(
	'CREATE TABLE kodepos_indonesia (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		province_code TEXT,
		province TEXT,
		city_code TEXT,
		city TEXT,
		district_code TEXT,
		district TEXT,
		sub_district_code TEXT,
		sub_district TEXT,
		postal_code TEXT,
		wp_state_code TEXT
	)'
);

$pdo->exec( 'CREATE INDEX idx_postal_code ON kodepos_indonesia(postal_code)' );
$pdo->exec( 'CREATE INDEX idx_city ON kodepos_indonesia(city)' );
$pdo->exec( 'CREATE INDEX idx_district ON kodepos_indonesia(district)' );
$pdo->exec( 'CREATE INDEX idx_sub_district ON kodepos_indonesia(sub_district)' );
$pdo->exec( 'CREATE INDEX idx_wp_state_code ON kodepos_indonesia(wp_state_code)' );
$pdo->exec( 'CREATE INDEX idx_province_code ON kodepos_indonesia(province_code)' );
$pdo->exec( 'CREATE INDEX idx_city_code ON kodepos_indonesia(city_code)' );
$pdo->exec( 'CREATE INDEX idx_district_code ON kodepos_indonesia(district_code)' );
$pdo->exec( 'CREATE INDEX idx_sub_district_code ON kodepos_indonesia(sub_district_code)' );

$pdo->exec( 'CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)' );

$columns = array(
	'province_code',
	'province',
	'city_code',
	'city',
	'district_code',
	'district',
	'sub_district_code',
	'sub_district',
	'postal_code',
	'wp_state_code',
);

$insert = $pdo->prepare(
	'INSERT INTO kodepos_indonesia
		(province_code, province, city_code, city, district_code, district, sub_district_code, sub_district, postal_code, wp_state_code)
	VALUES
		(:province_code, :province, :city_code, :city, :district_code, :district, :sub_district_code, :sub_district, :postal_code, :wp_state_code)'
);

$handle = fopen( $source, 'r' );
if ( false === $handle ) {
	fwrite( STDERR, "Could not open source CSV.\n" );
	exit( 1 );
}

// Strip a UTF-8 BOM if present so the header row matches exactly.
$bom = fread( $handle, 3 );
if ( "\xEF\xBB\xBF" !== $bom ) {
	rewind( $handle );
}

$header = fgetcsv( $handle, 0, ';' );
if ( false === $header || $header !== $columns ) {
	fwrite( STDERR, "Unexpected CSV header: " . implode( ';', (array) $header ) . "\n" );
	exit( 1 );
}

$pdo->beginTransaction();

$row_count     = 0;
$skipped_count = 0;
while ( false !== ( $row = fgetcsv( $handle, 0, ';' ) ) ) {
	if ( 1 === count( $row ) && null === $row[0] ) {
		continue; // Trailing blank line.
	}

	$values = array_combine( $columns, $row );

	// Rows with no province_code can't be resolved back to a province and
	// produce duplicate/blank entries in province-keyed lookups — drop them.
	if ( '' === trim( (string) $values['province_code'] ) ) {
		++$skipped_count;
		continue;
	}

	$insert->execute( $values );
	++$row_count;
}

fclose( $handle );
$pdo->commit();

$data_version = gmdate( 'Y.m.d' );

$meta = $pdo->prepare( 'INSERT INTO meta (key, value) VALUES (:key, :value)' );
$meta->execute( array( 'key' => 'data_version', 'value' => $data_version ) );
$meta->execute( array( 'key' => 'row_count', 'value' => (string) $row_count ) );

$pdo->exec( 'VACUUM' );
$pdo = null;

$size_mb = round( filesize( $target ) / 1048576, 2 );

echo "Wrote {$target}\n";
echo "Rows: {$row_count}\n";
echo "Skipped (blank province_code): {$skipped_count}\n";
echo "Data version: {$data_version}\n";
echo "File size: {$size_mb} MB\n";
