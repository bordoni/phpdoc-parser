<?php
/**
 * Lightweight structural reflectors built on nikic/php-parser 5 nodes.
 *
 * These replace the slice of the phpDocumentor 3 reflector API that runner.php
 * consumes (getShortName/getNamespace/getArguments/getDocBlock/...), keeping the
 * exported array shape identical. Each wraps a php-parser node and exposes only
 * what the exporter needs. Docblocks are parsed lazily via Docblock_Adapter.
 *
 * @package WP_Parser
 */

namespace WP_Parser;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * Shared rendering helpers for the structural reflectors.
 */
class Reflector_Helpers {

	/** @var Standard */
	private static $printer;

	private static function printer() {
		if ( null === self::$printer ) {
			self::$printer = new Standard();
		}

		return self::$printer;
	}

	/**
	 * Pretty print an expression to its source string.
	 *
	 * @param Node\Expr $expr
	 * @return string
	 */
	public static function pretty_print_expr( $expr ) {
		return self::printer()->prettyPrintExpr( $expr );
	}

	/**
	 * Render a default-value expression to its source string, or null when absent.
	 *
	 * @param Node\Expr|null $expr
	 * @return string|null
	 */
	public static function default_value( $expr ) {
		if ( null === $expr ) {
			return null;
		}

		return self::printer()->prettyPrintExpr( $expr );
	}

	/**
	 * Render a type node to a string, or '' when there is no type.
	 *
	 * @param Node\Identifier|Node\Name|Node\ComplexType|null $type
	 * @return string
	 */
	public static function type_string( $type ) {
		if ( null === $type ) {
			return '';
		}

		if ( $type instanceof Node\NullableType ) {
			return '?' . self::type_string( $type->type );
		}

		if ( $type instanceof Node\UnionType ) {
			return implode( '|', array_map( array( self::class, 'type_string' ), $type->types ) );
		}

		if ( $type instanceof Node\IntersectionType ) {
			return implode( '&', array_map( array( self::class, 'type_string' ), $type->types ) );
		}

		// Class-name types resolve to the legacy "\Fully\Qualified" form; built-in
		// Identifier types (int, string, array, void, …) stay bare.
		if ( $type instanceof Node\Name ) {
			return self::class_name( $type );
		}

		return $type->toString();
	}

	/**
	 * Map php-parser visibility flags to a visibility string.
	 *
	 * @param Node\Stmt\ClassMethod|Node\Stmt\Property $node
	 * @return string
	 */
	public static function visibility( $node ) {
		if ( $node->isPrivate() ) {
			return 'private';
		}

		if ( $node->isProtected() ) {
			return 'protected';
		}

		return 'public';
	}

	/**
	 * Resolve a class reference (extends/implements) to the legacy "\Fully\Qualified"
	 * form. Uses the NameResolver's resolvedName attribute when available so aliased
	 * and namespaced names resolve correctly; returns '' when there is no reference.
	 *
	 * @param Node\Name|null $name
	 * @return string
	 */
	public static function class_name( $name ) {
		if ( null === $name ) {
			return '';
		}

		$resolved = $name->getAttribute( 'resolvedName' );

		return '\\' . ( $resolved ? $resolved->toString() : $name->toString() );
	}

	/**
	 * Render a namespace-alias map for export, fully-qualifying each target with a
	 * leading backslash (alias => "\Fully\Qualified"), matching the legacy output.
	 *
	 * @param array $aliases Map of alias => fully-qualified name (no leading slash).
	 * @return array
	 */
	public static function export_aliases( array $aliases ) {
		$out = array();
		foreach ( $aliases as $alias => $fqn ) {
			$out[ $alias ] = '\\' . ltrim( $fqn, '\\' );
		}

		return $out;
	}

	/**
	 * Build Argument_Reflectors for a list of parameters.
	 *
	 * @param Node\Param[] $params
	 * @return Argument_Reflector[]
	 */
	public static function arguments( array $params ) {
		$arguments = array();
		foreach ( $params as $param ) {
			$arguments[] = new Argument_Reflector( $param );
		}

		return $arguments;
	}
}

/**
 * A function argument (parameter).
 */
class Argument_Reflector {

	/** @var Node\Param */
	protected $node;

	public function __construct( Node\Param $node ) {
		$this->node = $node;
	}

	public function getName() {
		return '$' . ( is_string( $this->node->var->name ) ? $this->node->var->name : '' );
	}

	public function getDefault() {
		return Reflector_Helpers::default_value( $this->node->default );
	}

	public function getType() {
		return Reflector_Helpers::type_string( $this->node->type );
	}

	public function getNode() {
		return $this->node;
	}
}

/**
 * A top-level (or namespaced) function definition.
 */
class Function_Reflector {

	/** @var array|null Elements used by this function; populated during traversal. */
	public $uses;

	/** @var Node\Stmt\Function_ */
	protected $node;
	protected $namespace;
	protected $aliases;

	public function __construct( Node\Stmt\Function_ $node, $namespace, array $aliases ) {
		$this->node      = $node;
		$this->namespace = $namespace;
		$this->aliases   = $aliases;
		$this->uses      = $node->getAttribute( 'wp_parser_uses' );
	}

	public function getShortName() {
		return $this->node->name->toString();
	}

	public function getNamespace() {
		return $this->namespace;
	}

	public function getNamespaceAliases() {
		return Reflector_Helpers::export_aliases( $this->aliases );
	}

	public function getLineNumber() {
		return $this->node->getStartLine();
	}

	public function getNode() {
		return $this->node;
	}

	public function getArguments() {
		return Reflector_Helpers::arguments( $this->node->params );
	}

	public function getDocBlock() {
		return Docblock_Adapter::from_node( $this->node, $this->namespace, $this->aliases );
	}
}

/**
 * A class definition.
 */
class Class_Reflector {

	/** @var Node\Stmt\Class_ */
	protected $node;
	protected $namespace;
	protected $aliases;

	public function __construct( Node\Stmt\Class_ $node, $namespace, array $aliases ) {
		$this->node      = $node;
		$this->namespace = $namespace;
		$this->aliases   = $aliases;
	}

	public function getShortName() {
		return $this->node->name->toString();
	}

	public function getNamespace() {
		return $this->namespace;
	}

	public function getNamespaceAliases() {
		return $this->aliases;
	}

	public function getLineNumber() {
		return $this->node->getStartLine();
	}

	public function getNode() {
		return $this->node;
	}

	public function isFinal() {
		return $this->node->isFinal();
	}

	public function isAbstract() {
		return $this->node->isAbstract();
	}

	public function getParentClass() {
		return Reflector_Helpers::class_name( $this->node->extends );
	}

	public function getInterfaces() {
		$interfaces = array();
		foreach ( $this->node->implements as $interface ) {
			$interfaces[] = Reflector_Helpers::class_name( $interface );
		}

		return $interfaces;
	}

	public function getProperties() {
		$properties = array();
		foreach ( $this->node->getProperties() as $property ) {
			foreach ( $property->props as $prop ) {
				$properties[] = new Property_Reflector( $property, $prop, $this->namespace, $this->aliases );
			}
		}

		return $properties;
	}

	public function getMethods() {
		$methods = array();
		foreach ( $this->node->getMethods() as $method ) {
			$methods[] = new Method_Reflector( $method, $this->namespace, $this->aliases );
		}

		return $methods;
	}

	public function getDocBlock() {
		return Docblock_Adapter::from_node( $this->node, $this->namespace, $this->aliases );
	}
}

/**
 * A class method.
 */
class Method_Reflector {

	/** @var array|null Elements used by this method; populated during traversal. */
	public $uses;

	/** @var Node\Stmt\ClassMethod */
	protected $node;

	/** @var string Namespace used to resolve docblock types (the class's namespace). */
	protected $resolve_namespace;
	protected $aliases;

	public function __construct( Node\Stmt\ClassMethod $node, $resolve_namespace, array $aliases ) {
		$this->node              = $node;
		$this->resolve_namespace = $resolve_namespace;
		$this->aliases           = $aliases;
		$this->uses              = $node->getAttribute( 'wp_parser_uses' );
	}

	public function getShortName() {
		return $this->node->name->toString();
	}

	public function getNamespace() {
		// A method carries its enclosing namespace; the global namespace is reported
		// as '' (not 'global', unlike functions/classes), matching the legacy output.
		return 'global' === $this->resolve_namespace ? '' : $this->resolve_namespace;
	}

	public function getNamespaceAliases() {
		return Reflector_Helpers::export_aliases( $this->aliases );
	}

	public function getLineNumber() {
		return $this->node->getStartLine();
	}

	public function getNode() {
		return $this->node;
	}

	public function isFinal() {
		return $this->node->isFinal();
	}

	public function isAbstract() {
		return $this->node->isAbstract();
	}

	public function isStatic() {
		return $this->node->isStatic();
	}

	public function getVisibility() {
		return Reflector_Helpers::visibility( $this->node );
	}

	public function getArguments() {
		return Reflector_Helpers::arguments( $this->node->params );
	}

	public function getDocBlock() {
		return Docblock_Adapter::from_node( $this->node, $this->resolve_namespace, $this->aliases );
	}
}

/**
 * A single declared class property.
 */
class Property_Reflector {

	/** @var Node\Stmt\Property */
	protected $stmt;

	/** @var Node\PropertyItem|object The individual property within the declaration. */
	protected $prop;

	protected $namespace;
	protected $aliases;

	public function __construct( Node\Stmt\Property $stmt, $prop, $namespace, array $aliases ) {
		$this->stmt      = $stmt;
		$this->prop      = $prop;
		$this->namespace = $namespace;
		$this->aliases   = $aliases;
	}

	public function getName() {
		return '$' . $this->prop->name->toString();
	}

	public function getLineNumber() {
		return $this->stmt->getStartLine();
	}

	public function getNode() {
		return $this->stmt;
	}

	public function getDefault() {
		return Reflector_Helpers::default_value( $this->prop->default );
	}

	public function isStatic() {
		return $this->stmt->isStatic();
	}

	public function getVisibility() {
		return Reflector_Helpers::visibility( $this->stmt );
	}

	public function getDocBlock() {
		return Docblock_Adapter::from_node( $this->stmt, $this->namespace, $this->aliases );
	}
}

/**
 * Reflects a single include/require statement, exposing the surface runner.php
 * reads. The type is the legacy human-readable label ("Include", "Require Once").
 */
class Include_Reflector {

	protected $name;
	protected $line;
	protected $type;

	public function __construct( $name, $line, $type ) {
		$this->name = $name;
		$this->line = $line;
		$this->type = $type;
	}

	public function getName() {
		return $this->name;
	}

	public function getLineNumber() {
		return $this->line;
	}

	public function getType() {
		return $this->type;
	}
}

/**
 * Reflects a file-level constant — a define() call or the const keyword — exposing
 * the surface runner.php reads. The value is the pretty-printed default expression.
 */
class Constant_Reflector {

	protected $name;
	protected $line;
	protected $value;

	public function __construct( $name, $line, $value ) {
		$this->name  = $name;
		$this->line  = $line;
		$this->value = $value;
	}

	public function getShortName() {
		return $this->name;
	}

	public function getLineNumber() {
		return $this->line;
	}

	public function getValue() {
		return $this->value;
	}
}
