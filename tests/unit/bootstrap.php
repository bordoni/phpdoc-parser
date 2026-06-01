<?php
/**
 * Bootstrap for the WordPress-free unit suite.
 *
 * Exercises the modern parser building blocks (pretty printer, name resolution,
 * docblock adapter, reflectors) in isolation from File_Reflector and WordPress,
 * so each piece can be green throughout the rewrite. Needs only Composer's
 * autoloader.
 *
 * Run:  ./vendor/bin/phpunit -c phpunit-unit.xml.dist
 *
 * @package WP_Parser\Tests\Unit
 */

$repo = dirname( __DIR__, 2 );

if ( ! file_exists( $repo . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "ERROR: vendor/ is not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require $repo . '/vendor/autoload.php';
