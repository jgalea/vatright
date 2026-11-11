<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter,Universal.NamingConventions.NoReservedKeywordParameterNames

require_once __DIR__ . '/WpOptionStubs.php';
require_once __DIR__ . '/TransientStub.php';

$GLOBALS['vatright_test_multisite']    = false;
$GLOBALS['vatright_test_site_options'] = array();

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return ! empty( $GLOBALS['vatright_test_multisite'] );
	}

	function get_site_option( $name, $default = false ) {
		return $GLOBALS['vatright_test_site_options'][ $name ] ?? $default;
	}

	function wp_roles() {
		return new class() {
			public function is_role( $role ) {
				return in_array( $role, array( 'subscriber', 'customer', 'administrator' ), true );
			}
		};
	}
}
