<?php
/**
 * File with constants and includes.
 */

require_once dirname( __FILE__ ) . '/bootstrap.php';
include 'helper.php';
require ABSPATH . 'wp-load.php';
include_once 'once.php';

define( 'MY_CONSTANT', 42 );
define( 'MY_STRING', 'hello' );
const MY_CONST_KEYWORD = 'world';

/**
 * A function with an include and a define inside.
 */
function uses_constant() {
	include 'inside-function.php';
	define( 'INSIDE_FUNC', true );
	return MY_CONSTANT;
}

/**
 * A class with a constant.
 */
class Has_Const {
	const CLASS_CONST = 'cls';
}
