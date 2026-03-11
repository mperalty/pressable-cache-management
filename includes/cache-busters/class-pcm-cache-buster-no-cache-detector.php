<?php
/**
 * Cache Busters - No-cache detector.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect no-cache directives on public pages.
 */
class PCM_Cache_Buster_No_Cache_Detector extends PCM_Cache_Buster_Base_Detector {
	public function get_key(): string {
		return 'no_cache';
	}

	public function detect( array $snapshot ): array {
		$events = array();

		if ( empty( $snapshot['findings'] ) || ! is_array( $snapshot['findings'] ) ) {
			return $events;
		}

		$directives     = array( 'no-store', 'private', 'max-age=0' );
		$directive_urls = array();

		foreach ( $snapshot['findings'] as $finding ) {
			$headers       = isset( $finding['evidence']['headers'] ) && is_array( $finding['evidence']['headers'] ) ? $finding['evidence']['headers'] : array();
			$cache_control = isset( $headers['cache-control'] ) ? strtolower( (string) $headers['cache-control'] ) : '';

			if ( '' === $cache_control ) {
				continue;
			}

			foreach ( $directives as $directive ) {
				if ( ! str_contains( $cache_control, $directive ) ) {
					continue;
				}

				if ( ! isset( $directive_urls[ $directive ] ) ) {
					$directive_urls[ $directive ] = array();
				}

				$directive_urls[ $directive ][] = isset( $finding['url'] ) ? $finding['url'] : '';
			}
		}

		foreach ( $directive_urls as $directive => $urls ) {
			$events[] = new PCM_Cache_Buster_Event(
				array(
					'category'         => 'no_cache',
					'signature'        => 'cache-control:' . $directive,
					'confidence'       => 'high',
					'count'            => count( $urls ),
					'likely_source'    => 'cache-control-header',
					'affected_urls'    => array_values( array_unique( $urls ) ),
					'evidence_samples' => array(
						array( 'directive' => $directive ),
					),
				)
			);
		}

		return $events;
	}
}
