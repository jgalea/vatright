<?php
// phpcs:ignoreFile -- declarations for static analysis only.

class WP_CLI {

	/**
	 * @param callable|object|string $callable
	 * @param array<string,mixed>    $args
	 */
	public static function add_command( string $name, $callable, array $args = array() ): bool {}

	public static function log( string $message ): void {}

	public static function success( string $message ): void {}

	/**
	 * @return never
	 */
	public static function error( string $message ) {}
}
