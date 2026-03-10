<?php
/**
 * Pressable Cache Management — Flush Batcache for WooCommerce Individual Pages
 *
 * When enabled, copies pcm_batcache_manager.php into mu-plugins so that Batcache
 * is flushed automatically for any individual page/product updated via WooCommerce API.
 * When disabled, removes the mu-plugin file and restores the previous state.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$options = pcm_get_options();
$enabled = ! empty( $options['flush_batcache_for_woo_product_individual_page_checkbox'] );

$mu_plugin_dest = WP_CONTENT_DIR . '/mu-plugins/pcm_batcache_manager.php';
$mu_plugin_src  = plugin_dir_path( __FILE__ ) . 'pcm_batcache_manager.php';

if ( $enabled ) {

    // ── Feature ON ───────────────────────────────────────────────────────────

    // Always sync the source file into mu-plugins so that any updates to
    // pcm_batcache_manager.php (e.g. targeted flush fixes) take effect immediately.
    // Previously this only copied on first enable, meaning edits to the source
    // were never deployed to the live mu-plugin copy.
    $needs_update = ! file_exists( $mu_plugin_dest )
        || ( file_exists( $mu_plugin_src ) && md5_file( $mu_plugin_src ) !== md5_file( $mu_plugin_dest ) );

    if ( $needs_update && file_exists( $mu_plugin_src ) && copy( $mu_plugin_src, $mu_plugin_dest ) ) {
        // Schedule a deferred flush instead of flushing immediately
        pcm_schedule_deferred_flush();
        update_option( PCM_Options::WOO_INDIVIDUAL_PAGE_NOTICE->value, 'activating' );
    }

    // ── Show branded activation notice (once, on next page load) ─────────────
    add_action( 'admin_notices', 'pcm_woo_individual_page_activation_notice' );

    function pcm_woo_individual_page_activation_notice(): void {
        $state = get_option( PCM_Options::WOO_INDIVIDUAL_PAGE_NOTICE->value, 'activated' );
        if ( 'activating' !== $state || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'toplevel_page_pressable_cache_management' ) {
            return;
        }
        pcm_admin_notice(
            __( 'Flush Batcache for WooCommerce Product Pages — Enabled. Automatically flush individual pages, including product pages updated via the WooCommerce API.', 'pressable_cache_management' ),
            'success'
        );
        update_option( PCM_Options::WOO_INDIVIDUAL_PAGE_NOTICE->value, 'activated' );
    }

} else {

    // ── Feature OFF ──────────────────────────────────────────────────────────

    // Reset so notice shows again next time it is re-enabled
    update_option( PCM_Options::WOO_INDIVIDUAL_PAGE_NOTICE->value, 'activating' );

    if ( file_exists( $mu_plugin_dest ) ) {
        @unlink( $mu_plugin_dest );
        pcm_schedule_deferred_flush();
    }

}
