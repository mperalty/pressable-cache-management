<?php
/**
 * Cache Busters - Purge detector.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect frequent full-site purge events using known timestamps.
 */
class PCM_Cache_Buster_Purge_Detector extends PCM_Cache_Buster_Base_Detector {
	public function get_key(): string {
		return 'purge';
	}

	public function detect( array $snapshot ): array {
		unset( $snapshot );

		$known_timestamps = array(
			get_option( PCM_Options::FLUSH_OBJECT_CACHE_TIMESTAMP->value, '' ),
			get_option( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value, '' ),
		);

		$known_timestamps = array_filter( array_map( 'sanitize_text_field', $known_timestamps ) );

		if ( count( $known_timestamps ) < 2 ) {
			return array();
		}

		return array(
			new PCM_Cache_Buster_Event(
				array(
					'category'         => 'purge_patterns',
					'signature'        => 'repeated-global-purges',
					'confidence'       => 'low',
					'count'            => count( $known_timestamps ),
					'likely_source'    => 'manual-or-automated-flush',
					'affected_urls'    => array( home_url( '/' ) ),
					'evidence_samples' => array(
						array( 'timestamps' => array_values( $known_timestamps ) ),
					),
				)
			),
		);
	}
}
