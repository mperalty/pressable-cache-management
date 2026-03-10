<?php
// Pressable Cache Management - Extend batcache by 24 hours

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$options = pcm_get_options();

if ( isset( $options['extend_batcache_checkbox'] ) && ! empty( $options['extend_batcache_checkbox'] ) ) {

    update_option( PCM_Options::EXTEND_BATCACHE_CHECKBOX->value, $options['extend_batcache_checkbox'] );

    $dest_file = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/pcm_extend_batcache.php';

    if ( ! file_exists( $dest_file ) ) {
        // Fresh enable — use the shared sync helper (which defers the flush)
        $synced = pcm_sync_mu_plugin(
            plugin_dir_path( __FILE__ ) . '/extend_batcache_mu_plugin.php',
            'pcm_extend_batcache.php'
        );
        if ( $synced ) {
            // Mark notice as pending — shows ONCE on next admin page load
            update_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value, '1' );
        }
    }

} else {

    // Checkbox is OFF — clear the notice flag and remove the mu-plugin
    delete_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value );
    pcm_remove_mu_plugin( 'pcm_extend_batcache.php' );
}
