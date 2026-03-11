<?php
/**
 * Redirect Assistant - Candidate discovery.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Candidate discovery service.
 */
class PCM_Redirect_Assistant_Candidate_Discovery {
	/**
	 * Build candidates from observed URL list.
	 *
	 * @param array $observed_urls Full URLs.
	 *
	 * @return array
	 */
	public function discover( array $observed_urls ): array {
		$candidates = array();

		if ( empty( $observed_urls ) ) {
			return $candidates;
		}

		foreach ( $observed_urls as $url ) {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				continue;
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}

			$path  = $parts['path'] ?? '/';
			$query = $parts['query'] ?? '';

			if ( '' !== $query ) {
				parse_str( $query, $params );
				$noise_params = array_intersect( array_keys( $params ), array( 'gclid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) );

				if ( ! empty( $noise_params ) ) {
					$canonical = trailingslashit( $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim( $path, '/' ) );

					$candidates[] = array(
						'id'              => 'cand_' . md5( 'query_' . $path ),
						'enabled'         => false,
						'priority'        => 100,
						'match_type'      => 'prefix',
						'source_pattern'  => $path,
						'target_pattern'  => $canonical,
						'status_code'     => 301,
						'notes'           => 'Query parameter normalization for tracking keys: ' . implode( ', ', $noise_params ),
						'expected_impact' => 'high',
						'confidence'      => 'high',
					);
				}
			}

			if ( strtolower( $path ) !== $path ) {
				$target       = trailingslashit( $parts['scheme'] . '://' . $parts['host'] . '/' . strtolower( ltrim( $path, '/' ) ) );
				$candidates[] = array(
					'id'              => 'cand_' . md5( 'case_' . $path ),
					'enabled'         => false,
					'priority'        => 200,
					'match_type'      => 'exact',
					'source_pattern'  => $path,
					'target_pattern'  => $target,
					'status_code'     => 301,
					'notes'           => 'Lowercase canonicalization pattern.',
					'expected_impact' => 'medium',
					'confidence'      => 'medium',
				);
			}

			if ( '/' !== $path && '/' === substr( $path, -1 ) ) {
				$trimmed      = untrailingslashit( $path );
				$target       = $parts['scheme'] . '://' . $parts['host'] . $trimmed;
				$candidates[] = array(
					'id'              => 'cand_' . md5( 'slash_' . $path ),
					'enabled'         => false,
					'priority'        => 300,
					'match_type'      => 'exact',
					'source_pattern'  => $path,
					'target_pattern'  => $target,
					'status_code'     => 301,
					'notes'           => 'Trailing slash normalization.',
					'expected_impact' => 'low',
					'confidence'      => 'medium',
				);
			}
		}

		return array_values(
			array_map(
				'pcm_redirect_assistant_sanitize_rule',
				pcm_redirect_assistant_unique_by_key( $candidates, 'id' )
			)
		);
	}
}
