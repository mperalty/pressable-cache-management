<?php
/**
 * Cache Busters - Snapshot provider.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshot provider (latest scan run).
 */
class PCM_Cache_Buster_Snapshot_Provider {
	/**
	 * @return array<string, mixed>
	 */
	public function get_latest_snapshot(): array {
		global $wpdb;

		$runs_table     = $wpdb->prefix . 'pcm_scan_runs';
		$urls_table     = $wpdb->prefix . 'pcm_scan_urls';
		$findings_table = $wpdb->prefix . 'pcm_findings';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from trusted $wpdb->prefix.
		$run = $wpdb->get_row( "SELECT * FROM {$runs_table} WHERE status = 'completed' ORDER BY id DESC LIMIT 1", ARRAY_A );

		if ( ! $run ) {
			return array(
				'run'      => null,
				'urls'     => array(),
				'findings' => array(),
			);
		}

		$run_id = absint( $run['id'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from trusted $wpdb->prefix.
		$urls = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$urls_table} WHERE run_id = %d", $run_id ), ARRAY_A );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from trusted $wpdb->prefix.
		$findings = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$findings_table} WHERE run_id = %d", $run_id ), ARRAY_A );

		foreach ( $findings as $index => $finding ) {
			$decoded = ! empty( $finding['evidence_json'] ) ? json_decode( $finding['evidence_json'], true ) : array();

			$findings[ $index ]['evidence'] = is_array( $decoded ) ? $decoded : array();
			unset( $findings[ $index ]['evidence_json'] );
		}

		return array(
			'run'      => $run,
			'urls'     => $urls,
			'findings' => $findings,
		);
	}
}
