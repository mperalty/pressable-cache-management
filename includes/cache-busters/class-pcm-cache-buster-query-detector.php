<?php
/**
 * Cache Busters - Query detector.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect noisy query parameter fragmentation.
 */
class PCM_Cache_Buster_Query_Detector extends PCM_Cache_Buster_Base_Detector {
	public function get_key(): string {
		return 'query';
	}

	public function detect( array $snapshot ): array {
		$events = array();

		if ( empty( $snapshot['urls'] ) || ! is_array( $snapshot['urls'] ) ) {
			return $events;
		}

		$tracked_keys = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid' );
		$key_to_urls  = array();

		foreach ( $snapshot['urls'] as $url_row ) {
			if ( empty( $url_row['url'] ) ) {
				continue;
			}

			$query_string = wp_parse_url( $url_row['url'], PHP_URL_QUERY );
			if ( empty( $query_string ) ) {
				continue;
			}

			parse_str( $query_string, $params );
			if ( empty( $params ) ) {
				continue;
			}

			foreach ( array_keys( $params ) as $param_key ) {
				$normalized_key = sanitize_key( $param_key );
				if ( ! in_array( $normalized_key, $tracked_keys, true ) ) {
					continue;
				}

				if ( ! isset( $key_to_urls[ $normalized_key ] ) ) {
					$key_to_urls[ $normalized_key ] = array();
				}

				$key_to_urls[ $normalized_key ][] = $this->normalize_url_for_report( $url_row['url'] );
			}
		}

		foreach ( $key_to_urls as $key => $urls ) {
			$events[] = new PCM_Cache_Buster_Event(
				array(
					'category'         => 'query_params',
					'signature'        => 'query-param:' . $key,
					'confidence'       => 'high',
					'count'            => count( $urls ),
					'likely_source'    => 'tracking-query-params',
					'affected_urls'    => array_values( array_unique( $urls ) ),
					'evidence_samples' => array(
						array( 'param' => $key ),
					),
				)
			);
		}

		return $events;
	}
}
