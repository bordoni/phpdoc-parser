<?php
/**
 * Unit tests for php-parser 5 name resolution.
 *
 * The rewritten File_Reflector (Stage 4) registers NameResolver with
 * `replaceNodes: false` so the original Name nodes are preserved and resolved
 * names are attached as attributes — the v5 analog of v1's `$node->namespacedName`.
 * Function_Call_Reflector (Stage 6) reads that attribute. This pins the behavior.
 *
 * @package WP_Parser\Tests\Unit
 */

namespace WP_Parser\Tests\Unit;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

class Name_Resolver_Test extends TestCase {

	/**
	 * Resolve a snippet with NameResolver in non-replacing mode.
	 *
	 * @param string $code PHP source (without the opening tag).
	 * @return Node\Stmt[]
	 */
	private function resolve( $code ) {
		$stmts = ( new ParserFactory() )->createForNewestSupportedVersion()->parse( "<?php\n" . $code );

		$traverser = new NodeTraverser();
		$traverser->addVisitor( new NameResolver( null, array( 'replaceNodes' => false ) ) );

		return $traverser->traverse( $stmts );
	}

	public function test_attaches_namespaced_name_to_unqualified_call() {
		$stmts = $this->resolve( "namespace Awesome\\Space;\nohai();" );

		$call = $stmts[0]->stmts[0]->expr; // Namespace_ -> Expression -> FuncCall
		$this->assertInstanceOf( Node\Expr\FuncCall::class, $call );

		// Original Name node is preserved (replaceNodes:false).
		$this->assertInstanceOf( Node\Name::class, $call->name );

		// Unqualified calls in a namespace cannot be statically resolved (global
		// fallback), so they receive a namespacedName attribute.
		$namespaced = $call->name->getAttribute( 'namespacedName' );
		$this->assertNotNull( $namespaced );
		$this->assertSame( 'Awesome\\Space\\ohai', $namespaced->toString() );
	}

	public function test_resolves_fully_qualified_call() {
		$stmts = $this->resolve( "namespace Awesome\\Space;\n\\Other\\Place\\greet();" );

		$call    = $stmts[0]->stmts[0]->expr;
		$resolved = $call->name->getAttribute( 'resolvedName' );

		$this->assertNotNull( $resolved );
		$this->assertSame( 'Other\\Place\\greet', $resolved->toString() );
	}
}
