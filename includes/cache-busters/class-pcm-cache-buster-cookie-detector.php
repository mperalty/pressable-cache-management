<?php
/**
 * Cache Busters - Cookie detector.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects anonymous Set-Cookie cache busters.
 */
class PCM_Cache_Buster_Cookie_Detector extends PCM_Cache_Buster_Base_Detector {
	public function get_key(): string {
		return 'cookie';
	}

	public function detect( array $snapshot ): array {
		$events = array();

		if ( empty( $snapshot['findings'] ) || ! is_array( $snapshot['findings'] ) ) {
			return $events;
		}

		$cookie_urls = array();

		foreach ( $snapshot['findings'] as $finding ) {
			$headers = isset( $finding['evidence']['headers'] ) && is_array( $finding['evidence']['headers'] ) ? $finding['evidence']['headers'] : array();

			if ( empty( $headers['set-cookie'] ) ) {
				continue;
			}

			$cookie_lines = is_array( $headers['set-cookie'] ) ? $headers['set-cookie'] : array( $headers['set-cookie'] );

			foreach ( $cookie_lines as $cookie_line ) {
				$cookie_name = $this->get_cookie_name_only( $cookie_line );
				if ( '' === $cookie_name ) {
					continue;
				}

				if ( ! isset( $cookie_urls[ $cookie_name ] ) ) {
					$cookie_urls[ $cookie_name ] = array();
				}

				$cookie_urls[ $cookie_name ][] = isset( $finding['url'] ) ? $finding['url'] : '';
			}
		}

		foreach ( $cookie_urls as $cookie_name => $urls ) {
			$events[] = new PCM_Cache_Buster_Event(
				array(
					'category'         => 'cookies',
					'signature'        => 'set-cookie:' . $cookie_name,
					'confidence'       => 'high',
					'count'            => count( $urls ),
					'likely_source'    => 'runtime-header',
					'affected_urls'    => array_values( array_unique( $urls ) ),
					'evidence_samples' => array(
						array(
							'cookie_name' => $cookie_name,
						),
					),
				)
			);
		}

		return $events;
	}
}
