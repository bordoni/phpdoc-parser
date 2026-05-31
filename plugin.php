<?php
/**
 * Plugin Name: WP Parser
 * Description: Create a function reference site powered by WordPress
 * Author: Ryan McCue, Paul Gibbs, Andrey "Rarst" Savchenko and Contributors
 * Author URI: https://github.com/WordPress/phpdoc-parser/graphs/contributors
 * Plugin URI: https://github.com/WordPress/phpdoc-parser
 * Version:
 * Text Domain: wp-parser
 */

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';
}

// Check the class exists, to avoid fatals when composer hasn't been run.
if ( class_exists( 'WP_Parser\Plugin' ) ) {
	global $wp_parser;
	$wp_parser = new WP_Parser\Plugin();
	$wp_parser->on_load();
}

register_activation_hook( __FILE__, function () {
	// The bundled Posts-to-Posts library may not be loaded at activation time
	// (e.g. before Composer dependencies are installed in CI). Relationships also
	// creates these tables on demand, so guard against a fatal here.
	if ( class_exists( 'P2P_Storage' ) ) {
		\P2P_Storage::init();
		\P2P_Storage::install();
	}
} );

// TODO safer handling for uninstall
//register_uninstall_hook( __FILE__, array( 'P2P_Storage', 'uninstall' ) );
