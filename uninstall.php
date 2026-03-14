<?php
/**
 * Runs on uninstall of Pressable Cache Management.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'remove-mu-plugins-batcache-on-uninstall.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/constants.php';

$options_to_delete = array(
	PCM_Options::MAIN_OPTIONS->value,
	PCM_Options::REMOVE_BRANDING_OPTIONS->value,
	PCM_Options::EDGE_CACHE_SETTINGS_OPTIONS->value,
	PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value,
	PCM_Options::FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP->value,
	PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP->value,
	PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP->value,
	PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP->value,
	PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value,
	PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value,
	PCM_Options::SINGLE_PAGE_URL_FLUSHED->value,
	PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP->value,
	'single-page-path-url',
	PCM_Options::PAGE_TITLE->value,
	'page-url',
	PCM_Options::EDGE_CACHE_ENABLED->value,
	PCM_Options::EDGE_CACHE_STATUS->value,
	PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value,
	PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED->value,
	PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value,
	PCM_Options::EXEMPT_FROM_BATCACHE->value,
	PCM_Options::EXCLUDE_QUERY_STRING_GCLID->value,
	PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value,
	PCM_Options::WOO_INDIVIDUAL_PAGE_NOTICE->value,
	PCM_Options::CACHE_WPP_COOKIES_PAGES->value,
	PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value,
	PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value,
	PCM_Options::ENABLE_ADVANCED_SCAN_WORKFLOWS->value,
	PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value,
	PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value,
	PCM_Options::MICROCACHE_USE_CUSTOM_TABLE_INDEX->value,
	PCM_Options::LATEST_OBJECT_CACHE_HIT_RATIO->value,
	PCM_Options::LATEST_OBJECT_CACHE_EVICTIONS->value,
	PCM_Options::LATEST_OPCACHE_MEMORY_PRESSURE->value,
	PCM_Options::LATEST_OPCACHE_RESTARTS->value,
	PCM_Options::LATEST_CACHEABILITY_SCORE->value,
	PCM_Options::LATEST_PURGE_ACTIVITY->value,
	PCM_Options::REPORT_DIGEST_RECIPIENTS->value,
	PCM_Options::REPORTING_RETENTION_DAYS->value,
	PCM_Options::BATCACHE_HITS_24H->value,
	PCM_Options::LAST_TTL->value,
	PCM_Options::OBJECT_CACHE_RETENTION_DAYS->value,
	PCM_Options::OPCACHE_THRESHOLDS_V1->value,
	PCM_Options::OPCACHE_RETENTION_DAYS->value,
	'pcm_microcache_index_v1',
	'pcm_microcache_stats_v1',
	'pcm_microcache_invalidation_events_v1',
	'pcm_metric_rollups_v1',
	'pcm_object_cache_snapshots_v1',
	'pcm_opcache_snapshots_v1',
	'pcm_playbook_progress_v1',
	'pcm_route_memcache_sensitivity_v1',
	'cdn_settings_tab_options',
	'pressable_api_authentication_tab_options',
	'cdn-cache-purge-time-stamp',
	'cdn-api-state',
	'cdnenabled',
	PCM_Options::API_ADMIN_NOTICE_STATUS->value,
	'pressable_cdn_connection_decactivated_notice',
	'pressable_api_enable_cdn_connection_admin_notice',
	'extend_batcache_activate_notice',
	'extend_cdn_activate_notice',
	'exclude_images_from_cdn_activate_notice',
	'exclude_json_js_from_cdn_notice',
	'exclude_json_js_from_cdn_activate_notice',
	'exclude_css_from_cdn_activate_notice',
	'exclude_fonts_from_cdn_activate_notice',
	'exempt_batcache_activate_notice',
	'pressable_site_id',
	'pcm_site_id_added_activate_notice',
	'pcm_site_id_con_res',
	'pcm_client_id',
	'pcm_client_secret',
	'puc_check_now_pressable-cache-management',
	'_transient_pcm_batcache_status',
	'_transient_timeout_pcm_batcache_status',
	'pcm_legacy_migration_done',
);

foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

foreach (
	array(
		'pcm_batcache_status',
		'pcm_ec_status_cache',
		'pcm_last_cache_flush',
		'pcm_mu_plugin_sync_failure',
		'pcm_oci_latest_snapshot',
		'pcm-page-post-delete-notice',
		'pcm_popular_url_tracker_schema_ready',
		'pcm_smart_purge_settings_notices',
		'wpsc_config_error',
	) as $transient
) {
	delete_transient( $transient );
}

foreach (
	array(
		'pcm_smart_purge_run_queue',
		'pcm_cacheability_retention_cleanup',
		'pcm_object_cache_collect_snapshot',
		'pcm_opcache_collect_snapshot',
		'pcm_reporting_daily_rollup',
		'pcm_reporting_weekly_digest',
	) as $hook
) {
	wp_clear_scheduled_hook( $hook );
}

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_puc_' ) . '%pressable-cache-management%',
		$wpdb->esc_like( '_transient_timeout_puc_' ) . '%pressable-cache-management%',
		$wpdb->esc_like( '_transient_pcm_scan_queue_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_pcm_scan_queue_' ) . '%',
		$wpdb->esc_like( '_transient_pcm_microcache_rebuild_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_pcm_microcache_rebuild_' ) . '%'
	)
);

foreach (
	array(
		$wpdb->prefix . 'pcm_scan_urls',
		$wpdb->prefix . 'pcm_findings',
		$wpdb->prefix . 'pcm_template_scores',
		$wpdb->prefix . 'pcm_scan_runs',
		$wpdb->prefix . 'pcm_popular_url_hits',
		$wpdb->prefix . 'pcm_microcache_index',
	) as $table_name
) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the trusted site prefix plus plugin-owned suffixes.
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}
