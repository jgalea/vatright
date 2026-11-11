<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter,Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['vatright_test_options'][ $name ] ?? $default;
	}

	function update_option( $name, $value ) {
		$GLOBALS['vatright_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
