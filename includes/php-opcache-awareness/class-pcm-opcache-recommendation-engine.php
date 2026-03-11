<?php
/**
 * PHP OPcache Awareness - Recommendation engine.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommendation engine for OPcache snapshots.
 */
class PCM_OPcache_Recommendation_Engine {
	/**
	 * @param array $snapshot Normalized snapshot.
	 *
	 * @return array
	 */
	public function evaluate( array $snapshot ): array {
		$health          = 'healthy';
		$recommendations = array();

		$free_percent   = isset( $snapshot['memory']['free_memory_percent'] ) ? (float) $snapshot['memory']['free_memory_percent'] : 0;
		$wasted_percent = isset( $snapshot['memory']['wasted_percent'] ) ? (float) $snapshot['memory']['wasted_percent'] : 0;
		$restart_total  = isset( $snapshot['statistics']['restart_total'] ) ? absint( $snapshot['statistics']['restart_total'] ) : 0;
		$thresholds     = pcm_get_opcache_thresholds();

		if ( $free_percent < $thresholds['free_warning_percent'] ) {
			$health            = 'warning';
			$recommendations[] = array(
				'rule_id'   => 'low_free_memory',
				'severity'  => 'warning',
				'message'   => sprintf( 'OPcache free memory is %s%% (<%s%%). Consider increasing opcache.memory_consumption.', $free_percent, $thresholds['free_warning_percent'] ),
				'checklist' => array(
					'Increase opcache.memory_consumption incrementally.',
					'Monitor free memory and restart behavior after change.',
					'Confirm improved hit rate after tuning.',
				),
			);
		}

		if ( $wasted_percent > $thresholds['wasted_warning_percent'] ) {
			$health            = 'warning';
			$recommendations[] = array(
				'rule_id'   => 'high_wasted_memory',
				'severity'  => 'warning',
				'message'   => sprintf( 'OPcache wasted memory is %s%% (>%s%%). Investigate invalidation churn and deployment frequency.', $wasted_percent, $thresholds['wasted_warning_percent'] ),
				'checklist' => array(
					'Audit deployment cadence and cache invalidation patterns.',
					'Review timestamp validation and revalidate frequency settings.',
					'Verify wasted memory trend drops after adjustments.',
				),
			);
		}

		if ( $restart_total >= $thresholds['restart_critical_count'] ) {
			$health            = 'degraded';
			$recommendations[] = array(
				'rule_id'   => 'frequent_restarts',
				'severity'  => 'critical',
				'message'   => sprintf( 'OPcache restart counters are elevated (%d). Check memory sizing and invalidation storms.', $restart_total ),
				'checklist' => array(
					'Inspect deployment/reload patterns in the last 24h.',
					'Tune memory and max file/key settings as needed.',
					'Re-check restart counters after mitigation.',
				),
			);
		}

		$recommendations[] = array(
			'rule_id'   => 'timestamp_validation_note',
			'severity'  => 'info',
			'message'   => 'Timestamp validation improves code freshness but may reduce cache efficiency. Tune opcache.validate_timestamps and opcache.revalidate_freq for your deployment model.',
			'checklist' => array(
				'Document current deployment workflow expectations.',
				'Adjust validation settings in small increments.',
				'Verify both correctness and hit-rate trends post-change.',
			),
		);

		return array(
			'health'          => $health,
			'recommendations' => $recommendations,
		);
	}
}
