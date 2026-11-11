<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter

$GLOBALS['vatright_transients'] = array();

function vatright_test_reset_transients(): void {
	$GLOBALS['vatright_transients'] = array();
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['vatright_transients'][ $key ] ?? false;
	}

	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['vatright_transients'][ $key ] = $value;
		return true;
	}

	function delete_transient( $key ) {
		unset( $GLOBALS['vatright_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $GLOBALS['vatright_filter_overrides'][ $hook ] ?? $value;
	}

	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}

	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}
