<?php
/**
 * Cacheability Advisor - Probe client.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Probe client (A1.2).
 */
class PCM_Cacheability_Probe_Client {
	/**
	 * Probe URL and return normalized metadata.
	 *
	 * @param string $url URL.
	 *
	 * @return array
	 */
	public function probe( string $url ): array {
		$url = esc_url_raw( $url );

		$args = array(
			'timeout'     => 8,
			'redirection' => 3,
			'headers'     => array(
				'User-Agent' => 'Pressable-Cache-Advisor/1.0',
			),
			'cookies'     => array(),
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		);

		if ( function_exists( 'curl_init' ) ) {
			$curl_probe = $this->probe_with_curl( $url, $args );
			if ( is_array( $curl_probe ) && empty( $curl_probe['is_error'] ) ) {
				return $curl_probe;
			}
		}

		$start_time = microtime( true );
		$attempts   = 2;
		$response   = null;
		for ( $i = 0; $i < $attempts; $i++ ) {
			$response = wp_remote_get( $url, $args );
			if ( ! is_wp_error( $response ) ) {
				break;
			}
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'url'              => $url,
				'effective_url'    => $url,
				'redirect_chain'   => array(),
				'status_code'      => 0,
				'headers'          => array(),
				'timing'           => array(
					'total_time'         => $this->format_duration( microtime( true ) - $start_time ),
					'namelookup_time'    => null,
					'connect_time'       => null,
					'starttransfer_time' => null,
				),
				'response_size'    => 0,
				'platform_headers' => array(),
				'error_code'       => $response->get_error_code(),
				'error_message'    => $response->get_error_message(),
				'is_error'         => true,
			);
		}

		$headers            = wp_remote_retrieve_headers( $response );
		$normalized_headers = $this->normalize_headers( $headers );

		$history = $this->extract_redirect_chain( $response );
		$body    = wp_remote_retrieve_body( $response );

		return array(
			'url'              => $url,
			'effective_url'    => ! empty( $history ) ? end( $history ) : $url,
			'redirect_chain'   => $history,
			'status_code'      => absint( wp_remote_retrieve_response_code( $response ) ),
			'headers'          => $normalized_headers,
			'timing'           => array(
				'total_time'         => $this->format_duration( microtime( true ) - $start_time ),
				'namelookup_time'    => null,
				'connect_time'       => null,
				'starttransfer_time' => null,
			),
			'response_size'    => $this->resolve_response_size( $body, $normalized_headers ),
			'platform_headers' => $this->collect_platform_headers( $normalized_headers ),
			'error_code'       => '',
			'error_message'    => '',
			'is_error'         => false,
		);
	}

	/**
	 * Probe URL using cURL to capture richer timing metadata.
	 *
	 * @param string $url URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array|null
	 */
	protected function probe_with_curl( string $url, array $args ): ?array {
		$timeout = isset( $args['timeout'] ) ? max( 1, absint( $args['timeout'] ) ) : 8;

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- cURL fallback captures redirect and transfer timing metadata not exposed consistently via WP_Http.
		$ch = curl_init();
		if ( ! $ch ) {
			return null;
		}

		$header_blocks   = array();
		$current_headers = array();
		$status_line     = '';

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_MAXREDIRS, isset( $args['redirection'] ) ? absint( $args['redirection'] ) : 3 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			function ( $curl, $line ) use ( &$header_blocks, &$current_headers, &$status_line ) {
				$trimmed = trim( $line );
				if ( '' === $trimmed ) {
					if ( ! empty( $status_line ) || ! empty( $current_headers ) ) {
						$header_blocks[] = array(
							'status'  => $status_line,
							'headers' => $current_headers,
						);
					}
					$current_headers = array();
					$status_line     = '';

					return strlen( $line );
				}

				if ( str_starts_with( strtoupper( $trimmed ), 'HTTP/' ) ) {
					$status_line = sanitize_text_field( $trimmed );

					return strlen( $line );
				}

				$parts = explode( ':', $trimmed, 2 );
				if ( 2 !== count( $parts ) ) {
					return strlen( $line );
				}

				$name  = strtolower( sanitize_text_field( $parts[0] ) );
				$value = sanitize_text_field( trim( $parts[1] ) );

				if ( isset( $current_headers[ $name ] ) ) {
					if ( ! is_array( $current_headers[ $name ] ) ) {
						$current_headers[ $name ] = array( $current_headers[ $name ] );
					}
					$current_headers[ $name ][] = $value;
				} else {
					$current_headers[ $name ] = $value;
				}

				return strlen( $line );
			}
		);

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$curl_headers = array();
			foreach ( $args['headers'] as $name => $value ) {
				$curl_headers[] = sanitize_text_field( (string) $name ) . ': ' . sanitize_text_field( (string) $value );
			}
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
		}

		$body = curl_exec( $ch );
		$info = curl_getinfo( $ch );
		if ( false === $info ) {
			$info = array(
				'url'                => $url,
				'http_code'          => 0,
				'total_time'         => 0.0,
				'namelookup_time'    => 0.0,
				'connect_time'       => 0.0,
				'starttransfer_time' => 0.0,
			);
		}

		if ( false === $body ) {
			$error_code = curl_errno( $ch );
			$error_msg  = curl_error( $ch );
			curl_close( $ch );

			return array(
				'url'              => $url,
				'effective_url'    => $url,
				'redirect_chain'   => array( $url ),
				'status_code'      => 0,
				'headers'          => array(),
				'timing'           => array(
					'total_time'         => null,
					'namelookup_time'    => null,
					'connect_time'       => null,
					'starttransfer_time' => null,
				),
				'response_size'    => 0,
				'platform_headers' => array(),
				'error_code'       => 'curl_' . absint( $error_code ),
				'error_message'    => sanitize_text_field( $error_msg ),
				'is_error'         => true,
			);
		}

		curl_close( $ch );
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close

		$final_headers = array();
		if ( ! empty( $header_blocks ) ) {
			$last          = end( $header_blocks );
			$final_headers = $last['headers'];
		}

		$redirect_chain = array( $url );
		foreach ( $header_blocks as $block ) {
			$location = isset( $block['headers']['location'] ) ? $block['headers']['location'] : '';
			if ( is_array( $location ) ) {
				$location = end( $location );
			}
			$location = esc_url_raw( (string) $location );
			if ( '' !== $location ) {
				$redirect_chain[] = $location;
			}
		}

		$effective_url = esc_url_raw( $info['url'] );
		if ( '' !== $effective_url && end( $redirect_chain ) !== $effective_url ) {
			$redirect_chain[] = $effective_url;
		}

		$normalized_headers = $this->normalize_headers( $final_headers );

		return array(
			'url'              => $url,
			'effective_url'    => '' !== $effective_url ? $effective_url : $url,
			'redirect_chain'   => array_values( array_unique( array_filter( $redirect_chain ) ) ),
			'status_code'      => absint( $info['http_code'] ),
			'headers'          => $normalized_headers,
			'timing'           => array(
				'total_time'         => $this->format_duration( $info['total_time'] ),
				'namelookup_time'    => $this->format_duration( $info['namelookup_time'] ),
				'connect_time'       => $this->format_duration( $info['connect_time'] ),
				'starttransfer_time' => $this->format_duration( $info['starttransfer_time'] ),
			),
			'response_size'    => $this->resolve_response_size( $body, $normalized_headers, $info ),
			'platform_headers' => $this->collect_platform_headers( $normalized_headers ),
			'error_code'       => '',
			'error_message'    => '',
			'is_error'         => false,
		);
	}

	/**
	 * Resolve response size from body length with header/cURL fallbacks.
	 *
	 * @param string $body Response body.
	 * @param array  $headers Normalized response headers.
	 * @param array  $curl_info Optional cURL info array.
	 *
	 * @return int
	 */
	protected function resolve_response_size( string $body, array $headers, array $curl_info = array() ): int {
		$size = strlen( (string) $body );

		if ( $size > 0 ) {
			return absint( $size );
		}

		if ( isset( $headers['content-length'] ) ) {
			$content_length = $headers['content-length'];
			if ( is_array( $content_length ) ) {
				$content_length = end( $content_length );
			}

			if ( is_numeric( $content_length ) && (float) $content_length > 0 ) {
				return absint( $content_length );
			}
		}

		if ( isset( $curl_info['size_download'] ) && (float) $curl_info['size_download'] > 0 ) {
			return absint( round( (float) $curl_info['size_download'] ) );
		}

		return 0;
	}

	/**
	 * @param array $headers Header map.
	 *
	 * @return array
	 */
	protected function collect_platform_headers( array $headers ): array {
		$interesting = array(
			'x-cache',
			'x-cache-hits',
			'x-cache-group',
			'x-batcache',
			'x-batcache-bypass-reason',
			'x-serve-cache',
			'x-cacheable',
			'cf-cache-status',
			'server-timing',
			'age',
			'via',
		);

		$output = array();
		foreach ( $interesting as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				$output[ $name ] = $headers[ $name ];
			}
		}

		return $output;
	}

	/**
	 * @param array $response HTTP response.
	 *
	 * @return array
	 */
	protected function extract_redirect_chain( array $response ): array {
		$chain         = array();
		$http_response = isset( $response['http_response'] ) ? $response['http_response'] : null;

		if ( is_object( $http_response ) && method_exists( $http_response, 'get_response_object' ) ) {
			$requests_response = $http_response->get_response_object();
			if ( is_object( $requests_response ) && ! empty( $requests_response->history ) && is_array( $requests_response->history ) ) {
				foreach ( $requests_response->history as $history_entry ) {
					if ( is_object( $history_entry ) && ! empty( $history_entry->url ) ) {
						$chain[] = esc_url_raw( $history_entry->url );
					}
				}
			}

			if ( is_object( $requests_response ) && ! empty( $requests_response->url ) ) {
				$chain[] = esc_url_raw( $requests_response->url );
			}
		}

		if ( empty( $chain ) ) {
			$chain[] = isset( $response['url'] ) ? esc_url_raw( $response['url'] ) : '';
		}

		return array_values( array_filter( array_unique( $chain ) ) );
	}

	/**
	 * @param float $duration Duration in seconds.
	 *
	 * @return float
	 */
	protected function format_duration( float $duration ): float {
		return round( (float) $duration, 4 );
	}

	/**
	 * @param array|object $headers Headers.
	 *
	 * @return array
	 */
	protected function normalize_headers( array|object $headers ): array {
		$output = array();

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		foreach ( (array) $headers as $name => $value ) {
			$key = strtolower( sanitize_text_field( (string) $name ) );
			if ( is_array( $value ) ) {
				$output[ $key ] = array_map( 'sanitize_text_field', $value );
			} else {
				$output[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $output;
	}
}
