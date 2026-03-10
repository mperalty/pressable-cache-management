<?php
/**
 * Pressable Cache Management - Flush cache for a particular page (column link)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$options = pcm_get_options();

if ( isset( $options['flush_object_cache_for_single_page'] ) && ! empty( $options['flush_object_cache_for_single_page'] ) ) {

    add_action( 'init', 'pcm_show_flush_cache_column' );

    function pcm_show_flush_cache_column(): void {
        if ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) {
            $column = new FlushObjectCachePageColumn();
            $column->add();
        }
    }

    function flush_object_cache_for_single_page_notice(): void {
        $state = get_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value, 'activating' );
        if ( 'activating' !== $state ) return;
        if ( ! ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) ) return;

        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'toplevel_page_pressable_cache_management' ) return;

        pcm_admin_notice(
            __( 'You can Flush Cache for Individual page or post from page preview.', 'pressable_cache_management' ),
            'success'
        );
        update_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value, 'activated' );
    }
    add_action( 'admin_notices', 'flush_object_cache_for_single_page_notice' );

} else {
    update_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value, 'activating' );
}

// ─── FlushObjectCachePageColumn class ────────────────────────────────────────
if ( ! class_exists( 'FlushObjectCachePageColumn' ) ) {
    class FlushObjectCachePageColumn {
        public function add(): void {
            add_filter( 'post_row_actions', array( $this, 'add_flush_object_cache_link' ), 10, 2 );
            add_filter( 'page_row_actions', array( $this, 'add_flush_object_cache_link' ), 10, 2 );
            add_action( 'admin_enqueue_scripts', array( $this, 'load_js' ) );
            add_action( 'wp_ajax_pcm_flush_object_cache_column', array( $this, 'flush_object_cache_column' ) );
        }

        public function add_flush_object_cache_link( array $actions, \WP_Post $post ): array {
            if ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) {
                $actions['flush_object_cache_url'] =
                    '<a data-id="' . esc_attr( $post->ID ) . '"'
                    . ' data-nonce="' . esc_attr( wp_create_nonce( 'flush-object-cache_' . $post->ID ) ) . '"'
                    . ' id="flush-object-cache-url-' . esc_attr( $post->ID ) . '"'
                    . ' style="cursor:pointer;">'
                    . esc_html__( 'Flush Cache', 'pressable_cache_management' ) . '</a>';
            }
            return $actions;
        }

        public function flush_object_cache_column(): void {
            if ( ! ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) ) {
                wp_send_json_error( 'Unauthorized', 403 );
            }

            $post_id      = (int) ( $_GET['id'] ?? 0 );
            $nonce_action = 'flush-object-cache_' . $post_id;
            pcm_verify_ajax_request( 'nonce', $nonce_action, method: 'GET', capability: 'edit_posts' );

            $url        = get_permalink( $post_id );
            $page_title = get_the_title( $post_id );
            update_option( PCM_Options::PAGE_TITLE->value, $page_title );

            if ( ! $url || ! pcm_flush_batcache_url( $url ) ) {
                wp_send_json_error( array( 'reason' => 'Batcache not available' ) );
            }

            update_option( PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value, pcm_format_flush_timestamp() );
            update_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value, set_url_scheme( $url, 'http' ) );

            wp_send_json_success( array( 'flushed' => true ) );
        }

        public function load_js(): void {
            $js_base_path = plugin_dir_path( dirname( __FILE__ ) ) . 'public/js/';
            $js_base_url  = plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/';

            $a11y_path = $js_base_path . 'pcm-modal-a11y.js';
            wp_enqueue_script(
                'pcm-modal-a11y',
                $js_base_url . 'pcm-modal-a11y.js',
                array(), file_exists( $a11y_path ) ? (string) filemtime( $a11y_path ) : '3.0.0', true
            );

            $js_path = $js_base_path . 'column.js';
            wp_enqueue_script(
                'flush-object-cache-column',
                $js_base_url . 'column.js',
                array( 'pcm-modal-a11y' ), file_exists( $js_path ) ? (string) filemtime( $js_path ) : '3.0.0', true
            );
        }
    }
}
