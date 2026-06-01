<?php
/**
 * Class features fixture.
 */

abstract class Base_Thing extends Parent_Thing implements Interface_One, Interface_Two {
	const A_CONST = 1;

	public static $static_prop = array();
	protected $untyped_prop;
	private $a, $b = 'x';

	/**
	 * Must be implemented.
	 */
	abstract public function must_implement( $arg );

	/**
	 * Cannot be overridden.
	 */
	final public function cannot_override() {}

	public static function static_method() {}
}

final class Final_Thing {
	use Some_Trait;
}

interface My_Interface extends Base_Interface {
	public function contract();
}

trait My_Trait {
	public function shared() {}
}
