<?php
/**
 * Pressable Cache Management — Option Name Constants.
 *
 * Every wp_options key written or read by the plugin is defined here as a
 * class constant.  Using constants instead of string literals prevents
 * typo-related silent bugs and makes it easy to rename an option in one
 * place.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCM_Options {

    // ── Main settings groups ──────────────────────────────────────────────────
    const MAIN_OPTIONS                              = 'pressable_cache_management_options';
    const REMOVE_BRANDING_OPTIONS                   = 'remove_pressable_branding_tab_options';
    const EDGE_CACHE_SETTINGS_OPTIONS               = 'edge_cache_settings_tab_options';

    // ── Object cache flush timestamps ─────────────────────────────────────────
    const FLUSH_OBJ_CACHE_TIMESTAMP                 = 'flush-obj-cache-time-stamp';
    const FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP        = 'flush-cache-theme-plugin-time-stamp';
    const FLUSH_CACHE_PAGE_EDIT_TIMESTAMP           = 'flush-cache-page-edit-time-stamp';
    const FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP    = 'flush-cache-on-page-post-delete-time-stamp';
    const FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP      = 'flush-cache-on-comment-delete-time-stamp';

    // ── Individual page flush ─────────────────────────────────────────────────
    const FLUSH_SINGLE_PAGE_TIMESTAMP               = 'flush-object-cache-for-single-page-time-stamp';
    const FLUSH_SINGLE_PAGE_NOTICE                  = 'flush-object-cache-for-single-page-notice';
    const SINGLE_PAGE_URL_FLUSHED                   = 'single-page-url-flushed';
    const SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP    = 'single-page-edge-cache-purge-time-stamp';
    const PAGE_TITLE                                = 'page-title';

    // ── Edge cache ────────────────────────────────────────────────────────────
    const EDGE_CACHE_ENABLED                        = 'edge-cache-enabled';
    const EDGE_CACHE_STATUS                         = 'edge-cache-status';
    const EDGE_CACHE_PURGE_TIMESTAMP                = 'edge-cache-purge-time-stamp';
    const EDGE_CACHE_SINGLE_PAGE_URL_PURGED         = 'edge-cache-single-page-url-purged';

    // ── Batcache extension ────────────────────────────────────────────────────
    const EXTEND_BATCACHE_CHECKBOX                  = 'extend_batcache_checkbox';
    const EXTEND_BATCACHE_NOTICE_PENDING            = 'pcm_extend_batcache_notice_pending';

    // ── Cache exclusions ──────────────────────────────────────────────────────
    const EXEMPT_FROM_BATCACHE                      = 'exempt_from_batcache';
    const EXCLUDE_QUERY_STRING_GCLID                = 'exclude_query_string_gclid';
    const EXCLUDE_QUERY_STRING_GCLID_NOTICE         = 'exclude_query_string_gclid_activate_notice';

    // ── WooCommerce product page flush ────────────────────────────────────────
    const WOO_INDIVIDUAL_PAGE_NOTICE                = 'flush_batcache_for_woo_product_individual_page_activate_notice';

    // ── Cookie / WP-PP cache ──────────────────────────────────────────────────
    const CACHE_WPP_COOKIES_PAGES                   = 'cache_wpp_cookies_pages';
    const CACHE_WPP_COOKIES_PAGES_NOTICE            = 'cache_wpp_cookies_pages_activate_notice';

    // ── Feature flags ─────────────────────────────────────────────────────────
    const ENABLE_CACHING_SUITE_FEATURES             = 'pcm_enable_caching_suite_features';
    const ENABLE_DURABLE_ORIGIN_MICROCACHE          = 'pcm_enable_durable_origin_microcache';

    // ── Smart Purge Strategy ──────────────────────────────────────────────────
    const SMART_PURGE_ACTIVE_MODE                   = 'pcm_smart_purge_active_mode';
    const SMART_PURGE_COOLDOWN_SECONDS              = 'pcm_smart_purge_cooldown_seconds';
    const SMART_PURGE_DEFER_SECONDS                 = 'pcm_smart_purge_defer_seconds';
    const SMART_PURGE_ENABLE_PREWARM                = 'pcm_smart_purge_enable_prewarm';
    const SMART_PURGE_PREWARM_URL_CAP               = 'pcm_smart_purge_prewarm_url_cap';
    const SMART_PURGE_PREWARM_BATCH_SIZE            = 'pcm_smart_purge_prewarm_batch_size';
    const SMART_PURGE_PREWARM_REPEAT_HITS           = 'pcm_smart_purge_prewarm_repeat_hits';
    const SMART_PURGE_IMPORTANT_URLS                = 'pcm_smart_purge_important_urls';
    const SMART_PURGE_JOBS_V1                       = 'pcm_smart_purge_jobs_v1';

    // ── Observability / Reporting metrics ─────────────────────────────────────
    const LATEST_OBJECT_CACHE_HIT_RATIO             = 'pcm_latest_object_cache_hit_ratio';
    const LATEST_OBJECT_CACHE_EVICTIONS             = 'pcm_latest_object_cache_evictions';
    const LATEST_OPCACHE_MEMORY_PRESSURE            = 'pcm_latest_opcache_memory_pressure';
    const LATEST_OPCACHE_RESTARTS                   = 'pcm_latest_opcache_restarts';
    const LATEST_CACHEABILITY_SCORE                 = 'pcm_latest_cacheability_score';
    const LATEST_PURGE_ACTIVITY                     = 'pcm_latest_purge_activity';
    const REPORT_DIGEST_RECIPIENTS                  = 'pcm_report_digest_recipients';
    const REPORTING_RETENTION_DAYS                  = 'pcm_reporting_retention_days';
    const BATCACHE_HITS_24H                         = 'pcm_batcache_hits_24h';
    const LAST_TTL                                  = 'pcm_last_ttl';

    // ── Object Cache Intelligence ─────────────────────────────────────────────
    const OBJECT_CACHE_RETENTION_DAYS               = 'pcm_object_cache_retention_days';

    // ── OPcache Awareness ─────────────────────────────────────────────────────
    const OPCACHE_THRESHOLDS_V1                     = 'pcm_opcache_thresholds_v1';
    const OPCACHE_RETENTION_DAYS                    = 'pcm_opcache_retention_days';

    // ── Cacheability Advisor ──────────────────────────────────────────────────
    const CACHEABILITY_ADVISOR_DB_VERSION           = 'pcm_cacheability_advisor_db_version';

    // ── Security / Privacy ────────────────────────────────────────────────────
    const SECURITY_PRIVACY_CAPS_VERSION             = 'pcm_security_privacy_caps_version';
    const PRIVACY_SETTINGS_V1                       = 'pcm_privacy_settings_v1';

    // ── Durable Origin Microcache ─────────────────────────────────────────────
    const MICROCACHE_USE_CUSTOM_TABLE_INDEX         = 'pcm_microcache_use_custom_table_index';

    // ── Legacy / activation ───────────────────────────────────────────────────
    const API_ADMIN_NOTICE_STATUS                   = 'pressable_api_admin_notice__status';
    const FLUSH_OBJECT_CACHE_TIMESTAMP              = 'flush-object-cache-time-stamp';
}
