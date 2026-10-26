<?php

namespace Vatright\Registry;

class WpHttpTransport implements HttpTransport {

	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
		$timeout = (int) ceil( min( 5.0, max( 1.0, (float) apply_filters( 'vatright_registry_timeout', (float) $timeout ) ) ) );

		// ec.europa.eu cuts compressed answers short through the WordPress HTTP API (cURL error 56).
		$args = array(
			'method'  => $method,
			'headers' => $headers + array( 'Accept-Encoding' => 'identity' ),
			'timeout' => $timeout,
		);

		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => '',
		);
	}
}
