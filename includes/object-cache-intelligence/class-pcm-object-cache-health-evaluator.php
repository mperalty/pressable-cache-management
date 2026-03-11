<?php
/**
 * Object Cache Intelligence - Health evaluator.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluate health heuristics and generate recommendations.
 */
class PCM_Object_Cache_Health_Evaluator {
	/**
	 * @param array<string, mixed> $metrics Metrics payload.
	 *
	 * @return array{health: string, recommendations: array<int, array<string, mixed>>}
	 */
	public function evaluate( array $metrics ): array {
		$health          = 'connected';
		$recommendations = array();

		if ( empty( $metrics ) || 'offline' === $metrics['status'] ) {
			return array(
				'health'          => 'offline',
				'recommendations' => array(
					array(
						'rule_id'   => 'memcache_unavailable',
						'severity'  => 'critical',
						'message'   => 'Object cache statistics are unavailable. Verify object-cache drop-in and Memcached connectivity.',
						'checklist' => array(
							'Confirm object-cache.php drop-in exists and loads without warnings.',
							'Confirm Memcached service endpoint is reachable from WP runtime.',
							'Re-check stats after infrastructure validation.',
						),
					),
				),
			);
		}

		$hit_ratio = isset( $metrics['hit_ratio'] ) ? $metrics['hit_ratio'] : null;
		$evictions = isset( $metrics['evictions'] ) ? absint( $metrics['evictions'] ) : 0;

		if ( null !== $hit_ratio && $hit_ratio < 70 ) {
			$health            = 'degraded';
			$recommendations[] = array(
				'rule_id'   => 'low_hit_ratio',
				'severity'  => 'warning',
				'message'   => sprintf( 'Hit ratio is %s%% (below 70%% warning threshold). Investigate short TTLs and high key churn.', $hit_ratio ),
				'checklist' => array(
					'Review frequent cache flush triggers in plugin/theme workflow.',
					'Verify cache key normalization for anonymous requests.',
					'Measure hit ratio again after configuration changes.',
				),
			);
		}

		if ( $evictions > 0 ) {
			$memory_pressure = pcm_calculate_memory_pressure( $metrics );

			if ( $evictions >= 100 || $memory_pressure >= 90 ) {
				$health            = 'degraded';
				$recommendations[] = array(
					'rule_id'   => 'high_evictions_or_pressure',
					'severity'  => 'critical',
					'message'   => sprintf( 'Evictions (%d) and/or memory pressure (%s%%) indicate cache churn and likely reduced effectiveness.', $evictions, $memory_pressure ),
					'checklist' => array(
						'Check object cache memory allocation and slab usage.',
						'Reduce oversized or low-value cache entries where possible.',
						'Verify lower eviction trend after adjustments.',
					),
				);
			}
		}

		return array(
			'health'          => $health,
			'recommendations' => $recommendations,
		);
	}
}
