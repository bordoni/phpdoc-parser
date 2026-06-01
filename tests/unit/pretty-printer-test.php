<?php
/**
 * Unit tests for the php-parser 5 pretty printer.
 *
 * Asserts the rewritten Pretty_Printer reproduces the source-equivalent strings
 * the hook/method reflectors rely on — matching the values frozen in the golden
 * snapshots (tests/golden/snapshots/export__hooks.json), proving the printer is
 * correct before Stage 4/6 wire it into the parser.
 *
 * @package WP_Parser\Tests\Unit
 */

namespace WP_Parser\Tests\Unit;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use WP_Parser\Pretty_Printer;

class Pretty_Printer_Test extends TestCase {

	/**
	 * Parse a snippet and return the FuncCall of its first statement.
	 *
	 * @param string $code A single call expression, e.g. "do_action( 'x' );".
	 * @return Node\Expr\FuncCall
	 */
	private function first_call( $code ) {
		$stmts = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( "<?php\n" . $code );

		return $stmts[0]->expr; // Stmt\Expression -> Expr\FuncCall
	}

	public function test_pretty_prints_simple_string_hook_name() {
		$call    = $this->first_call( "do_action( 'plain_action' );" );
		$printer = new Pretty_Printer();

		$this->assertSame( "'plain_action'", $printer->prettyPrintExpr( $call->args[0]->value ) );
	}

	public function test_pretty_prints_concatenated_hook_name() {
		$call    = $this->first_call( "do_action( \$variable . '-action' );" );
		$printer = new Pretty_Printer();

		$this->assertSame( "\$variable . '-action'", $printer->prettyPrintExpr( $call->args[0]->value ) );
	}

	public function test_pretty_prints_filter_arguments() {
		$call    = $this->first_call( "apply_filters( 'plain_filter', \$variable, \$filter_context );" );
		$printer = new Pretty_Printer();

		$args = array_map(
			function ( Node\Arg $arg ) use ( $printer ) {
				return $printer->prettyPrintArg( $arg );
			},
			$call->args
		);

		// The leading name arg is shifted off by Hook_Reflector; here we assert the
		// full rendered list, matching the golden contract for plain_filter.
		$this->assertSame( array( "'plain_filter'", '$variable', '$filter_context' ), $args );
	}
}
