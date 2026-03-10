<?php
/**
 * Pressable Cache Management - Flush Object Cache + Page Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'pcm_flush_all_caches' ) ) {
    function pcm_flush_all_caches(): bool {
        $success = (bool) wp_cache_flush();

        if ( function_exists( 'batcache_clear_cache' ) ) {
            batcache_clear_cache();
        }

        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }

        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }

        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }

        do_action( 'pcm_flush_all_cache' );
        do_action( 'pcm_after_object_cache_flush' );
        delete_transient( 'pcm_batcache_status' );

        if ( $success ) {
            update_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value, pcm_format_flush_timestamp() );
        }

        return $success;
    }
}

if ( ! function_exists( 'pcm_ajax_flush_object_cache' ) ) {
    function pcm_ajax_flush_object_cache(): void {
        pcm_verify_ajax_request( 'flush_object_cache_nonce', 'flush_object_cache_nonce' );

        if ( ! pcm_flush_all_caches() ) {
            wp_send_json_error( array( 'message' => __( 'Object cache flush failed. Please try again.', 'pressable_cache_management' ) ) );
        }

        wp_send_json_success( array(
            'message'   => __( 'Object Cache Flushed Successfully.', 'pressable_cache_management' ),
            'timestamp' => (string) get_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value, '' ),
        ) );
    }
}
add_action( 'wp_ajax_pcm_flush_object_cache', 'pcm_ajax_flush_object_cache' );

if ( isset( $_POST['flush_object_cache_nonce'] ) ) {
    if ( pcm_verify_request( 'flush_object_cache_nonce', 'flush_object_cache_nonce' ) && pcm_flush_all_caches() ) {
        add_action( 'admin_notices', function(): void {
            pcm_admin_notice( __( 'Object Cache Flushed Successfully.', 'pressable_cache_management' ), 'success' );
        });
    }
}
