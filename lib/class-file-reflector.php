<?php

namespace WP_Parser;

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
 * methods and properties) that runner.php exports, while keeping the exported
 * array shape identical.
 *
 * Hook detection (do_action/apply_filters) and the per-element $uses list are
 * added in a later stage; for now $uses stays empty.
 */
class File_Reflector extends NodeVisitorAbstract {

	/**
	 * Elements used in file scope, indexed by element type (hooks, functions, methods).
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

		$traverser = new NodeTraverser();
		// replaceNodes:false keeps the original Name nodes and attaches resolved
		// names as attributes — the v5 analog of v1's $node->namespacedName.
		$traverser->addVisitor( new NameResolver( null, array( 'replaceNodes' => false ) ) );
		$traverser->addVisitor( $this );
		$traverser->traverse( $stmts );
	}

	/**
	 * Track the current namespace and import aliases as we enter nodes.
	 *
	 * @param Node $node
	 *
	 * @return null
	 */
	public function enterNode( Node $node ) {
		if ( $node instanceof Node\Stmt\Namespace_ ) {
			$this->namespace = $node->name ? $node->name->toString() : 'global';
			$this->aliases   = array();
		} elseif ( $node instanceof Node\Stmt\Use_ ) {
			foreach ( $node->uses as $use ) {
				$this->aliases[ $use->getAlias()->toString() ] = $use->name->toString();
			}
		}

		return null;
	}

	/**
	 * Collect structural elements as the traverser leaves each node.
	 *
	 * Functions and classes are recorded on leave (not enter) so that nested
	 * functions are listed before their enclosing function — matching the order
	 * the legacy parser produced.
	 *
	 * @param Node $node
	 *
	 * @return null
	 */
	public function leaveNode( Node $node ) {
		if ( $node instanceof Node\Stmt\Function_ ) {
			$this->functions[] = new Function_Reflector( $node, $this->namespace, $this->aliases );
		} elseif ( $node instanceof Node\Stmt\Class_ && null !== $node->name ) {
			$this->classes[] = new Class_Reflector( $node, $this->namespace, $this->aliases );
		}

		return null;
	}

	/**
	 * The file-level docblock. Added with the docblock adapter (Stage 5).
	 *
	 * @return null
	 */
	public function getDocBlock() {
		return null;
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
