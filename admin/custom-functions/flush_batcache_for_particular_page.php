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

    function pcm_show_flush_cache_column() {
        if ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) {
            $column = new FlushObjectCachePageColumn();
            $column->add();
        }
    }

    function flush_object_cache_for_single_page_notice() {
        $state = get_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE, 'activating' );

        if ( 'activating' === $state &&
            ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') )
        ) {
            add_action( 'admin_notices', function() {
                $screen = get_current_screen();
                if ( ! isset( $screen ) || $screen->id !== 'toplevel_page_pressable_cache_management' ) return;

                $wrap = 'display:flex;align-items:center;justify-content:space-between;gap:12px;'
                      . 'border-left:4px solid #03fcc2;background:#fff;border-radius:0 8px 8px 0;'
                      . 'padding:14px 18px;box-shadow:0 2px 8px rgba(4,0,36,.07);'
                      . 'margin:10px 0;font-family:sans-serif;';
                $btn     = 'background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:0;';
                $pcm_nid = 'pcm-sp-notice-' . substr( md5( microtime() ), 0, 8 );
                echo '<div style="max-width:1120px;margin:0 auto;padding:0 20px;box-sizing:border-box;">';
                echo '<div id="' . $pcm_nid . '" style="' . $wrap . '">';
                echo '<p style="margin:0;font-size:13px;color:#040024;">'
                   . esc_html__( 'You can Flush Cache for Individual page or post from page preview.', 'pressable_cache_management' )
                   . '</p>';
                echo '<button type="button" onclick="document.getElementById(\'' . $pcm_nid . '\').remove();" style="' . $btn . '">&#x2297;</button>';
                echo '</div>';
                echo '</div>';
            });

            update_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE, 'activated' );
        }
    }
    add_action( 'init', 'flush_object_cache_for_single_page_notice' );

} else {
    update_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE, 'activating' );
}

// ─── FlushObjectCachePageColumn class ────────────────────────────────────────
if ( ! class_exists( 'FlushObjectCachePageColumn' ) ) {
    class FlushObjectCachePageColumn {
        public function __construct() {}

        public function add() {
            add_filter( 'post_row_actions', array( $this, 'add_flush_object_cache_link' ), 10, 2 );
            add_filter( 'page_row_actions', array( $this, 'add_flush_object_cache_link' ), 10, 2 );
            add_action( 'admin_enqueue_scripts', array( $this, 'load_js' ) );
            add_action( 'wp_ajax_pcm_flush_object_cache_column', array( $this, 'flush_object_cache_column' ) );
        }

        public function add_flush_object_cache_link( $actions, $post ) {
            if ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) {
                $actions['flush_object_cache_url'] =
                    '<a data-id="' . esc_attr( $post->ID ) . '"'
                    . ' data-nonce="' . wp_create_nonce( 'flush-object-cache_' . $post->ID ) . '"'
                    . ' id="flush-object-cache-url-' . esc_attr( $post->ID ) . '"'
                    . ' style="cursor:pointer;">'
                    . esc_html__( 'Flush Cache', 'pressable_cache_management' ) . '</a>';
            }
            return $actions;
        }

        public function flush_object_cache_column() {
            if ( ! ( current_user_can('administrator') || current_user_can('editor') || current_user_can('manage_woocommerce') ) ) {
                wp_send_json_error( 'Unauthorized', 403 );
            }

            $post_id      = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
            $nonce_action = 'flush-object-cache_' . $post_id;
            pcm_verify_ajax_request( 'nonce', $nonce_action, 'GET', 'edit_posts' );

            $url_key    = get_permalink( intval( $_GET['id'] ) );
            $page_title = get_the_title( intval( $_GET['id'] ) );
            update_option( PCM_Options::PAGE_TITLE, $page_title );

            global $batcache, $wp_object_cache;

            if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
                die( json_encode( array( 'success' => false ) ) );
            }

            $batcache->configure_groups();
            $url = apply_filters( 'batcache_manager_link', $url_key );
            if ( empty( $url ) ) {
                die( json_encode( array( 'success' => false ) ) );
            }

            do_action( 'batcache_manager_before_flush', $url );
            $url     = set_url_scheme( $url, 'http' );
            $url_key = md5( $url );

            wp_cache_add( "{$url_key}_version", 0, $batcache->group );
            wp_cache_incr( "{$url_key}_version", 1, $batcache->group );

            if ( property_exists( $wp_object_cache, 'no_remote_groups' ) ) {
                $k = array_search( $batcache->group, (array) $wp_object_cache->no_remote_groups );
                if ( false !== $k ) {
                    unset( $wp_object_cache->no_remote_groups[ $k ] );
                    wp_cache_set( "{$url_key}_version", $batcache->group );
                    $wp_object_cache->no_remote_groups[ $k ] = $batcache->group;
                }
            }

            do_action( 'batcache_manager_after_flush', $url );
            update_option( PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP, pcm_format_flush_timestamp() );
            // Also store the flushed URL so it shows on the settings page
            update_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED, $url );

            die( json_encode( array( 'success' => true ) ) );
        }

        public function load_js() {
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
