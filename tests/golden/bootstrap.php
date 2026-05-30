<?php
/**
 * Bootstrap for the standalone golden-master test suite.
 *
 * Unlike the main suite (tests/phpunit), the golden suite does NOT need the
 * WordPress test framework — parse_files() is WordPress-free — so it loads only
 * Composer's autoloader and the shared helpers.
 *
 * @package WP_Parser\Golden
 */

$repo = dirname( __DIR__, 2 );

if ( ! file_exists( $repo . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "ERROR: vendor/ is not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require $repo . '/vendor/autoload.php';
require __DIR__ . '/golden.php';
