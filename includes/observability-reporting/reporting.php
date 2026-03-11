<?php
/**
 * Observability & Reporting (Pillar 7).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_reporting_dir = plugin_dir_path( __FILE__ );

require_once $pcm_reporting_dir . 'class-pcm-metric-registry.php';
require_once $pcm_reporting_dir . 'class-pcm-metric-rollup-storage.php';
require_once $pcm_reporting_dir . 'class-pcm-metric-rollup-service.php';
require_once $pcm_reporting_dir . 'class-pcm-report-export-service.php';
require_once $pcm_reporting_dir . 'class-pcm-report-digest-service.php';
require_once $pcm_reporting_dir . 'class-pcm-reporting-cli-command.php';

/**
 * Feature flag for reporting.
 *
 * @return bool
 */
function pcm_reporting_is_enabled(): bool {
	$enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );

	return (bool) apply_filters( 'pcm_enable_observability_reporting', $enabled );
}

/**
 * Register schedules.
 *
 * @param array $schedules Existing schedules.
 *
 * @return array
 */
function pcm_reporting_register_schedules( array $schedules ): array {
	if ( ! isset( $schedules['pcm_daily'] ) ) {
		$schedules['pcm_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily (PCM Reporting)', 'pressable_cache_management' ),
		);
	}

	if ( ! isset( $schedules['pcm_weekly'] ) ) {
		$schedules['pcm_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (PCM Reporting)', 'pressable_cache_management' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'pcm_reporting_register_schedules' );

/**
 * Schedule jobs when feature is enabled.
 *
 * @return void
 */
function pcm_reporting_maybe_schedule_jobs(): void {
	if ( ! pcm_reporting_is_enabled() ) {
		return;
	}

	if ( ! wp_next_scheduled( 'pcm_reporting_daily_rollup' ) ) {
		wp_schedule_event( time() + 120, 'pcm_daily', 'pcm_reporting_daily_rollup' );
	}

	if ( ! wp_next_scheduled( 'pcm_reporting_weekly_digest' ) ) {
		wp_schedule_event( time() + 300, 'pcm_weekly', 'pcm_reporting_weekly_digest' );
	}
}
add_action( 'init', 'pcm_reporting_maybe_schedule_jobs' );

/**
 * Daily rollup aggregation hook.
 *
 * @return void
 */
function pcm_reporting_daily_rollup(): void {
	if ( ! pcm_reporting_is_enabled() ) {
		return;
	}

	$rollups = new PCM_Metric_Rollup_Service();

	$cacheability_score = pcm_reporting_latest_cacheability_score();
	$rollups->write_rollup( 'cacheability_score', $cacheability_score );

	$cache_buster_incidence = function_exists( 'pcm_cache_busters_get_total_incidence' ) ? (float) pcm_cache_busters_get_total_incidence( '7d' ) : 0.0;
	$rollups->write_rollup( 'cache_buster_incidence', $cache_buster_incidence );

	$rollups->write_rollup( 'batcache_hits', (float) pcm_reporting_latest_batcache_hits() );

	$rollups->flush_storage();
	$rollups->cleanup_storage( (int) get_option( PCM_Options::REPORTING_RETENTION_DAYS->value, 90 ) );
}
add_action( 'pcm_reporting_daily_rollup', 'pcm_reporting_daily_rollup' );

/**
 * Weekly digest hook.
 *
 * @return void
 */
function pcm_reporting_weekly_digest(): void {
	$service = new PCM_Report_Digest_Service();
	$service->send_weekly_digest();
}
add_action( 'pcm_reporting_weekly_digest', 'pcm_reporting_weekly_digest' );


/**
 * @return float
 */
function pcm_reporting_latest_cacheability_score(): float {
	global $wpdb;

	$runs_table = $wpdb->prefix . 'pcm_scan_runs';
	$urls_table = $wpdb->prefix . 'pcm_scan_urls';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted $wpdb->prefix.
	$run_id = (int) $wpdb->get_var( "SELECT id FROM {$runs_table} WHERE status = 'completed' ORDER BY id DESC LIMIT 1" );
	if ( $run_id <= 0 ) {
		return (float) get_option( PCM_Options::LATEST_CACHEABILITY_SCORE->value, 0 );
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted $wpdb->prefix.
	$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(score) FROM {$urls_table} WHERE run_id = %d", $run_id ) );

	return null !== $avg ? (float) round( (float) $avg, 2 ) : 0.0;
}

/**
 * @return int
 */
function pcm_reporting_latest_batcache_hits(): int {
	return (int) get_option( PCM_Options::BATCACHE_HITS_24H->value, 0 );
}

/**
 * AJAX: query reporting trends.
 *
 * @return void
 */
function pcm_ajax_reporting_trends(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		$can_view = function_exists( 'pcm_current_user_can' ) ? pcm_current_user_can( 'pcm_view_diagnostics' ) : current_user_can( 'manage_options' );
		if ( ! $can_view ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$range       = isset( $_REQUEST['range'] ) ? sanitize_key( wp_unslash( $_REQUEST['range'] ) ) : '7d';
	$metric_keys = isset( $_REQUEST['metric_keys'] ) ? (array) wp_unslash( $_REQUEST['metric_keys'] ) : array();

	$service = new PCM_Metric_Rollup_Service();

	wp_send_json_success(
		array(
			'range' => $range,
			'rows'  => $service->query_trends( $range, $metric_keys ),
		)
	);
}
add_action( 'wp_ajax_pcm_reporting_trends', 'pcm_ajax_reporting_trends' );

/**
 * AJAX: secure export endpoint for JSON/CSV.
 *
 * @return void
 */
function pcm_ajax_reporting_export(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_export_reports' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );
	}

	$format      = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'json';
	$range       = isset( $_REQUEST['range'] ) ? sanitize_key( wp_unslash( $_REQUEST['range'] ) ) : '7d';
	$metric_keys = isset( $_REQUEST['metric_keys'] ) ? (array) wp_unslash( $_REQUEST['metric_keys'] ) : array();

	$exporter = new PCM_Report_Export_Service();
	$result   = $exporter->export( $format, $range, $metric_keys );

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( $result, 403 );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_pcm_reporting_export', 'pcm_ajax_reporting_export' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'pcm-report', 'PCM_Reporting_CLI_Command' );
}
