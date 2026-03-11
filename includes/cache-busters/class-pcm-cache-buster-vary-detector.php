<?php
/**
 * Cache Busters - Vary detector.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect high-cardinality Vary headers.
 */
class PCM_Cache_Buster_Vary_Detector extends PCM_Cache_Buster_Base_Detector {
	public function get_key(): string {
		return 'vary';
	}

	public function detect( array $snapshot ): array {
		$events = array();

		if ( empty( $snapshot['findings'] ) || ! is_array( $snapshot['findings'] ) ) {
			return $events;
		}

		$watch_headers = array( 'cookie', 'user-agent', 'origin', 'accept-language' );
		$vary_urls     = array();

		foreach ( $snapshot['findings'] as $finding ) {
			$headers = isset( $finding['evidence']['headers'] ) && is_array( $finding['evidence']['headers'] ) ? $finding['evidence']['headers'] : array();
			$vary    = isset( $headers['vary'] ) ? strtolower( (string) $headers['vary'] ) : '';

			if ( '' === $vary ) {
				continue;
			}

			$values = $this->split_header_csv( $vary );
			foreach ( $values as $value ) {
				$normalized = sanitize_key( $value );
				if ( ! in_array( $normalized, $watch_headers, true ) ) {
					continue;
				}

				if ( ! isset( $vary_urls[ $normalized ] ) ) {
					$vary_urls[ $normalized ] = array();
				}

				$vary_urls[ $normalized ][] = isset( $finding['url'] ) ? $finding['url'] : '';
			}
		}

		foreach ( $vary_urls as $header => $urls ) {
			$events[] = new PCM_Cache_Buster_Event(
				array(
					'category'         => 'vary',
					'signature'        => 'vary:' . $header,
					'confidence'       => 'medium',
					'count'            => count( $urls ),
					'likely_source'    => 'response-header',
					'affected_urls'    => array_values( array_unique( $urls ) ),
					'evidence_samples' => array(
						array( 'vary_header' => $header ),
					),
				)
			);
		}

		return $events;
	}
}
