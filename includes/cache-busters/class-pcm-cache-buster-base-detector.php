<?php
/**
 * Cache Busters - Base detector helpers.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base detector helpers.
 */
abstract class PCM_Cache_Buster_Base_Detector implements PCM_Cache_Buster_Detector_Interface {
	/**
	 * @param string $header_value Header value.
	 *
	 * @return array<int, string>
	 */
	protected function split_header_csv( string $header_value ): array {
		if ( '' === $header_value ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', $header_value ) );

		return array_filter( $parts );
	}

	/**
	 * @param string $cookie_line Set-Cookie value.
	 *
	 * @return string
	 */
	protected function get_cookie_name_only( string $cookie_line ): string {
		$first_part = strtok( (string) $cookie_line, ';' );
		$pair       = explode( '=', (string) $first_part, 2 );

		return sanitize_key( trim( $pair[0] ) );
	}

	/**
	 * @param string $url URL.
	 *
	 * @return string
	 */
	protected function normalize_url_for_report( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return esc_url_raw( $url );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . $path );
	}
}
