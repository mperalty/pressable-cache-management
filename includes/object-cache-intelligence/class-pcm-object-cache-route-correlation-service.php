<?php
/**
 * Object Cache Intelligence - Route correlation service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Correlate object-cache state to cacheability URL findings and score route sensitivity.
 */
class PCM_Object_Cache_Route_Correlation_Service {
	/**
	 * @param int $run_id Run ID. 0 means latest completed run.
	 *
	 * @return array<string, mixed>
	 */
	public function correlate_latest( int $run_id = 0 ): array {
		global $wpdb;

		$snapshot = pcm_object_cache_get_cached_snapshot();

		$runs_table = $wpdb->prefix . 'pcm_scan_runs';
		$urls_table = $wpdb->prefix . 'pcm_scan_urls';
		$find_table = $wpdb->prefix . 'pcm_findings';

		$selected_run_id = absint( $run_id );
		if ( $selected_run_id <= 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted $wpdb->prefix.
			$selected_run_id = (int) $wpdb->get_var( "SELECT id FROM {$runs_table} WHERE status = 'completed' ORDER BY id DESC LIMIT 1" );
		}

		if ( $selected_run_id <= 0 ) {
			return array(
				'run_id'           => 0,
				'snapshot'         => $snapshot,
				'assessed_at'      => current_time( 'mysql', true ),
				'routes'           => array(),
				'high_route_count' => 0,
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted $wpdb->prefix.
		$url_rows = $wpdb->get_results( $wpdb->prepare( "SELECT url, score FROM {$urls_table} WHERE run_id = %d", $selected_run_id ), ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted $wpdb->prefix.
		$findings = $wpdb->get_results( $wpdb->prepare( "SELECT url, rule_id, severity FROM {$find_table} WHERE run_id = %d", $selected_run_id ), ARRAY_A );

		$grouped_findings = array();
		foreach ( (array) $findings as $finding ) {
			$url = isset( $finding['url'] ) ? (string) $finding['url'] : '';
			if ( '' === $url ) {
				continue;
			}

			if ( ! isset( $grouped_findings[ $url ] ) ) {
				$grouped_findings[ $url ] = array();
			}
			$grouped_findings[ $url ][] = $finding;
		}

		$assessed_at  = current_time( 'mysql', true );
		$route_groups = array();

		foreach ( (array) $url_rows as $url_row ) {
			$url   = isset( $url_row['url'] ) ? esc_url_raw( $url_row['url'] ) : '';
			$score = isset( $url_row['score'] ) ? (int) $url_row['score'] : 0;
			if ( '' === $url ) {
				continue;
			}

			$route = pcm_object_cache_route_from_url( $url );
			$calc  = pcm_object_cache_calculate_memcache_sensitivity( $score, $grouped_findings[ $url ] ?? array(), $snapshot );

			if ( ! isset( $route_groups[ $route ] ) ) {
				$route_groups[ $route ] = array(
					'route'             => $route,
					'sample_url'        => $url,
					'scores'            => array(),
					'critical_findings' => 0,
					'warning_findings'  => 0,
					'expensive_signals' => 0,
					'reasons'           => array(),
				);
			}

			$route_groups[ $route ]['scores'][]           = $score;
			$route_groups[ $route ]['critical_findings'] += (int) $calc['critical_findings'];
			$route_groups[ $route ]['warning_findings']  += (int) $calc['warning_findings'];
			$route_groups[ $route ]['expensive_signals'] += (int) $calc['expensive_signals'];
			$route_groups[ $route ]['reasons']            = array_values( array_unique( array_merge( $route_groups[ $route ]['reasons'], (array) $calc['reasons'] ) ) );
		}

		$rows = array();
		foreach ( $route_groups as $route => $group ) {
			$avg_score = (int) round( array_sum( $group['scores'] ) / count( $group['scores'] ) );
			$calc      = pcm_object_cache_calculate_memcache_sensitivity(
				$avg_score,
				array(
					array(
						'severity' => 'critical',
						'rule_id'  => 'route_aggregate',
						'count'    => (int) $group['critical_findings'],
					),
					array(
						'severity' => 'warning',
						'rule_id'  => 'route_aggregate',
						'count'    => (int) $group['warning_findings'],
					),
				),
				$snapshot
			);

			$rows[] = array(
				'run_id'               => $selected_run_id,
				'url'                  => $group['sample_url'],
				'route'                => $route,
				'memcache_sensitivity' => $calc['sensitivity'],
				'assessed_at'          => $assessed_at,
				'metrics'              => array(
					'score'             => $avg_score,
					'url_count'         => count( $group['scores'] ),
					'expensive_signals' => (int) $group['expensive_signals'],
					'critical_findings' => (int) $group['critical_findings'],
					'warning_findings'  => (int) $group['warning_findings'],
					'hit_ratio'         => isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : null,
					'evictions'         => isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : null,
					'memory_pressure'   => isset( $snapshot['memory_pressure'] ) ? (float) $snapshot['memory_pressure'] : 0,
					'reasons'           => array_values( array_unique( array_merge( (array) $group['reasons'], (array) $calc['reasons'] ) ) ),
				),
			);
		}

		$storage = new PCM_Object_Cache_Route_Sensitivity_Storage();
		$storage->upsert_batch( $rows );
		$storage->cleanup( (int) get_option( PCM_Options::OBJECT_CACHE_RETENTION_DAYS->value, 90 ) );

		$high_count = 0;
		foreach ( $rows as $row ) {
			if ( 'high' === $row['memcache_sensitivity'] ) {
				++$high_count;
			}
		}

		return array(
			'run_id'           => $selected_run_id,
			'snapshot'         => $snapshot,
			'assessed_at'      => $assessed_at,
			'routes'           => $rows,
			'high_route_count' => $high_count,
		);
	}
}
