<?php
/**
 * Cacheability Advisor - Decision tracer.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds structured bypass/poisoning/risk diagnostics for each route.
 */
class PCM_Cacheability_Decision_Tracer {
	/**
	 * @param string $url Route URL.
	 * @param array  $probe Probe response.
	 * @param array  $evaluation Rule evaluation payload.
	 *
	 * @return array
	 */
	public function trace( string $url, array $probe, array $evaluation ): array {
		$headers = isset( $probe['headers'] ) && is_array( $probe['headers'] ) ? $probe['headers'] : array();

		$edge_bypass_reasons     = array();
		$batcache_bypass_reasons = array();
		$poisoning_signals       = array();
		$route_risk_labels       = array();

		$cache_control = strtolower( (string) $this->header_to_scalar( $headers, 'cache-control' ) );
		if ( '' !== $cache_control && ( str_contains( $cache_control, 'no-store' ) || str_contains( $cache_control, 'private' ) ) ) {
			$edge_bypass_reasons[] = array(
				'reason'   => 'cache_control_non_public',
				'evidence' => $cache_control,
			);
		}

		$vary = strtolower( (string) $this->header_to_scalar( $headers, 'vary' ) );
		if ( '' !== $vary && str_contains( $vary, 'cookie' ) ) {
			$edge_bypass_reasons[]     = array(
				'reason'   => 'vary_cookie',
				'evidence' => $vary,
			);
			$batcache_bypass_reasons[] = array(
				'reason'   => 'vary_cookie',
				'evidence' => $vary,
			);
		}

		$set_cookie = $this->header_values( $headers, 'set-cookie' );
		if ( ! empty( $set_cookie ) ) {
			$batcache_bypass_reasons[] = array(
				'reason'   => 'set_cookie_present',
				'evidence' => array_slice( $set_cookie, 0, 5 ),
			);

			foreach ( $set_cookie as $cookie_line ) {
				$cookie_name = sanitize_key( strtolower( trim( explode( '=', (string) $cookie_line, 2 )[0] ) ) );
				if ( '' !== $cookie_name ) {
					$poisoning_signals[] = array(
						'type'     => 'cookie',
						'key'      => $cookie_name,
						'evidence' => sanitize_text_field( (string) $cookie_line ),
					);
				}
			}
		}

		$url_parts = wp_parse_url( $url );
		if ( is_array( $url_parts ) && ! empty( $url_parts['query'] ) ) {
			parse_str( $url_parts['query'], $query_args );
			foreach ( (array) $query_args as $query_key => $query_value ) {
				$poisoning_signals[] = array(
					'type'     => 'query',
					'key'      => sanitize_key( (string) $query_key ),
					'evidence' => sanitize_text_field( is_scalar( $query_value ) ? (string) $query_value : wp_json_encode( $query_value ) ),
				);
			}
		}

		foreach ( array( 'vary', 'cache-control', 'pragma', 'x-forwarded-host', 'x-forwarded-proto' ) as $header_key ) {
			if ( isset( $headers[ $header_key ] ) ) {
				$poisoning_signals[] = array(
					'type'     => 'header',
					'key'      => $header_key,
					'evidence' => $headers[ $header_key ],
				);
			}
		}

		$score = isset( $evaluation['score'] ) ? absint( $evaluation['score'] ) : 0;
		if ( $score < 60 ) {
			$route_risk_labels[] = array(
				'label'    => 'fragile',
				'evidence' => 'Score below 60',
			);
		}
		if ( ! empty( $probe['timing']['total_time'] ) && $probe['timing']['total_time'] > 1.2 ) {
			$route_risk_labels[] = array(
				'label'    => 'expensive',
				'evidence' => 'Total response time ' . $probe['timing']['total_time'] . 's',
			);
		}
		if ( ! empty( $probe['platform_headers']['x-cache'] ) && str_contains( strtolower( (string) $this->header_to_scalar( $probe['platform_headers'], 'x-cache' ) ), 'miss' ) ) {
			$route_risk_labels[] = array(
				'label'    => 'cold',
				'evidence' => 'x-cache indicates miss',
			);
		}

		return array(
			'edge_bypass_reasons'     => $edge_bypass_reasons,
			'batcache_bypass_reasons' => $batcache_bypass_reasons,
			'poisoning_signals'       => $poisoning_signals,
			'route_risk_labels'       => $route_risk_labels,
		);
	}

	/**
	 * @param array  $headers Headers map.
	 * @param string $key Header key.
	 *
	 * @return array
	 */
	protected function header_values( array $headers, string $key ): array {
		if ( ! isset( $headers[ $key ] ) ) {
			return array();
		}

		return is_array( $headers[ $key ] ) ? $headers[ $key ] : array( $headers[ $key ] );
	}

	/**
	 * @param array  $headers Headers map.
	 * @param string $key Header key.
	 *
	 * @return string
	 */
	protected function header_to_scalar( array $headers, string $key ): string {
		if ( ! isset( $headers[ $key ] ) ) {
			return '';
		}

		return is_array( $headers[ $key ] ) ? implode( ', ', $headers[ $key ] ) : (string) $headers[ $key ];
	}
}
