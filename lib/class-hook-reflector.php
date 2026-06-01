<?php
/**
 * Custom reflector for WordPress hooks, on nikic/php-parser 5.
 *
 * @package WP_Parser
 */

namespace WP_Parser;

use PhpParser\Node;

class Hook_Reflector {

	/** @var Node\Expr\FuncCall */
	protected $node;
	protected $namespace;
	protected $aliases;

	public function __construct( Node\Expr\FuncCall $node, $namespace = 'global', array $aliases = array() ) {
		$this->node      = $node;
		$this->namespace = $namespace;
		$this->aliases   = $aliases;
	}

	/**
	 * @return string
	 */
	public function getName() {
		$printer = new Pretty_Printer();

		return $this->cleanupName( $printer->prettyPrintExpr( $this->node->args[0]->value ) );
	}

	/**
	 * Normalize a hook name expression to the documented form.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private function cleanupName( $name ) {
		$matches = array();

		// quotes on both ends of a string
		if ( preg_match( '/^[\'"]([^\'"]*)[\'"]$/', $name, $matches ) ) {
			return $matches[1];
		}

		// two concatenated things, last one of them a variable
		if ( preg_match(
			'/(?:[\'"]([^\'"]*)[\'"]\s*\.\s*)?' . // First filter name string (optional)
			'(\$[^\s]*)' .                        // Dynamic variable
			'(?:\s*\.\s*[\'"]([^\'"]*)[\'"])?/',  // Second filter name string (optional)
			$name, $matches ) ) {

			if ( isset( $matches[3] ) ) {
				return $matches[1] . '{' . $matches[2] . '}' . $matches[3];
			} else {
				return $matches[1] . '{' . $matches[2] . '}';
			}
		}

		return $name;
	}

	/**
	 * @return string
	 */
	public function getShortName() {
		return $this->getName();
	}

	/**
	 * @return string
	 */
	public function getType() {
		$type = 'filter';

		switch ( (string) $this->node->name ) {
			case 'do_action':
				$type = 'action';
				break;
			case 'do_action_ref_array':
				$type = 'action_reference';
				break;
			case 'do_action_deprecated':
				$type = 'action_deprecated';
				break;
			case 'apply_filters_ref_array':
				$type = 'filter_reference';
				break;
			case 'apply_filters_deprecated':
				$type = 'filter_deprecated';
				break;
		}

		return $type;
	}

	/**
	 * @return array
	 */
	public function getArgs() {
		$printer = new Pretty_Printer();
		$args    = array();

		foreach ( $this->node->args as $arg ) {
			$args[] = $printer->prettyPrintArg( $arg );
		}

		// Skip the hook name.
		array_shift( $args );

		return $args;
	}

	public function getLineNumber() {
		return $this->node->getStartLine();
	}

	public function getNode() {
		return $this->node;
	}

	/**
	 * @return Docblock_Adapter|null
	 */
	public function getDocBlock() {
		return Docblock_Adapter::from_node( $this->node, $this->namespace, $this->aliases );
	}
}
