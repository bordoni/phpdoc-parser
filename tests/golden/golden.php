<?php
/**
 * Shared helpers for the golden-master parser characterization harness.
 *
 * The "golden master" is the OLD parser's output captured as JSON snapshots. It
 * serves as the regression oracle while the parser is rewritten onto the modern
 * stack (nikic/php-parser 5 + phpstan/phpdoc-parser + reflection-docblock 6): the
 * rewritten parser must reproduce the exported array key-for-key.
 *
 * WP_Parser\parse_files() is WordPress-free, so this harness needs only Composer's
 * autoloader — no WordPress test framework. Both bin/generate-golden.php and the
 * PHPUnit test consume these helpers so generation and comparison never drift.
 *
 * @package WP_Parser\Golden
 */

namespace WP_Parser\Golden;

/**
 * Placeholder substituted for the absolute corpus root so snapshots are portable
 * across machines and PHP versions (e.g. a Docker mount vs. a local checkout).
 */
const ROOT_PLACEHOLDER = '{{ROOT}}';

/**
 * Absolute path to the repository root.
 *
 * @return string
 */
function repo_root() {
	return dirname( __DIR__, 2 );
}

/**
 * Directory holding the committed JSON snapshots.
 *
 * @return string
 */
function snapshots_dir() {
	return __DIR__ . '/snapshots';
}

/**
 * Build the fixture corpus.
 *
 * Each fixture file is parsed on its own (root = its directory), mirroring how
 * tests/phpunit/includes/export-testcase.php drives parse_files(). The corpus is
 * the existing hand-written fixtures: tests/source/*.php plus every export/import
 * *.inc file. Add real wp-includes files here later to widen coverage.
 *
 * @return array<string,array{files:string[],root:string}> Map of slug => entry.
 */
function corpus() {
	$root  = repo_root();
	$files = array();

	foreach ( (array) glob( $root . '/tests/source/*.php' ) as $file ) {
		$files[] = $file;
	}

	$dir = $root . '/tests/phpunit/tests';
	if ( is_dir( $dir ) ) {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( 'inc' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
	}

	sort( $files ); // Deterministic ordering across platforms.

	$corpus = array();
	foreach ( $files as $file ) {
		$corpus[ slug( $file ) ] = array(
			'files' => array( $file ),
			'root'  => dirname( $file ),
		);
	}

	return $corpus;
}

/**
 * Turn a fixture path into a stable, filesystem-safe snapshot slug.
 *
 * @param string $file Absolute path to a fixture.
 * @return string
 */
function slug( $file ) {
	$relative = ltrim( str_replace( repo_root(), '', $file ), '/\\' );
	$relative = preg_replace( '#^tests/phpunit/tests/#', '', $relative );
	$relative = preg_replace( '#^tests/#', '', $relative );
	$relative = preg_replace( '#\.(php|inc)$#', '', $relative );

	return str_replace( array( '/', '\\' ), '__', $relative );
}

/**
 * Absolute path to a slug's snapshot file.
 *
 * @param string $slug Snapshot slug.
 * @return string
 */
function snapshot_path( $slug ) {
	return snapshots_dir() . '/' . $slug . '.json';
}

/**
 * Scrub environment-specific values from parser output so it is reproducible.
 *
 * Only the absolute root path is scrubbed; every other value is content-derived
 * and must match exactly between the old and rewritten parsers.
 *
 * @param array $data Output of parse_files().
 * @return array
 */
function normalize( array $data ) {
	foreach ( $data as &$file ) {
		if ( isset( $file['root'] ) ) {
			$file['root'] = ROOT_PLACEHOLDER;
		}
	}
	unset( $file );

	return $data;
}

/**
 * Canonical JSON encoding shared by the generator and the test, so the comparison
 * is byte-for-byte.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function to_json( $data ) {
	return json_encode(
		$data,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
}

/**
 * Parse a corpus entry and return its normalized output array.
 *
 * @param array{files:string[],root:string} $entry Corpus entry.
 * @return array
 */
function parse_entry( array $entry ) {
	return normalize( \WP_Parser\parse_files( $entry['files'], $entry['root'] ) );
}
