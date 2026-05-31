<?php
/**
 * A reflection of a function-call expression, on nikic/php-parser 5.
 *
 * @package WP_Parser
 */

namespace WP_Parser;

use PhpParser\Node;

class Function_Call_Reflector {

	/** @var Node\Expr\FuncCall */
	protected $node;

	public function __construct( Node\Expr\FuncCall $node ) {
		$this->node = $node;
	}

	/**
	 * The called function's name.
	 *
	 * Namespaced calls resolve to a leading-backslash FQN (via the NameResolver
	 * namespacedName attribute); global calls stay unqualified, matching the
	 * legacy output (e.g. apply_filters, do_action).
	 *
	 * @return string
	 */
	public function getName() {
		$name = $this->node->name;

		if ( $name instanceof Node\Name ) {
			$namespaced = $name->getAttribute( 'namespacedName' );
			if ( null !== $namespaced ) {
				return '\\' . $namespaced->toString();
			}

			return $name->toString();
		}

		if ( $name instanceof Node\Expr\Variable && is_string( $name->name ) ) {
			return $name->name;
		}

		return Reflector_Helpers::pretty_print_expr( $name );
	}

	public function getLineNumber() {
		return $this->node->getStartLine();
	}

	public function getNode() {
		return $this->node;
	}
}
