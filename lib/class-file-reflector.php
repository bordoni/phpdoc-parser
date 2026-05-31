<?php

namespace WP_Parser;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Reflects a single PHP file using nikic/php-parser 5.
 *
 * Replaces the former phpDocumentor FileReflector subclass. It walks the AST as
 * a NodeVisitor, collecting the structural elements (functions, classes, their
 * methods and properties) that runner.php exports, the WordPress hooks declared
 * via do_action()/apply_filters(), and the functions/methods each element uses —
 * all while keeping the exported array shape identical.
 */
class File_Reflector extends NodeVisitorAbstract {

	/**
	 * Elements used in file scope, indexed by element type (functions, methods, hooks).
	 *
	 * @var array
	 */
	public $uses = array();

	protected $filename;
	protected $contents;
	protected $path;

	/** @var Function_Reflector[] */
	protected $functions = array();

	/** @var Class_Reflector[] */
	protected $classes = array();

	protected $constants = array();
	protected $includes  = array();

	/** @var Docblock_Adapter|null The file-level docblock, if any. */
	protected $file_docblock = null;

	/** @var Node[] Stack of scope nodes (function/method/class) currently open. */
	protected $location = array();

	/** @var Doc|null Last docblock seen on a non-documentable node, for the next hook. */
	protected $last_doc = null;

	/** @var string Current namespace ('global' at file scope). */
	protected $namespace = 'global';

	/** @var array Map of import alias => fully qualified name for the current namespace. */
	protected $aliases = array();

	/**
	 * @param string $filename Absolute path to the file to reflect.
	 */
	public function __construct( $filename ) {
		$this->filename = $filename;
		$this->contents = file_get_contents( $filename );
	}

	/**
	 * Set the (root-relative) path reported for this file.
	 *
	 * @param string $path
	 */
	public function setFilename( $path ) {
		$this->path = $path;
	}

	/**
	 * @return string
	 */
	public function getFilename() {
		return $this->path;
	}

	/**
	 * Parse and walk the file, populating the structural collections.
	 */
	public function process() {
		$parser = ( new ParserFactory() )->createForNewestSupportedVersion();

		try {
			$stmts = $parser->parse( $this->contents );
		} catch ( \PhpParser\Error $e ) {
			$stmts = null;
		}

		if ( null === $stmts ) {
			return;
		}

		$this->file_docblock = $this->detect_file_docblock( $stmts );

		// Pass 1: resolve names. replaceNodes:false keeps the original Name nodes and
		// attaches resolved names as attributes — the v5 analog of v1's namespacedName.
		$resolver = new NodeTraverser();
		$resolver->addVisitor( new NameResolver( null, array( 'replaceNodes' => false ) ) );
		$stmts = $resolver->traverse( $stmts );

		// Pass 2: fully-qualify class-position names (so a nested `Class::m()` caller
		// prints as `\Class::m()`) while leaving function names alone, then reflect.
		$traverser = new NodeTraverser();
		$traverser->addVisitor( new Class_Name_Resolver() );
		$traverser->addVisitor( $this );
		$traverser->traverse( $stmts );
	}

	/**
	 * Track scope, record hook/function/method usage, and carry hook docblocks.
	 *
	 * @param Node $node
	 *
	 * @return null
	 */
	public function enterNode( Node $node ) {
		// Track namespace and import aliases.
		if ( $node instanceof Node\Stmt\Namespace_ ) {
			$this->namespace = $node->name ? $node->name->toString() : 'global';
			$this->aliases   = array();
		} elseif ( $node instanceof Node\Stmt\Use_ ) {
			foreach ( $node->uses as $use ) {
				$this->aliases[ $use->getAlias()->toString() ] = $use->name->toString();
			}
		}

		// Maintain the scope stack so calls are attributed to the right element.
		if ( $node instanceof Node\Stmt\Function_
			|| $node instanceof Node\Stmt\ClassMethod
			|| $node instanceof Node\Stmt\Class_ ) {
			$this->location[] = $node;
		}

		// Record function/method/hook usage.
		if ( $node instanceof Node\Expr\FuncCall ) {
			$this->add_use( 'functions', new Function_Call_Reflector( $node ) );

			if ( $this->is_filter( $node ) ) {
				if ( $this->last_doc && null === $node->getDocComment() ) {
					$node->setAttribute( 'comments', array( $this->last_doc ) );
					$this->last_doc = null;
				}

				$this->add_use( 'hooks', new Hook_Reflector( $node, $this->namespace, $this->aliases ) );
			}
		} elseif ( $node instanceof Node\Expr\MethodCall ) {
			$this->add_use( 'methods', new Method_Call_Reflector( $node ) );
		} elseif ( $node instanceof Node\Expr\StaticCall ) {
			$this->add_use( 'methods', new Static_Method_Call_Reflector( $node ) );
		} elseif ( $node instanceof Node\Expr\New_ ) {
			$this->add_use( 'methods', new Method_Call_Reflector( $node ) );
		}

		// Record file includes and constants (define() and the const keyword),
		// captured wherever they appear — matching the legacy FileReflector output.
		if ( $node instanceof Node\Expr\Include_ ) {
			$this->add_include( $node );
		} elseif ( $node instanceof Node\Expr\FuncCall && $this->is_define( $node ) ) {
			$this->add_define( $node );
		} elseif ( $node instanceof Node\Stmt\Const_ ) {
			foreach ( $node->consts as $const ) {
				$this->constants[] = new Constant_Reflector(
					$const->name->toString(),
					$const->getStartLine(),
					Reflector_Helpers::default_value( $const->value )
				);
			}
		}

		// Carry a docblock from a non-documentable node to the next hook.
		if ( ! $this->is_node_documentable( $node )
			&& ! ( $node instanceof Node\Name )
			&& null !== $node->getDocComment() ) {
			$this->last_doc = $node->getDocComment();
		}

		return null;
	}

	/**
	 * Collect structural elements as the traverser leaves each node.
	 *
	 * Functions and classes are recorded on leave (not enter) so that nested
	 * functions are listed before their enclosing function — matching the order
	 * the legacy parser produced. Leaving a class is also when its methods' calls
	 * learn which class they were made in (for $this/self/parent resolution).
	 *
	 * @param Node $node
	 *
	 * @return null
	 */
	public function leaveNode( Node $node ) {
		if ( $node instanceof Node\Stmt\Function_ ) {
			$this->functions[] = new Function_Reflector( $node, $this->namespace, $this->aliases );
		} elseif ( $node instanceof Node\Stmt\Class_ && null !== $node->name ) {
			$class = new Class_Reflector( $node, $this->namespace, $this->aliases );
			$this->set_called_in_class( $node, $class );
			$this->classes[] = $class;
		}

		if ( ! empty( $this->location ) && end( $this->location ) === $node ) {
			array_pop( $this->location );
		}

		return null;
	}

	/**
	 * Append a used element to the current scope (file, function, or method).
	 *
	 * Method/function scope uses are stored on the scope node as an attribute, so
	 * the matching reflector picks them up when it is built.
	 *
	 * @param string $type Use type: functions, methods, or hooks.
	 * @param object $item The reflector for the used element.
	 */
	protected function add_use( $type, $item ) {
		if ( empty( $this->location ) ) {
			$this->uses[ $type ][] = $item;

			return;
		}

		$scope           = end( $this->location );
		$uses            = $scope->getAttribute( 'wp_parser_uses', array() );
		$uses[ $type ][] = $item;
		$scope->setAttribute( 'wp_parser_uses', $uses );
	}

	/**
	 * Record an include/require statement with the legacy "Include"/"Require" (Once)
	 * type label. The name is the literal path when written as a plain string, or ''
	 * for a computed expression (e.g. dirname( __FILE__ ) . '/bootstrap.php').
	 *
	 * @param Node\Expr\Include_ $node
	 */
	protected function add_include( Node\Expr\Include_ $node ) {
		static $types = array(
			Node\Expr\Include_::TYPE_INCLUDE      => 'Include',
			Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'Include Once',
			Node\Expr\Include_::TYPE_REQUIRE      => 'Require',
			Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'Require Once',
		);

		$this->includes[] = new Include_Reflector(
			$node->expr instanceof Node\Scalar\String_ ? $node->expr->value : '',
			$node->getStartLine(),
			isset( $types[ $node->type ] ) ? $types[ $node->type ] : ''
		);
	}

	/**
	 * Whether a function call is a call to define().
	 *
	 * @param Node\Expr\FuncCall $node
	 * @return bool
	 */
	protected function is_define( Node\Expr\FuncCall $node ) {
		return $node->name instanceof Node\Name
			&& 'define' === strtolower( ltrim( $node->name->toString(), '\\' ) );
	}

	/**
	 * Record a constant declared via define( 'NAME', value ). Only a string-literal
	 * name is recorded, matching the legacy parser (a computed name has no short name).
	 *
	 * @param Node\Expr\FuncCall $node
	 */
	protected function add_define( Node\Expr\FuncCall $node ) {
		$args = $node->getArgs();

		if ( ! isset( $args[0], $args[1] ) || ! ( $args[0]->value instanceof Node\Scalar\String_ ) ) {
			return;
		}

		$this->constants[] = new Constant_Reflector(
			$args[0]->value->value,
			$node->getStartLine(),
			Reflector_Helpers::default_value( $args[1]->value )
		);
	}

	/**
	 * Tell each method call within a class which class it was made in.
	 *
	 * @param Node\Stmt\Class_ $node
	 * @param Class_Reflector  $class
	 */
	protected function set_called_in_class( Node\Stmt\Class_ $node, Class_Reflector $class ) {
		foreach ( $node->getMethods() as $method ) {
			$uses = $method->getAttribute( 'wp_parser_uses' );

			if ( empty( $uses['methods'] ) ) {
				continue;
			}

			foreach ( $uses['methods'] as $call ) {
				if ( $call instanceof Method_Call_Reflector ) {
					$call->set_class( $class );
				}
			}
		}
	}

	/**
	 * Whether a function call is a WordPress hook declaration.
	 *
	 * @param Node\Expr\FuncCall $node
	 *
	 * @return bool
	 */
	protected function is_filter( Node\Expr\FuncCall $node ) {
		if ( ! ( $node->name instanceof Node\Name ) ) {
			return false;
		}

		$functions = array(
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		);

		return in_array( (string) $node->name, $functions, true );
	}

	/**
	 * Whether a node is a documentable structural element (or a hook).
	 *
	 * @param Node $node
	 *
	 * @return bool
	 */
	protected function is_node_documentable( Node $node ) {
		return $node instanceof Node\Stmt\Function_
			|| $node instanceof Node\Stmt\ClassLike
			|| $node instanceof Node\Stmt\ClassMethod
			|| $node instanceof Node\Stmt\Property
			|| $node instanceof Node\Stmt\ClassConst
			|| $node instanceof Node\Stmt\Const_
			|| ( $node instanceof Node\Expr\FuncCall && $this->is_filter( $node ) );
	}

	/**
	 * The file-level docblock, or null when the file has none.
	 *
	 * @return Docblock_Adapter|null
	 */
	public function getDocBlock() {
		return $this->file_docblock;
	}

	/**
	 * Detect the file-level docblock.
	 *
	 * The file docblock is the first docblock in the file, unless that docblock
	 * directly documents the first structural element (function/class/...). So a
	 * lone docblock before a `function`/`class` belongs to that element, but a
	 * docblock before a non-structural statement (or a second docblock preceding
	 * the first element) floats to the file.
	 *
	 * @param Node[] $stmts
	 *
	 * @return Docblock_Adapter|null
	 */
	protected function detect_file_docblock( array $stmts ) {
		if ( empty( $stmts ) ) {
			return null;
		}

		$first = $stmts[0];

		// A docblock attached before a `namespace` declaration is a file docblock.
		if ( $first instanceof Node\Stmt\Namespace_ ) {
			$docs = $this->doc_comments( $first );
			if ( ! empty( $docs ) ) {
				return Docblock_Adapter::from_text( $docs[0]->getText(), 'global', array() );
			}

			if ( empty( $first->stmts ) ) {
				return null;
			}

			$first = $first->stmts[0];
		}

		$docs = $this->doc_comments( $first );

		// Two docblocks before the first element: the first one is the file docblock.
		if ( count( $docs ) >= 2 ) {
			return Docblock_Adapter::from_text( $docs[0]->getText(), 'global', array() );
		}

		// A single docblock floats to the file only when it is attached to the open
		// tag (no blank line after `<?php`) AND the first statement does not claim it.
		// A blank line, or a claiming statement (function/class/const, hook, define(),
		// or include/require), makes the docblock belong to the code instead — matching
		// the legacy parser.
		if ( 1 === count( $docs ) && ! $this->claims_docblock( $first ) && $docs[0]->getStartLine() <= 2 ) {
			return Docblock_Adapter::from_text( $docs[0]->getText(), 'global', array() );
		}

		return null;
	}

	/**
	 * The Doc comments attached to a node, in source order.
	 *
	 * @param Node $node
	 *
	 * @return Doc[]
	 */
	protected function doc_comments( Node $node ) {
		$docs = array();
		foreach ( $node->getComments() as $comment ) {
			if ( $comment instanceof Doc ) {
				$docs[] = $comment;
			}
		}

		return $docs;
	}

	/**
	 * Whether the first statement in a file claims a preceding docblock as its own,
	 * keeping that docblock from floating up to become the file docblock. The legacy
	 * parser reflects — and so attaches the docblock to — structural elements
	 * (function/class/const), hooks, define() constants, and include/require, but
	 * not plain function calls or assignments.
	 *
	 * @param Node $node
	 *
	 * @return bool
	 */
	protected function claims_docblock( Node $node ) {
		if ( $node instanceof Node\Stmt\Function_
			|| $node instanceof Node\Stmt\ClassLike
			|| $node instanceof Node\Stmt\Const_ ) {
			return true;
		}

		if ( $node instanceof Node\Stmt\Expression ) {
			$expr = $node->expr;

			return $expr instanceof Node\Expr\Include_
				|| ( $expr instanceof Node\Expr\FuncCall
					&& ( $this->is_filter( $expr ) || $this->is_define( $expr ) ) );
		}

		return false;
	}

	/**
	 * @return Function_Reflector[]
	 */
	public function getFunctions() {
		return $this->functions;
	}

	/**
	 * @return Class_Reflector[]
	 */
	public function getClasses() {
		return $this->classes;
	}

	/**
	 * @return array
	 */
	public function getConstants() {
		return $this->constants;
	}

	/**
	 * @return array
	 */
	public function getIncludes() {
		return $this->includes;
	}
}

/**
 * Replaces class-position names (static calls, `new`, class-const/static-property
 * fetches, instanceof) with their resolved fully-qualified form, so they render
 * with a leading backslash when pretty printed — while leaving function/constant
 * names untouched. This reproduces the legacy php-parser 1 name-resolution output.
 */
class Class_Name_Resolver extends NodeVisitorAbstract {

	public function enterNode( Node $node ) {
		$is_class_ref = $node instanceof Node\Expr\StaticCall
			|| $node instanceof Node\Expr\New_
			|| $node instanceof Node\Expr\ClassConstFetch
			|| $node instanceof Node\Expr\StaticPropertyFetch
			|| $node instanceof Node\Expr\Instanceof_;

		if ( $is_class_ref && $node->class instanceof Node\Name ) {
			$resolved = $node->class->getAttribute( 'resolvedName' );
			if ( null !== $resolved ) {
				$node->class = $resolved;
			}
		}

		return null;
	}
}
