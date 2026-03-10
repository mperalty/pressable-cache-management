<?php
/**
 * Pressable Cache Management - Flush Batcache for Individual Page from toolbar
 * Sourced from official repo flush_single_page_toolbar.php with branded notices.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$options = pcm_get_options();

if ( isset( $options['flush_object_cache_for_single_page'] ) && ! empty( $options['flush_object_cache_for_single_page'] ) ) {

    if ( ! class_exists( 'PcmFlushCacheAdminbar' ) ) {

        class PcmFlushCacheAdminbar {

            public function add(): void {
                if ( is_admin() ) {
                    add_action( 'wp_before_admin_bar_render', array( $this, 'PcmFlushCacheAdminbar' ) );
                    add_action( 'admin_enqueue_scripts', array( $this, 'load_toolbar_js' ) );
                    add_action( 'admin_enqueue_scripts', array( $this, 'load_remove_branding_toolbar_js' ) );
                    add_action( 'admin_enqueue_scripts', array( $this, 'load_toolbar_css' ) );
                } elseif ( is_admin_bar_showing() ) {
                    add_action( 'wp_before_admin_bar_render', array( $this, 'pcm_toolbar_for_page_preview' ) );
                    add_action( 'wp_enqueue_scripts', array( $this, 'load_toolbar_js' ) );
                    add_action( 'admin_enqueue_scripts', array( $this, 'load_remove_branding_toolbar_js' ) );
                    add_action( 'wp_enqueue_scripts', array( $this, 'load_toolbar_css' ) );
                    add_action( 'wp_footer', array( $this, 'print_my_inline_script' ) );
                }

                // AJAX: flush batcache for current page
                add_action( 'wp_ajax_pcm_delete_current_page_cache',      array( $this, 'pcm_delete_current_page_cache' ) );
                // AJAX: purge edge cache for current page
                add_action( 'wp_ajax_pcm_purge_current_page_edge_cache',  array( $this, 'pcm_purge_current_page_edge_cache' ) );
            }

            public function pcm_delete_current_page_cache(): void {
                pcm_verify_ajax_request( 'nonce', 'pcm_nonce', method: 'GET' );

                $path = esc_url_raw( urldecode( wp_unslash( $_GET['path'] ) ) );

                // Validate path: must start with / and contain no traversal sequences
                $parsed = wp_parse_url( $path, PHP_URL_PATH );
                if ( empty( $parsed ) || str_contains( $parsed, '..' ) || $parsed[0] !== '/' ) {
                    wp_send_json_error( array( 'reason' => 'Invalid path' ), 400 );
                }
                $path = $parsed;

                $url = get_home_url() . $path;

                if ( ! pcm_flush_batcache_url( $url ) ) {
                    wp_send_json_error( array( 'reason' => 'Batcache not available' ) );
                }

                update_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value, set_url_scheme( $url, 'http' ) );
                update_option( PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value, pcm_format_flush_timestamp() );
                wp_send_json_success( array( 'flushed' => 'batcache' ) );
            }

            public function pcm_purge_current_page_edge_cache(): void {
                pcm_verify_ajax_request( 'nonce', 'pcm_nonce', method: 'GET' );

                $path = esc_url_raw( urldecode( wp_unslash( $_GET['path'] ) ) );

                // Validate path: must start with / and contain no traversal sequences
                $parsed = wp_parse_url( $path, PHP_URL_PATH );
                if ( empty( $parsed ) || str_contains( $parsed, '..' ) || $parsed[0] !== '/' ) {
                    wp_send_json_error( array( 'reason' => 'Invalid path' ), 400 );
                }
                $path = $parsed;

                $url = get_home_url() . $path;
                update_option( PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED->value, $url );
                if ( empty( $url ) ) return;
                if ( class_exists( 'Edge_Cache_Plugin' ) ) {
                    $edge_cache = Edge_Cache_Plugin::get_instance();
                    $result     = $edge_cache->purge_uris_now( array( $url ) );
                    update_option( PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP->value, pcm_format_flush_timestamp() );
                    wp_send_json_success( array( 'flushed' => 'edge-cache' ) );
                }

                wp_send_json_error( array( 'reason' => 'Edge_Cache_Plugin not available' ) );
            }

            public function load_toolbar_css(): void {
                $css_path = plugin_dir_path( dirname( __FILE__ ) ) . 'public/css/toolbar.css';
                wp_enqueue_style( 'pressable-cache-management-toolbar',
                    plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/toolbar.css',
                    array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : '3.0.0', 'all' );
            }

            public function load_toolbar_js(): void {
                $js_path = plugin_dir_path( dirname( __FILE__ ) ) . 'public/js/toolbar.js';
                wp_enqueue_script( 'pcm-toolbar',
                    plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/toolbar.js',
                    array( 'jquery' ), file_exists( $js_path ) ? (string) filemtime( $js_path ) : '3.0.0', true );

                // Pass nonce and edge-cache state to JS for BOTH admin and frontend contexts.
                // pcm_nonce from print_my_inline_script() only runs on wp_footer (frontend).
                // wp_localize_script covers both admin and frontend reliably.
                $edge_on = ( get_option( PCM_Options::EDGE_CACHE_ENABLED->value ) === 'enabled' ) ? '1' : '0';
                wp_localize_script( 'pcm-toolbar', 'pcmToolbarData', array(
                    'nonce'    => wp_create_nonce( 'pcm_nonce' ),
                    'ajaxurl'  => admin_url( 'admin-ajax.php' ),
                    'flushEdge'=> $edge_on,
                ) );
            }

            public function load_remove_branding_toolbar_js(): void {
                $js_path = plugin_dir_path( dirname( __FILE__ ) ) . 'public/js/toolbar_remove_branding.js';
                wp_enqueue_script( 'pcm-toolbar-branding',
                    plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/toolbar_remove_branding.js',
                    array( 'jquery' ), file_exists( $js_path ) ? (string) filemtime( $js_path ) : '3.0.0', true );
            }

            public function print_my_inline_script(): void { ?>
                <script>
                var pcm_ajaxurl = "<?php echo esc_url( admin_url('admin-ajax.php') ); ?>";
                var pcm_nonce   = "<?php echo esc_js( wp_create_nonce('pcm_nonce') ); ?>";
                </script>
                <?php
            }

            public function pcm_toolbar_for_page_preview(): void {
                global $wp_admin_bar;

                $branding_opts     = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
                $branding_disabled = $branding_opts && 'disable' === $branding_opts['branding_on_off_radio_button'];
                $edge_cache_on     = ( get_option( PCM_Options::EDGE_CACHE_ENABLED->value ) === 'enabled' );

                // Single label: include Edge Cache in the title when it is active
                $flush_label = $edge_cache_on
                    ? __( 'Flush Cache for This Page', 'pressable_cache_management' )
                    : __( 'Flush Batcache for This Page', 'pressable_cache_management' );

                if ( $branding_disabled ) {
                    $parent = 'pcm-toolbar-parent-remove-branding';
                    $wp_admin_bar->add_node( array(
                        'id'    => $parent,
                        'title' => __( 'Flush Cache', 'pressable_cache_management' ),
                        'class' => 'pcm-toolbar-child',
                    ));
                    // Combined item — JS fires both Batcache + Edge Cache flushes in sequence
                    $wp_admin_bar->add_menu( array(
                        'id'     => 'pcm-toolbar-parent-remove-branding-flush-cache-of-this-page',
                        'title'  => $flush_label,
                        'parent' => $parent,
                        'meta'   => array( 'class' => 'pcm-toolbar-child' ),
                    ));
                } else {
                    $parent = 'pcm-toolbar-parent';
                    $wp_admin_bar->add_node( array(
                        'id'    => $parent,
                        'title' => __( 'Flush Cache', 'pressable_cache_management' ),
                    ));
                    // Combined item — JS fires both Batcache + Edge Cache flushes in sequence
                    $wp_admin_bar->add_menu( array(
                        'id'     => 'pcm-toolbar-parent-flush-cache-of-this-page',
                        'title'  => $flush_label,
                        'parent' => $parent,
                        'meta'   => array( 'class' => 'pcm-toolbar-child' ),
                    ));
                }
            }

            // Admin-side toolbar rendering is handled by cache-purge-admin-bar.php — this
            // method exists only as a hook target registered in add() above.
            public function PcmFlushCacheAdminbar(): void {}
        }
    }

    add_action( 'init', 'pcm_show_flush_cache_option_for_single_page' );

    function pcm_show_flush_cache_option_for_single_page(): void {
        if ( current_user_can( 'manage_options' ) ) {
            $toolbar = new PcmFlushCacheAdminbar();
            $toolbar->add();
        } else {
            if ( ! function_exists('load_admin_toolbar_css') ) {
                function load_admin_toolbar_css() {
                    // Use lightweight frontend-only CSS (no base64 loader, no admin styles)
                    $css_path = dirname( dirname( plugin_dir_path( __FILE__ ) ) ) . '/public/css/pcm-toolbar.css';
                    wp_enqueue_style( 'pressable-cache-management-toolbar',
                        plugins_url( 'public/css/pcm-toolbar.css', dirname( dirname( __FILE__ ) ) . '/pressable-cache-management.php' ),
                        array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : '3.0.0', 'all' );
                }
            }
            add_action( 'init', 'load_admin_toolbar_css' );
        }
    }
}
