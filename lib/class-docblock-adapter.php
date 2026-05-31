<?php
/**
 * Adapts phpdocumentor/reflection-docblock 6 to the legacy reflection-docblock 2
 * surface that runner.php::export_docblock() consumes.
 *
 * export_docblock() probes each tag with method_exists() for getTypes/getLink/
 * getVariableName/getReference/getVersion, so the tag adapters are split into
 * distinct classes that expose only the methods their tag kind should — exactly
 * mirroring the old class hierarchy. The result keeps the exported docblock array
 * ({description, long_description, tags[]}) identical.
 *
 * @package WP_Parser
 */

namespace WP_Parser;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlock\Tags;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Context;

/**
 * Wraps a parsed reflection-docblock DocBlock.
 */
class Docblock_Adapter {

	/** @var DocBlock */
	protected $docblock;

	/** @var Context|null */
	protected $context;

	public function __construct( DocBlock $docblock, Context $context = null ) {
		$this->docblock = $docblock;
		$this->context  = $context;
	}

	/**
	 * Build an adapter from a node's doc comment, or null when there is none.
	 *
	 * @param \PhpParser\Node $node      Node carrying the doc comment.
	 * @param string          $namespace Namespace to resolve types against ('global' => root).
	 * @param array           $aliases   Import aliases (alias => FQN).
	 *
	 * @return Docblock_Adapter|null
	 */
	public static function from_node( $node, $namespace, array $aliases ) {
		$comment = $node->getDocComment();
		if ( null === $comment ) {
			return null;
		}

		return self::from_text( $comment->getText(), $namespace, $aliases );
	}

	/**
	 * Build an adapter from raw docblock text.
	 *
	 * @param string $text
	 * @param string $namespace
	 * @param array  $aliases
	 *
	 * @return Docblock_Adapter|null
	 */
	public static function from_text( $text, $namespace, array $aliases ) {
		static $factory = null;
		if ( null === $factory ) {
			$factory = DocBlockFactory::createInstance();
		}

		$context = new Context( 'global' === $namespace ? '' : $namespace, $aliases );

		try {
			$docblock = $factory->create( $text, $context );
		} catch ( \Throwable $e ) {
			return null;
		}

		return new self( $docblock, $context );
	}

	public function getShortDescription() {
		return $this->docblock->getSummary();
	}

	public function getLongDescription() {
		return new Description_Adapter( $this->docblock->getDescription() );
	}

	/**
	 * @return object[] Tag adapters.
	 */
	public function getTags() {
		$tags = array();
		foreach ( $this->docblock->getTags() as $tag ) {
			$tags[] = $this->adapt_tag( $tag );
		}

		return $tags;
	}

	/**
	 * Map a reflection-docblock tag to the adapter exposing the right legacy methods.
	 *
	 * @param Tags\BaseTag $tag
	 *
	 * @return object
	 */
	protected function adapt_tag( $tag ) {
		$name = $tag->getName();

		// Tags reflection-docblock can't parse strictly (e.g. @see with a non-FQSEN
		// reference, or @param with a $this variable) come back as InvalidTag;
		// reconstruct from the raw body to match the legacy loose parsing.
		if ( $tag instanceof Tags\InvalidTag ) {
			return $this->adapt_invalid_tag( $tag, $name );
		}

		$description = self::render_description( method_exists( $tag, 'getDescription' ) ? $tag->getDescription() : null );

		// @param and @var: type + variable name.
		if ( $tag instanceof Tags\Param || $tag instanceof Tags\Var_ ) {
			$variable = $tag->getVariableName();

			return new Param_Tag_Adapter(
				$name,
				$description,
				self::type_to_legacy_strings( $tag->getType() ),
				$variable ? '$' . $variable : ''
			);
		}

		// @return and other typed tags: type, no variable.
		if ( $tag instanceof Tags\TagWithType ) {
			return new Typed_Tag_Adapter( $name, $description, self::type_to_legacy_strings( $tag->getType() ) );
		}

		// @since and @deprecated: version becomes the content.
		if ( $tag instanceof Tags\Since || $tag instanceof Tags\Deprecated ) {
			return new Version_Tag_Adapter( $name, $description, (string) $tag->getVersion() );
		}

		// @see: a reference.
		if ( $tag instanceof Tags\See ) {
			return new See_Tag_Adapter( $name, $description, (string) $tag->getReference() );
		}

		// @link: a URL. When no description is given the URL itself is the content.
		if ( $tag instanceof Tags\Link ) {
			$link = $tag->getLink();

			return new Link_Tag_Adapter( $name, '' !== $description ? $description : $link, $link );
		}

		return new Generic_Tag_Adapter( $name, $description );
	}

	/**
	 * Reconstruct a tag reflection-docblock flagged invalid, recovering its body
	 * from the rendered "@name body" string.
	 *
	 * @param Tags\InvalidTag $tag
	 * @param string          $name
	 *
	 * @return object
	 */
	protected function adapt_invalid_tag( $tag, $name ) {
		$body = preg_replace( '/^@' . preg_quote( $name, '/' ) . '\s*/', '', $tag->render() );

		// @see / @uses: the first token is the reference, the remainder the description.
		if ( 'see' === $name || 'uses' === $name ) {
			$parts = preg_split( '/\s+/', $body, 2 );

			return new See_Tag_Adapter(
				$name,
				isset( $parts[1] ) ? $parts[1] : '',
				isset( $parts[0] ) ? $parts[0] : ''
			);
		}

		// @param / @var with a variable reflection-docblock rejects (e.g. $this):
		// "<type> <$variable> <description>".
		if ( 'param' === $name || 'var' === $name || 'property' === $name ) {
			$parts = preg_split( '/\s+/', $body, 3 );

			if ( isset( $parts[1] ) && 0 === strpos( $parts[1], '$' ) ) {
				return new Param_Tag_Adapter(
					$name,
					isset( $parts[2] ) ? $parts[2] : '',
					$this->resolve_type_string( $parts[0] ),
					$parts[1]
				);
			}
		}

		return new Generic_Tag_Adapter( $name, $body );
	}

	/**
	 * Resolve a written type string to the legacy array of type strings, using the
	 * docblock context (so class names get the leading-backslash FQN form).
	 *
	 * @param string $type_string
	 *
	 * @return string[]
	 */
	protected function resolve_type_string( $type_string ) {
		static $resolver = null;
		if ( null === $resolver ) {
			$resolver = new TypeResolver();
		}

		try {
			return self::type_to_legacy_strings( $resolver->resolve( $type_string, $this->context ) );
		} catch ( \Throwable $e ) {
			return array( $type_string );
		}
	}

	/**
	 * Render a tag's description object to a string.
	 *
	 * @param mixed $description
	 *
	 * @return string
	 */
	protected static function render_description( $description ) {
		return $description ? $description->render() : '';
	}

	/**
	 * Convert a type-resolver Type to the legacy array of type strings.
	 *
	 * Compound (union) types are split into their parts; everything else renders
	 * to a single string. Object types resolve to a leading-backslash FQN via the
	 * docblock Context (e.g. WP_Post => \WP_Post).
	 *
	 * @param Type|null $type
	 *
	 * @return string[]
	 */
	public static function type_to_legacy_strings( $type ) {
		if ( null === $type ) {
			return array();
		}

		if ( $type instanceof Compound ) {
			$types = array();
			foreach ( $type as $part ) {
				$types[] = (string) $part;
			}

			return $types;
		}

		return array( (string) $type );
	}
}

/**
 * Wraps a reflection-docblock Description, exposing the legacy getFormattedContents().
 */
class Description_Adapter {

	/** @var DocBlock\Description */
	protected $description;

	public function __construct( $description ) {
		$this->description = $description;
	}

	/**
	 * The long description as formatted HTML.
	 *
	 * The legacy reflection-docblock ran the body through a Markdown block parser
	 * (wrapping paragraphs in <p>); export_docblock() then collapses the soft line
	 * breaks via fix_newlines(). Parsedown reproduces that output.
	 *
	 * @return string
	 */
	public function getFormattedContents() {
		$text = $this->description->render();

		if ( '' !== $text && class_exists( 'Parsedown' ) ) {
			$text = \Parsedown::instance()->text( $text );
		}

		return $text;
	}
}

/**
 * Base tag: name + description only (e.g. @access, @global, @package).
 */
class Generic_Tag_Adapter {

	protected $name;
	protected $description;

	public function __construct( $name, $description ) {
		$this->name        = $name;
		$this->description  = $description;
	}

	public function getName() {
		return $this->name;
	}

	public function getDescription() {
		return $this->description;
	}
}

/**
 * A tag carrying types but no variable (e.g. @return).
 */
class Typed_Tag_Adapter extends Generic_Tag_Adapter {

	protected $types;

	public function __construct( $name, $description, array $types ) {
		parent::__construct( $name, $description );
		$this->types = $types;
	}

	public function getTypes() {
		return $this->types;
	}
}

/**
 * A tag carrying types and a variable name (e.g. @param, @var).
 */
class Param_Tag_Adapter extends Typed_Tag_Adapter {

	protected $variable;

	public function __construct( $name, $description, array $types, $variable ) {
		parent::__construct( $name, $description, $types );
		$this->variable = $variable;
	}

	public function getVariableName() {
		return $this->variable;
	}
}

/**
 * A versioned tag (e.g. @since, @deprecated).
 */
class Version_Tag_Adapter extends Generic_Tag_Adapter {

	protected $version;

	public function __construct( $name, $description, $version ) {
		parent::__construct( $name, $description );
		$this->version = $version;
	}

	public function getVersion() {
		return $this->version;
	}
}

/**
 * A reference tag (e.g. @see).
 */
class See_Tag_Adapter extends Generic_Tag_Adapter {

	protected $reference;

	public function __construct( $name, $description, $reference ) {
		parent::__construct( $name, $description );
		$this->reference = $reference;
	}

	public function getReference() {
		return $this->reference;
	}
}

/**
 * A link tag (e.g. @link).
 */
class Link_Tag_Adapter extends Generic_Tag_Adapter {

	protected $link;

	public function __construct( $name, $description, $link ) {
		parent::__construct( $name, $description );
		$this->link = $link;
	}

	public function getLink() {
		return $this->link;
	}
}
