<?php
/**
 * Runs on Uninstall of Pressable Cache Management
 *
 * @package   Pressable Cache Management
 * @author    Pressable Support Team
 * @license   GPL-2.0+
 * @link      http://pressable.com
 */

// Exit if uninstall constant is not defined (security check)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove mu-plugins written by this plugin (batcache extensions, exclusions, etc.)
include_once plugin_dir_path( __FILE__ ) . 'remove-mu-plugins-batcache-on-uninstall.php';

// Load option name constants so uninstall uses the same single source of truth.
require_once plugin_dir_path( __FILE__ ) . 'includes/constants.php';

// ── Delete every option this plugin has ever written to the database ──────────

$options_to_delete = array(

    // ── Main settings groups ──────────────────────────────────────────────────
    PCM_Options::MAIN_OPTIONS,                              // All main tab checkbox settings
    PCM_Options::REMOVE_BRANDING_OPTIONS,                   // Branding show/hide setting
    PCM_Options::EDGE_CACHE_SETTINGS_OPTIONS,               // Edge Cache tab settings

    // ── Object cache flush timestamps ─────────────────────────────────────────
    PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP,                 // Global object cache flush time
    PCM_Options::FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP,        // Flush on plugin/theme update
    PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP,           // Flush on post/page edit
    PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP,    // Flush on page/post delete
    PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP,      // Flush on comment delete

    // ── Individual page flush ─────────────────────────────────────────────────
    PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP,               // Single page flush time
    PCM_Options::FLUSH_SINGLE_PAGE_NOTICE,                  // Single page flush notice
    PCM_Options::SINGLE_PAGE_URL_FLUSHED,                   // URL of last single-page flush
    PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP,    // Single page edge cache purge time
    'single-page-path-url',                                 // Stored path for single page (legacy)
    PCM_Options::PAGE_TITLE,                                // Stored page title
    'page-url',                                             // Stored page URL (legacy)

    // ── Edge cache ────────────────────────────────────────────────────────────
    PCM_Options::EDGE_CACHE_ENABLED,                        // Edge cache on/off state
    PCM_Options::EDGE_CACHE_STATUS,                         // Edge cache status string
    PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP,                // Last edge cache purge time
    PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED,         // Last single-page edge purge URL

    // ── Batcache extension ────────────────────────────────────────────────────
    PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING,            // Pending "extending batcache" notice flag

    // ── Cache exclusions ──────────────────────────────────────────────────────
    PCM_Options::EXEMPT_FROM_BATCACHE,                      // Pages excluded from Batcache
    PCM_Options::EXCLUDE_QUERY_STRING_GCLID,                // GCLID query string exclusion flag
    PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE,

    // ── WooCommerce product page flush ────────────────────────────────────────
    PCM_Options::WOO_INDIVIDUAL_PAGE_NOTICE,

    // ── Cookie / WP-PP cache ──────────────────────────────────────────────────
    PCM_Options::CACHE_WPP_COOKIES_PAGES,
    PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE,

    // ── Legacy / CDN options (from older plugin versions) ─────────────────────
    'cdn_settings_tab_options',
    'pressable_api_authentication_tab_options',
    'cdn-cache-purge-time-stamp',
    'cdn-api-state',
    'cdnenabled',
    PCM_Options::API_ADMIN_NOTICE_STATUS,
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
    PCM_Options::FLUSH_SINGLE_PAGE_NOTICE,
    'pressable_site_id',
    'pcm_site_id_added_activate_notice',
    'pcm_site_id_con_res',
    'pcm_client_id',
    'pcm_client_secret',

    // ── Update checker transients (PUC) ───────────────────────────────────────
    // PUC stores its own transients; delete them to leave no trace
    'puc_check_now_pressable-cache-management',

    // ── Plugin own transients (stored as options by WP) ───────────────────────
    '_transient_pcm_batcache_status',
    '_transient_timeout_pcm_batcache_status',
);

foreach ( $options_to_delete as $option ) {
    delete_option( $option );
}

// ── Delete PUC transients (wp_options rows with _transient_ prefix) ───────────
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_puc_' )     . '%pressable-cache-management%',
        $wpdb->esc_like( '_transient_timeout_puc_' ) . '%pressable-cache-management%'
    )
);
