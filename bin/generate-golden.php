<?php
/**
 * Generate golden-master snapshots from the CURRENT parser.
 *
 * Run this ONCE on the OLD stack (PHP 7.4 + phpdocumentor/reflection ~3.0) to
 * capture the baseline oracle, then commit tests/golden/snapshots/. After the
 * rewrite, the PHPUnit golden test (tests/golden/test-golden-master.php) replays
 * the same corpus through the modern parser and asserts it still matches.
 *
 * Do NOT regenerate against the new parser — that would defeat the oracle. The
 * snapshots are a frozen record of the old behavior. See tests/golden/README.md
 * for the Docker recipe that produces a clean PHP 7.4 environment.
 *
 * Usage:  php bin/generate-golden.php
 *
 * @package WP_Parser\Golden
 */

$repo = dirname( __DIR__ );

if ( ! file_exists( $repo . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "ERROR: vendor/ is not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require $repo . '/vendor/autoload.php';
require $repo . '/tests/golden/golden.php';

$snapshots = \WP_Parser\Golden\snapshots_dir();
if ( ! is_dir( $snapshots ) && ! mkdir( $snapshots, 0777, true ) && ! is_dir( $snapshots ) ) {
	fwrite( STDERR, "ERROR: could not create {$snapshots}\n" );
	exit( 1 );
}

$corpus = \WP_Parser\Golden\corpus();
if ( ! $corpus ) {
	fwrite( STDERR, "ERROR: empty corpus — no fixtures found under tests/.\n" );
	exit( 1 );
}

$count = 0;
foreach ( $corpus as $slug => $entry ) {
	$json = \WP_Parser\Golden\to_json( \WP_Parser\Golden\parse_entry( $entry ) );
	file_put_contents( \WP_Parser\Golden\snapshot_path( $slug ), $json );
	printf( "  wrote %-32s %6d bytes\n", $slug, strlen( $json ) );
	$count++;
}

printf( "\nDone. %d snapshot(s) written to %s\n", $count, $snapshots );
printf( "PHP %s | php-parser/reflection as installed in vendor/\n", PHP_VERSION );
