<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.CodeAnalysis.UnusedFunctionParameter

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		return new WC_Order();
	}

	function current_user_can( $capability, $id = 0 ) {
		return ! in_array( $id, $GLOBALS['vatright_test_denied'] ?? array(), true );
	}

	function user_can( $user, $capability, $id = 0 ) {
		return ! in_array( $id, $GLOBALS['vatright_test_denied'] ?? array(), true );
	}

	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
		$GLOBALS['vatright_test_async'][] = array( $hook, $args, $group );
		return 1;
	}

	function get_current_user_id() {
		return 1;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args );
	}

	function wc_get_logger() {
		return new class() {
			public function warning( $message, $context = array() ) {}
		};
	}

	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return (object) array(
			'countries' => new class() {
				public function get_countries() {
					return array(
						'DE' => 'Germany',
						'US' => 'United States',
					);
				}
			},
		);
	}
}
