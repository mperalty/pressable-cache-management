<?php
/**
 * Pressable Cache Management - Flush Object Cache + Page Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'pcm_flush_all_caches' ) ) {
    function pcm_flush_all_caches() {
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
            update_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP, pcm_format_flush_timestamp() );
        }

        return $success;
    }
}

if ( ! function_exists( 'pcm_ajax_flush_object_cache' ) ) {
    function pcm_ajax_flush_object_cache() {
        pcm_verify_ajax_request( 'flush_object_cache_nonce', 'flush_object_cache_nonce' );

        if ( ! pcm_flush_all_caches() ) {
            wp_send_json_error( array( 'message' => __( 'Object cache flush failed. Please try again.', 'pressable_cache_management' ) ) );
        }

        wp_send_json_success( array(
            'message'   => __( 'Object Cache Flushed Successfully.', 'pressable_cache_management' ),
            'timestamp' => (string) get_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP, '' ),
        ) );
    }
}
add_action( 'wp_ajax_pcm_flush_object_cache', 'pcm_ajax_flush_object_cache' );

if ( isset( $_POST['flush_object_cache_nonce'] ) ) {
    if ( pcm_verify_request( 'flush_object_cache_nonce', 'flush_object_cache_nonce' ) && pcm_flush_all_caches() ) {
        function flush_cache_notice__success() {
            $pcm_nid = 'pcm-obj-notice-' . substr( md5( microtime() ), 0, 8 );
            $wrap = 'display:flex;align-items:center;justify-content:space-between;gap:12px;'
                  . 'border-left:4px solid #03fcc2;background:#fff;border-radius:0 8px 8px 0;'
                  . 'padding:14px 18px;box-shadow:0 2px 8px rgba(4,0,36,.07);'
                  . 'margin:10px 20px 10px 0;font-family:sans-serif;';
            $btn  = 'background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:0;';
            echo '<div id="' . $pcm_nid . '" style="' . $wrap . '">';
            echo '<p style="margin:0;font-size:13px;color:#040024;">'
               . esc_html__( 'Object Cache Flushed Successfully.', 'pressable_cache_management' )
               . '</p>';
            echo '<button type="button" onclick="document.getElementById(\'' . $pcm_nid . '\').remove();" style="' . $btn . '">&#x2297;</button>';
            echo '</div>';
        }
        add_action( 'admin_notices', 'flush_cache_notice__success' );
    }
}
