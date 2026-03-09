<?php
// Pressable Cache Management - Extend batcache by 24 hours

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$options = pcm_get_options();

if ( isset( $options['extend_batcache_checkbox'] ) && ! empty( $options['extend_batcache_checkbox'] ) ) {

    update_option( PCM_Options::EXTEND_BATCACHE_CHECKBOX->value, $options['extend_batcache_checkbox'] );
    $extend_batcache = get_option( PCM_Options::EXTEND_BATCACHE_CHECKBOX->value );

    // Create the mu-plugin index file if it doesn't exist
    $pcm_mu_plugins_index = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management.php';
    if ( ! file_exists( $pcm_mu_plugins_index ) ) {
        copy( plugin_dir_path( __FILE__ ) . '/pressable_cache_management_mu_plugin_index.php', $pcm_mu_plugins_index );
    }

    // Ensure mu-plugins directory exists
    if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/' ) ) {
        wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/' );
    }

    $obj_extend_batcache        = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/pcm_extend_batcache.php';
    $obj_extend_batcache_source = plugin_dir_path( __FILE__ ) . '/extend_batcache_mu_plugin.php';
    $obj_extend_batcache_active = $obj_extend_batcache;

    if ( ! file_exists( $obj_extend_batcache ) ) {
        // mu-plugin doesn't exist yet — this is a FRESH enable, copy file and queue notice
        wp_cache_flush();
        if ( copy( $obj_extend_batcache_source, $obj_extend_batcache_active ) ) {
            // Mark notice as pending — will show ONCE on next admin page load, then clear itself
            update_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value, '1' );
        }
    }
    // If file already exists: checkbox was already on before this load — do NOT re-queue notice

} else {

    // Checkbox is OFF — clear the notice flag and remove the mu-plugin
    delete_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value );

    $obj_extend_batcache = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/pcm_extend_batcache.php';
    if ( file_exists( $obj_extend_batcache ) ) {
        unlink( $obj_extend_batcache );
        wp_cache_flush();
    }
}
