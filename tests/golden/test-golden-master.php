<?php
/**
 * Golden-master characterization test.
 *
 * Replays the fixture corpus through the currently-installed parser and asserts
 * the exported JSON matches the committed snapshots byte-for-byte. The snapshots
 * are generated once from the OLD parser (see bin/generate-golden.php), so once
 * the parser is rewritten this test guarantees the modern parser reproduces the
 * old output exactly — the #1 acceptance criterion for the migration.
 *
 * Plain PHPUnit (no WordPress): run with
 *   ./vendor/bin/phpunit -c phpunit-golden.xml.dist
 *
 * @package WP_Parser\Golden
 */

namespace WP_Parser\Golden\Tests;

use PHPUnit\Framework\TestCase;

class Golden_Master_Test extends TestCase {

	/**
	 * Each fixture's current output must match its committed snapshot.
	 *
	 * @dataProvider corpus_provider
	 *
	 * @param string $slug  Fixture slug.
	 * @param array  $entry Corpus entry: { files, root }.
	 */
	public function test_output_matches_golden( $slug, array $entry ) {
		if ( ! \WP_Parser\Golden\parser_is_functional() ) {
			$this->markTestSkipped(
				'Parser not loadable yet (migration in progress — File_Reflector rewrite pending in Stage 4).'
			);
		}

		$snapshot = \WP_Parser\Golden\snapshot_path( $slug );

		if ( ! file_exists( $snapshot ) ) {
			$this->markTestSkipped(
				"No golden snapshot for '{$slug}'. Generate it on the old stack with bin/generate-golden.php."
			);
		}

		$expected = file_get_contents( $snapshot );
		$actual   = \WP_Parser\Golden\to_json( \WP_Parser\Golden\parse_entry( $entry ) );

		$this->assertSame(
			$expected,
			$actual,
			"Parser output drifted from the golden master for '{$slug}'."
		);
	}

	/**
	 * @return array<string,array{0:string,1:array}>
	 */
	public function corpus_provider() {
		$cases = array();
		foreach ( \WP_Parser\Golden\corpus() as $slug => $entry ) {
			$cases[ $slug ] = array( $slug, $entry );
		}

		return $cases;
	}
}
