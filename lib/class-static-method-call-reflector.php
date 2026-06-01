<?php
/**
 * A reflection of a static method-call expression, on nikic/php-parser 5.
 *
 * @package WP_Parser
 */

namespace WP_Parser;

use PhpParser\Node;

class Static_Method_Call_Reflector extends Method_Call_Reflector {

	/**
	 * Returns the name for this Reflector instance.
	 *
	 * @return string[] Index 0 is the class name, 1 is the method name.
	 */
	public function getName() {
		$method = $this->node->name instanceof Node\Identifier ? $this->node->name->toString() : '';

		return array( $this->resolve_caller( $this->node->class ), $method );
	}

	/**
	 * @return bool
	 */
	public function isStatic() {
		return true;
	}
}
