<?php
/**
 * Pressable Cache Management — Option Name Constants.
 *
 * Every wp_options key written or read by the plugin is defined here as an
 * enum case.  Using a string-backed enum instead of string literals prevents
 * typo-related silent bugs and makes it easy to rename an option in one
 * place.  Access the raw string with ->value.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

enum PCM_Options: string {

    // ── Main settings groups ──────────────────────────────────────────────────
    case MAIN_OPTIONS                              = 'pressable_cache_management_options';
    case REMOVE_BRANDING_OPTIONS                   = 'remove_pressable_branding_tab_options';
    case EDGE_CACHE_SETTINGS_OPTIONS               = 'edge_cache_settings_tab_options';

    // ── Object cache flush timestamps ─────────────────────────────────────────
    case FLUSH_OBJ_CACHE_TIMESTAMP                 = 'flush-obj-cache-time-stamp';
    case FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP        = 'flush-cache-theme-plugin-time-stamp';
    case FLUSH_CACHE_PAGE_EDIT_TIMESTAMP           = 'flush-cache-page-edit-time-stamp';
    case FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP    = 'flush-cache-on-page-post-delete-time-stamp';
    case FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP      = 'flush-cache-on-comment-delete-time-stamp';

    // ── Individual page flush ─────────────────────────────────────────────────
    case FLUSH_SINGLE_PAGE_TIMESTAMP               = 'flush-object-cache-for-single-page-time-stamp';
    case FLUSH_SINGLE_PAGE_NOTICE                  = 'flush-object-cache-for-single-page-notice';
    case SINGLE_PAGE_URL_FLUSHED                   = 'single-page-url-flushed';
    case SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP    = 'single-page-edge-cache-purge-time-stamp';
    case PAGE_TITLE                                = 'page-title';

    // ── Edge cache ────────────────────────────────────────────────────────────
    case EDGE_CACHE_ENABLED                        = 'edge-cache-enabled';
    case EDGE_CACHE_STATUS                         = 'edge-cache-status';
    case EDGE_CACHE_PURGE_TIMESTAMP                = 'edge-cache-purge-time-stamp';
    case EDGE_CACHE_SINGLE_PAGE_URL_PURGED         = 'edge-cache-single-page-url-purged';

    // ── Batcache extension ────────────────────────────────────────────────────
    case EXTEND_BATCACHE_CHECKBOX                  = 'extend_batcache_checkbox';
    case EXTEND_BATCACHE_NOTICE_PENDING            = 'pcm_extend_batcache_notice_pending';

    // ── Cache exclusions ──────────────────────────────────────────────────────
    case EXEMPT_FROM_BATCACHE                      = 'exempt_from_batcache';
    case EXCLUDE_QUERY_STRING_GCLID                = 'exclude_query_string_gclid';
    case EXCLUDE_QUERY_STRING_GCLID_NOTICE         = 'exclude_query_string_gclid_activate_notice';

    // ── WooCommerce product page flush ────────────────────────────────────────
    case WOO_INDIVIDUAL_PAGE_NOTICE                = 'flush_batcache_for_woo_product_individual_page_activate_notice';

    // ── Cookie / WP-PP cache ──────────────────────────────────────────────────
    case CACHE_WPP_COOKIES_PAGES                   = 'cache_wpp_cookies_pages';
    case CACHE_WPP_COOKIES_PAGES_NOTICE            = 'cache_wpp_cookies_pages_activate_notice';

    // ── Feature flags ─────────────────────────────────────────────────────────
    case ENABLE_CACHING_SUITE_FEATURES             = 'pcm_enable_caching_suite_features';
    case ENABLE_REDIRECT_ASSISTANT                 = 'pcm_enable_redirect_assistant';
    case ENABLE_ADVANCED_SCAN_WORKFLOWS            = 'pcm_enable_advanced_scan_workflows';
    case ENABLE_DURABLE_ORIGIN_MICROCACHE          = 'pcm_enable_durable_origin_microcache';

    // ── Observability / Reporting metrics ─────────────────────────────────────
    case LATEST_OBJECT_CACHE_HIT_RATIO             = 'pcm_latest_object_cache_hit_ratio';
    case LATEST_OBJECT_CACHE_EVICTIONS             = 'pcm_latest_object_cache_evictions';
    case LATEST_OPCACHE_MEMORY_PRESSURE            = 'pcm_latest_opcache_memory_pressure';
    case LATEST_OPCACHE_RESTARTS                   = 'pcm_latest_opcache_restarts';
    case LATEST_CACHEABILITY_SCORE                 = 'pcm_latest_cacheability_score';
    case LATEST_PURGE_ACTIVITY                     = 'pcm_latest_purge_activity';
    case REPORT_DIGEST_RECIPIENTS                  = 'pcm_report_digest_recipients';
    case REPORTING_RETENTION_DAYS                   = 'pcm_reporting_retention_days';
    case BATCACHE_HITS_24H                         = 'pcm_batcache_hits_24h';
    case LAST_TTL                                  = 'pcm_last_ttl';

    // ── Object Cache Intelligence ─────────────────────────────────────────────
    case OBJECT_CACHE_RETENTION_DAYS               = 'pcm_object_cache_retention_days';

    // ── OPcache Awareness ─────────────────────────────────────────────────────
    case OPCACHE_THRESHOLDS_V1                     = 'pcm_opcache_thresholds_v1';
    case OPCACHE_RETENTION_DAYS                    = 'pcm_opcache_retention_days';

    // ── Cacheability Advisor ──────────────────────────────────────────────────
    case CACHEABILITY_ADVISOR_DB_VERSION           = 'pcm_cacheability_advisor_db_version';

    // ── Security / Privacy ────────────────────────────────────────────────────
    case SECURITY_PRIVACY_CAPS_VERSION             = 'pcm_security_privacy_caps_version';
    case PRIVACY_SETTINGS_V1                       = 'pcm_privacy_settings_v1';

    // ── Durable Origin Microcache ─────────────────────────────────────────────
    case MICROCACHE_USE_CUSTOM_TABLE_INDEX         = 'pcm_microcache_use_custom_table_index';

    // ── Legacy / activation ───────────────────────────────────────────────────
    case API_ADMIN_NOTICE_STATUS                   = 'pressable_api_admin_notice__status';
    case FLUSH_OBJECT_CACHE_TIMESTAMP              = 'flush-object-cache-time-stamp';
}
