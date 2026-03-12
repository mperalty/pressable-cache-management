<?php
/*
Plugin Name:  Pressable Cache Management
Description:  Pressable cache management made easy
Plugin URI:   https://pressable.com/knowledgebase/pressable-cache-management-plugin/#overview
Author:       Malcolm Peralty and Pressable Customer Support Team
Version:      5.9.0
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 8.1
Text Domain:  pressable_cache_management
Domain Path:  /languages
License:      GPL v2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.txt
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pcm_activate_plugin_defaults(): void {
	add_option( PCM_Options::API_ADMIN_NOTICE_STATUS->value, 'OK' );
}
register_activation_hook( __FILE__, 'pcm_activate_plugin_defaults' );

/**
 * On deactivation, remove all MU-plugin files that PCM has installed.
 *
 * Options and transients are intentionally preserved so that settings
 * survive a deactivate-reactivate cycle.  The admin_init hooks in the
 * custom-functions files will re-create the MU-plugin files automatically
 * on the next admin page load after the plugin is reactivated.
 */
function pcm_deactivate_cleanup(): void {
	global $wp_filesystem;

	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
	}

	// ── 1. Remove the MU-plugin index loader ─────────────────────────────────
	$index_file = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management.php';
	if ( $wp_filesystem->exists( $index_file ) ) {
		$wp_filesystem->delete( $index_file );
	}

	// ── 2. Remove the entire pressable-cache-management MU-plugin directory ──
	//    Contains: pcm_extend_batcache.php, pcm_exclude_pages_from_batcache.php,
	//              pcm_exclude_query_string_gclid.php, pcm_cache_wpp_cookies_pages.php
	$mu_dir = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management';
	if ( $wp_filesystem->exists( $mu_dir ) ) {
		$wp_filesystem->delete( $mu_dir, true ); // true = recursive
	}

	// ── 3. Remove the Woo individual-page batcache MU-plugin ───────────────
	$batcache_mgr = WP_CONTENT_DIR . '/mu-plugins/pcm_batcache_manager.php';
	if ( $wp_filesystem->exists( $batcache_mgr ) ) {
		$wp_filesystem->delete( $batcache_mgr );
	}

	// ── 3b. Remove legacy hyphenated filename (pre-rename installs) ─────────
	$legacy_file = WP_CONTENT_DIR . '/mu-plugins/pcm-batcache-manager.php';
	if ( $wp_filesystem->exists( $legacy_file ) ) {
		$wp_filesystem->delete( $legacy_file );
	}

	// ── 4. Clean up orphaned Smart Purge Strategy options & cron ─────────────
	$smart_purge_options = array(
		'pcm_smart_purge_active_mode',
		'pcm_smart_purge_cooldown_seconds',
		'pcm_smart_purge_defer_seconds',
		'pcm_smart_purge_enable_prewarm',
		'pcm_smart_purge_prewarm_url_cap',
		'pcm_smart_purge_prewarm_batch_size',
		'pcm_smart_purge_prewarm_repeat_hits',
		'pcm_smart_purge_important_urls',
		'pcm_smart_purge_jobs_v1',
		'pcm_smart_purge_events_v1',
		'pcm_smart_purge_outcomes_v1',
	);
	foreach ( $smart_purge_options as $opt ) {
		delete_option( $opt );
	}
	delete_transient( 'pcm_smart_purge_settings_notices' );
	wp_clear_scheduled_hook( 'pcm_smart_purge_run_queue' );
}
register_deactivation_hook( __FILE__, 'pcm_deactivate_cleanup' );

// ─── GitHub Auto-Updates via plugin-update-checker (YahnisElsts/plugin-update-checker) ──
// Library lives at: includes/plugin-update-checker/plugin-update-checker.php
// How updates are triggered: create a GitHub Release (or tag) on the repo and
// bump the Version header in this file. WordPress will show the update notice
// to all sites running the plugin within ~12 hours.
$pcm_puc_file = plugin_dir_path( __FILE__ ) . 'includes/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $pcm_puc_file ) ) {
	require_once $pcm_puc_file;
}

if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
	$pcm_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/pressable/pressable-cache-management/', // GitHub repo URL (with trailing slash)
		__FILE__,                                                     // Full path to main plugin file
		'pressable-cache-management'                                  // Plugin slug (folder name)
	);

	// Use tagged GitHub Releases as the update source.
	// To release an update: tag the commit as v5.x.x in GitHub and publish a Release.
	if ( method_exists( $pcm_update_checker, 'getVcsApi' ) ) {
		$pcm_vcs_api = $pcm_update_checker->getVcsApi();
		if ( is_object( $pcm_vcs_api ) && method_exists( $pcm_vcs_api, 'enableReleaseAssets' ) ) {
			$pcm_vcs_api->enableReleaseAssets();
		}
	}
}

// ─── Platform check ──────────────────────────────────────────────────────────
if ( ! defined( 'IS_PRESSABLE' ) ) {
	add_action( 'admin_notices', 'pcm_auto_deactivation_notice' );
	add_action( 'admin_init', 'deactivate_plugin_if_not_pressable' );
}

function deactivate_plugin_if_not_pressable(): void {
	deactivate_plugins( plugin_basename( __FILE__ ) );
}

function pcm_auto_deactivation_notice(): void {
	$style = 'margin:50px 20px 20px 0;background:#fff;'
			. 'border-left:4px solid #dd3a03;border-radius:0 8px 8px 0;'
			. 'padding:18px 20px;box-shadow:0 2px 8px rgba(4,0,36,.07);font-family:sans-serif;';
	echo '<div style="' . esc_attr( $style ) . '">';
	echo '<h3 style="margin:0 0 8px;color:#dd3a03;font-weight:700;">'
		. esc_html__( 'Attention!', 'pressable_cache_management' ) . '</h3>';
	echo '<p style="margin:0;color:#040024;">'
		. esc_html__( 'This plugin is not supported on this platform.', 'pressable_cache_management' ) . '</p>';
	echo '</div>';
}

// ─── i18n – load translations (en_US, es_ES, fr_FR, etc.) ───────────────────
function pressable_cache_management_load_textdomain(): void {
	// Third parameter must be relative to WP_PLUGIN_DIR (no leading slash, no absolute path)
	load_plugin_textdomain(
		'pressable_cache_management',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);
}
add_action( 'plugins_loaded', 'pressable_cache_management_load_textdomain' );

// ─── Option name constants ───────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/constants.php';

// ─── Shared helpers ──────────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers.php';

// ─── Canonical cache service (must load before custom-functions) ─────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/cache-service.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/cacheability-advisor/popular-url-tracker.php';

// ─── Admin-only includes ─────────────────────────────────────────────────────
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'admin/admin-menu.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/settings-page.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/settings-register.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/settings-callbacks.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/settings-validate.php';
	require_once plugin_dir_path( __FILE__ ) . 'remove-old-mu-plugins.php';

	// Must load turn_on_off BEFORE purge (purge reuses pcm_edge_notice)
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/turn-on-off-edge-cache.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/purge-edge-cache.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/flush-object-cache.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/extend-batcache.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/object-cache-admin-bar.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/flush-batcache-for-woo-individual-page.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/exclude-pages-from-batcache.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/flush-batcache-for-particular-page.php';
	require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/remove-pressable-branding.php';
}

// ─── Front-end + admin cache flush triggers ──────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/flush-cache-on-theme-plugin-update.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/class-pcm-cache-flush-dispatcher.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/custom-functions/class-pcm-flush-single-page-toolbar.php';

// ─── Microcache hooks into save_post at priority 20, must load early ─────────
if ( (bool) apply_filters( 'pcm_enable_durable_origin_microcache', (bool) get_option( PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value, false ) ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/durable-origin-microcache/microcache.php';
}

// ─── Defer heavy feature modules — only load on PCM admin pages or AJAX ──────
function pcm_load_feature_modules(): void {
	$dir = plugin_dir_path( __FILE__ ) . 'includes/';
	require_once $dir . 'cacheability-advisor/storage.php';
	require_once $dir . 'cache-busters/detector-framework.php';
	require_once $dir . 'object-cache-intelligence/intelligence.php';
	require_once $dir . 'php-opcache-awareness/opcache-awareness.php';
	require_once $dir . 'redirect-assistant/assistant.php';
	require_once $dir . 'security-privacy/security-privacy.php';
	require_once $dir . 'guided-remediation-playbooks/playbooks.php';
	require_once $dir . 'layered-probe/layered-probe.php';
	require_once $dir . 'cacheability-advisor/scenario-scan.php';
}
if ( wp_doing_ajax() ) {
	// AJAX handlers are registered in these modules — always load for AJAX.
	add_action( 'admin_init', 'pcm_load_feature_modules' );
} elseif ( is_admin() ) {
	// Only load on the plugin's own settings page to avoid overhead on every admin request.
	add_action(
		'admin_init',
		function (): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
			if ( 'pressable_cache_management' === $page ) {
				pcm_load_feature_modules();
			}
		}
	);
}

// ─── Settings link on plugin list page ──────────────────────────────────────
function pcm_settings_link( array $links ): array {
	$settings_link = '<a href="admin.php?page=pressable_cache_management">'
					. esc_html__( 'Settings', 'pressable_cache_management' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pcm_settings_link' );
