<?php
/**
 * Redirect Assistant - Simulation engine.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simulation and validation engine.
 */
class PCM_Redirect_Assistant_Simulation_Engine {
	/**
	 * Simulate one URL.
	 *
	 * @param string $input_url Input URL.
	 * @param array  $rules Rules list.
	 * @param int    $hop_cap Max redirect hops.
	 *
	 * @return array
	 */
	public function simulate_url( string $input_url, array $rules, int $hop_cap = 10 ): array {
		$current  = esc_url_raw( $input_url );
		$visited  = array();
		$warnings = array();

		$hop_limit = absint( $hop_cap );
		for ( $hop = 0; $hop < $hop_limit; $hop++ ) {
			if ( in_array( $current, $visited, true ) ) {
				$warnings[] = 'redirect_loop_detected';
				return array(
					'input_url'     => esc_url_raw( $input_url ),
					'result_status' => 'loop',
					'result_url'    => $current,
					'warnings'      => $warnings,
				);
			}

			$visited[] = $current;
			$match     = $this->match_first_rule( $current, $rules );

			if ( empty( $match ) ) {
				return array(
					'input_url'     => esc_url_raw( $input_url ),
					'result_status' => 'ok',
					'result_url'    => $current,
					'warnings'      => $warnings,
				);
			}

			$current = esc_url_raw( $match['target_pattern'] );
		}

		$warnings[] = 'hop_cap_reached';

		return array(
			'input_url'     => esc_url_raw( $input_url ),
			'result_status' => 'warning',
			'result_url'    => $current,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Batch simulate URLs.
	 *
	 * @param array $input_urls Input URLs.
	 * @param array $rules Rules list.
	 *
	 * @return array
	 */
	public function simulate_batch( array $input_urls, array $rules ): array {
		$results = array();

		foreach ( $input_urls as $url ) {
			$results[] = $this->simulate_url( $url, $rules );
		}

		return $results;
	}

	/**
	 * Detect conflicting/overlapping rules (v1 heuristic).
	 *
	 * @param array $rules Rules list.
	 *
	 * @return array
	 */
	public function detect_conflicts( array $rules ): array {
		$warnings = array();

		foreach ( $rules as $i => $left ) {
			foreach ( $rules as $j => $right ) {
				if ( $i >= $j ) {
					continue;
				}

				if ( empty( $left['enabled'] ) || empty( $right['enabled'] ) ) {
					continue;
				}

				if ( $left['source_pattern'] === $right['source_pattern'] && $left['target_pattern'] !== $right['target_pattern'] ) {
					$warnings[] = array(
						'type'        => 'source_conflict',
						'left_rule'   => $left['id'],
						'right_rule'  => $right['id'],
						'description' => 'Same source pattern redirects to different targets.',
					);
				}

				if ( 'prefix' === $left['match_type'] && str_starts_with( $right['source_pattern'], $left['source_pattern'] ) ) {
					$warnings[] = array(
						'type'        => 'overlap_prefix',
						'left_rule'   => $left['id'],
						'right_rule'  => $right['id'],
						'description' => 'Prefix pattern overlaps a more specific rule.',
					);
				}
			}
		}

		return $warnings;
	}

	/**
	 * @param string $url URL.
	 * @param array  $rules Rules list.
	 *
	 * @return array
	 */
	protected function match_first_rule( string $url, array $rules ): array {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			$source = (string) ( $rule['source_pattern'] ?? '' );
			$type   = (string) ( $rule['match_type'] ?? 'exact' );

			if ( 'exact' === $type && $source === $path ) {
				return $rule;
			}

			if ( 'prefix' === $type && '' !== $source && str_starts_with( $path, $source ) ) {
				return $rule;
			}

			if ( 'regex' === $type && 1 === pcm_redirect_assistant_safe_preg_match( $source, $path ) ) {
				return $rule;
			}
		}

		return array();
	}
}
