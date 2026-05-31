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
	 * Reproduces the legacy resolution: an unqualified name that resolves to itself
	 * is a global-fallback call and stays bare (count, apply_filters); a fully-
	 * qualified, qualified, or use-function-imported name resolves to a leading-
	 * backslash FQN (\do_action, \Other\helper, \My\Plugin\Sub\thing).
	 *
	 * @return string
	 */
	public function getName() {
		$name = $this->node->name;

		if ( $name instanceof Node\Name ) {
			$resolved = $name->getAttribute( 'resolvedName' );

			// Unqualified names resolving to themselves stay bare (global fallback).
			if ( $name->isUnqualified()
				&& ( null === $resolved || $resolved->toString() === $name->toString() ) ) {
				return $name->toString();
			}

			if ( null !== $resolved ) {
				return '\\' . $resolved->toString();
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
