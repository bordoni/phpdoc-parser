<?php
/**
 * Closures and callables.
 */

add_action( 'init', function () {
	do_something();
	$obj->method();
} );

$callback = function ( $arg ) use ( $x ) {
	return helper( $arg );
};

function with_closure() {
	$fn = function () {
		inner_call();
	};

	return $fn;
}
