<?php
/**
 * Comprehensive hook variants.
 */

do_action( 'simple_action' );
do_action( 'action_with_args', $a, $b );
do_action_ref_array( 'action_ref', array( &$x ) );
do_action_deprecated( 'deprecated_action', array( $y ), '5.0.0', 'new_action' );

apply_filters( 'simple_filter', $value );
apply_filters( 'filter_with_args', $value, $a, $b );
apply_filters_ref_array( 'filter_ref', array( $value, &$x ) );
apply_filters_deprecated( 'deprecated_filter', array( $value ), '5.0.0', 'new_filter' );

$result = apply_filters( 'assigned_filter', $default );

do_action( "interpolated_{$dynamic}_action" );
do_action( $variable_hook );
do_action( 'dynamic_' . $suffix );
