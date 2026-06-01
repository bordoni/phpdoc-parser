<?php
/**
 * Namespaced name-resolution fixture.
 */

namespace My\Plugin;

use Other\Thing;
use Some\Long\ClassName as Aliased;
use function Other\helper;

class Widget extends \WP_Widget implements Thing {
	/**
	 * Render.
	 *
	 * @param Thing $t A thing.
	 * @return Aliased
	 */
	public function render( Thing $t ) {
		$x = new Aliased();
		$y = Thing::make();
		self::helper();
		parent::widget();
		\do_action( 'my_hook' );
		helper();
		return new \DateTime();
	}
}

function namespaced_func( Thing $t ) {
	return Aliased::create();
}
