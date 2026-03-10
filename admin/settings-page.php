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

    $id   = 'pcm-settings-saved-notice';
    echo '<div id="' . $id . '" class="pcm-settings-saved-notice">';
    echo '<p class="pcm-branded-notice-text">'
       . esc_html__( 'Cache settings updated.', 'pressable_cache_management' ) . '</p>';
    echo '<button type="button" aria-label="Dismiss notification" data-pcm-dismiss="' . esc_attr( $id ) . '" class="pcm-settings-saved-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button>';
    echo '</div>';
    echo '<script>document.querySelector(\'[data-pcm-dismiss="' . esc_js( $id ) . '"]\').addEventListener("click",function(){document.getElementById("' . esc_js( $id ) . '").remove();});</script>';
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
    if ( '1' !== get_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value ) ) return;

    // Delete flag IMMEDIATELY — refresh / navigation will never trigger this again
    delete_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value );

    echo '<div class="pcm-batcache-notice-outer">';
    echo '<div id="pcm-extend-batcache-notice" class="pcm-branded-notice pcm-branded-notice-edge">';
    echo '<p class="pcm-branded-notice-text">'
       . esc_html__( 'Extending Batcache for 24 hours — see ', 'pressable_cache_management' )
       . '<a href="https://pressable.com/knowledgebase/modifying-cache-times-batcache/" target="_blank" rel="noopener noreferrer" '
       . 'class="pcm-branded-notice-link">'
       . esc_html__( 'Modifying Batcache Times.', 'pressable_cache_management' ) . '</a>'
       . '</p>';
    echo '<button type="button" aria-label="Dismiss notification" onclick="document.getElementById(\'pcm-extend-batcache-notice\').remove();" '
       . 'class="pcm-branded-notice-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button>';
    echo '</div>';
    echo '</div>';
}

// ─── Helper: pcm_branded_notice (shared, safe) ───────────────────────────────
if ( ! function_exists( 'pcm_branded_notice' ) ) {
    function pcm_branded_notice( $message, $border_color = '#03fcc2', $is_html = false ) {
        $id   = 'pcm-notice-' . substr( md5( $message . $border_color . microtime() ), 0, 8 );
        echo '<div id="' . esc_attr( $id ) . '" class="pcm-branded-notice" style="border-left-color:' . esc_attr( $border_color ) . ';"><div class="pcm-branded-notice-body">';
        if ( $is_html ) {
            echo wp_kses_post( $message );
        } else {
            echo '<p class="pcm-branded-notice-text">' . esc_html( $message ) . '</p>';
        }
        echo '</div><button type="button" aria-label="Dismiss notification" onclick="document.getElementById(\'' . esc_js( $id ) . '\').remove();" class="pcm-branded-notice-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button></div>';
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
    pcm_verify_ajax_request( 'nonce', 'pcm_batcache_nonce' );

    $raw = isset( $_POST['x_nananana'] ) ? sanitize_text_field( wp_unslash( $_POST['x_nananana'] ) ) : '';
    $val = strtolower( trim( $raw ) );

    $status = match ( true ) {
        str_contains( $val, 'batcache' )                                        => 'active',
        ( isset( $_POST['is_cloudflare'] ) && sanitize_text_field( wp_unslash( $_POST['is_cloudflare'] ) ) === '1' ) => 'cloudflare',
        default                                                                 => 'broken',
    };

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
        'label'  => $labels[ $status ] ?? $labels['broken'],
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
    update_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, $enabled, false );

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

    if ( pcm_verify_request( 'pcm_feature_flags_nonce', 'pcm_save_feature_flags' ) ) {
        $caching_suite_enabled  = isset( $_POST['pcm_enable_caching_suite_features'] ) && '1' === (string) wp_unslash( $_POST['pcm_enable_caching_suite_features'] );
        $redirect_enabled       = isset( $_POST['pcm_enable_redirect_assistant'] ) && '1' === (string) wp_unslash( $_POST['pcm_enable_redirect_assistant'] );
        $advanced_scan_enabled  = isset( $_POST['pcm_enable_advanced_scan_workflows'] ) && '1' === (string) wp_unslash( $_POST['pcm_enable_advanced_scan_workflows'] );
        $microcache_enabled     = isset( $_POST['pcm_enable_durable_origin_microcache'] ) && '1' === (string) wp_unslash( $_POST['pcm_enable_durable_origin_microcache'] );

        update_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, $caching_suite_enabled, false );
        update_option( PCM_Options::ENABLE_REDIRECT_ASSISTANT->value, $redirect_enabled, false );
        update_option( PCM_Options::ENABLE_ADVANCED_SCAN_WORKFLOWS->value, $advanced_scan_enabled, false );
        update_option( PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value, $microcache_enabled, false );

        if ( function_exists( 'pcm_audit_log' ) ) {
            pcm_audit_log( 'caching_suite_features_toggled', 'settings', array( 'enabled' => $caching_suite_enabled ) );
            pcm_audit_log( 'redirect_assistant_toggled', 'settings', array( 'enabled' => $redirect_enabled ) );
            pcm_audit_log( 'advanced_scan_toggled', 'settings', array( 'enabled' => $advanced_scan_enabled ) );
            pcm_audit_log( 'durable_microcache_toggled', 'settings', array( 'enabled' => $microcache_enabled ) );
        }
    }

    $caching_suite_enabled  = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
    $redirect_enabled       = (bool) get_option( PCM_Options::ENABLE_REDIRECT_ASSISTANT->value, false );
    $advanced_scan_enabled  = (bool) get_option( PCM_Options::ENABLE_ADVANCED_SCAN_WORKFLOWS->value, false );
    $tab                    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : null;

    $is_deep_dive_disabled  = ( 'deep_dive_tab' === $tab && ! $caching_suite_enabled );
    $is_redirects_disabled  = ( 'redirects_tab' === $tab && ! $redirect_enabled );

    if ( $is_deep_dive_disabled || $is_redirects_disabled ) {
        $tab = null;
    }

    $is_object_tab    = ( null === $tab );
    $is_deep_dive_tab = ( 'deep_dive_tab' === $tab );
    $is_redirects_tab = ( 'redirects_tab' === $tab );
    $is_settings_tab  = ( 'settings_tab' === $tab );

    $branding_opts         = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
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
        'pcm-utils',
        $base_js_url . 'pcm-utils.js',
        array(),
        file_exists( $base_js_path . 'pcm-utils.js' ) ? (string) filemtime( $base_js_path . 'pcm-utils.js' ) : false,
        true
    );

    wp_enqueue_script(
        'pcm-modal-a11y',
        $base_js_url . 'pcm-modal-a11y.js',
        array(),
        file_exists( $base_js_path . 'pcm-modal-a11y.js' ) ? (string) filemtime( $base_js_path . 'pcm-modal-a11y.js' ) : false,
        true
    );

    wp_enqueue_script(
        'pcm-post',
        $base_js_url . 'pcm-post.js',
        array( 'pcm-utils' ),
        file_exists( $base_js_path . 'pcm-post.js' ) ? (string) filemtime( $base_js_path . 'pcm-post.js' ) : false,
        true
    );

    wp_enqueue_script(
        'pcm-settings-page-settings',
        $base_js_url . 'settings.js',
        array( 'jquery', 'pcm-post', 'pcm-modal-a11y', 'pcm-utils' ),
        file_exists( $base_js_path . 'settings.js' ) ? (string) filemtime( $base_js_path . 'settings.js' ) : false,
        true
    );

    // Only load tab-specific JS when the corresponding tab is active.
    // This avoids loading ~2,300 lines of JS on tabs that don't need them.
    if ( $is_object_tab ) {
        wp_enqueue_script(
            'pcm-settings-page-object-cache',
            $base_js_url . 'object-cache-tab.js',
            array( 'pcm-post' ),
            file_exists( $base_js_path . 'object-cache-tab.js' ) ? (string) filemtime( $base_js_path . 'object-cache-tab.js' ) : false,
            true
        );
    }

    if ( $is_deep_dive_tab || $is_settings_tab ) {
        wp_enqueue_script(
            'pcm-settings-page-deep-dive',
            $base_js_url . 'deep-dive.js',
            array( 'pcm-post' ),
            file_exists( $base_js_path . 'deep-dive.js' ) ? (string) filemtime( $base_js_path . 'deep-dive.js' ) : false,
            true
        );
    }

    if ( 'redirects_tab' === $tab ) {
        wp_enqueue_script(
            'pcm-redirects-tab',
            $base_js_url . 'redirects-tab.js',
            array( 'pcm-post' ),
            file_exists( $base_js_path . 'redirects-tab.js' ) ? (string) filemtime( $base_js_path . 'redirects-tab.js' ) : false,
            true
        );
    }

    wp_localize_script(
        'pcm-settings-page-settings',
        'pcmSettingsData',
        array(
            'nonces'          => array(
                'cacheabilityScan'  => wp_create_nonce( 'pcm_cacheability_scan' ),
                'edgeCacheToggle'   => wp_create_nonce( 'pcm_edge_cache_toggle' ),
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

    // Read Batcache max_age from the global if available (set by advanced-cache / mu-plugin).
    $pcm_batcache_max_age = null;
    global $batcache;
    if ( is_object( $batcache ) && isset( $batcache->max_age ) ) {
        $pcm_batcache_max_age = (int) $batcache->max_age;
    } elseif ( is_array( $batcache ) && isset( $batcache['max_age'] ) ) {
        $pcm_batcache_max_age = (int) $batcache['max_age'];
    } elseif ( file_exists( WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/pcm_extend_batcache.php' ) ) {
        // Extend-batcache mu-plugin is active — max_age is 86400.
        $pcm_batcache_max_age = 86400;
    }

    if ( $is_object_tab ) {
        wp_localize_script(
            'pcm-settings-page-object-cache',
            'pcmObjectCacheData',
            array(
                'nonces'          => array(
                    'batcache' => wp_create_nonce( 'pcm_batcache_nonce' ),
                ),
                'siteUrl'         => trailingslashit( get_site_url() ),
                'isUnknown'       => $bc_is_unknown,
                'batcacheMaxAge'  => $pcm_batcache_max_age,
                'strings'         => array(
                    'alreadyChecking' => __( 'Already checking…', 'pressable_cache_management' ),
                    'checking'        => __( 'Checking…', 'pressable_cache_management' ),
                    'checkFailed'     => __( 'Check Failed', 'pressable_cache_management' ),
                ),
            )
        );
    }

    if ( $is_deep_dive_tab ) {
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
    }
    ?>
    <div class="wrap pcm-wrap pcm-page-wrap">
    <div class="pcm-page-inner">
    <h1 class="pcm-sr-only"><?php echo esc_html( get_admin_page_title() ); ?></h1>


    <!-- ── Tabs ── -->
    <nav class="nav-tab-wrapper pcm-tab-nav" id="pcm-main-tab-nav">
        <a href="admin.php?page=pressable_cache_management"
           class="nav-tab <?php echo $is_object_tab ? 'nav-tab-active' : ''; ?>">Object Cache</a>
        <a href="admin.php?page=pressable_cache_management&tab=edge_cache_settings_tab"
           class="nav-tab <?php echo $tab === 'edge_cache_settings_tab' ? 'nav-tab-active' : ''; ?>">Edge Cache</a>
        <?php if ( $caching_suite_enabled ) : ?>
        <a href="admin.php?page=pressable_cache_management&tab=deep_dive_tab"
           class="nav-tab <?php echo $is_deep_dive_tab ? 'nav-tab-active' : ''; ?>" id="pcm-deep-dive-tab">Deep Dive</a>
        <?php endif; ?>
        <?php if ( $redirect_enabled ) : ?>
        <a href="admin.php?page=pressable_cache_management&tab=redirects_tab"
           class="nav-tab <?php echo $is_redirects_tab ? 'nav-tab-active' : ''; ?>" id="pcm-redirects-tab">Redirects</a>
        <?php endif; ?>
        <a href="admin.php?page=pressable_cache_management&tab=settings_tab"
           class="nav-tab <?php echo $is_settings_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
        <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab"
           class="nav-tab nav-tab-hidden <?php echo $tab === 'remove_pressable_branding_tab' ? 'nav-tab-active' : ''; ?>">Branding</a>
    </nav>

    <?php if ( $is_deep_dive_disabled ) : ?>
    <div class="pcm-card pcm-deep-dive-disabled-notice">
        <span class="pcm-deep-dive-disabled-icon"><span class="dashicons dashicons-lock" aria-hidden="true"></span></span>
        <h3 class="pcm-deep-dive-disabled-title">
            <?php esc_html_e( 'Deep Dive is locked', 'pressable_cache_management' ); ?>
        </h3>
        <p class="pcm-deep-dive-disabled-text">
            <?php esc_html_e( 'Enable Caching Suite in Feature Flags to use Deep Dive.', 'pressable_cache_management' ); ?>
        </p>
        <a href="admin.php?page=pressable_cache_management&tab=settings_tab"
           class="pcm-btn-primary pcm-btn-inline-flex">
            <?php esc_html_e( 'Go to Settings', 'pressable_cache_management' ); ?>
        </a>
    </div>
    <?php endif; ?>

    <?php if ( $is_object_tab || $is_deep_dive_tab || $is_redirects_tab || $is_settings_tab ) :
        $options = pcm_get_options();

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
    <?php $microcache_enabled = (bool) get_option( PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value, false ); ?>
    <div class="pcm-card pcm-card-mb">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-flag pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Feature Flags', 'pressable_cache_management' ); ?></h3>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Control which major Caching Suite modules are active for diagnostics, automation, and deep-dive insights.', 'pressable_cache_management' ); ?></p>
        <form method="post">
            <input type="hidden" name="pcm_feature_flags_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pcm_save_feature_flags' ) ); ?>" />
            <input type="hidden" id="pcm-caching-suite-toggle-nonce" value="<?php echo esc_attr( wp_create_nonce( 'pcm_toggle_caching_suite_features' ) ); ?>" />

            <div class="pcm-toggle-row">
                <label class="switch pcm-switch-label">
                    <input type="checkbox" name="pcm_enable_caching_suite_features" id="pcm-caching-suite-toggle" value="1" <?php checked( $caching_suite_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Caching Suite', 'pressable_cache_management' ); ?>" />
                    <span class="slider round"></span>
                </label>
                <div>
                    <div class="pcm-toggle-title"><?php echo esc_html__( 'Caching Suite', 'pressable_cache_management' ); ?>
                        <span class="pcm-status-badge <?php echo $caching_suite_enabled ? 'is-active' : 'is-inactive'; ?>" id="pcm-caching-suite-status-badge"><?php echo $caching_suite_enabled ? esc_html__( 'Active', 'pressable_cache_management' ) : esc_html__( 'Inactive', 'pressable_cache_management' ); ?></span>
                    </div>
                    <div class="pcm-toggle-desc"><?php echo esc_html__( 'Turns on the full diagnostics and remediation toolset used across Deep Dive analysis.', 'pressable_cache_management' ); ?></div>
                    <div class="pcm-feature-chips">
                        <span class="pcm-chip-tooltip-wrap" tabindex="0" role="button" title="Analyzes headers and cache directives to highlight uncached opportunities." aria-describedby="pcm-chip-desc-1"><?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?> <span class="dashicons dashicons-info-outline pcm-chip-info-icon" aria-hidden="true"></span><span class="pcm-chip-tooltip" id="pcm-chip-desc-1" role="tooltip"><?php echo esc_html__( 'Analyzes headers and cache directives to highlight uncached opportunities.', 'pressable_cache_management' ); ?></span></span>
                        <span class="pcm-chip-tooltip-wrap" tabindex="0" role="button" title="Surfaces noisy query strings and cookie patterns that break cache hit rates." aria-describedby="pcm-chip-desc-2"><?php echo esc_html__( 'Cache-Busting Detector', 'pressable_cache_management' ); ?> <span class="dashicons dashicons-info-outline pcm-chip-info-icon" aria-hidden="true"></span><span class="pcm-chip-tooltip" id="pcm-chip-desc-2" role="tooltip"><?php echo esc_html__( 'Surfaces noisy query strings and cookie patterns that break cache hit rates.', 'pressable_cache_management' ); ?></span></span>
                        <span class="pcm-chip-tooltip-wrap" tabindex="0" role="button" title="Generates recommended next actions when anti-patterns are detected." aria-describedby="pcm-chip-desc-3"><?php echo esc_html__( 'Guided Remediation', 'pressable_cache_management' ); ?> <span class="dashicons dashicons-info-outline pcm-chip-info-icon" aria-hidden="true"></span><span class="pcm-chip-tooltip" id="pcm-chip-desc-3" role="tooltip"><?php echo esc_html__( 'Generates recommended next actions when anti-patterns are detected.', 'pressable_cache_management' ); ?></span></span>
                    </div>
                    <span class="pcm-ts-inline" id="pcm-caching-suite-inline-status"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $caching_suite_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
                </div>
            </div>

            <div class="pcm-toggle-row">
                <label class="switch pcm-switch-label">
                    <input type="checkbox" name="pcm_enable_redirect_assistant" value="1" <?php checked( $redirect_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Redirect Assistant', 'pressable_cache_management' ); ?>" />
                    <span class="slider round"></span>
                </label>
                <div>
                    <div class="pcm-toggle-title"><?php echo esc_html__( 'Redirect Assistant', 'pressable_cache_management' ); ?></div>
                    <div class="pcm-toggle-desc"><?php echo esc_html__( 'Enables the Redirects tab for discovering, managing, simulating, and exporting redirect rules.', 'pressable_cache_management' ); ?></div>
                    <span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $redirect_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
                </div>
            </div>

            <div class="pcm-toggle-row">
                <label class="switch pcm-switch-label">
                    <input type="checkbox" name="pcm_enable_advanced_scan_workflows" value="1" <?php checked( $advanced_scan_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Advanced Scanning Workflows', 'pressable_cache_management' ); ?>" />
                    <span class="slider round"></span>
                </label>
                <div>
                    <div class="pcm-toggle-title"><?php echo esc_html__( 'Advanced Scanning Workflows', 'pressable_cache_management' ); ?></div>
                    <div class="pcm-toggle-desc"><?php echo esc_html__( 'Allows deep header inspection and multi-URL scanning in Cacheability Advisor. Requires privacy opt-in.', 'pressable_cache_management' ); ?></div>
                    <span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $advanced_scan_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
                </div>
            </div>

            <div class="pcm-toggle-row pcm-toggle-row-no-border">
                <label class="switch pcm-switch-label">
                    <input type="checkbox" name="pcm_enable_durable_origin_microcache" value="1" <?php checked( $microcache_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Durable Origin Microcache', 'pressable_cache_management' ); ?>" />
                    <span class="slider round"></span>
                </label>
                <div>
                    <div class="pcm-toggle-title"><?php echo esc_html__( 'Durable Origin Microcache', 'pressable_cache_management' ); ?></div>
                    <div class="pcm-toggle-desc"><?php echo esc_html__( 'Caches anonymous-safe JSON/HTML artifacts at origin with tag-based invalidation and fast regeneration.', 'pressable_cache_management' ); ?></div>
                    <span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $microcache_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
                </div>
            </div>

            <div class="pcm-feature-flags-save-row">
                <button type="submit" class="pcm-btn-primary"><?php echo esc_html__( 'Save Feature Flags', 'pressable_cache_management' ); ?></button>
                <span class="pcm-feature-flags-note"><?php echo esc_html__( 'Changes take effect immediately after save.', 'pressable_cache_management' ); ?></span>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ( $is_object_tab ) : ?>
    <!-- Header: logo + status badge -->
    <div class="pcm-object-tab-header">
        <div>
            <?php if ( $show_branding ) : ?>
            <p class="pcm-branding-subtitle"><?php echo esc_html__( 'Cache Management by', 'pressable_cache_management' ); ?></p>
            <img class="pressablecmlogo"
                 src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/img/pressable-logo-primary.svg' ); ?>"
                 alt="Pressable">
            <?php endif; ?>
        </div>
        <div class="pcm-batcache-header-group">
            <span class="pcm-batcache-status <?php echo esc_attr($bc_class); ?>" id="pcm-bc-badge">
                <span class="pcm-dot" id="pcm-bc-dot"></span>
                <span id="pcm-bc-label"><?php echo esc_html($bc_label); ?></span>
                <button id="pcm-bc-refresh" title="<?php esc_attr_e('Re-check Batcache status', 'pressable_cache_management'); ?>"
                        class="pcm-bc-refresh-btn"
                        onclick="pcmRefreshBatcacheStatus()"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
            </span>
            <span class="screen-reader-text" aria-live="polite" id="pcm-bc-status-announce"></span>
            <span class="pcm-bc-tooltip-wrap">
                <span class="pcm-bc-tooltip-trigger" tabindex="0" role="button" aria-label="Batcache info"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></span>
                <span class="pcm-bc-tooltip">
                    <?php echo esc_html__( 'Use the refresh button to manually check your cache status. If the cache status remains broken for more than 4 minutes after two visits are recorded on your site, it is likely that caching is failing due to cookie interference from your plugin, theme or custom code.', 'pressable_cache_management' ); ?>
                    <span class="pcm-bc-tooltip-arrow"></span>
                </span>
            </span>
        </div>
    </div>


    <?php endif; ?>


    <?php if ( $is_deep_dive_tab || $is_redirects_tab ) : ?>
    <?php
    // Determine module availability for graceful degradation.
    $pcm_module_available = array(
        'object_cache'   => function_exists( 'pcm_object_cache_intelligence_is_enabled' ),
        'opcache'        => function_exists( 'pcm_opcache_awareness_is_enabled' ),
        'cacheability'   => function_exists( 'pcm_cacheability_advisor_is_enabled' ),
        'redirects'      => function_exists( 'pcm_redirect_assistant_is_enabled' ),
    );

    ?>
    <?php if ( $is_deep_dive_tab ) : ?>
    <nav class="pcm-anchor-nav" id="pcm-deep-dive-nav" aria-label="Deep Dive sections">
        <a href="#pcm-feature-cacheability-advisor">Cacheability</a>
        <a href="#pcm-feature-cache-overview">Cache Overview</a>
    </nav>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && $pcm_module_available['cacheability'] && pcm_cacheability_advisor_is_enabled() ) : ?>
    <div class="pcm-card pcm-card-hover pcm-card-mb-scroll pcm-lazy-section" id="pcm-feature-cacheability-advisor" data-section="cacheability">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-performance pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?></h3>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Run a cacheability scan and review per-template scores, URL results, and findings.', 'pressable_cache_management' ); ?></p>
        <div class="pcm-lazy-skeleton pcm-skeleton-panel" aria-hidden="true"></div>
        <template class="pcm-lazy-template">
        <p>
            <button type="button" class="pcm-btn-primary" id="pcm-advisor-run-btn"><?php echo esc_html__( 'Rescan now', 'pressable_cache_management' ); ?></button>
            <span id="pcm-advisor-run-status" class="pcm-inline-status" aria-live="polite" role="status"></span>
        </p>
        <div class="pcm-advisor-grid pcm-responsive-two-col pcm-grid-2col">
            <div>
                <h4 class="pcm-section-subhead"><?php echo esc_html__( 'Template Scores', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-advisor-template-scores" class="pcm-panel-text"></div>
            </div>
            <div>
                <h4 class="pcm-section-subhead"><?php echo esc_html__( 'Latest Findings', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-advisor-findings" class="pcm-panel-text"></div>
            </div>
        </div>
        <div class="pcm-mt-14">
            <h4 class="pcm-section-subhead"><?php echo esc_html__( 'Route Sensitivity', 'pressable_cache_management' ); ?></h4>
            <div id="pcm-advisor-sensitivity" class="pcm-panel-text"></div>
        </div>
        <div class="pcm-mt-14">
            <h4 class="pcm-section-subhead"><?php echo esc_html__( 'Route Diagnosis', 'pressable_cache_management' ); ?></h4>
            <div id="pcm-advisor-diagnosis" class="pcm-advisor-diagnosis pcm-advisor-diagnosis-box"><em><?php echo esc_html__( 'Select a route from findings to view diagnosis.', 'pressable_cache_management' ); ?></em></div>
        </div>
        <div id="pcm-advisor-playbook" class="pcm-playbook-panel"></div>
        </template>
    </div>
    <?php elseif ( $is_deep_dive_tab ) : ?>
    <div class="pcm-card pcm-card-mb-scroll" id="pcm-feature-cacheability-advisor">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-performance pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?></h3>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'This module is not available. It may be disabled or failed to load.', 'pressable_cache_management' ); ?></p>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pressable_cache_management&tab=settings_tab' ) ); ?>"><?php echo esc_html__( 'Check Feature Flags in Settings', 'pressable_cache_management' ); ?></a></p>
    </div>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab ) : ?>
    <div class="pcm-card pcm-card-hover pcm-card-mb-scroll pcm-lazy-section" id="pcm-feature-cache-overview" data-section="cache-overview">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-performance pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Cache Overview', 'pressable_cache_management' ); ?></h3>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Caching stack status, object cache hit ratio, and 7-day trend.', 'pressable_cache_management' ); ?></p>
        <div class="pcm-lazy-skeleton pcm-skeleton-panel" aria-hidden="true"></div>
        <template class="pcm-lazy-template">
        <p>
            <button type="button" class="pcm-btn-secondary" id="pcm-cache-overview-refresh"><?php echo esc_html__( 'Refresh', 'pressable_cache_management' ); ?></button>
            <span id="pcm-cache-overview-status" class="pcm-inline-status" aria-live="polite" role="status"></span>
        </p>
        <div id="pcm-cache-overview-cards" class="pcm-cache-insights-grid"></div>
        <div id="pcm-cache-overview-trend" class="pcm-trend-panel pcm-panel-text" style="margin-top:16px;"></div>
        </template>
    </div>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_microcache_render_deep_dive_card' ) ) : ?>
        <?php pcm_microcache_render_deep_dive_card(); ?>
    <?php endif; ?>

    <?php endif; ?>

    <?php if ( $is_redirects_tab && $redirect_enabled && $pcm_module_available['redirects'] && pcm_redirect_assistant_is_enabled() ) : ?>
    <div class="pcm-redirects-grid">
        <div class="pcm-col-stack">
            <!-- Card 1: Discover Candidates -->
            <div class="pcm-card">
                <h3 class="pcm-card-title">
                    <span class="dashicons dashicons-search pcm-title-icon" aria-hidden="true"></span>
                    <?php echo esc_html__( 'Discover Candidates', 'pressable_cache_management' ); ?>
                </h3>
                <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Paste URLs from your old site or analytics to auto-generate redirect rule suggestions.', 'pressable_cache_management' ); ?></p>
                <textarea id="pcm-ra-urls" rows="4" class="pcm-textarea-full" placeholder="https://example.com/Page?utm_source=x&#10;https://example.com/page/"></textarea>
                <p>
                    <button type="button" class="pcm-btn-primary" id="pcm-ra-discover"><?php echo esc_html__( 'Discover Candidates', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-candidates-output" class="pcm-output-panel"></div>
            </div>

            <!-- Card 2: Rule Builder -->
            <div class="pcm-card">
                <h3 class="pcm-card-title">
                    <span class="dashicons dashicons-editor-table pcm-title-icon" aria-hidden="true"></span>
                    <?php echo esc_html__( 'Rule Builder', 'pressable_cache_management' ); ?>
                </h3>
                <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Create and manage your redirect rules. Load existing rules or add new ones manually.', 'pressable_cache_management' ); ?></p>
                <p>
                    <button type="button" class="pcm-btn-secondary" id="pcm-ra-load-rules"><?php echo esc_html__( 'Load Saved Rules', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="pcm-btn-text" id="pcm-ra-add-rule"><?php echo esc_html__( '+ Add Rule', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-rule-editor" class="pcm-rule-editor-box">
                    <div class="pcm-overflow-auto">
                        <table class="pcm-table-full" id="pcm-ra-rules-table">
                            <thead>
                                <tr>
                                    <th class="pcm-th-cell"><?php echo esc_html__( 'Source', 'pressable_cache_management' ); ?></th>
                                    <th class="pcm-th-cell"><?php echo esc_html__( 'Target', 'pressable_cache_management' ); ?></th>
                                    <th class="pcm-th-cell"><?php echo esc_html__( 'Match', 'pressable_cache_management' ); ?></th>
                                    <th class="pcm-th-cell"><?php echo esc_html__( 'Code', 'pressable_cache_management' ); ?></th>
                                    <th class="pcm-th-cell"><?php echo esc_html__( 'On', 'pressable_cache_management' ); ?></th>
                                    <th class="pcm-th-cell"><?php echo esc_html__( 'Actions', 'pressable_cache_management' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="pcm-ra-rules-body"></tbody>
                        </table>
                    </div>
                    <div id="pcm-ra-rule-errors" class="pcm-rule-errors" role="alert" aria-live="assertive"></div>
                </div>
                <p class="pcm-mt-8">
                    <button type="button" class="pcm-btn-text pcm-toggle-advanced-btn" id="pcm-ra-toggle-advanced"><?php echo esc_html__( 'Show Advanced JSON', 'pressable_cache_management' ); ?></button>
                </p>
                <textarea id="pcm-ra-rules-json" rows="10" class="pcm-textarea-mono pcm-hidden" placeholder='[ {"enabled":true,"match_type":"exact","source_pattern":"/old","target_pattern":"https://example.com/new"} ]'></textarea>
                <p>
                    <label><input type="checkbox" id="pcm-ra-confirm-wildcards" /> <?php echo esc_html__( 'I confirm wildcard/regex rules have been reviewed.', 'pressable_cache_management' ); ?></label>
                </p>
                <p>
                    <button type="button" class="pcm-btn-primary" id="pcm-ra-save"><?php echo esc_html__( 'Save Rules', 'pressable_cache_management' ); ?></button>
                </p>
            </div>
        </div>

        <div class="pcm-col-stack">
            <!-- Card 3: Dry-Run Simulator -->
            <div class="pcm-card">
                <h3 class="pcm-card-title">
                    <span class="dashicons dashicons-controls-play pcm-title-icon" aria-hidden="true"></span>
                    <?php echo esc_html__( 'Dry-Run Simulator', 'pressable_cache_management' ); ?>
                </h3>
                <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Test URLs against your current rules without affecting production.', 'pressable_cache_management' ); ?></p>
                <textarea id="pcm-ra-sim-urls" rows="4" class="pcm-textarea-full" placeholder="https://example.com/old&#10;https://example.com/OLD/"></textarea>
                <p>
                    <button type="button" class="pcm-btn-primary" id="pcm-ra-simulate"><?php echo esc_html__( 'Run Simulation', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-sim-output" class="pcm-output-panel"></div>
            </div>

            <!-- Card 4: Export & Import -->
            <div class="pcm-card">
                <h3 class="pcm-card-title">
                    <span class="dashicons dashicons-download pcm-title-icon" aria-hidden="true"></span>
                    <?php echo esc_html__( 'Export & Import', 'pressable_cache_management' ); ?>
                </h3>
                <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Generate deployable redirect payloads or import rules from another site.', 'pressable_cache_management' ); ?></p>

                <div class="pcm-ra-export-section">
                    <h5><?php echo esc_html__( 'Export', 'pressable_cache_management' ); ?></h5>
                    <p>
                        <button type="button" class="pcm-btn-secondary" id="pcm-ra-export"><?php echo esc_html__( 'Build Export', 'pressable_cache_management' ); ?></button>
                    </p>
                    <textarea id="pcm-ra-export-content" rows="8" class="pcm-textarea-mono" readonly placeholder="Generated custom-redirects.php content will appear here"></textarea>
                    <p>
                        <button type="button" class="pcm-btn-text" id="pcm-ra-copy"><?php echo esc_html__( 'Copy to Clipboard', 'pressable_cache_management' ); ?></button>
                        <button type="button" class="pcm-btn-secondary" id="pcm-ra-download"><?php echo esc_html__( 'Download custom-redirects.php', 'pressable_cache_management' ); ?></button>
                    </p>
                </div>

                <div class="pcm-ra-import-section">
                    <h5><?php echo esc_html__( 'Import', 'pressable_cache_management' ); ?></h5>
                    <textarea id="pcm-ra-import-content" rows="4" class="pcm-textarea-mono" placeholder="Paste JSON payload here to import rules"></textarea>
                    <p>
                        <button type="button" class="pcm-btn-secondary" id="pcm-ra-import"><?php echo esc_html__( 'Import JSON Payload', 'pressable_cache_management' ); ?></button>
                    </p>
                </div>

                <div id="pcm-ra-output" class="pcm-output-panel"></div>
            </div>
        </div>
    </div>
    <?php elseif ( $is_redirects_tab ) : ?>
    <div class="pcm-card">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-redo pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Redirects', 'pressable_cache_management' ); ?></h3>
        <?php if ( ! $redirect_enabled ) : ?>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Enable Redirect Assistant in Feature Flags to use Redirects.', 'pressable_cache_management' ); ?></p>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pressable_cache_management&tab=settings_tab' ) ); ?>" class="pcm-btn-primary pcm-btn-inline-flex"><?php echo esc_html__( 'Go to Settings', 'pressable_cache_management' ); ?></a></p>
        <?php else : ?>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'This module is not available. It may be disabled or failed to load.', 'pressable_cache_management' ); ?></p>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pressable_cache_management&tab=settings_tab' ) ); ?>"><?php echo esc_html__( 'Check Feature Flags in Settings', 'pressable_cache_management' ); ?></a></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ( $is_settings_tab ) : ?>
    <div class="pcm-card pcm-card-mb-scroll" id="pcm-feature-security-privacy">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-shield pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Privacy & Security', 'pressable_cache_management' ); ?></h3>
        <p class="pcm-text-muted-intro"><?php echo esc_html__( 'Configure retention and redaction policy, then review audit log history for privileged actions.', 'pressable_cache_management' ); ?></p>
        <?php if ( function_exists( 'pcm_security_privacy_is_enabled' ) && ! pcm_security_privacy_is_enabled() ) : ?>
        <div class="pcm-warning-banner">
            <span aria-hidden="true" class="pcm-warning-banner-icon"><span class="dashicons dashicons-warning"></span></span>
            <span><?php echo esc_html__( 'Privacy & Security is currently disabled by feature filter. Enable pcm_enable_security_privacy to use retention controls and audit logs.', 'pressable_cache_management' ); ?></span>
        </div>
        <?php else : ?>
        <div class="pcm-responsive-two-col pcm-grid-2col">
            <div>
                <h4 class="pcm-section-subhead"><?php echo esc_html__( 'Privacy Settings', 'pressable_cache_management' ); ?></h4>
                <p><label><?php echo esc_html__( 'Retention Days', 'pressable_cache_management' ); ?> <input type="number" id="pcm-privacy-retention" min="7" max="365" value="<?php echo esc_attr( isset( $privacy_settings['retention_days'] ) ? (int) $privacy_settings['retention_days'] : 90 ); ?>" /></label></p>
                <p><label><input type="checkbox" id="pcm-privacy-audit-enabled" <?php checked( ! empty( $privacy_settings['audit_log_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable audit logging', 'pressable_cache_management' ); ?></label></p>
                <p><button type="button" class="pcm-btn-primary" id="pcm-privacy-save"><?php echo esc_html__( 'Save Privacy Settings', 'pressable_cache_management' ); ?></button>
                <span id="pcm-privacy-status" class="pcm-privacy-status" aria-live="polite" role="status"></span></p>
            </div>
            <div>
                <h4 class="pcm-section-subhead"><?php echo esc_html__( 'Audit Log', 'pressable_cache_management' ); ?></h4>
                <p>
                    <button type="button" class="pcm-btn-secondary" id="pcm-audit-refresh"><?php echo esc_html__( 'Refresh Audit Log', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="pcm-btn-secondary" id="pcm-audit-export-csv"><?php echo esc_html__( 'Export CSV', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-audit-log" class="pcm-audit-panel pcm-skeleton-panel" aria-live="polite"></div>
                <p class="pcm-mt-8"><button type="button" class="pcm-btn-secondary pcm-hidden" id="pcm-audit-load-more"><?php echo esc_html__( 'Load More', 'pressable_cache_management' ); ?></button></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>


    <?php if ( $is_object_tab ) : ?>

    <!-- ── 2-column grid ── -->
    <div class="pcm-object-cache-grid">

        <!-- LEFT -->
        <div class="pcm-col-stack">

            <!-- Global Controls -->
            <div class="pcm-card">
                <h3 class="pcm-card-title"><span class="dashicons dashicons-update pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Global Controls', 'pressable_cache_management' ); ?></h3>
                <p class="pcm-section-title"><?php echo esc_html__( 'Flush Object Cache', 'pressable_cache_management' ); ?></p>
                <form method="post" id="pcm-flush-form">
                    <input type="hidden" name="flush_object_cache_nonce" value="<?php echo esc_attr( wp_create_nonce('flush_object_cache_nonce') ); ?>">
                    <input type="submit" value="<?php esc_attr_e('Flush Cache for all Pages','pressable_cache_management'); ?>"
                           class="pcm-btn-primary pcm-btn-block" id="pcm-flush-btn">
                </form>
                <div id="pcm-flush-feedback" class="pcm-flush-feedback" aria-live="polite"></div>
                <?php $ts = get_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value ); ?>
                <div class="pcm-ts-block">
                    <span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span><br>
                    <span class="pcm-ts-value" id="pcm-last-flushed-value"><?php echo $ts ? esc_html($ts) : '—'; ?></span>
                </div>

                <!-- Current Cache TTL -->
                <?php $pcm_ttl = get_option( PCM_Options::LAST_TTL->value, array() ); ?>
                <div class="pcm-ts-divider">
                    <span class="pcm-ts-label"><?php esc_html_e( 'CURRENT CACHE MAX-AGE', 'pressable_cache_management' ); ?></span><br>
                    <span id="pcm-ttl-value" class="pcm-ts-value">—</span>
                </div>
            </div>

            <!-- Automated Rules -->
            <div class="pcm-card">
                <form action="options.php" method="post" id="pcm-main-settings-form">
                <?php settings_fields( PCM_Options::MAIN_OPTIONS->value ); ?>
                <h3 class="pcm-card-title"><?php echo esc_html__( 'Automated Rules', 'pressable_cache_management' ); ?></h3>

                <?php
                $rules = array(
                    'flush_cache_theme_plugin_checkbox' => array(
                        'title' => '<span class="dashicons dashicons-admin-plugins pcm-rule-icon" aria-hidden="true"></span> ' . __( 'Flush Cache on Plugin/Theme Update', 'pressable_cache_management' ),
                        'desc'  => __( 'Flush cache automatically on plugin & theme update.', 'pressable_cache_management' ),
                        'ts'    => get_option( PCM_Options::FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP->value ),
                    ),
                    'flush_cache_page_edit_checkbox' => array(
                        'title' => '<span class="dashicons dashicons-edit pcm-rule-icon" aria-hidden="true"></span> ' . __( 'Flush Cache on Post/Page Edit', 'pressable_cache_management' ),
                        'desc'  => __( 'Flush cache automatically when page/post/post_types are updated.', 'pressable_cache_management' ),
                        'ts'    => get_option( PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP->value ),
                    ),
                    'flush_cache_on_comment_delete_checkbox' => array(
                        'title' => '<span class="dashicons dashicons-admin-comments pcm-rule-icon" aria-hidden="true"></span> ' . __( 'Flush Cache on Comment Delete', 'pressable_cache_management' ),
                        'desc'  => __( 'Flush cache automatically when comments are deleted.', 'pressable_cache_management' ),
                        'ts'    => get_option( PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP->value ),
                    ),
                );
                foreach ( $rules as $id => $rule ) :
                    $checked = isset($options[$id]) ? checked($options[$id], 1, false) : '';
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch pcm-switch-label">
                        <input type="checkbox"
                               name="pressable_cache_management_options[<?php echo esc_attr($id); ?>]"
                               value="1" <?php echo $checked; ?>
                               aria-label="<?php echo esc_attr( wp_strip_all_tags( html_entity_decode( $rule['title'] ) ) ); ?>">
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo wp_kses_post($rule['title']); ?></div>
                        <div class="pcm-toggle-desc"><?php echo wp_kses_post($rule['desc']); ?></div>
                        <span class="pcm-ts-inline"><strong><?php echo esc_html__('Last flushed at:', 'pressable_cache_management'); ?></strong> <?php echo $rule['ts'] ? esc_html( $rule['ts'] ) : '&#8212;'; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                </form>
            </div>

        </div><!-- /LEFT -->

        <!-- RIGHT -->
        <div class="pcm-col-stack">

            <!-- Batcache & Page Rules -->
            <div class="pcm-card">
                <h3 class="pcm-card-title"><?php echo esc_html__( 'Batcache & Page Rules', 'pressable_cache_management' ); ?></h3>

                <?php
                // Extend Batcache
                $eb_checked = isset($options['extend_batcache_checkbox']) ? checked($options['extend_batcache_checkbox'],1,false) : '';
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch pcm-switch-label">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[extend_batcache_checkbox]"
                               value="1" <?php echo $eb_checked; ?>
                               aria-label="<?php echo esc_attr__( 'Extend Batcache TTL', 'pressable_cache_management' ); ?>">
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
                $sp_ts      = get_option( PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value );
                $sp_url     = get_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value );
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch pcm-switch-label">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[flush_object_cache_for_single_page]"
                               value="1" <?php echo $sp_checked; ?>
                               aria-label="<?php echo esc_attr__( 'Enable Individual Page Rules', 'pressable_cache_management' ); ?>">
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo esc_html__( 'Flush Batcache for Individual Pages', 'pressable_cache_management' ); ?></div>
                        <div class="pcm-toggle-desc"><?php echo esc_html__( 'Flush Batcache for individual pages from page preview toolbar.', 'pressable_cache_management' ); ?></div>
                        <span class="pcm-ts-inline"><strong><?php echo esc_html__('Last flushed at:', 'pressable_cache_management'); ?></strong> <?php echo $sp_ts ? esc_html( $sp_ts ) : '&#8212;'; ?></span>
                        <span class="pcm-ts-inline"><strong><?php echo esc_html__('Page URL:', 'pressable_cache_management'); ?></strong> <?php echo $sp_url ? esc_html( $sp_url ) : '&#8212;'; ?></span>
                    </div>
                </div>

                <?php
                // Flush cache automatically when published pages/posts are deleted
                $del_checked = isset($options['flush_cache_on_page_post_delete_checkbox']) ? checked($options['flush_cache_on_page_post_delete_checkbox'],1,false) : '';
                $del_ts      = get_option( PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP->value );
                ?>
                <div class="pcm-toggle-row">
                    <label class="switch pcm-switch-label">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[flush_cache_on_page_post_delete_checkbox]"
                               value="1" <?php echo $del_checked; ?>
                               aria-label="<?php echo esc_attr__( 'Flush on Page/Post Delete', 'pressable_cache_management' ); ?>">
                        <span class="slider round"></span>
                    </label>
                    <div>
                        <div class="pcm-toggle-title"><?php echo esc_html__( 'Flush cache automatically when published pages/posts are deleted.', 'pressable_cache_management' ); ?></div>
                        <div class="pcm-toggle-desc"><?php echo esc_html__( 'Flushes Batcache for the specific page when it is deleted.', 'pressable_cache_management' ); ?></div>
                        <span class="pcm-ts-inline"><strong><?php echo esc_html__('Last flushed at:', 'pressable_cache_management'); ?></strong> <?php echo $del_ts ? esc_html( $del_ts ) : '&#8212;'; ?></span>
                    </div>
                </div>

                <?php
                // Flush Batcache for WooCommerce product pages
                $woo_checked = isset($options['flush_batcache_for_woo_product_individual_page_checkbox']) ? checked($options['flush_batcache_for_woo_product_individual_page_checkbox'],1,false) : '';
                ?>
                <div class="pcm-toggle-row pcm-toggle-row-no-border">
                    <label class="switch pcm-switch-label">
                        <input type="checkbox" form="pcm-main-settings-form"
                               name="pressable_cache_management_options[flush_batcache_for_woo_product_individual_page_checkbox]"
                               value="1" <?php echo $woo_checked; ?>
                               aria-label="<?php echo esc_attr__( 'Enable WooCommerce Cache Rules', 'pressable_cache_management' ); ?>">
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
                <p class="pcm-section-title"><?php echo esc_html__( 'Cache Exclusions', 'pressable_cache_management' ); ?></p>

                <?php
                $exempt_val = isset($options['exempt_from_batcache']) ? sanitize_text_field($options['exempt_from_batcache']) : '';
                $pages = $exempt_val ? array_values( array_filter( array_map('trim', explode(',', $exempt_val)) ) ) : array();
                ?>

                <!-- Chips -->
                <div id="pcm-chips-wrap" class="pcm-chips-wrap">
                    <?php foreach ($pages as $page) : ?>
                    <span class="pcm-chip" data-value="<?php echo esc_attr($page); ?>">
                        <?php echo esc_html($page); ?>
                        <button type="button" class="pcm-chip-remove" title="Remove"><span class="dashicons dashicons-no" aria-hidden="true"></span></button>
                    </span>
                    <?php endforeach; ?>
                </div>
                <a href="#" id="pcm-chips-clear-all" class="pcm-chips-clear-all" style="display:<?php echo count($pages) >= 2 ? 'inline' : 'none'; ?>;"><?php echo esc_html__( 'Clear all', 'pressable_cache_management' ); ?></a>

                <input type="hidden" id="pcm-exempt-hidden"
                       name="pressable_cache_management_options[exempt_from_batcache]"
                       form="pcm-main-settings-form"
                       value="<?php echo esc_attr($exempt_val); ?>">

                <input type="text" id="pcm-exempt-input" autocomplete="off"
                       placeholder="<?php echo esc_attr__( 'Enter single URL (e.g., /pagename/).', 'pressable_cache_management' ); ?>"
                       class="pcm-exempt-input">
                <span id="pcm-exempt-dupe-msg" class="pcm-exempt-dupe-msg" style="display:none;" aria-live="polite"></span>
                <p class="pcm-exclusion-hint">
                    <?php echo esc_html__( 'To exclude a single page use', 'pressable_cache_management' ); ?> <code>/page/</code> — <?php echo esc_html__( 'for multiple pages separate with comma, e.g.', 'pressable_cache_management' ); ?> <code>/your-site.com/, /about-us/, /info/</code>
                </p>
            </div>

        </div><!-- /RIGHT -->
    </div><!-- /grid -->

    <!-- MU-Plugin Health Check -->
    <?php
    $missing_mu = pcm_check_mu_plugin_health();
    if ( ! empty( $missing_mu ) ) : ?>
    <div class="pcm-card pcm-card-mb pcm-notice-error-inline">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-warning pcm-title-icon" aria-hidden="true" style="color:#dd3a03;"></span> <?php echo esc_html__( 'MU-Plugin Health', 'pressable_cache_management' ); ?></h3>
        <p><?php echo esc_html__( 'The following MU-plugin files are expected but missing. Try toggling the related feature off and on again, or check file permissions.', 'pressable_cache_management' ); ?></p>
        <ul>
            <?php foreach ( $missing_mu as $file ) : ?>
                <li><code><?php echo esc_html( $file ); ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Save button -->
    <div class="pcm-save-row">
        <button type="submit" name="submit" form="pcm-main-settings-form" class="pcm-btn-primary"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php echo esc_html__( 'Save Settings', 'pressable_cache_management' ); ?></button>
    </div>

    <!-- Chip JS -->

    <?php endif; ?>

    <?php elseif ( $tab === 'edge_cache_settings_tab' ) : ?>



    <!-- Page heading -->
    <div class="pcm-edge-heading-wrap">
        <h2 class="pcm-edge-heading">
            <?php echo esc_html__( 'Manage Edge Cache Settings', 'pressable_cache_management' ); ?>
        </h2>
    </div>

    <!-- Card -->
    <div class="pcm-edge-cache-card-wrap">
    <div class="pcm-card pcm-card-no-pad">

        <!-- Row 1: Turn On/Off -->
        <div class="pcm-ec-row">
            <div>
                <p class="pcm-ec-row-title">
                    <?php echo esc_html__( 'Turn On/Off Edge Cache', 'pressable_cache_management' ); ?>
                </p>
                <p class="pcm-ec-row-desc">
                    <?php echo esc_html__( 'Enable or disable the edge cache for this site.', 'pressable_cache_management' ); ?>
                </p>
            </div>
            <div id="edge-cache-control-wrapper" class="pcm-ec-control-wrap">
                <div class="edge-cache-loader"></div>
            </div>
        </div>

        <!-- Row 2: Purge + description + timestamps -->
        <div class="pcm-ec-purge-section">

            <!-- Purge title + button -->
            <div class="pcm-ec-purge-header">
                <p class="pcm-ec-row-title pcm-mb-0">
                    <?php echo esc_html__( 'Purge Edge Cache', 'pressable_cache_management' ); ?>
                </p>
                <form method="post" id="purge_edge_cache_nonce_form_static" class="pcm-ec-purge-form">
                    <?php settings_fields('edge_cache_settings_tab_options'); ?>
                    <input type="hidden" name="purge_edge_cache_nonce" value="<?php echo esc_attr( wp_create_nonce('purge_edge_cache_nonce') ); ?>">
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
            <p class="pcm-ec-purge-warning">
                <?php echo esc_html__( 'Purging cache will temporarily slow down your site for all visitors while the cache rebuilds.', 'pressable_cache_management' ); ?>
            </p>

            <!-- Timestamps -->
            <div class="pcm-ec-timestamps">

                <div>
                    <span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span>
                    <span class="pcm-ts-value pcm-ts-value-block"><?php
                        $v = get_option( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value );
                        echo $v ? esc_html($v) : '&mdash;';
                    ?></span>
                </div>

                <div>
                    <span class="pcm-ts-label"><?php echo esc_html__( 'SINGLE PAGE LAST FLUSHED', 'pressable_cache_management' ); ?></span>
                    <span class="pcm-ts-value pcm-ts-value-block"><?php
                        $v = get_option( PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP->value );
                        echo $v ? esc_html($v) : '&mdash;';
                    ?></span>
                </div>

                <div>
                    <span class="pcm-ts-label"><?php echo esc_html__( 'SINGLE PAGE URL', 'pressable_cache_management' ); ?></span>
                    <span class="pcm-ts-value pcm-ts-value-block-break"><?php
                        $v = get_option( PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED->value );
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
        settings_fields( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
        do_settings_sections('remove_pressable_branding_tab');
        echo '<button type="submit" class="pcm-btn-primary">' . esc_html__( 'Save Settings', 'pressable_cache_management' ) . '</button>'; 
        ?>
    </form>

    <?php endif; ?>

    </div><!-- /inner-center -->
    </div><!-- /wrap -->

    <?php
}

// ─── Footer ──────────────────────────────────────────────────────────────────
function pcm_footer_msg() {
    if ( 'not-exists' === get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value, 'not-exists') ) {
        add_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value, '');
        update_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value, array('branding_on_off_radio_button'=>'enable'));
    }
    add_filter('admin_footer_text','pcm_replace_default_footer');
}

function pcm_replace_default_footer($footer_text) {
    if ( is_admin() && isset($_GET['page']) && sanitize_key( $_GET['page'] ) === 'pressable_cache_management' ) {
        $opts              = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
        $branding_disabled = $opts && 'disable' === $opts['branding_on_off_radio_button'];

        if ( $branding_disabled ) {
            // Branding hidden: "Built with ♥" — heart links to branding settings page
            return 'Built with <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab" title="Show or Hide Plugin Branding" class="pcm-footer-heart"><span class="dashicons dashicons-heart pcm-footer-heart-icon-minimal" onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'"></span></a>';
        } else {
            // Branding shown: full credit — heart links to branding settings page
            return 'Built with <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab" title="Show or Hide Plugin Branding" class="pcm-footer-heart"><span class="dashicons dashicons-heart pcm-footer-heart-icon-branded" onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'"></span></a> by The Pressable CS Team.';
        }
    }
    return $footer_text;
}
add_action('admin_init','pcm_footer_msg');
