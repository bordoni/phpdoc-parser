<?php
/**
 * A reflection of a method-call (or `new`) expression, on nikic/php-parser 5.
 *
 * @package WP_Parser
 */

namespace WP_Parser;

use PhpParser\Node;

class Method_Call_Reflector {

	/** @var Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\New_ */
	protected $node;

	/**
	 * The class this call was made in, for resolving $this/self/parent.
	 *
	 * @var Class_Reflector|false
	 */
	protected $called_in_class = false;

	public function __construct( $node ) {
		$this->node = $node;
	}

	/**
	 * Returns the name for this Reflector instance.
	 *
	 * @return string[] Index 0 is the calling instance/class, 1 is the method name.
	 */
	public function getName() {
		if ( $this->node instanceof Node\Expr\New_ ) {
			$name   = '__construct';
			$caller = $this->node->class;
		} else {
			$name   = $this->node->name instanceof Node\Identifier ? $this->node->name->toString() : '';
			$caller = $this->node->var;
		}

		return array( $this->resolve_caller( $caller ), $name );
	}

	/**
	 * Resolve a caller node to its legacy string form.
	 *
	 * @param Node $caller
	 *
	 * @return string
	 */
	protected function resolve_caller( $caller ) {
		if ( $caller instanceof Node\Name ) {
			$lower = strtolower( $caller->toString() );

			if ( 'self' === $lower || 'parent' === $lower || 'static' === $lower ) {
				return $this->map_class( $this->resolve_relative_name( $caller->toString() ) );
			}

			return $this->map_class( Reflector_Helpers::class_name( $caller ) );
		}

		if ( $caller instanceof Node\Expr ) {
			return $this->map_class( $this->resolve_relative_name( Reflector_Helpers::pretty_print_expr( $caller ) ) );
		}

		return $this->map_class( (string) $caller );
	}

	/**
	 * Apply the WordPress global-variable => class mapping.
	 *
	 * @param string $caller
	 *
	 * @return string
	 */
	protected function map_class( $caller ) {
		$mapping = $this->get_class_mapping();

		return array_key_exists( $caller, $mapping ) ? $mapping[ $caller ] : $caller;
	}

	/**
	 * Set the class that this method was called within.
	 *
	 * @param Class_Reflector $class
	 */
	public function set_class( Class_Reflector $class ) {
		$this->called_in_class = $class;
	}

	/**
	 * Whether this is a static call.
	 *
	 * @return bool
	 */
	public function isStatic() {
		return false;
	}

	public function getLineNumber() {
		return $this->node->getStartLine();
	}

	public function getNode() {
		return $this->node;
	}

	/**
	 * Resolve $this/self/parent against the enclosing class.
	 *
	 * @param string $class
	 *
	 * @return string
	 */
	protected function resolve_relative_name( $class ) {
		if ( ! $this->called_in_class ) {
			return $class;
		}

		switch ( $class ) {
			case '$this':
			case 'self':
				$namespace = (string) $this->called_in_class->getNamespace();
				$namespace = ( 'global' !== $namespace ) ? $namespace . '\\' : '';

				return '\\' . $namespace . $this->called_in_class->getShortName();

			case 'parent':
				return $this->called_in_class->getParentClass();
		}

		return $class;
	}

	/**
	 * A mapping from common WordPress global variable names to their class.
	 *
	 * @return array
	 */
	protected function get_class_mapping() {
		$wp_globals = array(
			'authordata'         => 'WP_User',
			'custom_background'  => 'Custom_Background',
			'custom_image_header' => 'Custom_Image_Header',
			'phpmailer'          => 'PHPMailer',
			'post'               => 'WP_Post',
			'userdata'           => 'WP_User',
			'wp'                 => 'WP',
			'wp_admin_bar'       => 'WP_Admin_Bar',
			'wp_customize'       => 'WP_Customize_Manager',
			'wp_embed'           => 'WP_Embed',
			'wp_filesystem'      => 'WP_Filesystem',
			'wp_hasher'          => 'PasswordHash',
			'wp_json'            => 'Services_JSON',
			'wp_list_table'      => 'WP_List_Table',
			'wp_locale'          => 'WP_Locale',
			'wp_object_cache'    => 'WP_Object_Cache',
			'wp_query'           => 'WP_Query',
			'wp_rewrite'         => 'WP_Rewrite',
			'wp_roles'           => 'WP_Roles',
			'wp_scripts'         => 'WP_Scripts',
			'wp_styles'          => 'WP_Styles',
			'wp_the_query'       => 'WP_Query',
			'wp_widget_factory'  => 'WP_Widget_Factory',
			'wp_xmlrpc_server'   => 'wp_xmlrpc_server',
			'wpdb'               => 'wpdb',
		);

		$wp_functions = array(
			'get_current_screen()' => 'WP_Screen',
			'_get_list_table()'    => 'WP_List_Table',
			'wp_get_theme()'       => 'WP_Theme',
		);

		return array_merge( $wp_globals, $wp_functions );
	}
}
