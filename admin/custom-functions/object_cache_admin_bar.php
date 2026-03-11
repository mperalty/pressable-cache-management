<?php
/**
 * Pressable Cache Management - Admin Bar Cache Buttons
 * Branded popup notices matching plugin theme (#dd3a03, #040024, #03fcc2)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Branded modal popup (replaces browser alert) ─────────────────────────
add_action( 'admin_footer', 'pcm_abar_modal_html' );
function pcm_abar_modal_html(): void {
    if ( ! pcm_abar_can_view() ) return;
    ?>
    <div id="pcm-modal-overlay" class="pcm-modal-overlay">
        <div class="pcm-modal-dialog">
            <div class="pcm-modal-accent"></div>
            <div id="pcm-modal-message" class="pcm-modal-message"></div>
            <button id="pcm-modal-ok" class="pcm-modal-ok">OK</button>
        </div>
    </div>
    <script>
    (function($){
        function pcmShowModal(msg) {
            $('#pcm-modal-message').text(msg);
            $('#pcm-modal-overlay').css('display','flex');
        }
        $('#pcm-modal-ok, #pcm-modal-overlay').on('click', function(e){
            if (e.target === this) $('#pcm-modal-overlay').hide();
        });
        $('#pcm-modal-ok').hover(
            function(){ $(this).css('background','#b82f00'); },
            function(){ $(this).css('background','#dd3a03'); }
        );
        window.pcmShowModal = pcmShowModal;
    })(jQuery);
    </script>
    <?php
}

// ─── JS: Flush Object Cache ────────────────────────────────────────────────
add_action( 'admin_footer', 'pcm_abar_object_js' );
function pcm_abar_object_js(): void {
    if ( ! pcm_abar_can_global_flush() ) return;
    ?>
    <script>
    jQuery(document).ready(function($){
        var $node = $('li#wp-admin-bar-cache-purge .ab-item');
        if (!$node.length) return;
        $node.on('click', function(e){
            e.preventDefault();
            $.post(ajaxurl, { action: 'flush_pressable_cache', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'pcm_abar_flush' ) ); ?>' }, function(r){
                window.pcmShowModal(r.trim());
            });
        });
    });
    </script>
<?php }

// ─── JS: Purge Edge Cache ──────────────────────────────────────────────────
add_action( 'admin_footer', 'pcm_abar_edge_js' );
function pcm_abar_edge_js(): void {
    if ( ! pcm_abar_can_global_flush() ) return;
    ?>
    <script>
    jQuery(document).ready(function($){
        var $node = $('li#wp-admin-bar-edge-purge .ab-item');
        if (!$node.length) return;
        $node.on('click', function(e){
            e.preventDefault();
            $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'pressable_edge_cache_purge', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'pcm_abar_flush' ) ); ?>' },
                success: function(r){ window.pcmShowModal(r.trim()); },
                error:   function(){ window.pcmShowModal('An error occurred during the Edge Cache purge request.'); }
            });
        });
    });
    </script>
<?php }

// ─── JS: Flush Object + Edge Cache ────────────────────────────────────────
add_action( 'admin_footer', 'pcm_abar_combined_js' );
function pcm_abar_combined_js(): void {
    if ( ! pcm_abar_can_global_flush() ) return;
    ?>
    <script>
    jQuery(document).ready(function($){
        var $node = $('li#wp-admin-bar-combined-cache-purge .ab-item');
        if (!$node.length) return;
        $node.on('click', function(e){
            e.preventDefault();
            $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'flush_combined_cache', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'pcm_abar_flush' ) ); ?>' },
                success: function(r){ window.pcmShowModal(r.trim()); },
                error:   function(){ window.pcmShowModal('An error occurred during the combined cache flush.'); }
            });
        });
    });
    </script>
<?php }

// ─── Enqueue toolbar CSS ───────────────────────────────────────────────────
function pcm_abar_load_css(): void {
    $css_path = plugin_dir_path( dirname( __FILE__ ) ) . 'public/css/toolbar.css';
    wp_enqueue_style( 'pressable-cache-management-toolbar',
        plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/toolbar.css',
        array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : '3.0.0', 'all' );
}
add_action( 'init', 'pcm_abar_load_css' );

// ─── AJAX Hooks ───────────────────────────────────────────────────────────
add_action( 'wp_ajax_flush_pressable_cache',    'pcm_abar_flush_object_callback' );
add_action( 'wp_ajax_pressable_edge_cache_purge', 'pcm_abar_purge_edge_callback' );
add_action( 'wp_ajax_flush_combined_cache',     'pcm_abar_flush_combined_callback' );

function pcm_abar_flush_object_callback(): void {
    check_ajax_referer( 'pcm_abar_flush' );
    $required_cap = apply_filters( 'pcm_flush_cache_capability', 'manage_options' );
    if ( ! current_user_can( $required_cap ) ) {
        echo 'You do not have permission to flush the Object Cache.';
        wp_die();
    }
    // Route through the canonical flush helper so post-flush hooks,
    // batcache status invalidation, and microcache cleanup all fire.
    if ( function_exists( 'pcm_flush_all_caches' ) ) {
        pcm_flush_all_caches();
    } else {
        wp_cache_flush();
        if ( function_exists( 'batcache_clear_cache' ) ) {
            batcache_clear_cache();
        }
        update_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value, pcm_format_flush_timestamp() );
    }
    echo esc_html__( 'Object Cache Flushed successfully.', 'pressable_cache_management' );
    wp_die();
}

function pcm_abar_purge_edge_callback(): void {
    check_ajax_referer( 'pcm_abar_flush' );
    $required_cap = apply_filters( 'pcm_flush_cache_capability', 'manage_options' );
    if ( ! current_user_can( $required_cap ) ) {
        echo 'You do not have permission to purge the Edge Cache.';
        wp_die();
    }
    if ( ! class_exists('Edge_Cache_Plugin') ) {
        echo esc_html__( 'Error: Edge Cache Plugin is not active. Purge aborted.', 'pressable_cache_management' );
        wp_die();
    }
    $edge_cache = Edge_Cache_Plugin::get_instance();
    if ( ! method_exists( $edge_cache, 'purge_domain_now' ) ) {
        echo esc_html__( 'Error: Edge Cache purge method unavailable.', 'pressable_cache_management' );
        wp_die();
    }
    $result = $edge_cache->purge_domain_now( 'admin-bar-edge-purge' );
    if ( $result ) {
        update_option( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value, pcm_format_flush_timestamp() );
        echo esc_html__( 'Edge Cache purged successfully.', 'pressable_cache_management' );
    } else {
        echo esc_html__( 'Edge Cache purge failed. It might be disabled or rate-limited.', 'pressable_cache_management' );
    }
    wp_die();
}

function pcm_abar_flush_combined_callback(): void {
    check_ajax_referer( 'pcm_abar_flush' );
    $required_cap = apply_filters( 'pcm_flush_cache_capability', 'manage_options' );
    if ( ! current_user_can( $required_cap ) ) {
        echo 'You do not have permission to flush the combined cache.';
        wp_die();
    }
    $messages = array();

    // Object cache — route through the canonical flush helper.
    if ( function_exists( 'pcm_flush_all_caches' ) ) {
        pcm_flush_all_caches();
    } else {
        wp_cache_flush();
        if ( function_exists( 'batcache_clear_cache' ) ) {
            batcache_clear_cache();
        }
        update_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value, pcm_format_flush_timestamp() );
    }
    $messages[] = esc_html__( 'Object Cache Flushed successfully.', 'pressable_cache_management' );

    // Edge cache
    if ( class_exists('Edge_Cache_Plugin') ) {
        $edge_cache = Edge_Cache_Plugin::get_instance();
        if ( method_exists( $edge_cache, 'purge_domain_now' ) ) {
            $result = $edge_cache->purge_domain_now( 'admin-bar-combined-purge' );
            if ( $result ) {
                update_option( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value, pcm_format_flush_timestamp() );
                $messages[] = esc_html__( 'Edge Cache Purged successfully.', 'pressable_cache_management' );
            } else {
                $messages[] = esc_html__( 'Edge Cache purge failed (possibly disabled or rate-limited).', 'pressable_cache_management' );
            }
        } else {
            $messages[] = esc_html__( 'Edge Cache Plugin active, but purge method unavailable.', 'pressable_cache_management' );
        }
    } else {
        $messages[] = esc_html__( 'Edge Cache Plugin not found; skipping Edge Cache purge.', 'pressable_cache_management' );
    }

    echo '- ' . implode( "\n- ", $messages );
    wp_die();
}

// ─── Permission helpers ───────────────────────────────────────────────────

/**
 * Whether the current user can perform global cache flush operations.
 *
 * Uses the filterable `pcm_flush_cache_capability` (default: manage_options)
 * so site owners can customise who may flush the entire object/edge cache.
 *
 * @return bool
 */
if ( ! function_exists( 'pcm_abar_can_global_flush' ) ) {
    function pcm_abar_can_global_flush(): bool {
        $required_cap = apply_filters( 'pcm_flush_cache_capability', 'manage_options' );
        return current_user_can( $required_cap );
    }
}

/**
 * Whether the current user can flush a single page's cache (low-impact).
 *
 * Available to anyone with the edit_posts capability (editors, authors, etc.).
 *
 * @return bool
 */
if ( ! function_exists( 'pcm_abar_can_single_page_flush' ) ) {
    function pcm_abar_can_single_page_flush(): bool {
        return current_user_can( 'edit_posts' );
    }
}

/**
 * Whether the current user should see *any* cache item in the admin bar.
 *
 * Returns true when the user qualifies for either global flush or
 * single-page flush.
 *
 * @return bool
 */
if ( ! function_exists( 'pcm_abar_can_view' ) ) {
    function pcm_abar_can_view(): bool {
        return pcm_abar_can_global_flush() || pcm_abar_can_single_page_flush();
    }
}

// ─── Admin Bar Menu ───────────────────────────────────────────────────────
add_action( 'admin_bar_menu', 'pcm_abar_add_menu', 100 );
function pcm_abar_add_menu( \WP_Admin_Bar $wp_admin_bar ): void {
    if ( is_network_admin() || ! pcm_abar_can_view() ) return;

    $branding_opts     = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
    $branding_disabled = $branding_opts && 'disable' == $branding_opts['branding_on_off_radio_button'];

    $parent_id    = $branding_disabled ? 'pcm-wp-admin-toolbar-parent-remove-branding' : 'pcm-wp-admin-toolbar-parent';
    $parent_title = $branding_disabled ? 'Cache Control' : 'Cache Management';

    // Detect Edge Cache state
    $edge_cache_is_enabled = false;
    if ( class_exists('Edge_Cache_Plugin') ) {
        $ec            = Edge_Cache_Plugin::get_instance();
        $server_status = method_exists($ec,'get_ec_status') ? $ec->get_ec_status() : null;
        if ( defined('Edge_Cache_Plugin::EC_ENABLED') && $server_status === Edge_Cache_Plugin::EC_ENABLED ) {
            $edge_cache_is_enabled = true;
        } elseif ( get_option( PCM_Options::EDGE_CACHE_ENABLED->value ) === 'enabled' ) {
            $edge_cache_is_enabled = true;
        }
    }

    // Parent
    $wp_admin_bar->add_node( array( 'id' => $parent_id, 'title' => $parent_title ) );

    // ── Global flush items (manage_options by default) ────────────────────

    if ( pcm_abar_can_global_flush() ) {
        // Flush Object Cache
        $wp_admin_bar->add_menu( array(
            'id'     => 'cache-purge',
            'title'  => __( 'Flush Object Cache', 'pressable_cache_management' ),
            'parent' => $parent_id,
            'meta'   => array( 'class' => 'pcm-wp-admin-toolbar-child' ),
        ));

        // Edge Cache options (only if enabled)
        if ( $edge_cache_is_enabled ) {
            $wp_admin_bar->add_menu( array(
                'id'     => 'edge-purge',
                'title'  => __( 'Purge Edge Cache', 'pressable_cache_management' ),
                'parent' => $parent_id,
                'meta'   => array( 'class' => 'pcm-wp-admin-toolbar-child' ),
            ));
            $wp_admin_bar->add_menu( array(
                'id'     => 'combined-cache-purge',
                'title'  => __( 'Flush Object & Edge Cache', 'pressable_cache_management' ),
                'parent' => $parent_id,
                'meta'   => array( 'class' => 'pcm-wp-admin-toolbar-child' ),
            ));
        }
    }

    // Cache Settings (admin only)
    if ( current_user_can('manage_options') ) {
        $wp_admin_bar->add_menu( array(
            'id'     => 'settings',
            'title'  => __( 'Cache Settings', 'pressable_cache_management' ),
            'parent' => $parent_id,
            'href'   => admin_url('admin.php?page=pressable_cache_management'),
            'meta'   => array( 'class' => 'pcm-wp-admin-toolbar-child' ),
        ));
    }
}
