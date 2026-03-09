<?php
/**
 * Pressable Cache Management - Settings Page (Redesigned v3)
 * Fixes: double notices, visible timestamps, red→green hover button,
 *        correct feature list matching official repo, branded notices.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Kill WP's default "Settings saved." on our page – one branded notice only ──
// Priority 0 runs before settings_errors (priority 10), so we can remove it first.
add_action( 'admin_notices', 'pcm_kill_default_settings_notice', 0 );
function pcm_kill_default_settings_notice() {
    if ( ! isset( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'pressable_cache_management' ) return;
    remove_action( 'admin_notices', 'settings_errors', 10 );
}

add_action( 'admin_notices', 'pcm_branded_settings_saved_notice', 5 );
function pcm_branded_settings_saved_notice() {
    if ( ! isset( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'pressable_cache_management' ) return;
    if ( ! isset( $_GET['settings-updated'] ) || sanitize_key( $_GET['settings-updated'] ) !== 'true' ) return;

    $wrap = 'display:inline-flex;align-items:center;justify-content:space-between;gap:24px;'
          . 'border-left:4px solid #03fcc2;background:#fff;border-radius:0 8px 8px 0;'
          . 'padding:12px 16px;box-shadow:0 2px 8px rgba(4,0,36,.07);'
          . 'margin:10px 0 10px 8px;font-family:sans-serif;min-width:260px;max-width:480px;';
    $btn  = 'background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:0;flex-shrink:0;';
    $id   = 'pcm-settings-saved-notice';
    echo '<div id="' . $id . '" style="' . $wrap . '">';
    echo '<p style="margin:0;font-size:13px;color:#040024;">'
       . esc_html__( 'Cache settings updated.', 'pressable_cache_management' ) . '</p>';
    echo '<button type="button" onclick="document.getElementById(\'' . $id . '\').remove();" style="' . $btn . '">&#x2297;</button>';
    echo '</div>';
}

// ─── "Extending Batcache" notice — shows ONCE after first enable, then never again ─
// extend_batcache.php sets 'pcm_extend_batcache_notice_pending' only when it copies
// the mu-plugin for the first time (fresh enable). We delete the flag here immediately
// after rendering, so any subsequent page load or refresh will never see it again.
add_action( 'admin_notices', 'pcm_extend_batcache_branded_notice' );
function pcm_extend_batcache_branded_notice() {
    if ( ! isset( $_GET['page'] ) || sanitize_key( $_GET['page'] ) !== 'pressable_cache_management' ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Only show if freshly enabled
    if ( '1' !== get_option( 'pcm_extend_batcache_notice_pending' ) ) return;

    // Delete flag IMMEDIATELY — refresh / navigation will never trigger this again
    delete_option( 'pcm_extend_batcache_notice_pending' );

    $wrap = 'display:flex;align-items:center;justify-content:space-between;gap:12px;'
          . 'border-left:4px solid #03fcc2;background:#fff;border-radius:0 8px 8px 0;'
          . 'padding:14px 18px;box-shadow:0 2px 8px rgba(4,0,36,.07);'
          . 'margin:10px 0;font-family:sans-serif;';
    $btn  = 'background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:0;';
    echo '<div style="max-width:1120px;margin:0 auto;padding:0 20px;box-sizing:border-box;">';
    echo '<div id="pcm-extend-batcache-notice" style="' . $wrap . '">';
    echo '<p style="margin:0;font-size:13px;color:#040024;">'
       . esc_html__( 'Extending Batcache for 24 hours — see ', 'pressable_cache_management' )
       . '<a href="https://pressable.com/knowledgebase/modifying-cache-times-batcache/" target="_blank" rel="noopener noreferrer" '
       . 'style="color:#dd3a03;font-weight:600;text-decoration:none;">'
       . esc_html__( 'Modifying Batcache Times.', 'pressable_cache_management' ) . '</a>'
       . '</p>';
    echo '<button type="button" onclick="document.getElementById(\'pcm-extend-batcache-notice\').remove();" '
       . 'style="' . $btn . '">&#x2297;</button>';
    echo '</div>';
    echo '</div>';
}

// ─── Helper: pcm_branded_notice (shared, safe) ───────────────────────────────
if ( ! function_exists( 'pcm_branded_notice' ) ) {
    function pcm_branded_notice( $message, $border_color = '#03fcc2', $is_html = false ) {
        $id   = 'pcm-notice-' . substr( md5( $message . $border_color . microtime() ), 0, 8 );
        $wrap = 'display:inline-flex;align-items:flex-start;justify-content:space-between;gap:16px;'
              . 'border-left:4px solid ' . esc_attr( $border_color ) . ';background:#fff;'
              . 'border-radius:0 8px 8px 0;padding:14px 18px;'
              . 'box-shadow:0 2px 8px rgba(4,0,36,.07);margin:10px 0 10px 8px;font-family:sans-serif;min-width:260px;max-width:480px;';
        $btn  = 'background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;'
              . 'line-height:1;padding:0;flex-shrink:0;margin-top:2px;';
        echo '<div id="' . esc_attr( $id ) . '" style="' . $wrap . '"><div style="flex:1;">';
        if ( $is_html ) {
            echo $message;
        } else {
            echo '<p style="margin:0;font-size:13px;color:#040024;">' . esc_html( $message ) . '</p>';
        }
        echo '</div><button type="button" onclick="document.getElementById(\'' . esc_js( $id ) . '\').remove();" style="' . $btn . '">&#x2297;</button></div>';
    }
}

// ─── Batcache status check ───────────────────────────────────────────────────
// WHY BROWSER-SIDE: wp_remote_get() is a server-side loopback request. Pressable's
// infrastructure routes loopback requests directly to PHP, bypassing the Batcache/CDN
// layer entirely — so x-nananana is never present regardless of real cache state.
// The browser is the only client that sees the actual CDN response headers.
// SOLUTION: JS fetches the homepage with cache:reload (forces a fresh CDN response)
// and reads x-nananana directly, then reports the result back to PHP via AJAX.
// PHP only stores/returns the transient — it never probes the URL itself.

function pcm_get_batcache_status() {
    $cached = get_transient( 'pcm_batcache_status' );
    return ( $cached !== false ) ? $cached : 'unknown';
}

// ── AJAX: browser reports the header value it observed ───────────────────────
function pcm_ajax_report_batcache_header() {
    check_ajax_referer( 'pcm_batcache_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $raw = isset( $_POST['x_nananana'] ) ? sanitize_text_field( wp_unslash( $_POST['x_nananana'] ) ) : '';
    $val = strtolower( trim( $raw ) );

    if ( strpos( $val, 'batcache' ) !== false ) {
        $status = 'active';
    } elseif ( isset( $_POST['is_cloudflare'] ) && $_POST['is_cloudflare'] === '1' ) {
        $status = 'cloudflare';
    } else {
        $status = 'broken';
    }

    // Active: 24 hrs — prevents the badge falsely flipping to broken after 5 min.
    // Broken: 2 min — re-probe frequently until resolved.
    $ttl = ( $status === 'active' ) ? 86400 : 120;
    set_transient( 'pcm_batcache_status', $status, $ttl );

    $labels = array(
        'active'     => __( 'Batcache Active',     'pressable_cache_management' ),
        'cloudflare' => __( 'Cloudflare Detected', 'pressable_cache_management' ),
        'broken'     => __( 'Batcache Broken',     'pressable_cache_management' ),
    );

    wp_send_json_success( array(
        'status' => $status,
        'label'  => $labels[ $status ],
    ) );
}
add_action( 'wp_ajax_pcm_report_batcache_header', 'pcm_ajax_report_batcache_header' );

// ── AJAX: return current stored status (for badge refresh without re-fetching) ─
function pcm_ajax_get_batcache_status() {
    check_ajax_referer( 'pcm_batcache_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $status = pcm_get_batcache_status();
    $labels = array(
        'active'     => __( 'Batcache Active',     'pressable_cache_management' ),
        'cloudflare' => __( 'Cloudflare Detected', 'pressable_cache_management' ),
        'broken'     => __( 'Batcache Broken',     'pressable_cache_management' ),
        'unknown'    => __( 'Batcache Broken',     'pressable_cache_management' ),
    );

    wp_send_json_success( array(
        'status' => $status,
        'label'  => isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['broken'],
    ) );
}
add_action( 'wp_ajax_pcm_get_batcache_status', 'pcm_ajax_get_batcache_status' );

// Keep the old action name as an alias so any cached JS still works
add_action( 'wp_ajax_pcm_refresh_batcache_status', 'pcm_ajax_get_batcache_status' );

// ── AJAX: toggle Caching Suite feature flag without page reload ───────────────
function pcm_ajax_toggle_caching_suite_features() {
    check_ajax_referer( 'pcm_toggle_caching_suite_features', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Unauthorized', 'pressable_cache_management' ) ), 403 );
    }

    $enabled = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );
    update_option( 'pcm_enable_caching_suite_features', $enabled, false );

    if ( function_exists( 'pcm_audit_log' ) ) {
        pcm_audit_log( 'caching_suite_features_toggled', 'settings', array( 'enabled' => $enabled, 'source' => 'ajax' ) );
    }

    wp_send_json_success( array(
        'enabled' => $enabled,
        'label'   => $enabled
            ? __( 'Caching Suite enabled', 'pressable_cache_management' )
            : __( 'Caching Suite disabled', 'pressable_cache_management' ),
    ) );
}
add_action( 'wp_ajax_pcm_toggle_caching_suite_features', 'pcm_ajax_toggle_caching_suite_features' );

/**
 * Clear the cached status immediately after any cache flush
 * so the badge re-checks on next page load.
 */
function pcm_clear_batcache_status_transient() {
    delete_transient( 'pcm_batcache_status' );
}
add_action( 'pcm_after_object_cache_flush', 'pcm_clear_batcache_status_transient' );
add_action( 'pcm_after_batcache_flush',     'pcm_clear_batcache_status_transient' );
// Also clear when edge cache is fully purged — Batcache is implicitly invalidated too,
// so the next probe will correctly detect the transitional 'broken' state.
add_action( 'pcm_after_edge_cache_purge',   'pcm_clear_batcache_status_transient' );

// ─── Main page ───────────────────────────────────────────────────────────────
function pressable_cache_management_display_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    if ( isset( $_POST['pcm_feature_flags_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcm_feature_flags_nonce'] ) ), 'pcm_save_feature_flags' ) ) {
        $caching_suite_enabled = isset( $_POST['pcm_enable_caching_suite_features'] ) && '1' === (string) wp_unslash( $_POST['pcm_enable_caching_suite_features'] );
        $microcache_enabled    = isset( $_POST['pcm_enable_durable_origin_microcache'] ) && '1' === (string) wp_unslash( $_POST['pcm_enable_durable_origin_microcache'] );

        update_option( 'pcm_enable_caching_suite_features', $caching_suite_enabled, false );
        update_option( 'pcm_enable_durable_origin_microcache', $microcache_enabled, false );

        if ( function_exists( 'pcm_audit_log' ) ) {
            pcm_audit_log( 'caching_suite_features_toggled', 'settings', array( 'enabled' => $caching_suite_enabled ) );
            pcm_audit_log( 'durable_microcache_toggled', 'settings', array( 'enabled' => $microcache_enabled ) );
        }
    }

    $caching_suite_enabled = (bool) get_option( 'pcm_enable_caching_suite_features', false );
    $tab                   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : null;

    if ( 'deep_dive_tab' === $tab && ! $caching_suite_enabled ) {
        $tab = null;
    }

    $is_object_tab    = ( null === $tab );
    $is_deep_dive_tab = ( 'deep_dive_tab' === $tab );
    $is_settings_tab  = ( 'settings_tab' === $tab );

    $branding_opts         = get_option('remove_pressable_branding_tab_options');
    $show_branding         = ! ( $branding_opts && 'disable' == $branding_opts['branding_on_off_radio_button'] );
    $privacy_settings      = function_exists( 'pcm_get_privacy_settings' ) ? pcm_get_privacy_settings() : array();
    $bc_status             = pcm_get_batcache_status();
    $bc_is_unknown         = ( $bc_status === 'unknown' );

    $css_path = dirname( __DIR__ ) . '/public/css/style.css';
    wp_enqueue_style( 'pressable_cache_management',
        plugins_url( 'public/css/style.css', dirname( __DIR__ ) . '/pressable-cache-management.php' ),
        array(),
        file_exists( $css_path ) ? (string) filemtime( $css_path ) : '3.0.0',
        'screen'
    );
    wp_enqueue_style( 'pcm-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );

    $base_js_url  = plugin_dir_url( __FILE__ ) . 'public/js/';
    $base_js_path = plugin_dir_path( __FILE__ ) . 'public/js/';

    wp_enqueue_script(
        'pcm-post',
        $base_js_url . 'pcm-post.js',
        array(),
        file_exists( $base_js_path . 'pcm-post.js' ) ? (string) filemtime( $base_js_path . 'pcm-post.js' ) : false,
        true
    );

    wp_enqueue_script(
        'pcm-settings-page-settings',
        $base_js_url . 'settings.js',
        array( 'jquery', 'pcm-post' ),
        file_exists( $base_js_path . 'settings.js' ) ? (string) filemtime( $base_js_path . 'settings.js' ) : false,
        true
    );

    wp_enqueue_script(
        'pcm-settings-page-object-cache',
        $base_js_url . 'object-cache-tab.js',
        array( 'pcm-post' ),
        file_exists( $base_js_path . 'object-cache-tab.js' ) ? (string) filemtime( $base_js_path . 'object-cache-tab.js' ) : false,
        true
    );

    wp_enqueue_script(
        'pcm-settings-page-deep-dive',
        $base_js_url . 'deep-dive.js',
        array( 'pcm-post' ),
        file_exists( $base_js_path . 'deep-dive.js' ) ? (string) filemtime( $base_js_path . 'deep-dive.js' ) : false,
        true
    );

    wp_localize_script(
        'pcm-settings-page-settings',
        'pcmSettingsData',
        array(
            'nonces'          => array(
                'cacheabilityScan' => wp_create_nonce( 'pcm_cacheability_scan' ),
            ),
            'privacySettings' => $privacy_settings,
            'strings'         => array(
                'failedRetrieveStatus' => __( 'Failed to retrieve status.', 'pressable_cache_management' ),
                'couldNotConnect'      => __( 'Could not connect to server.', 'pressable_cache_management' ),
                'flushFailed'         => __( 'Object cache flush failed. Please try again.', 'pressable_cache_management' ),
                'flushUnauthorized'   => __( 'Unauthorized request.', 'pressable_cache_management' ),
            ),
        )
    );

    wp_localize_script(
        'pcm-settings-page-object-cache',
        'pcmObjectCacheData',
        array(
            'nonces'    => array(
                'batcache' => wp_create_nonce( 'pcm_batcache_nonce' ),
            ),
            'siteUrl'   => trailingslashit( get_site_url() ),
            'isUnknown' => $bc_is_unknown,
            'strings'   => array(
                'alreadyChecking' => __( 'Already checking…', 'pressable_cache_management' ),
                'checking'        => __( 'Checking…', 'pressable_cache_management' ),
            ),
        )
    );

    wp_localize_script(
        'pcm-settings-page-deep-dive',
        'pcmDeepDiveData',
        array(
            'nonces' => array(
                'cacheabilityScan' => wp_create_nonce( 'pcm_cacheability_scan' ),
            ),
            'siteUrl' => trailingslashit( get_site_url() ),
        )
    );
    ?>
    <div class="wrap pcm-wrap" style="background:#f0f2f5;margin-left:-20px;margin-right:-20px;padding:24px 28px 40px;min-height:calc(100vh - 32px);font-family:'Inter',sans-serif;">
    <div style="max-width:1120px;margin:0 auto;">
    <h1 style="display:none;"><?php echo esc_html( get_admin_page_title() ); ?></h1>


    <!-- ── Tabs ── -->
    <nav class="nav-tab-wrapper" id="pcm-main-tab-nav" style="margin-bottom:28px;">
        <a href="admin.php?page=pressable_cache_management"
           class="nav-tab <?php echo $is_object_tab ? 'nav-tab-active' : ''; ?>">Object Cache</a>
        <a href="admin.php?page=pressable_cache_management&tab=edge_cache_settings_tab"
           class="nav-tab <?php echo $tab === 'edge_cache_settings_tab' ? 'nav-tab-active' : ''; ?>">Edge Cache</a>
        <?php if ( $caching_suite_enabled ) : ?>
        <a href="admin.php?page=pressable_cache_management&tab=deep_dive_tab"
           class="nav-tab <?php echo $is_deep_dive_tab ? 'nav-tab-active' : ''; ?>" id="pcm-deep-dive-tab">Deep Dive</a>
        <?php endif; ?>
        <a href="admin.php?page=pressable_cache_management&tab=settings_tab"
           class="nav-tab <?php echo $is_settings_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
        <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab"
           class="nav-tab nav-tab-hidden <?php echo $tab === 'remove_pressable_branding_tab' ? 'nav-tab-active' : ''; ?>">Branding</a>
    </nav>

    <?php if ( $is_object_tab || $is_deep_dive_tab || $is_settings_tab ) :
        $options = get_option('pressable_cache_management_options');

        // Batcache status badge
        $bc_status     = pcm_get_batcache_status();
        $bc_is_unknown = ( $bc_status === 'unknown' );
        $bc_label      = $bc_is_unknown
            ? __( 'Checking…', 'pressable_cache_management' )
            : ( $bc_status === 'active'
                ? __( 'Batcache Active', 'pressable_cache_management' )
                : ( $bc_status === 'cloudflare'
                    ? __( 'Cloudflare Detected', 'pressable_cache_management' )
                    : __( 'Batcache Broken', 'pressable_cache_management' ) ) );
        $bc_class  = $bc_is_unknown ? 'checking' : ( $bc_status === 'active' ? 'active' : 'broken' );
    ?>

    <?php if ( $is_settings_tab ) : ?>
    <?php $microcache_enabled = (bool) get_option( 'pcm_enable_durable_origin_microcache', false ); ?>
    <div class="pcm-card" style="margin-bottom:20px;">
        <h3 class="pcm-card-title">🚩 <?php echo esc_html__( 'Feature Flags', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Control which major Caching Suite modules are active for diagnostics, automation, and deep-dive insights.', 'pressable_cache_management' ); ?></p>
        <form method="post">
            <input type="hidden" name="pcm_feature_flags_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pcm_save_feature_flags' ) ); ?>" />
            <input type="hidden" id="pcm-caching-suite-toggle-nonce" value="<?php echo esc_attr( wp_create_nonce( 'pcm_toggle_caching_suite_features' ) ); ?>" />

            <div class="pcm-toggle-row">
                <label class="switch" style="flex-shrink:0;margin-top:2px;">
                    <input type="checkbox" name="pcm_enable_caching_suite_features" id="pcm-caching-suite-toggle" value="1" <?php checked( $caching_suite_enabled ); ?> />
                    <span class="slider round"></span>
                </label>
                <div>
                    <div class="pcm-toggle-title"><?php echo esc_html__( 'Caching Suite', 'pressable_cache_management' ); ?>
                        <span class="pcm-status-badge <?php echo $caching_suite_enabled ? 'is-active' : 'is-inactive'; ?>" id="pcm-caching-suite-status-badge"><?php echo $caching_suite_enabled ? esc_html__( 'Active', 'pressable_cache_management' ) : esc_html__( 'Inactive', 'pressable_cache_management' ); ?></span>
                    </div>
                    <div class="pcm-toggle-desc"><?php echo esc_html__( 'Turns on the full diagnostics and remediation toolset used across Deep Dive analysis.', 'pressable_cache_management' ); ?></div>
                    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;font-size:11.5px;color:#475569;">
                        <span title="Analyzes headers and cache directives to highlight uncached opportunities."><?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?> ⓘ</span>
                        <span title="Surfaces noisy query strings and cookie patterns that break cache hit rates."><?php echo esc_html__( 'Cache-Busting Detector', 'pressable_cache_management' ); ?> ⓘ</span>
                        <span title="Tracks trend telemetry, reports, and exports for cache health visibility."><?php echo esc_html__( 'Observability Reporting', 'pressable_cache_management' ); ?> ⓘ</span>
                        <span title="Generates recommended next actions when anti-patterns are detected."><?php echo esc_html__( 'Guided Remediation', 'pressable_cache_management' ); ?> ⓘ</span>
                    </div>
                    <span class="pcm-ts-inline" id="pcm-caching-suite-inline-status" style="margin-top:6px;"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $caching_suite_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
                </div>
            </div>

            <div class="pcm-toggle-row" style="border-bottom:none;">
                <label class="switch" style="flex-shrink:0;margin-top:2px;">
                    <input type="checkbox" name="pcm_enable_durable_origin_microcache" value="1" <?php checked( $microcache_enabled ); ?> />
                    <span class="slider round"></span>
                </label>
                <div>
                    <div class="pcm-toggle-title"><?php echo esc_html__( 'Durable Origin Microcache', 'pressable_cache_management' ); ?></div>
                    <div class="pcm-toggle-desc"><?php echo esc_html__( 'Caches anonymous-safe JSON/HTML artifacts at origin with tag-based invalidation and fast regeneration.', 'pressable_cache_management' ); ?></div>
                    <span class="pcm-ts-inline" style="margin-top:6px;"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $microcache_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
                </div>
            </div>

            <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="pcm-btn-primary"><?php echo esc_html__( 'Save Feature Flags', 'pressable_cache_management' ); ?></button>
                <span style="color:#64748b;font-size:12px;"><?php echo esc_html__( 'Changes take effect immediately after save.', 'pressable_cache_management' ); ?></span>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ( $is_object_tab ) : ?>
    <!-- Header: logo + status badge -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <div>
            <?php if ( $show_branding ) : ?>
            <p style="font-size:11px;font-weight:600;color:#94a3b8;letter-spacing:1.2px;text-transform:uppercase;margin:0 0 6px;font-family:'Inter',sans-serif;"><?php echo esc_html__( 'Cache Management by', 'pressable_cache_management' ); ?></p>
            <img class="pressablecmlogo"
                 src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/img/pressable-logo-primary.svg' ); ?>"
                 alt="Pressable"
                 style="width:180px;height:auto;display:block;margin-bottom:6px;">
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <span class="pcm-batcache-status <?php echo esc_attr($bc_class); ?>" id="pcm-bc-badge">
                <span class="pcm-dot" id="pcm-bc-dot"></span>
                <span id="pcm-bc-label"><?php echo esc_html($bc_label); ?></span>
                <button id="pcm-bc-refresh" title="<?php esc_attr_e('Re-check Batcache status', 'pressable_cache_management'); ?>"
                        style="background:none;border:none;cursor:pointer;padding:0 0 0 6px;line-height:1;opacity:.6;font-size:13px;vertical-align:middle;"
                        onclick="pcmRefreshBatcacheStatus()">&#x21BB;</button>
            </span>
            <span class="pcm-bc-tooltip-wrap" style="position:relative;display:inline-flex;align-items:center;">
                <span style="width:16px;height:16px;border-radius:50%;background:#e2e8f0;color:#64748b;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;cursor:default;font-family:'Inter',sans-serif;line-height:1;flex-shrink:0;" aria-label="Batcache info">&#x3F;</span>
                <span class="pcm-bc-tooltip" style="display:none;position:absolute;right:0;top:24px;width:270px;background:#1e293b;color:#f1f5f9;font-size:11.5px;line-height:1.55;padding:10px 13px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.18);z-index:9999;font-family:'Inter',sans-serif;font-weight:400;">
                    <?php echo esc_html__( 'Use the refresh button to manually check your cache status. If the cache status remains broken for more than 4 minutes after two visits are recorded on your site, it is likely that caching is failing due to cookie interference from your plugin, theme or custom code.', 'pressable_cache_management' ); ?>
                    <span style="position:absolute;right:8px;top:-5px;width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-bottom:5px solid #1e293b;"></span>
                </span>
            </span>
        </div>
    </div>


    <?php endif; ?>


    <?php if ( $is_deep_dive_tab ) : ?>
    <?php
    $summary_cards = array(
        array(
            'icon' => '🧠',
            'label' => __( 'Object Cache Health', 'pressable_cache_management' ),
            'value' => (string) get_option( 'pcm_latest_object_cache_hit_ratio', '—' ) . '%',
            'target' => '#pcm-feature-object-cache-intelligence',
            'status' => (float) get_option( 'pcm_latest_object_cache_hit_ratio', 0 ) >= 70 ? 'good' : 'warn',
        ),
        array(
            'icon' => '📦',
            'label' => __( 'OPcache Health', 'pressable_cache_management' ),
            'value' => (string) get_option( 'pcm_latest_opcache_memory_pressure', '—' ) . '%',
            'target' => '#pcm-feature-opcache-awareness',
            'status' => (float) get_option( 'pcm_latest_opcache_memory_pressure', 0 ) < 90 ? 'good' : 'bad',
        ),
        array(
            'icon' => '⚡',
            'label' => __( 'Cacheability Score', 'pressable_cache_management' ),
            'value' => (string) get_option( 'pcm_latest_cacheability_score', '0' ),
            'target' => '#pcm-feature-cacheability-advisor',
            'status' => (float) get_option( 'pcm_latest_cacheability_score', 0 ) >= 80 ? 'good' : 'warn',
        ),
        array(
            'icon' => '🧹',
            'label' => __( 'Purge Activity', 'pressable_cache_management' ),
            'value' => (string) get_option( 'pcm_latest_purge_activity', 'N/A' ),
            'target' => '#pcm-feature-smart-purge-strategy',
            'status' => is_numeric( get_option( 'pcm_latest_purge_activity', null ) )
                ? ( (float) get_option( 'pcm_latest_purge_activity', 0 ) > 50 ? 'bad' : ( (float) get_option( 'pcm_latest_purge_activity', 0 ) > 20 ? 'warn' : 'good' ) )
                : 'warn',
        ),
    );
    ?>
    <div class="pcm-summary-grid" style="margin-bottom:16px;">
        <?php foreach ( $summary_cards as $card ) : ?>
        <a class="pcm-card pcm-card-hover pcm-summary-card" href="<?php echo esc_attr( $card['target'] ); ?>">
            <span class="pcm-summary-heading"><span class="pcm-summary-icon"><?php echo esc_html( $card['icon'] ); ?></span><span class="pcm-summary-label"><?php echo esc_html( $card['label'] ); ?></span></span>
            <span class="pcm-status-dot <?php echo esc_attr( 'is-' . $card['status'] ); ?>" aria-hidden="true"></span>
            <strong class="pcm-summary-value"><?php echo esc_html( $card['value'] ); ?></strong>
        </a>
        <?php endforeach; ?>
    </div>
    <nav class="pcm-anchor-nav" id="pcm-deep-dive-nav" aria-label="Deep Dive sections">
        <a href="#pcm-feature-cacheability-advisor">Cacheability</a>
        <a href="#pcm-feature-object-cache-intelligence">Object Cache</a>
        <a href="#pcm-feature-opcache-awareness">OPcache</a>
        <a href="#pcm-feature-redirect-assistant">Redirects</a>
        <a href="#pcm-feature-smart-purge-strategy">Smart Purge</a>
    </nav>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_cacheability_advisor_is_enabled' ) && pcm_cacheability_advisor_is_enabled() ) : ?>
    <div class="pcm-card pcm-card-hover" id="pcm-feature-cacheability-advisor" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">⚡ <?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0; color:#4b5563;"><?php echo esc_html__( 'Run a cacheability scan and review per-template scores, URL results, and findings.', 'pressable_cache_management' ); ?></p>
        <p>
            <button type="button" class="pcm-btn-primary" id="pcm-advisor-run-btn"><?php echo esc_html__( 'Rescan now', 'pressable_cache_management' ); ?></button>
            <span id="pcm-advisor-run-status" style="margin-left:10px;color:#374151;"></span>
        </p>
        <div class="pcm-advisor-grid pcm-responsive-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Template Scores', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-advisor-template-scores" style="font-size:13px;color:#111827;"></div>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Latest Findings', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-advisor-findings" style="font-size:13px;color:#111827;"></div>
            </div>
        </div>
        <div style="margin-top:14px;">
            <h4 style="margin:8px 0;"><?php echo esc_html__( 'Route Sensitivity', 'pressable_cache_management' ); ?></h4>
            <div id="pcm-advisor-sensitivity" style="font-size:13px;color:#111827;"></div>
        </div>
        <div style="margin-top:14px;">
            <h4 style="margin:8px 0;"><?php echo esc_html__( 'Route Diagnosis', 'pressable_cache_management' ); ?></h4>
            <div id="pcm-advisor-diagnosis" class="pcm-advisor-diagnosis" style="font-size:13px;color:#111827;border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#f9fafb;"><em><?php echo esc_html__( 'Select a route from findings to view diagnosis.', 'pressable_cache_management' ); ?></em></div>
        </div>
        <div id="pcm-advisor-playbook" style="margin-top:14px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;display:none;"></div>
    </div>
    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_object_cache_intelligence_is_enabled' ) && pcm_object_cache_intelligence_is_enabled() ) : ?>
    <div class="pcm-card pcm-card-hover" id="pcm-feature-object-cache-intelligence" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">🧠 <?php echo esc_html__( 'Object Cache Intelligence', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Inspect object cache health, hit ratio, evictions, and memory pressure trends.', 'pressable_cache_management' ); ?></p>
        <p style="margin:0 0 10px;color:#6b7280;font-size:12px;"><?php echo esc_html__( 'Data source: we first read the active object-cache drop-in stats (global $wp_object_cache), then fall back to PHP Memcached extension stats when available. Evictions can show n/a when the provider does not expose that metric; memory pressure can show 0% when memory limit bytes are unavailable.', 'pressable_cache_management' ); ?></p>
        <p>
            <button type="button" class="pcm-btn-secondary" id="pcm-oci-refresh-btn"><?php echo esc_html__( 'Refresh diagnostics', 'pressable_cache_management' ); ?></button>
            <span id="pcm-oci-summary" style="margin-left:10px;color:#374151;"></span>
        </p>
        <div class="pcm-responsive-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Latest Snapshot', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-oci-latest" style="font-size:13px;color:#111827;"></div>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( '7-day Trend', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-oci-trends" class="pcm-trend-panel" style="font-size:13px;color:#111827;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_opcache_awareness_is_enabled' ) && pcm_opcache_awareness_is_enabled() ) : ?>
    <div class="pcm-card pcm-card-hover" id="pcm-feature-opcache-awareness" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">📦 <?php echo esc_html__( 'PHP OPcache Awareness', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Review OPcache memory pressure, restart patterns, and recommendations.', 'pressable_cache_management' ); ?></p>
        <p>
            <button type="button" class="pcm-btn-secondary" id="pcm-opcache-refresh-btn"><?php echo esc_html__( 'Refresh OPcache', 'pressable_cache_management' ); ?></button>
            <span id="pcm-opcache-summary" style="margin-left:10px;color:#374151;"></span>
        </p>
        <div class="pcm-responsive-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Latest OPcache Snapshot', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-opcache-latest" style="font-size:13px;color:#111827;"></div>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( '7-day OPcache Trend', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-opcache-trends" class="pcm-trend-panel" style="font-size:13px;color:#111827;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_redirect_assistant_is_enabled' ) && pcm_redirect_assistant_is_enabled() ) : ?>
    <div class="pcm-card" id="pcm-feature-redirect-assistant" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">↪ <?php echo esc_html__( 'Redirect Assistant', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Discover candidates, edit rules, run dry-run simulation, then export or import redirect payloads.', 'pressable_cache_management' ); ?></p>
        <ul style="margin:0 0 12px 18px;color:#6b7280;font-size:12px;">
            <li><?php echo esc_html__( 'Discover Candidates: paste legacy and canonical URLs, then auto-generate starter redirect rules.', 'pressable_cache_management' ); ?></li>
            <li><?php echo esc_html__( 'Load Saved Rules: pull your currently stored redirect rules into the JSON editor.', 'pressable_cache_management' ); ?></li>
            <li><?php echo esc_html__( 'Save Rules: validates and stores JSON rules (wildcard/regex requires confirmation checkbox).', 'pressable_cache_management' ); ?></li>
            <li><?php echo esc_html__( 'Dry-run Simulation: test URLs against the current rule set without changing production behavior.', 'pressable_cache_management' ); ?></li>
            <li><?php echo esc_html__( 'Build Export / Import: generate deployable payloads or import JSON metadata back into this site.', 'pressable_cache_management' ); ?></li>
        </ul>

        <div class="pcm-responsive-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Discover + Edit Rules', 'pressable_cache_management' ); ?></h4>
                <textarea id="pcm-ra-urls" rows="4" style="width:100%;" placeholder="https://example.com/Page?utm_source=x
https://example.com/page/"></textarea>
                <p>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-discover"><?php echo esc_html__( 'Discover Candidates', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-load-rules"><?php echo esc_html__( 'Load Saved Rules', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-rule-editor" style="border:1px solid #e2e8f0;border-radius:6px;padding:10px;background:#f8fafc;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
                        <strong style="font-size:12px;"><?php echo esc_html__( 'Rule Builder', 'pressable_cache_management' ); ?></strong>
                        <button type="button" class="pcm-btn-text" id="pcm-ra-add-rule"><?php echo esc_html__( 'Add Rule', 'pressable_cache_management' ); ?></button>
                    </div>
                    <div style="overflow:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:12px;" id="pcm-ra-rules-table">
                            <thead>
                                <tr>
                                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;"><?php echo esc_html__( 'Source', 'pressable_cache_management' ); ?></th>
                                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;"><?php echo esc_html__( 'Target', 'pressable_cache_management' ); ?></th>
                                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;"><?php echo esc_html__( 'Match Type', 'pressable_cache_management' ); ?></th>
                                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;"><?php echo esc_html__( 'Status Code', 'pressable_cache_management' ); ?></th>
                                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;"><?php echo esc_html__( 'Enabled', 'pressable_cache_management' ); ?></th>
                                    <th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;"><?php echo esc_html__( 'Actions', 'pressable_cache_management' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="pcm-ra-rules-body"></tbody>
                        </table>
                    </div>
                    <div id="pcm-ra-rule-errors" style="margin-top:8px;color:#b91c1c;font-size:12px;"></div>
                </div>
                <p style="margin:8px 0 4px;">
                    <button type="button" class="pcm-btn-text" id="pcm-ra-toggle-advanced" style="padding:0;height:auto;"><?php echo esc_html__( 'Show Advanced JSON', 'pressable_cache_management' ); ?></button>
                </p>
                <textarea id="pcm-ra-rules-json" rows="10" style="width:100%;font-family:monospace;display:none;" placeholder='[ {"enabled":true,"match_type":"exact","source_pattern":"/old","target_pattern":"https://example.com/new"} ]'></textarea>
                <p>
                    <label><input type="checkbox" id="pcm-ra-confirm-wildcards" /> <?php echo esc_html__( 'I confirm wildcard/regex rules have been reviewed.', 'pressable_cache_management' ); ?></label>
                </p>
                <p>
                    <button type="button" class="pcm-btn-primary" id="pcm-ra-save"><?php echo esc_html__( 'Save Rules', 'pressable_cache_management' ); ?></button>
                </p>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Dry Run + Export / Import', 'pressable_cache_management' ); ?></h4>
                <textarea id="pcm-ra-sim-urls" rows="4" style="width:100%;" placeholder="https://example.com/old
https://example.com/OLD/"></textarea>
                <p>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-simulate"><?php echo esc_html__( 'Dry-run Simulation', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-export"><?php echo esc_html__( 'Build Export', 'pressable_cache_management' ); ?></button>
                </p>
                <textarea id="pcm-ra-export-content" rows="8" style="width:100%;font-family:monospace;" placeholder="Exported custom-redirects.php content / JSON meta payload"></textarea>
                <p>
                    <button type="button" class="pcm-btn-text" id="pcm-ra-copy"><?php echo esc_html__( 'Copy Export', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-download"><?php echo esc_html__( 'Download custom-redirects.php', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-import"><?php echo esc_html__( 'Import JSON Payload', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-output" style="font-size:12px;color:#111827;max-height:220px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;padding:8px;border-radius:6px;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_smart_purge_is_enabled' ) && pcm_smart_purge_is_enabled() ) : ?>
    <div class="pcm-card" id="pcm-feature-smart-purge-strategy" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">🧹 <?php echo esc_html__( 'Smart Purge Strategy', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Tune active mode, cooldown, deferred execution, and inspect queued job outcomes.', 'pressable_cache_management' ); ?></p>
        <p style="margin:0 0 10px;color:#6b7280;font-size:12px;"><?php echo esc_html__( 'Smart Purge Strategy batches and sequences cache purge events to reduce cache stampedes and unnecessary invalidations. In shadow mode, jobs are recorded but not executed so you can review impact safely. In active mode, jobs execute with cooldown/defer controls to smooth purge load.', 'pressable_cache_management' ); ?></p>
        <form method="post" class="pcm-sp-settings-form">
            <?php wp_nonce_field( 'pcm_smart_purge_settings_action', 'pcm_smart_purge_settings_nonce' ); ?>
            <input type="hidden" name="pcm_smart_purge_settings_submit" value="1" />
            <div class="pcm-sp-settings-section">
                <h4><?php echo esc_html__( 'Mode', 'pressable_cache_management' ); ?></h4>
                <label class="pcm-sp-checkbox-row">
                    <input type="checkbox" name="pcm_smart_purge_active_mode" value="1" <?php checked( (bool) get_option( 'pcm_smart_purge_active_mode', false ), true ); ?> />
                    <span>
                        <strong><?php echo esc_html__( 'Enable active purge execution mode', 'pressable_cache_management' ); ?></strong>
                        <small><?php echo esc_html__( 'When disabled, Smart Purge runs in shadow mode: jobs are queued and logged for review, but no cache purges are executed.', 'pressable_cache_management' ); ?></small>
                    </span>
                </label>
            </div>

            <div class="pcm-sp-settings-section">
                <h4><?php echo esc_html__( 'Timing', 'pressable_cache_management' ); ?></h4>
                <div class="pcm-sp-two-col-grid">
                    <label class="pcm-sp-field-label">
                        <span><?php echo esc_html__( 'Cooldown', 'pressable_cache_management' ); ?></span>
                        <span class="pcm-sp-number-wrap">
                            <input type="number" min="15" max="3600" name="pcm_smart_purge_cooldown_seconds" value="<?php echo esc_attr( (int) get_option( 'pcm_smart_purge_cooldown_seconds', 120 ) ); ?>" />
                            <span class="pcm-sp-unit"><?php echo esc_html__( 'seconds', 'pressable_cache_management' ); ?></span>
                        </span>
                    </label>
                    <label class="pcm-sp-field-label">
                        <span><?php echo esc_html__( 'Deferred execution', 'pressable_cache_management' ); ?></span>
                        <span class="pcm-sp-number-wrap">
                            <input type="number" min="0" max="3600" name="pcm_smart_purge_defer_seconds" value="<?php echo esc_attr( (int) get_option( 'pcm_smart_purge_defer_seconds', 60 ) ); ?>" />
                            <span class="pcm-sp-unit"><?php echo esc_html__( 'seconds', 'pressable_cache_management' ); ?></span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="pcm-sp-settings-section">
                <h4><?php echo esc_html__( 'Prewarm', 'pressable_cache_management' ); ?></h4>
                <label class="pcm-sp-checkbox-row">
                    <input type="checkbox" name="pcm_smart_purge_enable_prewarm" value="1" <?php checked( (bool) get_option( 'pcm_smart_purge_enable_prewarm', false ), true ); ?> />
                    <span>
                        <strong><?php echo esc_html__( 'Enable post-purge URL prewarm', 'pressable_cache_management' ); ?></strong>
                        <small><?php echo esc_html__( 'Automatically request selected URLs after each purge to rebuild cache quickly.', 'pressable_cache_management' ); ?></small>
                    </span>
                </label>
                <div class="pcm-sp-two-col-grid">
                    <label class="pcm-sp-field-label">
                        <span><?php echo esc_html__( 'URL cap per job', 'pressable_cache_management' ); ?></span>
                        <small><?php echo esc_html__( 'Maximum number of URLs warmed after a single purge event.', 'pressable_cache_management' ); ?></small>
                        <span class="pcm-sp-number-wrap">
                            <input type="number" min="1" max="100" name="pcm_smart_purge_prewarm_url_cap" value="<?php echo esc_attr( (int) get_option( 'pcm_smart_purge_prewarm_url_cap', 10 ) ); ?>" />
                            <span class="pcm-sp-unit"><?php echo esc_html__( 'URLs', 'pressable_cache_management' ); ?></span>
                        </span>
                    </label>
                    <label class="pcm-sp-field-label">
                        <span><?php echo esc_html__( 'Batch size', 'pressable_cache_management' ); ?></span>
                        <small><?php echo esc_html__( 'How many prewarm requests are sent concurrently.', 'pressable_cache_management' ); ?></small>
                        <span class="pcm-sp-number-wrap">
                            <input type="number" min="1" max="20" name="pcm_smart_purge_prewarm_batch_size" value="<?php echo esc_attr( (int) get_option( 'pcm_smart_purge_prewarm_batch_size', 3 ) ); ?>" />
                            <span class="pcm-sp-unit"><?php echo esc_html__( 'requests', 'pressable_cache_management' ); ?></span>
                        </span>
                    </label>
                    <label class="pcm-sp-field-label">
                        <span><?php echo esc_html__( 'Repeat hits', 'pressable_cache_management' ); ?></span>
                        <small><?php echo esc_html__( 'Extra warm passes for important URLs to stabilize hot paths.', 'pressable_cache_management' ); ?></small>
                        <span class="pcm-sp-number-wrap">
                            <input type="number" min="1" max="5" name="pcm_smart_purge_prewarm_repeat_hits" value="<?php echo esc_attr( (int) get_option( 'pcm_smart_purge_prewarm_repeat_hits', 2 ) ); ?>" />
                            <span class="pcm-sp-unit"><?php echo esc_html__( 'hits', 'pressable_cache_management' ); ?></span>
                        </span>
                    </label>
                </div>
                <label class="pcm-sp-field-label">
                    <span><?php echo esc_html__( 'Important URLs (one per line or comma-separated)', 'pressable_cache_management' ); ?></span>
                    <small><?php echo esc_html__( 'Paste one URL per line. These URLs get priority warming after cache purges.', 'pressable_cache_management' ); ?></small>
                    <textarea name="pcm_smart_purge_important_urls" rows="4"><?php echo esc_textarea( (string) get_option( 'pcm_smart_purge_important_urls', '' ) ); ?></textarea>
                </label>
            </div>
            <button type="submit" class="pcm-btn-primary"><?php echo esc_html__( 'Save Smart Purge Settings', 'pressable_cache_management' ); ?></button>
        </form>
        <div class="pcm-sp-insights-grid">
            <div class="pcm-sp-insight-card">
                <h4><?php echo esc_html__( 'Queue Summary', 'pressable_cache_management' ); ?></h4>
                <?php
                $pcm_sp_storage = class_exists( 'PCM_Smart_Purge_Storage' ) ? new PCM_Smart_Purge_Storage() : null;
                $pcm_sp_jobs = $pcm_sp_storage ? $pcm_sp_storage->get_jobs() : array();
                $pcm_sp_outcomes = $pcm_sp_storage ? $pcm_sp_storage->get_outcomes() : array();
                $queued = 0;
                $executed = 0;
                $shadowed = 0;
                $warmed_urls = 0;
                $prewarm_failures = 0;
                $prewarm_latency_total = 0;
                $prewarm_latency_samples = 0;
                foreach ( (array) $pcm_sp_jobs as $job ) {
                    $status = isset( $job['status'] ) ? $job['status'] : 'queued';
                    if ( 'queued' === $status ) { $queued++; }
                    if ( 'executed' === $status ) { $executed++; }
                    if ( 'shadowed' === $status ) { $shadowed++; }

                    if ( ! empty( $job['prewarm_status'] ) && is_array( $job['prewarm_status'] ) ) {
                        $warmed_urls += isset( $job['prewarm_status']['warmed_url_count'] ) ? (int) $job['prewarm_status']['warmed_url_count'] : 0;
                        $prewarm_failures += isset( $job['prewarm_status']['failure_count'] ) ? (int) $job['prewarm_status']['failure_count'] : 0;
                        if ( isset( $job['prewarm_status']['average_latency_ms'] ) ) {
                            $prewarm_latency_total += (float) $job['prewarm_status']['average_latency_ms'];
                            $prewarm_latency_samples++;
                        }
                    }
                }
                $prewarm_avg_latency = $prewarm_latency_samples > 0 ? round( $prewarm_latency_total / $prewarm_latency_samples, 2 ) : 0;
                ?>
                <ul style="margin:0;padding-left:18px;">
                    <li><strong><?php echo esc_html__( 'Queued', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( $queued ); ?></li>
                    <li><strong><?php echo esc_html__( 'Executed', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( $executed ); ?></li>
                    <li><strong><?php echo esc_html__( 'Shadowed', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( $shadowed ); ?></li>
                    <li><strong><?php echo esc_html__( 'Warmed URLs', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( $warmed_urls ); ?></li>
                    <li><strong><?php echo esc_html__( 'Prewarm failures', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( $prewarm_failures ); ?></li>
                    <li><strong><?php echo esc_html__( 'Average warm latency (ms)', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( $prewarm_avg_latency ); ?></li>
                </ul>
            </div>
            <div class="pcm-sp-insight-card">
                <h4><?php echo esc_html__( 'Recent Impact Outcomes', 'pressable_cache_management' ); ?></h4>
                <div style="max-height:200px;overflow:auto;font-size:12px;">
                    <?php if ( empty( $pcm_sp_outcomes ) ) : ?>
                        <em><?php echo esc_html__( 'No outcomes captured yet.', 'pressable_cache_management' ); ?></em>
                    <?php else : ?>
                        <ul style="margin:0;padding-left:18px;">
                            <?php foreach ( array_slice( array_reverse( $pcm_sp_outcomes ), 0, 10 ) as $row ) : ?>
                                <li>
                                    <strong><?php echo esc_html( isset( $row['job_id'] ) ? $row['job_id'] : 'job' ); ?></strong>
                                    — Δhit <?php echo esc_html( isset( $row['observed_impact']['hit_ratio_delta'] ) ? $row['observed_impact']['hit_ratio_delta'] : 'n/a' ); ?>,
                                    Δevict <?php echo esc_html( isset( $row['observed_impact']['evictions_delta'] ) ? $row['observed_impact']['evictions_delta'] : 'n/a' ); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

            </div>
            <div class="pcm-sp-insight-card pcm-sp-insight-card-full">
                <h4><?php echo esc_html__( 'Recent Prewarm Logs', 'pressable_cache_management' ); ?></h4>
                <div style="max-height:200px;overflow:auto;font-size:12px;">
                    <?php
                    $prewarm_logs = array();
                    foreach ( array_reverse( (array) $pcm_sp_jobs ) as $job ) {
                        if ( empty( $job['prewarm_status']['logs'] ) || ! is_array( $job['prewarm_status']['logs'] ) ) {
                            continue;
                        }

                        foreach ( $job['prewarm_status']['logs'] as $log ) {
                            $log['job_id'] = isset( $job['job_id'] ) ? $job['job_id'] : 'job';
                            $prewarm_logs[] = $log;
                        }
                    }
                    ?>
                    <?php if ( empty( $prewarm_logs ) ) : ?>
                        <em><?php echo esc_html__( 'No prewarm logs captured yet.', 'pressable_cache_management' ); ?></em>
                    <?php else : ?>
                        <ul style="margin:0;padding-left:18px;">
                            <?php foreach ( array_slice( $prewarm_logs, 0, 20 ) as $log ) : ?>
                                <li>
                                    <strong><?php echo esc_html( isset( $log['job_id'] ) ? $log['job_id'] : 'job' ); ?></strong>
                                    — <?php echo esc_html( isset( $log['url'] ) ? $log['url'] : '' ); ?>
                                    (<?php echo esc_html( ! empty( $log['success'] ) ? 'ok' : 'fail' ); ?>,
                                    <?php echo esc_html( isset( $log['status_code'] ) ? (int) $log['status_code'] : 0 ); ?>,
                                    <?php echo esc_html( isset( $log['latency_ms'] ) ? (float) $log['latency_ms'] : 0 ); ?>ms)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>


    <?php if ( $is_deep_dive_tab ) : ?>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_microcache_render_deep_dive_card' ) ) : ?>
        <?php pcm_microcache_render_deep_dive_card(); ?>
    <?php endif; ?>

    <?php if ( $is_settings_tab ) : ?>
    <div class="pcm-card pcm-card-hover" id="pcm-feature-observability-reporting" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">📊 <?php echo esc_html__( 'Observability & Reporting', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Review trend rollups and export JSON/CSV diagnostics artifacts.', 'pressable_cache_management' ); ?></p>
        <?php if ( ! function_exists( 'pcm_reporting_is_enabled' ) || ! pcm_reporting_is_enabled() ) : ?>
        <div style="display:flex;align-items:flex-start;gap:10px;width:100%;box-sizing:border-box;margin:0;padding:12px 14px;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:8px;font-weight:600;">
            <span aria-hidden="true" style="font-size:18px;line-height:1;">⚠️</span>
            <span><?php echo esc_html__( 'Observability is currently disabled because Caching Suite features are turned off. Enable Caching Suite features above to load trends and exports.', 'pressable_cache_management' ); ?></span>
        </div>
        <?php else : ?>
        <p>
            <select id="pcm-report-range">
                <option value="24h">24h</option>
                <option value="7d" selected>7d</option>
                <option value="30d">30d</option>
            </select>
            <button type="button" class="pcm-btn-secondary" id="pcm-report-load"><?php echo esc_html__( 'Load Trends', 'pressable_cache_management' ); ?></button>
            <button type="button" class="pcm-btn-secondary" id="pcm-report-export-json"><?php echo esc_html__( 'Export JSON', 'pressable_cache_management' ); ?></button>
            <button type="button" class="pcm-btn-secondary" id="pcm-report-export-csv"><?php echo esc_html__( 'Export CSV', 'pressable_cache_management' ); ?></button>
        </p>
        <div id="pcm-report-output" style="max-height:260px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:6px;font-size:12px;"></div>
    </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( $is_settings_tab ) : ?>
    <div class="pcm-card" id="pcm-feature-security-privacy" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">🔐 <?php echo esc_html__( 'Privacy & Security', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Configure retention and redaction policy, then review audit log history for privileged actions.', 'pressable_cache_management' ); ?></p>
        <?php if ( function_exists( 'pcm_security_privacy_is_enabled' ) && ! pcm_security_privacy_is_enabled() ) : ?>
        <div style="display:flex;align-items:flex-start;gap:10px;width:100%;box-sizing:border-box;margin:0;padding:12px 14px;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:8px;font-weight:600;">
            <span aria-hidden="true" style="font-size:18px;line-height:1;">⚠️</span>
            <span><?php echo esc_html__( 'Privacy & Security is currently disabled by feature filter. Enable pcm_enable_security_privacy to use retention controls and audit logs.', 'pressable_cache_management' ); ?></span>
        </div>
        <?php else : ?>
        <div class="pcm-responsive-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Privacy Settings', 'pressable_cache_management' ); ?></h4>
                <p><label><?php echo esc_html__( 'Retention Days', 'pressable_cache_management' ); ?> <input type="number" id="pcm-privacy-retention" min="7" max="365" value="<?php echo esc_attr( isset( $privacy_settings['retention_days'] ) ? (int) $privacy_settings['retention_days'] : 90 ); ?>" /></label></p>
                <p><label><?php echo esc_html__( 'Redaction Level', 'pressable_cache_management' ); ?>
                    <select id="pcm-privacy-redaction"><option value="minimal" <?php selected( isset( $privacy_settings['redaction_level'] ) ? $privacy_settings['redaction_level'] : "standard", "minimal" ); ?>>minimal</option><option value="standard" <?php selected( isset( $privacy_settings['redaction_level'] ) ? $privacy_settings['redaction_level'] : "standard", "standard" ); ?>>standard</option><option value="strict" <?php selected( isset( $privacy_settings['redaction_level'] ) ? $privacy_settings['redaction_level'] : "standard", "strict" ); ?>>strict</option></select>
                </label></p>
                <p><label><input type="checkbox" id="pcm-privacy-advanced-scan" <?php checked( ! empty( $privacy_settings['advanced_scan_opt_in'] ) ); ?> /> <?php echo esc_html__( 'Allow advanced scanning workflows', 'pressable_cache_management' ); ?></label></p>
                <p><label><input type="checkbox" id="pcm-privacy-audit-enabled" <?php checked( ! empty( $privacy_settings['audit_log_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable audit logging', 'pressable_cache_management' ); ?></label></p>
                <p><button type="button" class="pcm-btn-primary" id="pcm-privacy-save"><?php echo esc_html__( 'Save Privacy Settings', 'pressable_cache_management' ); ?></button>
                <span id="pcm-privacy-status" style="margin-left:8px;color:#374151;"></span></p>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Audit Log', 'pressable_cache_management' ); ?></h4>
                <p><button type="button" class="pcm-btn-secondary" id="pcm-audit-refresh"><?php echo esc_html__( 'Refresh Audit Log', 'pressable_cache_management' ); ?></button></p>
                <div id="pcm-audit-log" class="pcm-audit-panel pcm-skeleton-panel" aria-live="polite"></div>
                <p style="margin-top:8px;"><button type="button" class="pcm-btn-secondary" id="pcm-audit-load-more" style="display:none;"><?php echo esc_html__( 'Load More', 'pressable_cache_management' ); ?></button></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <?php if ( $is_object_tab ) : ?>

    <!-- ── 2-column grid ── -->
    <div class="pcm-object-cache-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- LEFT -->
        <div style="display:flex;flex-direction:column;gap:20px;">

            <!-- Global Controls -->
            <div class="pcm-card">
                <h3 class="pcm-card-title">&#8635; <?php echo esc_html__( 'Global Controls', 'pressable_cache_management' ); ?></h3>
                <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 10px;"><?php echo esc_html__( 'Flush Object Cache', 'pressable_cache_management' ); ?></p>
                <form method="post" id="pcm-flush-form">
                    <input type="hidden" name="flush_object_cache_nonce" value="<?php echo wp_create_nonce('flush_object_cache_nonce'); ?>">
                    <input type="submit" value="<?php esc_attr_e('Flush Cache for all Pages','pressable_cache_management'); ?>"
                           class="pcm-btn-primary pcm-btn-block" id="pcm-flush-btn">
                </form>
                <div id="pcm-flush-feedback" style="margin-top:12px;" aria-live="polite"></div>
                <?php $ts = get_option('flush-obj-cache-time-stamp'); ?>
                <div style="margin-top:12px;">
                    <span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span><br>
                    <span class="pcm-ts-value" id="pcm-last-flushed-value"><?php echo $ts ? esc_html($ts) : '—'; ?></span>
                </div>

                <!-- Current Cache TTL -->
                <?php $pcm_ttl = get_option( 'pcm_last_ttl', array() ); ?>
                <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;">
                    <span class="pcm-ts-label"><?php esc_html_e( 'CURRENT CACHE MAX-AGE', 'pressable_cache_management' ); ?></span><br>
                    <span id="pcm-ttl-value" class="pcm-ts-value">—</span>
                </div>
            </div>

            <!-- Automated Rules -->
            <div class="pcm-card">
                <form action="options.php" method="post" id="pcm-main-settings-form">
                <?php settings_fields('pressable_cache_management_options'); ?>
                <h3 class="pcm-card-title"><?php echo esc_html__( 'Automated Rules', 'pressable_cache_management' ); ?></h3>

                <?php
                $rules = array(
                    'flush_cache_theme_plugin_checkbox' => array(
                        'title' => '&#x1F50C; ' . __( 'Flush Cache on Plugin/Theme Update', 'pressable_cache_management' ),
                        'desc'  => __( 'Flush cache automatically on plugin & theme update.', 'pressable_cache_management' ),
                        'ts'    => get_option('flush-cache-theme-plugin-time-stamp'),
                    ),
                    'flush_cache_page_edit_checkbox' => array(
                        'title' => '&#x1F4DD; ' . __( 'Flush Cache on Post/Page Edit', 'pressable_cache_management' ),
                        'desc'  => __( 'Flush cache automatically when page/post/post_types are updated.', 'pressable_cache_management' ),
                        'ts'    => get_option('flush-cache-page-edit-time-stamp'),
                    ),
                    'flush_cache_on_comment_delete_checkbox' => array(
                        'title' => '&#x1F4AC; ' . __( 'Flush Cache on Comment Delete', 'pressable_cache_management' ),
                        'desc'  => __( 'Flush cache automatically when comments are deleted.', 'pressable_cache_management' ),
                        'ts'    => get_option('flush-cache-on-comment-delete-time-stamp'),
                    ),
                );
                foreach ( $rules as $id => $rule ) :
                    $checked = isset($options[$id]) ? checked($options[$id], 1, false) : '';
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch" style="flex-shrink:0;margin-top:2px;">
                        <input type="checkbox"
                               name="pressable_cache_management_options[<?php echo esc_attr($id); ?>]"
                               value="1" <?php echo $checked; ?>>
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo wp_kses_post($rule['title']); ?></div>
                        <div class="pcm-toggle-desc"><?php echo wp_kses_post($rule['desc']); ?></div>
                        <span class="pcm-ts-inline"><strong><?php echo __('Last flushed at:', 'pressable_cache_management'); ?></strong> <?php echo $rule['ts'] ? wp_kses_post( str_replace( array("\n", "\r"), ' ', $rule['ts'] ) ) : '&#8212;'; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                </form>
            </div>

        </div><!-- /LEFT -->

        <!-- RIGHT -->
        <div style="display:flex;flex-direction:column;gap:20px;">

            <!-- Batcache & Page Rules -->
            <div class="pcm-card">
                <h3 class="pcm-card-title"><?php echo esc_html__( 'Batcache & Page Rules', 'pressable_cache_management' ); ?></h3>

                <?php
                // Extend Batcache
                $eb_checked = isset($options['extend_batcache_checkbox']) ? checked($options['extend_batcache_checkbox'],1,false) : '';
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch" style="flex-shrink:0;margin-top:2px;">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[extend_batcache_checkbox]"
                               value="1" <?php echo $eb_checked; ?>>
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo esc_html__( 'Extend Batcache (by 24 hrs)', 'pressable_cache_management' ); ?></div>
                        <div class="pcm-toggle-desc"><?php echo esc_html__( 'Extend Batcache storage time by 24 hours.', 'pressable_cache_management' ); ?></div>
                    </div>
                </div>

                <?php
                // Flush Batcache for Individual Pages
                $sp_checked = isset($options['flush_object_cache_for_single_page']) ? checked($options['flush_object_cache_for_single_page'],1,false) : '';
                $sp_ts      = get_option('flush-object-cache-for-single-page-time-stamp');
                $sp_url     = get_option('single-page-url-flushed');
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch" style="flex-shrink:0;margin-top:2px;">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[flush_object_cache_for_single_page]"
                               value="1" <?php echo $sp_checked; ?>>
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo esc_html__( 'Flush Batcache for Individual Pages', 'pressable_cache_management' ); ?></div>
                        <div class="pcm-toggle-desc"><?php echo esc_html__( 'Flush Batcache for individual pages from page preview toolbar.', 'pressable_cache_management' ); ?></div>
                        <span class="pcm-ts-inline"><strong><?php echo __('Last flushed at:', 'pressable_cache_management'); ?></strong> <?php echo $sp_ts ? wp_kses_post( str_replace( array("\n", "\r"), ' ', $sp_ts ) ) : '&#8212;'; ?></span>
                        <span class="pcm-ts-inline"><strong><?php echo __('Page URL:', 'pressable_cache_management'); ?></strong> <?php echo $sp_url ? esc_html( $sp_url ) : '&#8212;'; ?></span>
                    </div>
                </div>

                <?php
                // Flush cache automatically when published pages/posts are deleted
                $del_checked = isset($options['flush_cache_on_page_post_delete_checkbox']) ? checked($options['flush_cache_on_page_post_delete_checkbox'],1,false) : '';
                $del_ts      = get_option('flush-cache-on-page-post-delete-time-stamp');
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch" style="flex-shrink:0;margin-top:2px;">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[flush_cache_on_page_post_delete_checkbox]"
                               value="1" <?php echo $del_checked; ?>>
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo esc_html__( 'Flush cache automatically when published pages/posts are deleted.', 'pressable_cache_management' ); ?></div>
                        <div class="pcm-toggle-desc"><?php echo esc_html__( 'Flushes Batcache for the specific page when it is deleted.', 'pressable_cache_management' ); ?></div>
                        <span class="pcm-ts-inline"><strong><?php echo __('Last flushed at:', 'pressable_cache_management'); ?></strong> <?php echo $del_ts ? wp_kses_post( str_replace( array("\n", "\r"), ' ', $del_ts ) ) : '&#8212;'; ?></span>
                    </div>
                </div>

                <?php
                // Flush Batcache for WooCommerce product pages
                $woo_checked = isset($options['flush_batcache_for_woo_product_individual_page_checkbox']) ? checked($options['flush_batcache_for_woo_product_individual_page_checkbox'],1,false) : '';
                ?>
                <div class="pcm-toggle-row" style="border-bottom:none;">
                    <label class="switch" style="flex-shrink:0;margin-top:2px;">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[flush_batcache_for_woo_product_individual_page_checkbox]"
                               value="1" <?php echo $woo_checked; ?>>
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo esc_html__( 'Flush Batcache for WooCommerce product pages', 'pressable_cache_management' ); ?></div>
                        <div class="pcm-toggle-desc"><?php echo esc_html__( 'Flush Batcache for WooCommerce product pages.', 'pressable_cache_management' ); ?></div>
                    </div>
                </div>
            </div>

            <!-- Exclude Pages -->
            <div class="pcm-card">
                <h3 class="pcm-card-title"><?php echo esc_html__( 'Exclude Pages', 'pressable_cache_management' ); ?></h3>
                <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 10px;"><?php echo esc_html__( 'Cache Exclusions', 'pressable_cache_management' ); ?></p>

                <?php
                $exempt_val = isset($options['exempt_from_batcache']) ? sanitize_text_field($options['exempt_from_batcache']) : '';
                $pages = $exempt_val ? array_values( array_filter( array_map('trim', explode(',', $exempt_val)) ) ) : array();
                ?>

                <!-- Chips -->
                <div id="pcm-chips-wrap" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;min-height:0;">
                    <?php foreach ($pages as $page) : ?>
                    <span class="pcm-chip" data-value="<?php echo esc_attr($page); ?>">
                        <?php echo esc_html($page); ?>
                        <button type="button" class="pcm-chip-remove" title="Remove">&#xD7;</button>
                    </span>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" id="pcm-exempt-hidden"
                       name="pressable_cache_management_options[exempt_from_batcache]"
                       form="pcm-main-settings-form"
                       value="<?php echo esc_attr($exempt_val); ?>">

                <input type="text" id="pcm-exempt-input" autocomplete="off"
                       placeholder="<?php echo esc_attr__( 'Enter single URL (e.g., /pagename/).', 'pressable_cache_management' ); ?>"
                       style="width:100%;height:40px;padding:0 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#f8fafc;">
                <p style="font-size:11.5px;color:#94a3b8;margin:6px 0 0;">
                    <?php echo esc_html__( 'To exclude a single page use', 'pressable_cache_management' ); ?> <code>/page/</code> — <?php echo esc_html__( 'for multiple pages separate with comma, e.g.', 'pressable_cache_management' ); ?> <code>/your-site.com/, /about-us/, /info/</code>
                </p>
            </div>

        </div><!-- /RIGHT -->
    </div><!-- /grid -->

    <!-- Save button -->
    <div style="display:flex;justify-content:center;margin-top:28px;">
        <button type="submit" name="submit" form="pcm-main-settings-form" class="pcm-btn-primary"><?php echo wp_kses_post( __( '&#10003;&nbsp; Save Settings', 'pressable_cache_management' ) ); ?></button>
    </div>

    <!-- Chip JS -->

    <?php endif; ?>

    <?php elseif ( $tab === 'edge_cache_settings_tab' ) : ?>



    <!-- Page heading -->
    <div style="margin-bottom:20px;">
        <h2 style="font-size:20px;font-weight:700;color:#040024;margin:0 0 6px;font-family:'Inter',sans-serif;">
            <?php echo esc_html__( 'Manage Edge Cache Settings', 'pressable_cache_management' ); ?>
        </h2>
    </div>

    <!-- Card -->
    <div class="pcm-edge-cache-card-wrap" style="max-width:680px;">
    <div class="pcm-card" style="padding:0;">

        <!-- Row 1: Turn On/Off -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:24px;padding:24px 28px;border-bottom:1px solid #f1f5f9;">
            <div>
                <p style="font-size:15px;font-weight:700;color:#040024;margin:0 0 6px;font-family:'Inter',sans-serif;">
                    <?php echo esc_html__( 'Turn On/Off Edge Cache', 'pressable_cache_management' ); ?>
                </p>
                <p style="font-size:13px;color:#64748b;margin:0;font-family:'Inter',sans-serif;">
                    <?php echo esc_html__( 'Enable or disable the edge cache for this site.', 'pressable_cache_management' ); ?>
                </p>
            </div>
            <div id="edge-cache-control-wrapper" style="flex-shrink:0;min-width:180px;text-align:right;">
                <div class="edge-cache-loader"></div>
            </div>
        </div>

        <!-- Row 2: Purge + description + timestamps -->
        <div style="padding:24px 28px;">

            <!-- Purge title + button -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:12px;">
                <p style="font-size:15px;font-weight:700;color:#040024;margin:0;font-family:'Inter',sans-serif;">
                    <?php echo esc_html__( 'Purge Edge Cache', 'pressable_cache_management' ); ?>
                </p>
                <form method="post" id="purge_edge_cache_nonce_form_static" style="flex-shrink:0;">
                    <?php settings_fields('edge_cache_settings_tab_options'); ?>
                    <input type="hidden" name="purge_edge_cache_nonce" value="<?php echo wp_create_nonce('purge_edge_cache_nonce'); ?>">
                    <input id="purge-edge-cache-button-input"
                           name="edge_cache_settings_tab_options[purge_edge_cache_button]"
                           type="submit"
                           value="<?php echo esc_attr__( 'Purge Edge Cache', 'pressable_cache_management' ); ?>"
                           disabled
                           class="pcm-btn-danger disabled-button-style"
                           >
                </form>
            </div>

            <!-- Description -->
            <p style="font-size:13px;color:#64748b;margin:0 0 20px;font-family:'Inter',sans-serif;">
                <?php echo esc_html__( 'Purging cache will temporarily slow down your site for all visitors while the cache rebuilds.', 'pressable_cache_management' ); ?>
            </p>

            <!-- Timestamps -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <div>
                    <span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span>
                    <span class="pcm-ts-value" style="display:block;margin-top:4px;"><?php
                        $v = get_option('edge-cache-purge-time-stamp');
                        echo $v ? esc_html($v) : '&mdash;';
                    ?></span>
                </div>

                <div>
                    <span class="pcm-ts-label"><?php echo esc_html__( 'SINGLE PAGE LAST FLUSHED', 'pressable_cache_management' ); ?></span>
                    <span class="pcm-ts-value" style="display:block;margin-top:4px;"><?php
                        $v = get_option('single-page-edge-cache-purge-time-stamp');
                        echo $v ? esc_html($v) : '&mdash;';
                    ?></span>
                </div>

                <div>
                    <span class="pcm-ts-label"><?php echo esc_html__( 'SINGLE PAGE URL', 'pressable_cache_management' ); ?></span>
                    <span class="pcm-ts-value" style="display:block;margin-top:4px;word-break:break-all;"><?php
                        $v = get_option('edge-cache-single-page-url-purged');
                        echo $v ? esc_html($v) : '&mdash;';
                    ?></span>
                </div>

            </div>
        </div>

    </div><!-- /card -->
    </div><!-- /max-width -->

    <?php elseif ( $tab === 'remove_pressable_branding_tab' ) : ?>

    <form action="options.php" method="post">
        <?php
        settings_fields('remove_pressable_branding_tab_options');
        do_settings_sections('remove_pressable_branding_tab');
        echo '<button type="submit" class="pcm-btn-primary">' . esc_html__( 'Save Settings', 'pressable_cache_management' ) . '</button>'; 
        ?>
    </form>

    <?php endif; ?>

    </div><!-- /inner-center -->
    </div><!-- /wrap -->

    <style>
    /* ── Inline styles for component classes ── */
    .pcm-card {
        background:#fff;border-radius:12px;border:1px solid #e2e8f0;
        padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);
    }
    .pcm-card-title {
        font-size:15px;font-weight:700;color:#040024;margin:0 0 16px;
        font-family:'Inter',sans-serif;
    }
    .pcm-toggle-row {
        display:flex;align-items:flex-start;gap:14px;
        padding:14px 0;border-bottom:1px solid #f1f5f9;
    }
    .pcm-toggle-title {
        font-size:13.5px;font-weight:600;color:#040024;
        font-family:'Inter',sans-serif;line-height:1.3;
    }
    .pcm-status-badge{display:inline-flex;align-items:center;margin-left:8px;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.4;vertical-align:middle}
    .pcm-status-badge.is-active{background:#dcfce7;color:#166534;border:1px solid #86efac}
    .pcm-status-badge.is-inactive{background:#e5e7eb;color:#4b5563;border:1px solid #d1d5db}
    .pcm-toggle-desc {
        font-size:12px;color:#64748b;margin-top:2px;font-family:'Inter',sans-serif;
    }
    .pcm-ts-label {
        font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;
        font-family:'Inter',sans-serif;font-weight:600;
    }
    .pcm-ts-value {
        font-size:12.5px;color:#040024;font-family:'Inter',sans-serif;
        font-weight:500;display:block;margin-top:2px;
    }
    .pcm-ts-inline {
        font-size:11.5px;color:#475569;display:block;margin-top:4px;
        font-family:'Inter',sans-serif;font-weight:500;
    }
    #pcm-flush-btn.pcm-btn-loading {
        position:relative;
        color:transparent !important;
        cursor:not-allowed !important;
        opacity:.9;
        transform:none !important;
        box-shadow:none !important;
    }
    #pcm-flush-btn.pcm-btn-loading::after {
        content:'';
        position:absolute;
        top:50%;
        left:50%;
        width:16px;
        height:16px;
        margin:-8px 0 0 -8px;
        border:2px solid rgba(255,255,255,.45);
        border-top-color:#ffffff;
        border-radius:50%;
        animation:pcm-spin .8s linear infinite;
    }
    @keyframes pcm-spin{to{transform:rotate(360deg)}}

    .nav-tab-hidden { display:none !important; }

    #pcm-main-tab-nav{display:flex;flex-wrap:wrap;gap:4px}
    #pcm-main-tab-nav .nav-tab{float:none;margin-left:0}
    .pcm-anchor-nav{position:sticky;top:32px;z-index:4;display:flex;gap:8px;overflow:auto;padding:8px 0 12px;margin-bottom:14px}
    .pcm-anchor-nav a{display:inline-flex;white-space:nowrap;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;text-decoration:none;color:#374151;background:#fff;font-size:12px}
    .pcm-anchor-nav a.is-active{background:#03fcc2;color:#040024;border-color:#03fcc2}
    .pcm-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .pcm-card-hover{transition:box-shadow .2s}
    .pcm-card-hover:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
    .pcm-summary-card{text-decoration:none;color:#111827;padding:12px;display:flex;flex-direction:column;gap:8px;position:relative}
    .pcm-summary-heading{display:flex;align-items:center;gap:8px;padding-right:16px}
    .pcm-summary-icon{font-size:16px;line-height:1}
    .pcm-status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;position:absolute;right:12px;top:12px}
    .pcm-status-dot.is-good{background:#03fcc2}.pcm-status-dot.is-warn{background:#f59e0b}.pcm-status-dot.is-bad{background:#dd3a03}
    .pcm-summary-label{font-size:12px;color:#6b7280}.pcm-summary-value{font-size:18px;color:#040024}
    .pcm-trend-panel{display:flex;flex-direction:column;gap:12px}
    .pcm-trend-charts{display:grid;grid-template-columns:1fr;gap:10px}
    .pcm-trend-chart{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px}
    .pcm-trend-chart h5{margin:0 0 6px;font-size:12px;display:flex;justify-content:space-between;color:#111827}
    .pcm-trend-chart h5 span{font-weight:500;color:#64748b;font-size:11px}
    .pcm-trend-chart svg{display:block;width:100%;height:auto}
    .pcm-trend-details summary{cursor:pointer;color:#374151;font-weight:600;margin-bottom:8px}
    .pcm-inline-error{border-left:4px solid #dd3a03;background:#fff1f2;padding:8px 10px;border-radius:4px;color:#7f1d1d}
    .pcm-inline-success{border-left:4px solid #03fcc2;background:#ecfeff;padding:8px 10px;border-radius:4px;color:#0f766e}
    .pcm-audit-panel{max-height:260px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:6px;font-size:12px}
    .pcm-audit-table{margin:0}
    .pcm-skeleton-panel{position:relative;min-height:180px}
    .pcm-skeleton-panel::before{content:'';display:block;height:12px;border-radius:4px;background:linear-gradient(90deg,#e5e7eb 25%,#f3f4f6 37%,#e5e7eb 63%);background-size:400% 100%;animation:pcm-skeleton-pulse 1.2s ease infinite;box-shadow:0 28px 0 #e5e7eb,0 56px 0 #e5e7eb,0 84px 0 #e5e7eb,0 112px 0 #e5e7eb}
    @keyframes pcm-skeleton-pulse{0%{background-position:100% 0}100%{background-position:0 0}}
    .pcm-skeleton-block{display:flex;flex-direction:column;gap:0}
    .pcm-skeleton{background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200% 100%;animation:pcm-shimmer 1.5s infinite;border-radius:4px;height:16px;margin-bottom:8px}
    @keyframes pcm-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
    .pcm-toast{position:fixed;right:18px;bottom:24px;z-index:100000;display:inline-flex;align-items:center;gap:8px;background:#040024;color:#fff;padding:10px 14px;border-left:4px solid #03fcc2;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,.18);font-size:12.5px;font-weight:600;opacity:0;transform:translateY(8px);transition:opacity .2s ease,transform .2s ease}
    .pcm-toast.is-visible{opacity:1;transform:translateY(0)}
    .pcm-advisor-grid{align-items:start}
    .pcm-score-list{display:flex;flex-direction:column;gap:10px}
    .pcm-score-item{border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fff}
    .pcm-score-meta{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:#4b5563;margin-bottom:6px}
    .pcm-score-bar{position:relative;height:22px;background:#e5e7eb;border-radius:4px;overflow:hidden}
    .pcm-score-fill{display:block;height:100%;border-radius:4px;transition:width .3s ease}
    .pcm-score-fill.is-good{background:#16a34a}
    .pcm-score-fill.is-warn{background:#f59e0b}
    .pcm-score-fill.is-bad{background:#dc2626}
    .pcm-score-value{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#111827}
    .pcm-findings-list{display:flex;flex-direction:column;gap:10px}
    .pcm-finding-item{border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#fff}
    .pcm-finding-urls{display:grid;gap:4px;margin:6px 0 8px}
    .pcm-severity-badge{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;border:1px solid transparent;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.2px}
    .pcm-severity-badge.is-critical{background:#fee2e2;border-color:#fecaca;color:#991b1b}
    .pcm-severity-badge.is-warning{background:#fef3c7;border-color:#fde68a;color:#92400e}
    .pcm-severity-badge.is-info{background:#dcfce7;border-color:#bbf7d0;color:#166534}
    .pcm-advisor-chip{display:inline-flex;align-items:center;margin:2px 6px 2px 0;padding:2px 8px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px}
    .pcm-advisor-diagnosis{display:flex;flex-direction:column;gap:10px}
    .pcm-diagnosis-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .pcm-diagnosis-card{border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fff}
    .pcm-diagnosis-card dt{font-size:11px;text-transform:uppercase;color:#6b7280;font-weight:700}
    .pcm-diagnosis-card dd{margin:4px 0 0;color:#111827;word-break:break-word}
    .pcm-diagnosis-section{border-top:1px solid #e5e7eb;padding-top:8px}
    .pcm-diagnosis-section strong{display:block;margin-bottom:4px}
    .pcm-sp-settings-form{margin-bottom:16px;display:flex;flex-direction:column;gap:14px}
    .pcm-sp-settings-section{border-top:1px solid #e5e7eb;padding-top:12px;display:flex;flex-direction:column;gap:10px}
    .pcm-sp-settings-section:first-of-type{border-top:none;padding-top:0}
    .pcm-sp-settings-section h4{margin:0;font-size:13px;color:#111827}
    .pcm-sp-checkbox-row{display:flex;align-items:flex-start;gap:10px;color:#111827}
    .pcm-sp-checkbox-row input{margin-top:2px}
    .pcm-sp-checkbox-row span{display:flex;flex-direction:column;gap:3px}
    .pcm-sp-checkbox-row small,.pcm-sp-field-label small{color:#6b7280;font-size:12px;line-height:1.4}
    .pcm-sp-two-col-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .pcm-sp-field-label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#111827}
    .pcm-sp-number-wrap{display:flex;align-items:center;gap:8px;max-width:320px}
    .pcm-sp-number-wrap input[type='number']{width:120px;border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;background:#fff;color:#111827}
    .pcm-sp-number-wrap input[type='number']::-webkit-outer-spin-button,
    .pcm-sp-number-wrap input[type='number']::-webkit-inner-spin-button{opacity:1;height:22px}
    .pcm-sp-unit{font-size:12px;color:#6b7280;font-weight:600}
    .pcm-sp-field-label textarea{width:100%;max-width:620px;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;line-height:1.4}
    .pcm-sp-insights-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .pcm-sp-insight-card{border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#f8fafc}
    .pcm-sp-insight-card h4{margin:0 0 8px;font-size:13px;color:#111827}
    .pcm-sp-insight-card-full{grid-column:1 / -1}
    .pcm-timing-grid{display:grid;gap:6px}
    .pcm-timing-row{display:grid;grid-template-columns:64px 1fr auto;align-items:center;gap:8px;font-size:12px;color:#374151}
    .pcm-timing-track{height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden}
    .pcm-timing-track span{display:block;height:100%;background:#6366f1;border-radius:999px;transition:width .3s ease}
    .pcm-batcache-status{position:relative;overflow:hidden}
    .pcm-batcache-status .pcm-dot{animation:none}
    .pcm-batcache-status.checking .pcm-dot{animation:pcm-pulse 1s infinite}
    .pcm-batcache-status.checking::after{content:'';position:absolute;inset:0;background:linear-gradient(115deg,transparent 0%,rgba(255,255,255,.35) 45%,transparent 90%);transform:translateX(-140%);animation:pcm-badge-shimmer 1.2s ease-in-out infinite;pointer-events:none}
    @keyframes pcm-pulse{0%{opacity:.3}50%{opacity:1}100%{opacity:.3}}
    @keyframes pcm-badge-shimmer{100%{transform:translateX(140%)}}
    .pcm-wrap .nav-tab:focus-visible,.pcm-wrap button:focus-visible,.pcm-wrap a:focus-visible,.pcm-wrap input:focus-visible,.pcm-wrap select:focus-visible,.pcm-wrap textarea:focus-visible{outline:2px solid #03fcc2;outline-offset:2px}
    @media (max-width:1024px){.pcm-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
    @media (max-width:782px){.pcm-summary-grid{grid-template-columns:1fr}.pcm-anchor-nav{top:0}.pcm-responsive-two-col,.pcm-object-cache-grid,.pcm-advisor-grid{grid-template-columns:1fr !important}.pcm-diagnosis-grid{grid-template-columns:1fr}.pcm-sp-two-col-grid,.pcm-sp-insights-grid{grid-template-columns:1fr}.pcm-sp-number-wrap{max-width:100%}.pcm-edge-cache-card-wrap{max-width:100%}}

    code { background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:11.5px;color:#dd3a03; }
    </style>
    <?php
}

// ─── Footer ──────────────────────────────────────────────────────────────────
function pcm_footer_msg() {
    if ( 'not-exists' === get_option('remove_pressable_branding_tab_options','not-exists') ) {
        add_option('remove_pressable_branding_tab_options','');
        update_option('remove_pressable_branding_tab_options', array('branding_on_off_radio_button'=>'enable'));
    }
    add_filter('admin_footer_text','pcm_replace_default_footer');
}

function pcm_replace_default_footer($footer_text) {
    if ( is_admin() && isset($_GET['page']) && sanitize_key( $_GET['page'] ) === 'pressable_cache_management' ) {
        $opts              = get_option('remove_pressable_branding_tab_options');
        $branding_disabled = $opts && 'disable' === $opts['branding_on_off_radio_button'];

        if ( $branding_disabled ) {
            // Branding hidden: "Built with ♥" — heart links to branding settings page
            return 'Built with <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab" title="Show or Hide Plugin Branding" style="text-decoration:none;"><span style="color:#03fcc2;font-size:18px;transition:opacity .2s;" onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'">&#x2665;</span></a>';
        } else {
            // Branding shown: full credit — heart links to branding settings page
            return 'Built with <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab" title="Show or Hide Plugin Branding" style="text-decoration:none;"><span style="color:#dd3a03;font-size:20px;transition:opacity .2s;" onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'">&#x2665;</span></a> by The Pressable CS Team.';
        }
    }
    return $footer_text;
}
add_action('admin_init','pcm_footer_msg');
