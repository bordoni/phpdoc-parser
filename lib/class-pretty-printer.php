<?php

namespace WP_Parser;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * Extends the default pretty printer to render a single call argument.
 *
 * Used by the hook and method-call reflectors to turn argument and caller AST
 * nodes back into source-equivalent strings.
 */
class Pretty_Printer extends Standard {

	/**
	 * Pretty print a single call argument.
	 *
	 * php-parser exposes no public method for a lone Arg node, so this mirrors
	 * prettyPrintExpr()'s state handling — reset + magic-token cleanup around the
	 * internal Arg printer. This replaces the v1 `noIndentToken` stripping, which
	 * no longer exists in php-parser 5.
	 *
	 * @param Node\Arg $node Argument node.
	 *
	 * @return string Pretty printed argument.
	 */
	public function prettyPrintArg( Node\Arg $node ) {
		$this->resetState();

		return $this->handleMagicTokens( $this->p( $node ) );
	}
}
