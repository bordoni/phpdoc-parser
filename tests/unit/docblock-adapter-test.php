<?php
/**
 * Unit tests for the reflection-docblock 6 adapter.
 *
 * Verifies the adapter reproduces the legacy reflection-docblock 2 surface that
 * export_docblock() relies on — including the method_exists() differentiation
 * between tag kinds, the type-to-string shim, and the @see/@link reconstruction.
 *
 * @package WP_Parser\Tests\Unit
 */

namespace WP_Parser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Parser\Docblock_Adapter;

class Docblock_Adapter_Test extends TestCase {

	private function doc( $text ) {
		return Docblock_Adapter::from_text( $text, 'global', array() );
	}

	public function test_summary_and_long_description() {
		$db = $this->doc( "/**\n * Summary here.\n *\n * Long\n * description.\n */" );

		$this->assertSame( 'Summary here.', $db->getShortDescription() );
		$this->assertStringContainsString( '<p>', $db->getLongDescription()->getFormattedContents() );
	}

	public function test_param_return_since_tags() {
		$db = $this->doc(
			"/**\n * S.\n *\n * @since 1.2.0\n * @param string \$var A value.\n"
			. " * @param WP_Post \$post Post object.\n * @return bool Result.\n */"
		);

		$tags = $db->getTags();

		// @since exposes a version but no types (so export_docblock keeps it a version tag).
		$this->assertSame( 'since', $tags[0]->getName() );
		$this->assertSame( '1.2.0', $tags[0]->getVersion() );
		$this->assertFalse( method_exists( $tags[0], 'getTypes' ) );

		// @param: types + variable.
		$this->assertSame( 'param', $tags[1]->getName() );
		$this->assertSame( array( 'string' ), $tags[1]->getTypes() );
		$this->assertSame( '$var', $tags[1]->getVariableName() );

		// Class types resolve to a leading-backslash FQN.
		$this->assertSame( array( '\\WP_Post' ), $tags[2]->getTypes() );

		// @return: types, no variable.
		$this->assertSame( array( 'bool' ), $tags[3]->getTypes() );
		$this->assertFalse( method_exists( $tags[3], 'getVariableName' ) );
	}

	public function test_see_and_link_reconstruction() {
		$db = $this->doc( "/**\n * S.\n *\n * @see Function/method/class relied on\n * @link URL\n */" );

		$tags = $db->getTags();

		// @see is invalid to reflection-docblock (non-FQSEN); reconstructed loosely.
		$this->assertSame( 'see', $tags[0]->getName() );
		$this->assertSame( 'Function/method/class', $tags[0]->getReference() );
		$this->assertSame( 'relied on', $tags[0]->getDescription() );

		// @link with no description uses the URL as content.
		$this->assertSame( 'link', $tags[1]->getName() );
		$this->assertSame( 'URL', $tags[1]->getLink() );
		$this->assertSame( 'URL', $tags[1]->getDescription() );
	}

	/**
	 * A @see target reflection-docblock recognizes as an FQSEN comes back normalized
	 * with a leading backslash; the legacy parser kept it as written, so the adapter
	 * strips the single normalization backslash. (Golden: tests/.../export/docblocks.)
	 */
	public function test_see_fqsen_reference_drops_normalization_backslash() {
		$db = $this->doc( "/**\n * S.\n *\n * @see self::other_method() The description.\n */" );

		$tags = $db->getTags();

		$this->assertSame( 'see', $tags[0]->getName() );
		$this->assertSame( 'self::other_method()', $tags[0]->getReference() );
		$this->assertSame( 'The description.', $tags[0]->getDescription() );
	}

	/**
	 * Modern @param type syntaxes the legacy parser mangled (a leading "?" nullable
	 * and parenthesized unions) — reflection-docblock 6 parses them correctly. The
	 * old parser produced garbage for these, so they are excluded from the byte-for-
	 * byte golden suite and locked here instead. Class names still resolve to the
	 * leading-backslash FQN form the legacy output used.
	 */
	public function test_modern_param_type_syntax() {
		$db = $this->doc(
			"/**\n * S.\n *\n"
			. " * @param ?string \$nullable_string A nullable string.\n"
			. " * @param ( WP_Post | null ) \$nullable_post A nullable post.\n */"
		);

		$tags = $db->getTags();

		$this->assertSame( 'param', $tags[0]->getName() );
		$this->assertSame( array( '?string' ), $tags[0]->getTypes() );
		$this->assertSame( '$nullable_string', $tags[0]->getVariableName() );

		$this->assertSame( 'param', $tags[1]->getName() );
		$this->assertSame( array( '\\WP_Post', 'null' ), $tags[1]->getTypes() );
		$this->assertSame( '$nullable_post', $tags[1]->getVariableName() );
	}
}
