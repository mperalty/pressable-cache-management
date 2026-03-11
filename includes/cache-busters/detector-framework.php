<?php
/**
 * Cache Busters detector framework (Pillar 2).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_cache_busters_dir = plugin_dir_path( __FILE__ );

require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-event.php';
require_once $pcm_cache_busters_dir . 'interface-pcm-cache-buster-detector-interface.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-detector-registry.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-snapshot-provider.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-base-detector.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-cookie-detector.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-query-detector.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-vary-detector.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-no-cache-detector.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-purge-detector.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-detector-engine.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-event-storage.php';
require_once $pcm_cache_busters_dir . 'class-pcm-cache-buster-insights-service.php';

/**
 * Feature flag for cache buster detection.
 *
 * Depends on Cacheability Advisor data collection.
 *
 * @return bool
 */
function pcm_cache_busters_is_enabled(): bool {
	static $cached = null;
	if ( null === $cached ) {
		$suite_enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
		$enabled       = $suite_enabled || ( function_exists( 'pcm_cacheability_advisor_is_enabled' ) && pcm_cacheability_advisor_is_enabled() );
		$cached        = (bool) apply_filters( 'pcm_enable_cache_busters', $enabled );
	}

	return $cached;
}

/**
 * Verify nonce and capability for a cache-busters AJAX request.
 */
function pcm_cache_busters_verify_ajax(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
		return;
	}

	check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

	if ( function_exists( 'pcm_current_user_can' ) ) {
		$can = pcm_current_user_can( 'pcm_view_diagnostics' );
	} else {
		$can = current_user_can( 'manage_options' );
	}

	if ( ! $can ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
}

/**
 * Fetch a cache-busters AJAX parameter from POST, then GET.
 *
 * @param string $key Request key.
 *
 * @return string
 */
function pcm_cache_busters_request_value( string $key ): string {
	$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
	if ( ! is_string( $value ) ) {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
	}

	return is_string( $value ) ? wp_unslash( $value ) : '';
}

/**
 * Helper for reporting integration (A2.4).
 *
 * @param string $range Range key.
 *
 * @return int
 */
function pcm_cache_busters_get_total_incidence( string $range = '7d' ): int {
	if ( ! pcm_cache_busters_is_enabled() ) {
		return 0;
	}

	$engine = new PCM_Cache_Buster_Detector_Engine();
	$engine->detect_latest();

	$insights = new PCM_Cache_Buster_Insights_Service();

	return $insights->total_incidence( $range );
}

/**
 * AJAX endpoint: top cache-busting sources leaderboard.
 *
 * @return void
 */
function pcm_ajax_cache_busters_top_sources(): void {
	pcm_cache_busters_verify_ajax();

	$range       = sanitize_key( pcm_cache_busters_request_value( 'range' ) );
	$limit_input = filter_input( INPUT_POST, 'limit', FILTER_VALIDATE_INT );
	if ( false === $limit_input || null === $limit_input ) {
		$limit_input = filter_input( INPUT_GET, 'limit', FILTER_VALIDATE_INT );
	}

	$range   = '' !== $range ? $range : '7d';
	$limit   = is_int( $limit_input ) ? max( 1, absint( $limit_input ) ) : 10;
	$service = new PCM_Cache_Buster_Insights_Service();

	wp_send_json_success(
		array(
			'range'       => $range,
			'leaderboard' => $service->top_sources( $range, $limit ),
		)
	);
}
add_action( 'wp_ajax_pcm_cache_busters_top_sources', 'pcm_ajax_cache_busters_top_sources' );

/**
 * AJAX endpoint: cache-buster incidence trends.
 *
 * @return void
 */
function pcm_ajax_cache_busters_trends(): void {
	pcm_cache_busters_verify_ajax();

	$range   = sanitize_key( pcm_cache_busters_request_value( 'range' ) );
	$range   = '' !== $range ? $range : '7d';
	$service = new PCM_Cache_Buster_Insights_Service();

	wp_send_json_success(
		array(
			'range'  => $range,
			'points' => $service->trend_points( $range ),
		)
	);
}
add_action( 'wp_ajax_pcm_cache_busters_trends', 'pcm_ajax_cache_busters_trends' );
