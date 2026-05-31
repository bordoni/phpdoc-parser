<?php
/**
 * Unit tests for the php-parser 5 File_Reflector structural extraction.
 *
 * The golden suite is the full oracle, but it stays red during the rewrite
 * (docblocks/hooks not wired yet). These focused tests keep the structural
 * extraction — names, namespaces, lines, visibility, defaults, extends, and the
 * nested-function ordering quirk — verified and green throughout Stages 5-6.
 *
 * @package WP_Parser\Tests\Unit
 */

namespace WP_Parser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Parser\File_Reflector;

class File_Reflector_Test extends TestCase {

	/**
	 * @param string $relative Path under tests/.
	 * @return File_Reflector
	 */
	private function reflect( $relative ) {
		$path = dirname( __DIR__ ) . '/' . $relative;
		$file = new File_Reflector( $path );
		$file->setFilename( basename( $path ) );
		$file->process();

		return $file;
	}

	public function test_extracts_namespaced_function() {
		$functions = $this->reflect( 'phpunit/tests/export/namespace.inc' )->getFunctions();

		$this->assertCount( 1, $functions );
		$this->assertSame( 'ohai', $functions[0]->getShortName() );
		$this->assertSame( 'Awesome\\Space', $functions[0]->getNamespace() );
		$this->assertSame( array(), $functions[0]->getNamespaceAliases() );
		$this->assertSame( 5, $functions[0]->getLineNumber() );
		$this->assertSame( array(), $functions[0]->getArguments() );
	}

	public function test_nested_functions_ordered_inner_first_and_extends_resolved() {
		$file = $this->reflect( 'phpunit/tests/export/uses/nested.inc' );

		$names = array_map(
			static function ( $fn ) {
				return $fn->getShortName();
			},
			$file->getFunctions()
		);
		// Inner functions complete first, matching the legacy parser's order.
		$this->assertSame( array( 'sub_test', 'test', 'sub_method_test' ), $names );

		$classes = $file->getClasses();
		$this->assertCount( 1, $classes );
		$this->assertSame( 'My_Class', $classes[0]->getShortName() );
		$this->assertSame( '\\Parent_Class', $classes[0]->getParentClass() );
	}

	public function test_extracts_class_members() {
		$class = $this->reflect( 'source/good-class.php' )->getClasses()[0];

		$this->assertSame( 'Good_Doc_Class', $class->getShortName() );
		$this->assertSame( 'global', $class->getNamespace() );
		$this->assertFalse( $class->isAbstract() );
		$this->assertFalse( $class->isFinal() );
		$this->assertSame( '', $class->getParentClass() );

		$property = $class->getProperties()[0];
		$this->assertSame( '$good_doc_private_property_from_good_doc_class', $property->getName() );
		$this->assertSame( 'private', $property->getVisibility() );
		$this->assertFalse( $property->isStatic() );
		$this->assertSame( 'array()', $property->getDefault() );

		$method = $class->getMethods()[0];
		$this->assertSame( '', $method->getNamespace() );
		$this->assertSame( 'public', $method->getVisibility() );
		$arguments = $method->getArguments();
		$this->assertSame( '$code', $arguments[0]->getName() );
		$this->assertSame( "''", $arguments[0]->getDefault() );
		$this->assertSame( '', $arguments[0]->getType() );
	}

	public function test_handles_anonymous_class_without_fatal() {
		// Real WordPress core instantiates anonymous classes; this must not fatal.
		$tmp = tempnam( sys_get_temp_dir(), 'wpp' );
		file_put_contents(
			$tmp,
			"<?php\nfunction make() {\n\treturn new class extends Some_Base {\n\t\tpublic function go() {}\n\t};\n}\n"
		);

		try {
			$file = new File_Reflector( $tmp );
			$file->setFilename( 'anon.php' );
			$file->process();
		} finally {
			unlink( $tmp );
		}

		$functions = $file->getFunctions();
		$this->assertCount( 1, $functions );

		$uses = $functions[0]->uses;
		$this->assertNotEmpty( $uses['methods'] );

		$name = $uses['methods'][0]->getName();
		$this->assertSame( '', $name[0] ); // Anonymous class — no name.
		$this->assertSame( '__construct', $name[1] );
	}
}
