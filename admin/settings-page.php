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

    wp_enqueue_style( 'pressable_cache_management',
        plugin_dir_url( dirname( __FILE__ ) ) . 'public/css/style.css', array(), '3.0.0', 'screen' );
    wp_enqueue_style( 'pcm-google-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );
    ?>
    <div class="wrap pcm-wrap" style="background:#f0f2f5;margin-left:-20px;margin-right:-20px;padding:24px 28px 40px;min-height:calc(100vh - 32px);font-family:'Inter',sans-serif;">
    <div style="max-width:1120px;margin:0 auto;">
    <h1 style="display:none;"><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <script>
    window.pcmPost = window.pcmPost || function(bodyObj) {
        var params = new URLSearchParams();
        Object.keys(bodyObj || {}).forEach(function(key){
            var value = bodyObj[key];
            if (Array.isArray(value)) {
                value.forEach(function(item){ params.append(key + '[]', item); });
                return;
            }
            params.append(key, value);
        });
        return fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        }).then(function(response){
            if (!response.ok) {
                var error = new Error('http_' + response.status);
                error.status = response.status;
                throw error;
            }
            return response.json();
        });
    };

    window.pcmHandleError = window.pcmHandleError || function(context, error, targetEl) {
        var message = 'Unable to reach the server. Check your connection.';
        if (error && (error.status === 403 || /nonce|forbidden|permission/i.test(error.message || ''))) {
            message = "You don't have permission to perform this action. Your session may have expired. Please reload the page.";
        }
        if (targetEl) {
            targetEl.innerHTML = '<div class="pcm-inline-error"><strong>' + context + ':</strong> ' + message + '</div>';
        }
    };
    </script>

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
        $bc_class  = $bc_status === 'active' ? 'active' : 'broken';
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
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Feature Flags', 'pressable_cache_management' ); ?></button>
                <span style="color:#64748b;font-size:12px;"><?php echo esc_html__( 'Changes take effect immediately after save.', 'pressable_cache_management' ); ?></span>
            </div>
        </form>
    </div>
    <script>
    (function(){
        var toggle = document.getElementById('pcm-caching-suite-toggle');
        var nonceField = document.getElementById('pcm-caching-suite-toggle-nonce');
        if (!toggle || !nonceField || typeof window.pcmPost !== 'function') return;

        function pcmShowToast(message) {
            var existing = document.getElementById('pcm-caching-suite-toast');
            if (existing) {
                existing.remove();
            }
            var toast = document.createElement('div');
            toast.id = 'pcm-caching-suite-toast';
            toast.className = 'pcm-toast';
            toast.textContent = message;
            document.body.appendChild(toast);
            requestAnimationFrame(function(){
                toast.classList.add('is-visible');
            });
            window.setTimeout(function(){
                toast.classList.remove('is-visible');
                window.setTimeout(function(){
                    if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
                }, 250);
            }, 1900);
        }

        function pcmSyncCachingSuiteUi(enabled) {
            var badge = document.getElementById('pcm-caching-suite-status-badge');
            var inlineStatus = document.getElementById('pcm-caching-suite-inline-status');
            if (badge) {
                badge.textContent = enabled ? 'Active' : 'Inactive';
                badge.classList.toggle('is-active', !!enabled);
                badge.classList.toggle('is-inactive', !enabled);
            }
            if (inlineStatus) {
                inlineStatus.innerHTML = '<strong>Status:</strong> ' + (enabled ? 'Enabled' : 'Disabled');
            }

            var tabsNav = document.getElementById('pcm-main-tab-nav');
            var deepDiveTab = document.getElementById('pcm-deep-dive-tab');
            if (enabled) {
                if (!deepDiveTab && tabsNav) {
                    deepDiveTab = document.createElement('a');
                    deepDiveTab.id = 'pcm-deep-dive-tab';
                    deepDiveTab.className = 'nav-tab';
                    deepDiveTab.href = 'admin.php?page=pressable_cache_management&tab=deep_dive_tab';
                    deepDiveTab.textContent = 'Deep Dive';
                    var settingsTab = tabsNav.querySelector('a[href*="tab=settings_tab"]');
                    if (settingsTab) {
                        tabsNav.insertBefore(deepDiveTab, settingsTab);
                    } else {
                        tabsNav.appendChild(deepDiveTab);
                    }
                }
            } else if (deepDiveTab && deepDiveTab.parentNode) {
                deepDiveTab.parentNode.removeChild(deepDiveTab);
            }
        }

        toggle.addEventListener('change', function(){
            var enabled = toggle.checked;
            toggle.disabled = true;

            window.pcmPost({
                action: 'pcm_toggle_caching_suite_features',
                nonce: nonceField.value,
                enabled: enabled ? '1' : '0'
            }).then(function(res){
                if (!res || !res.success) {
                    throw new Error((res && res.data && res.data.message) ? res.data.message : 'toggle_failed');
                }
                pcmSyncCachingSuiteUi(!!res.data.enabled);
                pcmShowToast(res.data.label || (enabled ? 'Caching Suite enabled' : 'Caching Suite disabled'));
            }).catch(function(){
                toggle.checked = !enabled;
                pcmShowToast('Unable to save Caching Suite setting');
            }).finally(function(){
                toggle.disabled = false;
            });
        });
    })();
    </script>
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
        <script>
        var pcmBatcacheNonce = '<?php echo esc_js( wp_create_nonce('pcm_batcache_nonce') ); ?>';
        var pcmSiteUrl       = '<?php echo esc_js( trailingslashit( get_site_url() ) ); ?>';

        // WHY BROWSER-SIDE FETCH:
        // wp_remote_get() is a server-side loopback. Pressable routes loopbacks
        // directly to PHP, bypassing the Batcache/CDN layer, so x-nananana is
        // never returned regardless of real cache state.
        // The browser is the only client that sees the actual CDN response headers.
        // We fetch the homepage from JS, read x-nananana directly, then POST the
        // result to PHP which stores it in the transient.

        // Apply AJAX response to the badge DOM
        function pcmApplyStatus(res) {
            if (!res || !res.success) return null;
            var badge = document.getElementById('pcm-bc-badge');
            var label = document.getElementById('pcm-bc-label');
            if (!badge || !label) return null;
            label.textContent = res.data.label;
            ['active','broken','cloudflare'].forEach(function(cls) {
                badge.classList.remove(cls);
            });
            badge.classList.add(res.data.status === 'active' ? 'active' : 'broken');
            return res.data.status;
        }

        var pcmProbeInProgress = false;

        // Core: browser fetches homepage, reads header, reports to PHP.
        // cache:'reload' bypasses browser cache for a fresh CDN response.
        // Pragma: no-cache forces Pressable's Atomic Edge Cache to BYPASS (x-ac: BYPASS).
        function pcmProbeAndReport(onDone) {
            if (pcmProbeInProgress) {
                if (typeof onDone === 'function') onDone('busy');
                return;
            }
            pcmProbeInProgress = true;
            fetch(pcmSiteUrl, {
                method: 'GET',
                cache: 'reload',
                credentials: 'omit',
                redirect: 'follow',
                headers: { 'Pragma': 'no-cache' },
            })
            .then(function(resp) {
                var xNananana    = resp.headers.get('x-nananana') || '';
                var serverHdr    = resp.headers.get('server') || '';
                var cacheControl = resp.headers.get('cache-control') || '';
                var age          = resp.headers.get('age') || '';
                var isCloudflare = serverHdr.toLowerCase().indexOf('cloudflare') !== -1 ? '1' : '0';

                // Parse max-age and display as human readable
                var ttlHuman = '—';
                var maxAgeMatch = cacheControl.match(/max-age=(\d+)/i);
                if (maxAgeMatch) {
                    ttlHuman = pcmSecondsToHuman(parseInt(maxAgeMatch[1]));
                }
                var ttlEl = document.getElementById('pcm-ttl-value');
                if (ttlEl && ttlHuman !== '—') ttlEl.textContent = ttlHuman;

                var body = 'action=pcm_report_batcache_header'
                         + '&nonce='         + encodeURIComponent(pcmBatcacheNonce)
                         + '&x_nananana='    + encodeURIComponent(xNananana)
                         + '&is_cloudflare=' + isCloudflare;
                return fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                });
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var status = pcmApplyStatus(res);
                if (typeof onDone === 'function') onDone(status);
            })
            .catch(function() {
                if (typeof onDone === 'function') onDone(null);
            })
            .finally(function(){
                pcmProbeInProgress = false;
            });
        }

        function pcmSecondsToHuman(s) {
            s = parseInt(s);
            if (s <= 0) return '0 sec';
            if (s < 60) return s + ' sec';
            if (s < 3600) {
                var m = Math.floor(s / 60), sec = s % 60;
                return sec > 0 ? m + ' min ' + sec + ' sec' : m + ' min';
            }
            if (s < 86400) {
                var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
                return m > 0 ? h + ' hr ' + m + ' min' : h + ' hr';
            }
            var d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600);
            return h > 0 ? d + ' day' + (d !== 1 ? 's' : '') + ' ' + h + ' hr' : d + ' day' + (d !== 1 ? 's' : '');
        }

        // Manual refresh button
        function pcmRefreshBatcacheStatus() {
            var btn   = document.getElementById('pcm-bc-refresh');
            var label = document.getElementById('pcm-bc-label');
            if (pcmProbeInProgress) {
                label.textContent = '<?php echo esc_js(__('Already checking…', 'pressable_cache_management')); ?>';
                return;
            }
            btn.style.opacity = '0.3';
            btn.disabled = true;
            label.textContent = '<?php echo esc_js(__('Checking…', 'pressable_cache_management')); ?>';
            pcmProbeAndReport(function() {
                btn.style.opacity = '0.6';
                btn.disabled = false;
            });
        }

        // Auto-poll: re-probe every 60s while status is broken (up to 5 attempts max)
        var pcmPollTimer = null, pcmPollCount = 0, pcmPollMax = 5;
        function pcmStartRecoveryPoll() {
            clearInterval(pcmPollTimer);
            pcmPollCount = 0;
            pcmPollTimer = setInterval(function() {
                pcmPollCount++;
                if (pcmPollCount > pcmPollMax) { clearInterval(pcmPollTimer); return; }
                pcmProbeAndReport(function(status) {
                    if (status === 'active') {
                        clearInterval(pcmPollTimer);
                    }
                });
            }, 60000);
        }

        // Always fire one silent probe on page load to verify stored status.
        // If transient expired (unknown) → show Checking… then update to real result.
        // If stored active → silently confirms or corrects without waiting 24 hrs.
        // If stored broken → re-probes immediately, starts recovery poll if still broken.
        <?php if ( $bc_is_unknown ) : ?>
        document.getElementById('pcm-bc-label').textContent = '<?php echo esc_js( __( 'Checking…', 'pressable_cache_management' ) ); ?>';
        <?php endif; ?>
        pcmProbeAndReport(function(status) {
            if (status !== 'active') pcmStartRecoveryPoll();
        });
                // Tooltip show/hide
        (function() {
            var wrap = document.querySelector('.pcm-bc-tooltip-wrap');
            if (!wrap) return;
            var tip = wrap.querySelector('.pcm-bc-tooltip');
            wrap.addEventListener('mouseenter', function() { tip.style.display = 'block'; });
            wrap.addEventListener('mouseleave', function() { tip.style.display = 'none'; });
        })();
        </script>
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
            'value' => (string) get_option( 'pcm_latest_cacheability_score', '—' ),
            'target' => '#pcm-feature-cacheability-advisor',
            'status' => (float) get_option( 'pcm_latest_cacheability_score', 0 ) >= 80 ? 'good' : 'warn',
        ),
        array(
            'icon' => '🧹',
            'label' => __( 'Purge Activity', 'pressable_cache_management' ),
            'value' => (string) get_option( 'pcm_latest_purge_activity', '—' ),
            'target' => '#pcm-feature-smart-purge-strategy',
            'status' => is_numeric( get_option( 'pcm_latest_purge_activity', null ) )
                ? ( (float) get_option( 'pcm_latest_purge_activity', 0 ) > 50 ? 'bad' : ( (float) get_option( 'pcm_latest_purge_activity', 0 ) > 20 ? 'warn' : 'good' ) )
                : 'warn',
        ),
    );
    ?>
    <div class="pcm-summary-grid" style="margin-bottom:16px;">
        <?php foreach ( $summary_cards as $card ) : ?>
        <a class="pcm-card pcm-summary-card" href="<?php echo esc_attr( $card['target'] ); ?>">
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
    <div class="pcm-card" id="pcm-feature-cacheability-advisor" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">⚡ <?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0; color:#4b5563;"><?php echo esc_html__( 'Run a cacheability scan and review per-template scores, URL results, and findings.', 'pressable_cache_management' ); ?></p>
        <p>
            <button type="button" class="button button-primary" id="pcm-advisor-run-btn"><?php echo esc_html__( 'Rescan now', 'pressable_cache_management' ); ?></button>
            <span id="pcm-advisor-run-status" style="margin-left:10px;color:#374151;"></span>
        </p>
        <div class="pcm-advisor-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Template Scores', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-advisor-template-scores" style="font-size:13px;color:#111827;"></div>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Latest Findings', 'pressable_cache_management' ); ?></h4>
                <div id="pcm-advisor-findings" style="font-size:13px;color:#111827;max-height:220px;overflow:auto;"></div>
            </div>
        </div>
        <div style="margin-top:14px;">
            <h4 style="margin:8px 0;"><?php echo esc_html__( 'Route Diagnosis', 'pressable_cache_management' ); ?></h4>
            <div id="pcm-advisor-diagnosis" class="pcm-advisor-diagnosis" style="font-size:13px;color:#111827;border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#f9fafb;"><em><?php echo esc_html__( 'Select a route from findings to view diagnosis.', 'pressable_cache_management' ); ?></em></div>
        </div>
        <div id="pcm-advisor-playbook" style="margin-top:14px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;display:none;"></div>
    </div>
    <script>
    (function(){
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pcm_cacheability_scan' ) ); ?>;
        var runBtn = document.getElementById('pcm-advisor-run-btn');
        var runStatus = document.getElementById('pcm-advisor-run-status');
        var scoreWrap = document.getElementById('pcm-advisor-template-scores');
        var findingsWrap = document.getElementById('pcm-advisor-findings');
        var diagnosisWrap = document.getElementById('pcm-advisor-diagnosis');
        var playbookWrap = document.getElementById('pcm-advisor-playbook');
        var currentRunId = 0;
        var post = window.pcmPost;

        function renderScores(results) {
            if (!Array.isArray(results) || !results.length) {
                scoreWrap.innerHTML = '<em>No results available yet.</em>';
                return;
            }

            var agg = {};
            results.forEach(function(row){
                var type = row.template_type || 'unknown';
                var score = Number(row.score || 0);
                if (!agg[type]) agg[type] = { total: 0, count: 0 };
                agg[type].total += score;
                agg[type].count += 1;
            });

            var html = '<div class="pcm-score-list">';
            Object.keys(agg).sort().forEach(function(type){
                var avg = Math.round(agg[type].total / Math.max(1, agg[type].count));
                var scoreClass = avg > 80 ? 'is-good' : (avg >= 50 ? 'is-warn' : 'is-bad');
                html += '<div class="pcm-score-item">'
                    + '<div class="pcm-score-meta"><strong>' + escapeHtml(type) + '</strong><span>' + agg[type].count + ' URLs</span></div>'
                    + '<div class="pcm-score-bar"><span class="pcm-score-fill ' + scoreClass + '" style="width:' + Math.max(0, Math.min(100, avg)) + '%;"></span><span class="pcm-score-value">' + avg + '/100</span></div>'
                    + '</div>';
            });
            html += '</div>';

            html += '<div style="margin-top:8px;font-size:12px;color:#6b7280;">Sampled routes:</div><ul style="margin:4px 0 0;padding-left:18px;max-height:120px;overflow:auto;">';
            results.slice(0, 20).forEach(function(row){
                var routeUrl = row.url || '';
                html += '<li><button type="button" class="button-link" style="padding:0;height:auto;line-height:1.4;" data-action="open-diagnosis" data-url="' + escapeHtml(routeUrl) + '">' + escapeHtml(routeUrl) + '</button></li>';
            });
            html += '</ul>';
            scoreWrap.innerHTML = html;
        }

        function chips(items) {
            if (!Array.isArray(items) || !items.length) return '<em>None</em>';
            return items.map(function(item){
                var reason = item && item.reason ? item.reason : (item && item.label ? item.label : 'signal');
                var evidence = item && item.evidence ? (' <span style="color:#6b7280;">(' + escapeHtml(typeof item.evidence === 'string' ? item.evidence : JSON.stringify(item.evidence)) + ')</span>') : '';
                return '<span class="pcm-advisor-chip">' + escapeHtml(reason) + evidence + '</span>';
            }).join('');
        }

        function formatTimingValue(value) {
            if (value === null || typeof value === 'undefined' || value === '') return null;
            var num = Number(value);
            if (!isFinite(num)) return null;
            return num;
        }

        function renderTimingGrid(timing) {
            var entries = [
                { label: 'Total', key: 'total_time', value: formatTimingValue(timing.total_time) },
                { label: 'DNS', key: 'namelookup_time', value: formatTimingValue(timing.namelookup_time) },
                { label: 'Connect', key: 'connect_time', value: formatTimingValue(timing.connect_time) },
                { label: 'TTFB', key: 'starttransfer_time', value: formatTimingValue(timing.starttransfer_time) }
            ];
            var max = entries.reduce(function(acc, item){ return item.value !== null ? Math.max(acc, item.value) : acc; }, 0);
            if (max <= 0) {
                return '<em>Timing unavailable.</em>';
            }
            return entries.map(function(item){
                var percent = item.value === null ? 0 : Math.round((item.value / max) * 100);
                var valText = item.value === null ? 'n/a' : item.value.toFixed(3) + 's';
                return '<div class="pcm-timing-row"><span>' + escapeHtml(item.label) + '</span><div class="pcm-timing-track"><span style="width:' + percent + '%;"></span></div><strong>' + escapeHtml(valText) + '</strong></div>';
            }).join('');
        }

        function severityBadge(severity) {
            var normalized = String(severity || 'info').toLowerCase();
            var map = { critical: 'is-critical', warning: 'is-warning', info: 'is-info' };
            var cls = map[normalized] || 'is-info';
            return '<span class="pcm-severity-badge ' + cls + '">' + escapeHtml(normalized) + '</span>';
        }

        function renderDiagnosis(payload) {
            var diagnosis = payload && payload.diagnosis ? payload.diagnosis : {};
            var data = diagnosis.diagnosis || {};
            var trace = data.decision_trace || {};
            var probe = data.probe || {};
            var poisoning = Array.isArray(trace.poisoning_signals) ? trace.poisoning_signals : [];

            var topCookieSignals = poisoning.filter(function(p){ return p.type === 'cookie'; }).slice(0, 5);
            var topHeaderSignals = poisoning.filter(function(p){ return p.type === 'header'; }).slice(0, 5);

            diagnosisWrap.innerHTML = [
                '<div class="pcm-diagnosis-grid">',
                    '<div class="pcm-diagnosis-card"><dt>URL</dt><dd>' + escapeHtml(diagnosis.url || payload.url || '') + '</dd></div>',
                    '<div class="pcm-diagnosis-card"><dt>Final URL</dt><dd>' + escapeHtml(probe.effective_url || diagnosis.url || '') + '</dd></div>',
                    '<div class="pcm-diagnosis-card"><dt>Redirect chain</dt><dd>' + escapeHtml((probe.redirect_chain || []).join(' → ') || 'None') + '</dd></div>',
                    '<div class="pcm-diagnosis-card"><dt>Response size</dt><dd>' + escapeHtml(String(probe.response_size || 0)) + ' bytes</dd></div>',
                '</div>',
                '<div class="pcm-diagnosis-section"><strong>Why bypassed (Edge)</strong><div>' + chips(trace.edge_bypass_reasons || []) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Why bypassed (Batcache)</strong><div>' + chips(trace.batcache_bypass_reasons || []) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Poisoning cookies</strong><div>' + chips(topCookieSignals.map(function(p){ return { reason: p.key, evidence: p.evidence }; })) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Poisoning headers</strong><div>' + chips(topHeaderSignals.map(function(p){ return { reason: p.key, evidence: p.evidence }; })) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Route risk badges</strong><div>' + chips(trace.route_risk_labels || []) + '</div></div>',
                '<div class="pcm-diagnosis-section"><strong>Timing</strong><div class="pcm-timing-grid">' + renderTimingGrid(probe.timing || {}) + '</div></div>'
            ].join('');
        }

        function loadRouteDiagnosis(runId, url) {
            if (!runId || !url) return Promise.resolve();
            diagnosisWrap.innerHTML = '<em>Loading route diagnosis…</em>';
            return post({ action: 'pcm_cacheability_route_diagnosis', nonce: nonce, run_id: String(runId), url: url })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data) {
                        throw new Error('Unable to load diagnosis');
                    }
                    renderDiagnosis(payload.data);
                })
                .catch(function(){
                    diagnosisWrap.innerHTML = '<em>Unable to load route diagnosis for selected URL.</em>';
                });
        }

        function escapeHtml(input) {
            return String(input || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderPlaybook(playbook, ruleId, progress) {
            if (!playbook || !playbook.meta || !playbook.meta.playbook_id) {
                playbookWrap.style.display = 'none';
                playbookWrap.innerHTML = '';
                return;
            }

            var checklist = (progress && progress.checklist) ? progress.checklist : {};
            var verification = (progress && progress.verification) ? progress.verification : {};
            var checkedOne = checklist.step_1 ? 'checked' : '';
            var checkedTwo = checklist.step_2 ? 'checked' : '';
            var checkedThree = checklist.verify ? 'checked' : '';
            var verificationSummary = verification.status ? (verification.status + ' (' + (verification.checked_at || 'n/a') + ')') : 'Not run yet';

            playbookWrap.style.display = 'block';
            playbookWrap.innerHTML = [
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">',
                    '<h4 style="margin:0;">Playbook: ' + escapeHtml(playbook.meta.title || playbook.meta.playbook_id) + '</h4>',
                    '<button type="button" class="button button-small" data-action="close-playbook">Close</button>',
                '</div>',
                '<p style="margin:6px 0 8px;color:#4b5563;"><strong>Severity:</strong> ' + escapeHtml(playbook.meta.severity || 'warning') + '</p>',
                '<div class="pcm-playbook-body" style="font-size:13px;line-height:1.5;">' + (playbook.html_body || '') + '</div>',
                '<hr/>',
                '<div>',
                    '<label><input type="checkbox" data-check="step_1" ' + checkedOne + '> Step 1 complete</label><br>',
                    '<label><input type="checkbox" data-check="step_2" ' + checkedTwo + '> Step 2 complete</label><br>',
                    '<label><input type="checkbox" data-check="verify" ' + checkedThree + '> Verification complete</label>',
                '</div>',
                '<p style="margin-top:10px;display:flex;gap:8px;align-items:center;">',
                    '<button type="button" class="button" data-action="save-progress" data-playbook-id="' + escapeHtml(playbook.meta.playbook_id) + '">Save progress</button>',
                    '<button type="button" class="button button-secondary" data-action="verify" data-playbook-id="' + escapeHtml(playbook.meta.playbook_id) + '" data-rule-id="' + escapeHtml(ruleId) + '">Run post-fix verification</button>',
                    '<span data-role="verify-status" style="color:#374151;">Last verification: ' + escapeHtml(verificationSummary) + '</span>',
                '</p>'
            ].join('');
        }

        function renderFindings(findings) {
            if (!Array.isArray(findings) || !findings.length) {
                findingsWrap.innerHTML = '<em>No findings on latest run.</em>';
                playbookWrap.style.display = 'none';
                playbookWrap.innerHTML = '';
                return;
            }

            var grouped = {};
            findings.forEach(function(row){
                var rule = row.rule_id || 'unknown_rule';
                var sev = row.severity || 'warning';
                var key = rule + '|' + sev;
                if (!grouped[key]) {
                    grouped[key] = {
                        rule: rule,
                        severity: sev,
                        urls: [],
                        playbook: row.playbook_lookup || {}
                    };
                }
                if (row.url) {
                    grouped[key].urls.push(row.url);
                }
                if (!grouped[key].playbook.available && row.playbook_lookup && row.playbook_lookup.available) {
                    grouped[key].playbook = row.playbook_lookup;
                }
            });

            var html = '<div class="pcm-findings-list">';
            Object.keys(grouped).slice(0, 25).forEach(function(key){
                var group = grouped[key];
                var uniqueUrls = [];
                var seen = {};
                group.urls.forEach(function(url){
                    if (!seen[url]) {
                        seen[url] = true;
                        uniqueUrls.push(url);
                    }
                });

                html += '<div class="pcm-finding-item">';
                html += '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;"><div><strong>' + escapeHtml(group.rule) + '</strong></div>' + severityBadge(group.severity) + '</div>';
                if (uniqueUrls.length) {
                    html += '<div class="pcm-finding-urls">';
                    uniqueUrls.forEach(function(url){
                        html += '<div><button type="button" class="button-link" style="padding:0;height:auto;line-height:1.4;font-size:12px;" data-action="open-diagnosis" data-url="' + escapeHtml(url) + '">' + escapeHtml(url) + '</button></div>';
                    });
                    html += '</div>';
                }
                if (group.playbook.available) {
                    html += '<button type="button" class="button button-small" data-action="open-playbook" data-rule-id="' + escapeHtml(group.rule) + '">Open playbook</button>';
                }
                html += '</div>';
            });
            html += '</div>';
            findingsWrap.innerHTML = html;
        }


        function renderSensitivity(payload) {
            var topRoutes = payload && payload.data && Array.isArray(payload.data.top_routes) ? payload.data.top_routes : [];
            var summary = payload && payload.data && payload.data.summary ? payload.data.summary : {};

            if (!topRoutes.length) {
                sensitivityWrap.innerHTML = '<em>No route sensitivity data yet.</em>';
                return;
            }

            var html = '';
            html += '<p style="margin:0 0 8px;color:#4b5563;">High-sensitivity routes: 24h=' + Number(summary.high_24h || 0) + ', 7d=' + Number(summary.high_7d || 0) + '</p>';
            html += '<ul style="margin:0;padding-left:18px;">';
            topRoutes.forEach(function(row){
                var metrics = row.metrics || {};
                var reasons = Array.isArray(metrics.reasons) ? metrics.reasons.join(', ') : '';
                html += '<li><strong>' + escapeHtml(row.route || row.url || 'unknown') + '</strong> '
                    + '<span style="text-transform:uppercase;font-size:11px;border:1px solid #d1d5db;padding:1px 4px;border-radius:4px;">' + escapeHtml(row.memcache_sensitivity || 'low') + '</span>'
                    + ' — score ' + Number(metrics.score || 0)
                    + ', hit ' + (metrics.hit_ratio === null || typeof metrics.hit_ratio === 'undefined' ? 'n/a' : Number(metrics.hit_ratio).toFixed(2) + '%')
                    + ', evictions ' + (metrics.evictions === null || typeof metrics.evictions === 'undefined' ? 'n/a' : Number(metrics.evictions))
                    + (reasons ? '<br><span style="font-size:12px;color:#6b7280;">Signals: ' + escapeHtml(reasons) + '</span>' : '')
                    + '</li>';
            });
            html += '</ul>';
            sensitivityWrap.innerHTML = html;
        }

        function loadRunDetails(runId) {
            return Promise.all([
                post({ action: 'pcm_cacheability_scan_results', nonce: nonce, run_id: String(runId) }),
                post({ action: 'pcm_cacheability_scan_findings', nonce: nonce, run_id: String(runId) }),
                post({ action: 'pcm_route_memcache_sensitivity', nonce: nonce, run_id: String(runId) })
            ]).then(function(payloads){
                var resultsPayload = payloads[0];
                var findingsPayload = payloads[1];
                var sensitivityPayload = payloads[2];
                renderScores(resultsPayload && resultsPayload.success ? resultsPayload.data.results : []);
                renderFindings(findingsPayload && findingsPayload.success ? findingsPayload.data.findings : []);
                var firstResult = (resultsPayload && resultsPayload.success && resultsPayload.data && Array.isArray(resultsPayload.data.results)) ? resultsPayload.data.results[0] : null;
                if (firstResult && firstResult.url) {
                    return loadRouteDiagnosis(runId, firstResult.url);
                }
            });
        }

        function loadLatestRun() {
            return post({ action: 'pcm_cacheability_scan_status', nonce: nonce }).then(function(payload){
                if (!payload || !payload.success || !payload.data || !payload.data.run || !payload.data.run.id) {
                    runStatus.textContent = 'No scan runs found yet.';
                    renderScores([]);
                    renderFindings([]);
                    return;
                }

                var run = payload.data.run;
                currentRunId = Number(run.id || 0);
                runStatus.textContent = 'Latest run #' + run.id + ' — ' + (run.status || 'unknown');
                return loadRunDetails(run.id);
            });
        }

        runBtn.addEventListener('click', function(){
            runBtn.disabled = true;
            runStatus.textContent = 'Running scan…';
            post({ action: 'pcm_cacheability_scan_start', nonce: nonce })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data || !payload.data.run_id) {
                        throw new Error('Unable to start run');
                    }
                    runStatus.textContent = 'Scan completed for run #' + payload.data.run_id + '.';
                    return loadRunDetails(payload.data.run_id);
                })
                .catch(function(){
                    runStatus.textContent = 'Unable to run scan. Check permissions and feature flags.';
                })
                .finally(function(){
                    runBtn.disabled = false;
                });
        });

        findingsWrap.addEventListener('click', function(event){
            var diagnosisTrigger = event.target.closest('[data-action="open-diagnosis"]');
            if (diagnosisTrigger) {
                var diagnosisUrl = diagnosisTrigger.getAttribute('data-url') || '';
                if (diagnosisUrl) {
                    loadRouteDiagnosis(currentRunId, diagnosisUrl);
                }
                return;
            }

            var trigger = event.target.closest('[data-action="open-playbook"]');
            if (!trigger) return;
            var ruleId = trigger.getAttribute('data-rule-id') || '';
            if (!ruleId) return;

            post({ action: 'pcm_playbook_lookup', nonce: nonce, rule_id: ruleId })
                .then(function(payload){
                    if (!payload || !payload.success || !payload.data || !payload.data.available) {
                        throw new Error('Playbook unavailable');
                    }
                    renderPlaybook(payload.data.playbook, ruleId, payload.data.progress || {});
                })
                .catch(function(){
                    runStatus.textContent = 'Unable to load playbook.';
                });
        });

        playbookWrap.addEventListener('click', function(event){
            var trigger = event.target.closest('[data-action]');
            if (!trigger) return;
            var action = trigger.getAttribute('data-action');

            if (action === 'close-playbook') {
                playbookWrap.style.display = 'none';
                return;
            }

            if (action === 'save-progress') {
                var playbookId = trigger.getAttribute('data-playbook-id') || '';
                if (!playbookId) return;
                var checklist = {};
                playbookWrap.querySelectorAll('input[data-check]').forEach(function(box){
                    checklist[box.getAttribute('data-check')] = !!box.checked;
                });

                post({
                    action: 'pcm_playbook_progress_save',
                    nonce: nonce,
                    playbook_id: playbookId,
                    checklist: JSON.stringify(checklist)
                }).then(function(){
                    runStatus.textContent = 'Playbook progress saved.';
                }).catch(function(){
                    runStatus.textContent = 'Unable to save playbook progress.';
                });
                return;
            }

            if (action === 'verify') {
                var pbId = trigger.getAttribute('data-playbook-id') || '';
                var ruleId = trigger.getAttribute('data-rule-id') || '';
                if (!pbId || !ruleId) return;

                var statusEl = playbookWrap.querySelector('[data-role="verify-status"]');
                if (statusEl) statusEl.textContent = 'Verification running…';

                post({
                    action: 'pcm_playbook_verify',
                    nonce: nonce,
                    playbook_id: pbId,
                    rule_id: ruleId
                }).then(function(payload){
                    if (!payload || !payload.success || !payload.data) {
                        throw new Error('Verification failed');
                    }
                    if (statusEl) {
                        statusEl.textContent = 'Last verification: ' + (payload.data.status || 'unknown') + ' (run #' + (payload.data.run_id || 'n/a') + ')';
                    }
                    runStatus.textContent = payload.data.message || 'Verification complete.';
                }).catch(function(){
                    if (statusEl) statusEl.textContent = 'Verification failed.';
                    runStatus.textContent = 'Unable to run post-fix verification.';
                });
            }
        });

        scoreWrap.addEventListener('click', function(event){
            var trigger = event.target.closest('[data-action="open-diagnosis"]');
            if (!trigger) return;
            var url = trigger.getAttribute('data-url') || '';
            if (!url) return;
            loadRouteDiagnosis(currentRunId, url);
        });

        loadLatestRun();
    })();
    </script>
    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_object_cache_intelligence_is_enabled' ) && pcm_object_cache_intelligence_is_enabled() ) : ?>
    <div class="pcm-card" id="pcm-feature-object-cache-intelligence" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">🧠 <?php echo esc_html__( 'Object Cache Intelligence', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Inspect object cache health, hit ratio, evictions, and memory pressure trends.', 'pressable_cache_management' ); ?></p>
        <p style="margin:0 0 10px;color:#6b7280;font-size:12px;"><?php echo esc_html__( 'Data source: we first read the active object-cache drop-in stats (global $wp_object_cache), then fall back to PHP Memcached extension stats when available. Evictions can show n/a when the provider does not expose that metric; memory pressure can show 0% when memory limit bytes are unavailable.', 'pressable_cache_management' ); ?></p>
        <p>
            <button type="button" class="button" id="pcm-oci-refresh-btn"><?php echo esc_html__( 'Refresh diagnostics', 'pressable_cache_management' ); ?></button>
            <span id="pcm-oci-summary" style="margin-left:10px;color:#374151;"></span>
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
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
    <script>
    (function(){
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pcm_cacheability_scan' ) ); ?>;
        var refreshBtn = document.getElementById('pcm-oci-refresh-btn');
        var summaryEl = document.getElementById('pcm-oci-summary');
        var latestEl = document.getElementById('pcm-oci-latest');
        var trendEl = document.getElementById('pcm-oci-trends');
        var post = window.pcmPost;

        function renderLatest(snapshot) {
            if (!snapshot || !snapshot.taken_at) {
                latestEl.innerHTML = '<em>No snapshot data yet.</em>';
                summaryEl.textContent = 'No diagnostics snapshot available.';
                return;
            }

            summaryEl.textContent = 'Health: ' + (snapshot.health || 'unknown') + ' | Provider: ' + (snapshot.provider || 'n/a');
            var evictionsText = (snapshot.evictions == null ? 'n/a' : snapshot.evictions);
            var memoryText = (snapshot.memory_pressure == null ? 'n/a' : snapshot.memory_pressure + '%');
            var memoryNote = '';
            if (snapshot.memory_pressure === 0 && (!snapshot.bytes_limit || Number(snapshot.bytes_limit) <= 0)) {
                memoryNote = ' <span style="color:#6b7280;">(provider did not report memory limit bytes)</span>';
            }
            var evictionNote = snapshot.evictions == null
                ? ' <span style="color:#6b7280;">(provider did not report eviction counters)</span>'
                : '';

            latestEl.innerHTML = [
                '<ul style="margin:0;padding-left:18px;">',
                '<li><strong>Status</strong>: ' + (snapshot.status || 'unknown') + '</li>',
                '<li><strong>Hit Ratio</strong>: ' + (snapshot.hit_ratio == null ? 'n/a' : snapshot.hit_ratio + '%') + '</li>',
                '<li><strong>Evictions</strong>: ' + evictionsText + evictionNote + '</li>',
                '<li><strong>Memory Pressure</strong>: ' + memoryText + memoryNote + '</li>',
                '<li><strong>Captured</strong>: ' + snapshot.taken_at + '</li>',
                '</ul>'
            ].join('');
        }

        function toPoints(points, key) {
            return points.map(function(point){
                var value = Number(point[key]);
                return Number.isFinite(value) ? value : null;
            });
        }

        function lineChartSvg(values, labels, threshold, opts) {
            opts = opts || {};
            var width = 520;
            var height = 140;
            var pad = { top: 12, right: 12, bottom: 20, left: 28 };
            var valid = values.filter(function(value){ return value != null; });
            var maxVal = valid.length ? Math.max.apply(null, valid.concat([threshold || 0])) : 100;
            maxVal = Math.max(maxVal, opts.minMax || 100);
            var innerW = width - pad.left - pad.right;
            var innerH = height - pad.top - pad.bottom;
            var safeLength = Math.max(values.length - 1, 1);
            function xAt(index) { return pad.left + ((innerW * index) / safeLength); }
            function yAt(value) { return pad.top + innerH - ((Math.max(value, 0) / maxVal) * innerH); }

            var linePath = '';
            var areaPath = '';
            var started = false;
            values.forEach(function(value, index){
                if (value == null) {
                    if (started) {
                        areaPath += ' L ' + xAt(index - 1).toFixed(2) + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                        started = false;
                    }
                    return;
                }
                var x = xAt(index).toFixed(2);
                var y = yAt(value).toFixed(2);
                if (!started) {
                    linePath += (linePath ? ' M ' : 'M ') + x + ' ' + y;
                    areaPath += (areaPath ? ' M ' : 'M ') + x + ' ' + (pad.top + innerH).toFixed(2) + ' L ' + x + ' ' + y;
                    started = true;
                } else {
                    linePath += ' L ' + x + ' ' + y;
                    areaPath += ' L ' + x + ' ' + y;
                }
                if (index === values.length - 1) {
                    areaPath += ' L ' + x + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                }
            });

            var thresholdY = threshold != null ? yAt(threshold).toFixed(2) : null;
            var xTicks = labels.map(function(label, index){
                if (index === 0 || index === labels.length - 1 || index === Math.floor(labels.length / 2)) {
                    return '<text x="' + xAt(index).toFixed(2) + '" y="' + (height - 4) + '" text-anchor="middle" fill="#64748b" font-size="10">' + label + '</text>';
                }
                return '';
            }).join('');

            return [
                '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + (opts.label || 'Trend chart') + '">',
                '<line x1="' + pad.left + '" y1="' + (pad.top + innerH) + '" x2="' + (width - pad.right) + '" y2="' + (pad.top + innerH) + '" stroke="#e2e8f0" stroke-width="1"/>',
                thresholdY ? '<line x1="' + pad.left + '" y1="' + thresholdY + '" x2="' + (width - pad.right) + '" y2="' + thresholdY + '" stroke="#dd3a03" stroke-width="1" stroke-dasharray="4 4"/>' : '',
                areaPath ? '<path d="' + areaPath + '" fill="rgba(3,252,194,0.18)"/>' : '',
                linePath ? '<path d="' + linePath + '" fill="none" stroke="' + (opts.color || '#03fcc2') + '" stroke-width="2"/>' : '',
                xTicks,
                '</svg>'
            ].join('');
        }

        function renderTrends(points) {
            if (!Array.isArray(points) || !points.length) {
                trendEl.innerHTML = '<em>No trend points yet.</em>';
                return;
            }

            var rows = points.slice(-20);
            var labels = rows.map(function(point){ return (point.taken_at || '').slice(5, 10); });
            var hitValues = toPoints(rows, 'hit_ratio');
            var evictionValues = toPoints(rows, 'evictions');
            var memoryValues = toPoints(rows, 'memory_pressure');

            var html = '<div class="pcm-trend-charts">'
                + '<div class="pcm-trend-chart"><h5>Hit Ratio % <span>threshold 70%</span></h5>' + lineChartSvg(hitValues, labels, 70, { label: 'Object cache hit ratio trend', minMax: 100, color: '#03fcc2' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Evictions <span>watch for spikes</span></h5>' + lineChartSvg(evictionValues, labels, null, { label: 'Object cache evictions trend', minMax: 10, color: '#dd3a03' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Memory Pressure % <span>threshold 90%</span></h5>' + lineChartSvg(memoryValues, labels, 90, { label: 'Object cache memory pressure trend', minMax: 100, color: '#dd3a03' }) + '</div>'
                + '</div>';

            html += '<details class="pcm-trend-details"><summary>Details table</summary>';
            html += '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Date</th><th>Hit %</th><th>Evictions</th><th>Mem %</th></tr></thead><tbody>';
            rows.forEach(function(point){
                html += '<tr>'
                    + '<td>' + (point.taken_at || '') + '</td>'
                    + '<td>' + (point.hit_ratio == null ? 'n/a' : point.hit_ratio) + '</td>'
                    + '<td>' + (point.evictions == null ? 'n/a' : point.evictions) + '</td>'
                    + '<td>' + (point.memory_pressure == null ? 'n/a' : point.memory_pressure) + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></details>';
            trendEl.innerHTML = html;
        }

        function loadSnapshot(refresh) {
            return post({ action: 'pcm_object_cache_snapshot', nonce: nonce, refresh: refresh ? '1' : '0' })
                .then(function(payload){
                    renderLatest(payload && payload.success ? payload.data.snapshot : null);
                });
        }

        function loadTrends() {
            return post({ action: 'pcm_object_cache_trends', nonce: nonce, range: '7d' })
                .then(function(payload){
                    renderTrends(payload && payload.success ? payload.data.points : []);
                });
        }

        refreshBtn.addEventListener('click', function(){
            refreshBtn.disabled = true;
            summaryEl.textContent = 'Refreshing…';
            Promise.all([loadSnapshot(true), loadTrends()])
                .catch(function(){ summaryEl.textContent = 'Unable to refresh object cache diagnostics.'; })
                .finally(function(){ refreshBtn.disabled = false; });
        });

        Promise.all([loadSnapshot(false), loadTrends()]).catch(function(){
            summaryEl.textContent = 'Unable to load object cache diagnostics.';
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_opcache_awareness_is_enabled' ) && pcm_opcache_awareness_is_enabled() ) : ?>
    <div class="pcm-card" id="pcm-feature-opcache-awareness" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">📦 <?php echo esc_html__( 'PHP OPcache Awareness', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Review OPcache memory pressure, restart patterns, and recommendations.', 'pressable_cache_management' ); ?></p>
        <p>
            <button type="button" class="button" id="pcm-opcache-refresh-btn"><?php echo esc_html__( 'Refresh OPcache', 'pressable_cache_management' ); ?></button>
            <span id="pcm-opcache-summary" style="margin-left:10px;color:#374151;"></span>
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
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
    <script>
    (function(){
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pcm_cacheability_scan' ) ); ?>;
        var refreshBtn = document.getElementById('pcm-opcache-refresh-btn');
        var summaryEl = document.getElementById('pcm-opcache-summary');
        var latestEl = document.getElementById('pcm-opcache-latest');
        var trendEl = document.getElementById('pcm-opcache-trends');
        var post = window.pcmPost;

        function renderLatest(snapshot) {
            if (!snapshot || !snapshot.taken_at) {
                latestEl.innerHTML = '<em>No OPcache snapshot data yet.</em>';
                summaryEl.textContent = 'No OPcache diagnostics available.';
                return false;
            }

            if (!snapshot.enabled) {
                summaryEl.textContent = 'Health: ' + (snapshot.health || 'unknown') + ' | Enabled: no';
                latestEl.innerHTML = '<em>OPcache is disabled on this runtime. Snapshot details and trend history are hidden until OPcache is enabled.</em>';
                trendEl.innerHTML = '<em>OPcache is disabled, so trend history is unavailable.</em>';
                return false;
            }

            var mem = snapshot.memory || {};
            var stats = snapshot.statistics || {};

            summaryEl.textContent = 'Health: ' + (snapshot.health || 'unknown') + ' | Enabled: yes';
            latestEl.innerHTML = [
                '<ul style="margin:0;padding-left:18px;">',
                '<li><strong>Health</strong>: ' + (snapshot.health || 'unknown') + '</li>',
                '<li><strong>Hit Rate</strong>: ' + (stats.opcache_hit_rate == null ? 'n/a' : stats.opcache_hit_rate + '%') + '</li>',
                '<li><strong>Memory Pressure</strong>: ' + ((Number(mem.used_memory || 0) + Number(mem.wasted_memory || 0) + Number(mem.free_memory || 0)) > 0 ? Math.round(((Number(mem.used_memory || 0) + Number(mem.wasted_memory || 0)) / (Number(mem.used_memory || 0) + Number(mem.wasted_memory || 0) + Number(mem.free_memory || 0))) * 10000) / 100 : 0) + '%</li>',
                '<li><strong>Restart Total</strong>: ' + (stats.restart_total == null ? 'n/a' : stats.restart_total) + '</li>',
                '<li><strong>Captured</strong>: ' + snapshot.taken_at + '</li>',
                '</ul>'
            ].join('');
            return true;
        }

        function toPoints(points, key) {
            return points.map(function(point){
                var value = Number(point[key]);
                return Number.isFinite(value) ? value : null;
            });
        }

        function lineChartSvg(values, labels, threshold, opts) {
            opts = opts || {};
            var width = 520;
            var height = 140;
            var pad = { top: 12, right: 12, bottom: 20, left: 28 };
            var valid = values.filter(function(value){ return value != null; });
            var maxVal = valid.length ? Math.max.apply(null, valid.concat([threshold || 0])) : 100;
            maxVal = Math.max(maxVal, opts.minMax || 100);
            var innerW = width - pad.left - pad.right;
            var innerH = height - pad.top - pad.bottom;
            var safeLength = Math.max(values.length - 1, 1);
            function xAt(index) { return pad.left + ((innerW * index) / safeLength); }
            function yAt(value) { return pad.top + innerH - ((Math.max(value, 0) / maxVal) * innerH); }

            var linePath = '';
            var areaPath = '';
            var started = false;
            values.forEach(function(value, index){
                if (value == null) {
                    if (started) {
                        areaPath += ' L ' + xAt(index - 1).toFixed(2) + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                        started = false;
                    }
                    return;
                }
                var x = xAt(index).toFixed(2);
                var y = yAt(value).toFixed(2);
                if (!started) {
                    linePath += (linePath ? ' M ' : 'M ') + x + ' ' + y;
                    areaPath += (areaPath ? ' M ' : 'M ') + x + ' ' + (pad.top + innerH).toFixed(2) + ' L ' + x + ' ' + y;
                    started = true;
                } else {
                    linePath += ' L ' + x + ' ' + y;
                    areaPath += ' L ' + x + ' ' + y;
                }
                if (index === values.length - 1) {
                    areaPath += ' L ' + x + ' ' + (pad.top + innerH).toFixed(2) + ' Z';
                }
            });

            var thresholdY = threshold != null ? yAt(threshold).toFixed(2) : null;
            var xTicks = labels.map(function(label, index){
                if (index === 0 || index === labels.length - 1 || index === Math.floor(labels.length / 2)) {
                    return '<text x="' + xAt(index).toFixed(2) + '" y="' + (height - 4) + '" text-anchor="middle" fill="#64748b" font-size="10">' + label + '</text>';
                }
                return '';
            }).join('');

            return [
                '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + (opts.label || 'Trend chart') + '">',
                '<line x1="' + pad.left + '" y1="' + (pad.top + innerH) + '" x2="' + (width - pad.right) + '" y2="' + (pad.top + innerH) + '" stroke="#e2e8f0" stroke-width="1"/>',
                thresholdY ? '<line x1="' + pad.left + '" y1="' + thresholdY + '" x2="' + (width - pad.right) + '" y2="' + thresholdY + '" stroke="#dd3a03" stroke-width="1" stroke-dasharray="4 4"/>' : '',
                areaPath ? '<path d="' + areaPath + '" fill="rgba(3,252,194,0.18)"/>' : '',
                linePath ? '<path d="' + linePath + '" fill="none" stroke="' + (opts.color || '#03fcc2') + '" stroke-width="2"/>' : '',
                xTicks,
                '</svg>'
            ].join('');
        }

        function renderTrends(points) {
            if (!Array.isArray(points) || !points.length) {
                trendEl.innerHTML = '<em>No OPcache trend points yet.</em>';
                return;
            }

            var rows = points.slice(-20);
            var labels = rows.map(function(point){ return (point.taken_at || '').slice(5, 10); });
            var hitValues = toPoints(rows, 'hit_rate');
            var restartValues = toPoints(rows, 'restart_total');
            var memoryValues = toPoints(rows, 'memory_pressure');

            var html = '<div class="pcm-trend-charts">'
                + '<div class="pcm-trend-chart"><h5>Hit Rate % <span>threshold 70%</span></h5>' + lineChartSvg(hitValues, labels, 70, { label: 'OPcache hit rate trend', minMax: 100, color: '#03fcc2' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Restarts <span>watch for spikes</span></h5>' + lineChartSvg(restartValues, labels, null, { label: 'OPcache restart trend', minMax: 5, color: '#dd3a03' }) + '</div>'
                + '<div class="pcm-trend-chart"><h5>Memory Pressure % <span>threshold 90%</span></h5>' + lineChartSvg(memoryValues, labels, 90, { label: 'OPcache memory pressure trend', minMax: 100, color: '#dd3a03' }) + '</div>'
                + '</div>';

            html += '<details class="pcm-trend-details"><summary>Details table</summary>';
            html += '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Date</th><th>Mem %</th><th>Restarts</th><th>Hit %</th><th>Health</th></tr></thead><tbody>';
            rows.forEach(function(point){
                html += '<tr>'
                    + '<td>' + (point.taken_at || '') + '</td>'
                    + '<td>' + (point.memory_pressure == null ? 'n/a' : point.memory_pressure) + '</td>'
                    + '<td>' + (point.restart_total == null ? 'n/a' : point.restart_total) + '</td>'
                    + '<td>' + (point.hit_rate == null ? 'n/a' : point.hit_rate) + '</td>'
                    + '<td>' + (point.health || 'unknown') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table></details>';
            trendEl.innerHTML = html;
        }

        function loadSnapshot(refresh) {
            return post({ action: 'pcm_opcache_snapshot', nonce: nonce, refresh: refresh ? '1' : '0' })
                .then(function(payload){
                    return renderLatest(payload && payload.success ? payload.data.snapshot : null);
                });
        }

        function loadTrends() {
            return post({ action: 'pcm_opcache_trends', nonce: nonce, range: '7d' })
                .then(function(payload){
                    renderTrends(payload && payload.success ? payload.data.points : []);
                });
        }

        refreshBtn.addEventListener('click', function(){
            refreshBtn.disabled = true;
            summaryEl.textContent = 'Refreshing OPcache…';
            loadSnapshot(true)
                .then(function(enabled){
                    if (enabled) {
                        return loadTrends();
                    }
                    return null;
                })
                .catch(function(){ summaryEl.textContent = 'Unable to refresh OPcache diagnostics.'; })
                .finally(function(){ refreshBtn.disabled = false; });
        });

        loadSnapshot(false)
            .then(function(enabled){
                if (enabled) {
                    return loadTrends();
                }
                return null;
            })
            .catch(function(){
                summaryEl.textContent = 'Unable to load OPcache diagnostics.';
            });
    })();
    </script>
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

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Discover + Edit Rules', 'pressable_cache_management' ); ?></h4>
                <textarea id="pcm-ra-urls" rows="4" style="width:100%;" placeholder="https://example.com/Page?utm_source=x
https://example.com/page/"></textarea>
                <p>
                    <button type="button" class="button" id="pcm-ra-discover"><?php echo esc_html__( 'Discover Candidates', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="button" id="pcm-ra-load-rules"><?php echo esc_html__( 'Load Saved Rules', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-rule-editor" style="border:1px solid #e2e8f0;border-radius:6px;padding:10px;background:#f8fafc;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
                        <strong style="font-size:12px;"><?php echo esc_html__( 'Rule Builder', 'pressable_cache_management' ); ?></strong>
                        <button type="button" class="button" id="pcm-ra-add-rule"><?php echo esc_html__( 'Add Rule', 'pressable_cache_management' ); ?></button>
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
                    <button type="button" class="button-link" id="pcm-ra-toggle-advanced" style="padding:0;height:auto;"><?php echo esc_html__( 'Show Advanced JSON', 'pressable_cache_management' ); ?></button>
                </p>
                <textarea id="pcm-ra-rules-json" rows="10" style="width:100%;font-family:monospace;display:none;" placeholder='[ {"enabled":true,"match_type":"exact","source_pattern":"/old","target_pattern":"https://example.com/new"} ]'></textarea>
                <p>
                    <label><input type="checkbox" id="pcm-ra-confirm-wildcards" /> <?php echo esc_html__( 'I confirm wildcard/regex rules have been reviewed.', 'pressable_cache_management' ); ?></label>
                </p>
                <p>
                    <button type="button" class="button button-primary" id="pcm-ra-save"><?php echo esc_html__( 'Save Rules', 'pressable_cache_management' ); ?></button>
                </p>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Dry Run + Export / Import', 'pressable_cache_management' ); ?></h4>
                <textarea id="pcm-ra-sim-urls" rows="4" style="width:100%;" placeholder="https://example.com/old
https://example.com/OLD/"></textarea>
                <p>
                    <button type="button" class="button" id="pcm-ra-simulate"><?php echo esc_html__( 'Dry-run Simulation', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="button" id="pcm-ra-export"><?php echo esc_html__( 'Build Export', 'pressable_cache_management' ); ?></button>
                </p>
                <textarea id="pcm-ra-export-content" rows="8" style="width:100%;font-family:monospace;" placeholder="Exported custom-redirects.php content / JSON meta payload"></textarea>
                <p>
                    <button type="button" class="button" id="pcm-ra-copy"><?php echo esc_html__( 'Copy Export', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="button" id="pcm-ra-download"><?php echo esc_html__( 'Download custom-redirects.php', 'pressable_cache_management' ); ?></button>
                    <button type="button" class="button" id="pcm-ra-import"><?php echo esc_html__( 'Import JSON Payload', 'pressable_cache_management' ); ?></button>
                </p>
                <div id="pcm-ra-output" style="font-size:12px;color:#111827;max-height:220px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;padding:8px;border-radius:6px;"></div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pcm_cacheability_scan' ) ); ?>;
        var out = document.getElementById('pcm-ra-output');
        var rulesBox = document.getElementById('pcm-ra-rules-json');
        var exportBox = document.getElementById('pcm-ra-export-content');
        var post = window.pcmPost;
        var rulesBody = document.getElementById('pcm-ra-rules-body');
        var ruleErrors = document.getElementById('pcm-ra-rule-errors');
        var toggleAdvancedBtn = document.getElementById('pcm-ra-toggle-advanced');
        var advancedVisible = false;
        var ruleState = [];

        function escapeHtml(value) {
            var str = String(value == null ? '' : value);
            return str.replace(/[&<>"']/g, function(char){
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char] || char;
            });
        }

        function defaultRule() {
            return {
                id: 'ui_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
                source_pattern: '',
                target_pattern: '',
                match_type: 'exact',
                status_code: 301,
                enabled: true
            };
        }

        function normalizeRule(rule) {
            var matchType = rule && typeof rule.match_type === 'string' ? rule.match_type : 'exact';
            if (matchType === 'prefix') {
                matchType = 'wildcard';
            }
            if (['exact', 'wildcard', 'regex'].indexOf(matchType) === -1) {
                matchType = 'exact';
            }
            var statusCode = parseInt(rule && rule.status_code, 10);
            if ([301, 302, 307].indexOf(statusCode) === -1) {
                statusCode = 301;
            }
            return {
                id: rule && rule.id ? String(rule.id) : 'ui_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
                source_pattern: (rule && rule.source_pattern ? String(rule.source_pattern) : '').trim(),
                target_pattern: rule && rule.target_pattern ? String(rule.target_pattern) : '',
                match_type: matchType,
                status_code: statusCode,
                enabled: !(rule && rule.enabled === false)
            };
        }

        function convertRuleForJson(rule) {
            return {
                id: rule.id,
                enabled: !!rule.enabled,
                match_type: rule.match_type === 'wildcard' ? 'prefix' : rule.match_type,
                source_pattern: rule.source_pattern,
                target_pattern: rule.target_pattern,
                status_code: parseInt(rule.status_code, 10) || 301
            };
        }

        function parseRulesFromJson(jsonRaw) {
            var parsed;
            try {
                parsed = JSON.parse(jsonRaw || '[]');
            } catch (e) {
                return { rules: [], parseError: 'Invalid JSON in advanced editor.' };
            }
            if (!Array.isArray(parsed)) {
                return { rules: [], parseError: 'Rules JSON must be an array.' };
            }
            return { rules: parsed.map(normalizeRule), parseError: '' };
        }

        function validateRuleState() {
            var errors = [];
            var seen = {};
            var duplicates = {};

            ruleState.forEach(function(rule){
                var key = rule.source_pattern.trim().toLowerCase();
                if (!key) {
                    return;
                }
                if (seen[key]) {
                    duplicates[key] = true;
                }
                seen[key] = true;
            });

            ruleState.forEach(function(rule){
                var issues = [];
                var key = rule.source_pattern.trim().toLowerCase();

                if (!rule.source_pattern.trim()) {
                    issues.push('Source is required.');
                }
                if (key && duplicates[key]) {
                    issues.push('Duplicate source pattern.');
                }
                if (rule.match_type === 'regex' && rule.source_pattern.trim()) {
                    try {
                        new RegExp(rule.source_pattern);
                    } catch (e) {
                        issues.push('Invalid regex pattern.');
                    }
                }

                if (issues.length) {
                    errors.push({ id: rule.id, messages: issues });
                }
            });

            return errors;
        }

        function syncJsonFromState() {
            rulesBox.value = JSON.stringify(ruleState.map(convertRuleForJson), null, 2);
        }

        function renderRules() {
            var errorMap = {};
            var validationErrors = validateRuleState();
            validationErrors.forEach(function(entry){ errorMap[entry.id] = entry.messages; });

            rulesBody.innerHTML = ruleState.map(function(rule){
                var invalid = (errorMap[rule.id] || []).length > 0;
                var invalidStyle = invalid ? 'border:1px solid #dc2626;background:#fef2f2;' : 'border:1px solid #cbd5e1;';
                return '<tr data-rule-id="' + escapeHtml(rule.id) + '">' +
                    '<td style="padding:6px;vertical-align:top;"><input data-field="source_pattern" type="text" value="' + escapeHtml(rule.source_pattern) + '" style="width:160px;' + invalidStyle + '"></td>' +
                    '<td style="padding:6px;vertical-align:top;"><input data-field="target_pattern" type="text" value="' + escapeHtml(rule.target_pattern) + '" style="width:180px;border:1px solid #cbd5e1;"></td>' +
                    '<td style="padding:6px;vertical-align:top;"><select data-field="match_type" style="width:95px;border:1px solid #cbd5e1;">' +
                        '<option value="exact"' + (rule.match_type === 'exact' ? ' selected' : '') + '>exact</option>' +
                        '<option value="wildcard"' + (rule.match_type === 'wildcard' ? ' selected' : '') + '>wildcard</option>' +
                        '<option value="regex"' + (rule.match_type === 'regex' ? ' selected' : '') + '>regex</option>' +
                    '</select></td>' +
                    '<td style="padding:6px;vertical-align:top;"><select data-field="status_code" style="width:80px;border:1px solid #cbd5e1;">' +
                        '<option value="301"' + (parseInt(rule.status_code, 10) === 301 ? ' selected' : '') + '>301</option>' +
                        '<option value="302"' + (parseInt(rule.status_code, 10) === 302 ? ' selected' : '') + '>302</option>' +
                        '<option value="307"' + (parseInt(rule.status_code, 10) === 307 ? ' selected' : '') + '>307</option>' +
                    '</select></td>' +
                    '<td style="padding:6px;vertical-align:top;"><input data-field="enabled" type="checkbox"' + (rule.enabled ? ' checked' : '') + '></td>' +
                    '<td style="padding:6px;vertical-align:top;"><button type="button" class="button-link-delete" data-delete-rule="1">Delete</button></td>' +
                '</tr>';
            }).join('');

            if (!ruleState.length) {
                rulesBody.innerHTML = '<tr><td colspan="6" style="padding:8px;color:#64748b;">No rules yet. Click Add Rule.</td></tr>';
            }

            ruleErrors.innerHTML = validationErrors.map(function(item){
                return '• ' + escapeHtml(item.messages.join(' '));
            }).join('<br>');

            syncJsonFromState();
        }

        function setRulesFromJson(raw, fallbackDefault) {
            var parsed = parseRulesFromJson(raw);
            if (parsed.parseError) {
                ruleErrors.textContent = parsed.parseError;
                if (fallbackDefault && !ruleState.length) {
                    ruleState = [defaultRule()];
                    renderRules();
                }
                return;
            }
            ruleState = parsed.rules.length ? parsed.rules : (fallbackDefault ? [defaultRule()] : []);
            renderRules();
        }

        function getPathname(url) {
            try {
                return new URL(url, window.location.origin).pathname || '/';
            } catch (e) {
                return String(url || '/');
            }
        }

        function findMatchedRule(inputUrl) {
            var path = getPathname(inputUrl);
            for (var i = 0; i < ruleState.length; i++) {
                var rule = ruleState[i];
                if (!rule.enabled) {
                    continue;
                }
                if (rule.match_type === 'exact' && path === rule.source_pattern) {
                    return rule;
                }
                if (rule.match_type === 'wildcard' && path.indexOf(rule.source_pattern) === 0) {
                    return rule;
                }
                if (rule.match_type === 'regex') {
                    try {
                        if ((new RegExp(rule.source_pattern)).test(path)) {
                            return rule;
                        }
                    } catch (e) {}
                }
            }
            return null;
        }

        function renderDryRunTable(res) {
            var results = (res && res.data && Array.isArray(res.data.results)) ? res.data.results : [];
            if (!results.length) {
                out.innerHTML = '<em>No dry-run results.</em>';
                return;
            }

            var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                '<thead><tr>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Request URL</th>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Matched Rule</th>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Redirect Target</th>' +
                '<th style="text-align:left;padding:6px;border-bottom:1px solid #e2e8f0;">Status Code</th>' +
                '</tr></thead><tbody>';

            results.forEach(function(item){
                var matchedRule = findMatchedRule(item.input_url || '');
                html += '<tr>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(item.input_url || '') + '</td>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(matchedRule ? matchedRule.source_pattern : 'No match') + '</td>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(item.result_url || (matchedRule ? matchedRule.target_pattern : '')) + '</td>' +
                    '<td style="padding:6px;border-bottom:1px solid #f1f5f9;">' + escapeHtml(matchedRule ? String(matchedRule.status_code || 301) : '-') + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            out.innerHTML = html;
        }

        function render(obj) {
            out.textContent = JSON.stringify(obj || {}, null, 2);
        }

        toggleAdvancedBtn.addEventListener('click', function(){
            advancedVisible = !advancedVisible;
            rulesBox.style.display = advancedVisible ? 'block' : 'none';
            toggleAdvancedBtn.textContent = advancedVisible ? 'Hide Advanced JSON' : 'Show Advanced JSON';
            if (!advancedVisible) {
                setRulesFromJson(rulesBox.value, true);
            }
        });

        document.getElementById('pcm-ra-add-rule').addEventListener('click', function(){
            ruleState.push(defaultRule());
            renderRules();
        });

        rulesBody.addEventListener('click', function(event){
            var row = event.target.closest('tr[data-rule-id]');
            if (!row || !event.target.closest('[data-delete-rule="1"]')) {
                return;
            }
            var id = row.getAttribute('data-rule-id');
            ruleState = ruleState.filter(function(rule){ return rule.id !== id; });
            renderRules();
        });

        rulesBody.addEventListener('input', function(event){
            var row = event.target.closest('tr[data-rule-id]');
            var field = event.target.getAttribute('data-field');
            if (!row || !field) {
                return;
            }
            var id = row.getAttribute('data-rule-id');
            var currentRule = ruleState.find(function(rule){ return rule.id === id; });
            if (!currentRule) {
                return;
            }
            currentRule[field] = field === 'enabled' ? !!event.target.checked : event.target.value;
            if (field === 'status_code') {
                currentRule[field] = parseInt(event.target.value, 10) || 301;
            }
            renderRules();
        });

        rulesBox.addEventListener('input', function(){
            if (advancedVisible) {
                setRulesFromJson(rulesBox.value, false);
            }
        });

        document.getElementById('pcm-ra-discover').addEventListener('click', function(){
            post({ action: 'pcm_redirect_assistant_discover_candidates', nonce: nonce, urls: document.getElementById('pcm-ra-urls').value })
                .then(function(res){
                    if (res && res.success && res.data && Array.isArray(res.data.candidates)) {
                        rulesBox.value = JSON.stringify(res.data.candidates, null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                    render(res);
                })
                .catch(function(){ render({ error: 'discover_failed' }); });
        });

        document.getElementById('pcm-ra-load-rules').addEventListener('click', function(){
            post({ action: 'pcm_redirect_assistant_list_rules', nonce: nonce })
                .then(function(res){
                    if (res && res.success && res.data) {
                        rulesBox.value = JSON.stringify(res.data.rules || [], null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                    render(res);
                })
                .catch(function(){ render({ error: 'load_rules_failed' }); });
        });

        document.getElementById('pcm-ra-save').addEventListener('click', function(){
            if (validateRuleState().length) {
                ruleErrors.textContent = 'Fix validation errors before saving.';
                return;
            }
            syncJsonFromState();
            post({ action: 'pcm_redirect_assistant_save_rules', nonce: nonce, rules: rulesBox.value, confirm_wildcards: document.getElementById('pcm-ra-confirm-wildcards').checked ? '1' : '0' })
                .then(render)
                .catch(function(){ render({ error: 'save_failed' }); });
        });

        document.getElementById('pcm-ra-simulate').addEventListener('click', function(){
            syncJsonFromState();
            post({ action: 'pcm_redirect_assistant_simulate', nonce: nonce, urls: document.getElementById('pcm-ra-sim-urls').value, rules: rulesBox.value })
                .then(function(res){
                    renderDryRunTable(res);
                })
                .catch(function(){ render({ error: 'simulate_failed' }); });
        });

        document.getElementById('pcm-ra-export').addEventListener('click', function(){
            syncJsonFromState();
            post({ action: 'pcm_redirect_assistant_export', nonce: nonce, confirm_wildcards: document.getElementById('pcm-ra-confirm-wildcards').checked ? '1' : '0' })
                .then(function(res){
                    if (res && res.success && res.data && res.data.export) {
                        var content = (res.data.export.content || "") + "\n\n/* JSON PAYLOAD FOR IMPORT */\n" + (res.data.meta_json || "");
                        exportBox.value = content;
                    }
                    render(res);
                })
                .catch(function(){ render({ error: 'export_failed' }); });
        });

        document.getElementById('pcm-ra-copy').addEventListener('click', function(){
            var txt = exportBox.value || '';
            navigator.clipboard.writeText(txt).then(function(){ render({ copied: true }); }).catch(function(){ render({ copied: false }); });
        });

        document.getElementById('pcm-ra-download').addEventListener('click', function(){
            var content = exportBox.value || '';
            var idx = content.indexOf('/* JSON PAYLOAD FOR IMPORT */');
            if (idx > -1) {
                content = content.substring(0, idx).trim() + "\n";
            }
            var blob = new Blob([content], {type: 'text/x-php'});
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'custom-redirects.php';
            document.body.appendChild(a);
            a.click();
            a.remove();
        });

        document.getElementById('pcm-ra-import').addEventListener('click', function(){
            var raw = exportBox.value || '';
            var marker = '/* JSON PAYLOAD FOR IMPORT */';
            var payload = raw.indexOf(marker) > -1 ? raw.substring(raw.indexOf(marker) + marker.length).trim() : raw.trim();
            post({ action: 'pcm_redirect_assistant_import', nonce: nonce, payload: payload })
                .then(function(res){
                    render(res);
                    if (res && res.success) {
                        return post({ action: 'pcm_redirect_assistant_list_rules', nonce: nonce });
                    }
                })
                .then(function(res){
                    if (res && res.success && res.data) {
                        rulesBox.value = JSON.stringify(res.data.rules || [], null, 2);
                        setRulesFromJson(rulesBox.value, true);
                    }
                })
                .catch(function(){ render({ error: 'import_failed' }); });
        });

        setRulesFromJson(rulesBox.value, true);
    })();
    </script>
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
            <button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Smart Purge Settings', 'pressable_cache_management' ); ?></button>
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
    <script>
    (function(){
        var nav = document.getElementById('pcm-deep-dive-nav');
        if (!nav || !window.IntersectionObserver) return;
        var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
        var map = {};
        links.forEach(function(link){
            var id = (link.getAttribute('href') || '').replace('#', '');
            var node = document.getElementById(id);
            if (node) map[id] = link;
        });
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                var id = entry.target.getAttribute('id');
                if (!map[id]) return;
                if (entry.isIntersecting) {
                    links.forEach(function(a){ a.classList.remove('is-active'); });
                    map[id].classList.add('is-active');
                }
            });
        }, { rootMargin: '-25% 0px -60% 0px', threshold: 0.01 });
        Object.keys(map).forEach(function(id){ io.observe(document.getElementById(id)); });
    })();
    </script>
    <?php endif; ?>

    <?php if ( $is_deep_dive_tab && function_exists( 'pcm_microcache_render_deep_dive_card' ) ) : ?>
        <?php pcm_microcache_render_deep_dive_card(); ?>
    <?php endif; ?>

    <?php if ( $is_settings_tab ) : ?>
    <div class="pcm-card" id="pcm-feature-observability-reporting" style="margin-bottom:20px;scroll-margin-top:20px;">
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
            <button type="button" class="button" id="pcm-report-load"><?php echo esc_html__( 'Load Trends', 'pressable_cache_management' ); ?></button>
            <button type="button" class="button" id="pcm-report-export-json"><?php echo esc_html__( 'Export JSON', 'pressable_cache_management' ); ?></button>
            <button type="button" class="button" id="pcm-report-export-csv"><?php echo esc_html__( 'Export CSV', 'pressable_cache_management' ); ?></button>
        </p>
        <div id="pcm-report-output" style="max-height:260px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:6px;font-size:12px;"></div>
    </div>
    <script>
    (function(){
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pcm_cacheability_scan' ) ); ?>;
        var out = document.getElementById('pcm-report-output');
        var rangeEl = document.getElementById('pcm-report-range');

        var post = window.pcmPost;

        function metricName(key){
            var map = {
                cacheability_score: 'Cacheability Score',
                cache_buster_incidence: 'Cache Buster Incidence',
                purge_frequency_by_scope: 'Purge Frequency (By Scope)',
                object_cache_hit_ratio: 'Object Cache Hit Ratio',
                object_cache_evictions: 'Object Cache Evictions',
                opcache_memory_pressure: 'OPcache Memory Pressure',
                opcache_restarts: 'OPcache Restarts',
                batcache_hits: 'Batcache Hits',
                high_memcache_sensitivity_routes_24h: 'High Memcache Sensitivity Routes (24h)',
                high_memcache_sensitivity_routes_7d: 'High Memcache Sensitivity Routes (7d)'
            };
            if (map[key]) {
                return map[key];
            }

            return (key || '').replace(/_/g, ' ').replace(/\b\w/g, function(char){ return char.toUpperCase(); });
        }

        function formatValue(value){
            var num = Number(value);
            if (!Number.isFinite(num)) {
                return '—';
            }

            return num.toLocaleString(undefined, { maximumFractionDigits: 2 });
        }

        function buildSummaryRows(rows){
            var byMetric = {};

            rows.forEach(function(row){
                var metric = row && row.metric_key ? row.metric_key : '';
                if (!metric) {
                    return;
                }

                if (!byMetric[metric]) {
                    byMetric[metric] = [];
                }

                byMetric[metric].push({
                    value: Number(row.value),
                    bucketStart: row.bucket_start || ''
                });
            });

            return Object.keys(byMetric).map(function(metric){
                var entries = byMetric[metric].filter(function(entry){ return Number.isFinite(entry.value); });

                entries.sort(function(a, b){
                    return new Date(a.bucketStart).getTime() - new Date(b.bucketStart).getTime();
                });

                var first = entries.length ? entries[0].value : NaN;
                var latest = entries.length ? entries[entries.length - 1].value : NaN;
                var delta = Number.isFinite(first) && Number.isFinite(latest) ? latest - first : NaN;

                return {
                    metric: metric,
                    currentValue: latest,
                    delta: delta
                };
            }).sort(function(a, b){
                return metricName(a.metric).localeCompare(metricName(b.metric));
            });
        }

        function render(obj){
            var rows = (((obj||{}).data||{}).rows)||[];
            if (!rows.length) { out.innerHTML = '<em>No trend rows available.</em>'; return; }
            var summaryRows = buildSummaryRows(rows);

            if (!summaryRows.length) {
                out.innerHTML = '<em>No trend rows available.</em>';
                return;
            }

            var html = '<table class="widefat striped"><thead><tr><th>Metric</th><th>Current Value</th><th>7-day Trend</th></tr></thead><tbody>';
            summaryRows.forEach(function(row){
                var hasDelta = Number.isFinite(row.delta);
                var arrow = hasDelta ? (row.delta >= 0 ? '↑' : '↓') : '→';
                var deltaText = hasDelta ? Math.abs(row.delta).toLocaleString(undefined, { maximumFractionDigits: 2 }) : 'n/a';
                html += '<tr><td>' + metricName(row.metric) + '</td><td>' + formatValue(row.currentValue) + '</td><td>' + arrow + ' ' + deltaText + '</td></tr>';
            });
            html += '</tbody></table>';
            out.innerHTML = html;
        }

        function downloadText(filename, text, mime){
            var blob = new Blob([text], { type: mime || 'text/plain' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        document.getElementById('pcm-report-load').addEventListener('click', function(){
            post({
                action: 'pcm_reporting_trends',
                nonce: nonce,
                range: rangeEl.value,
                metric_keys: ['cacheability_score','cache_buster_incidence','object_cache_hit_ratio','object_cache_evictions','opcache_memory_pressure','opcache_restarts','purge_frequency_by_scope','high_memcache_sensitivity_routes_24h','high_memcache_sensitivity_routes_7d']
            }).then(render).catch(function(error){ window.pcmHandleError('Load Trends', error, out); });
        });

        function doExport(format){
            post({
                action: 'pcm_reporting_export',
                nonce: nonce,
                format: format,
                range: rangeEl.value,
                metric_keys: ['cacheability_score','cache_buster_incidence','object_cache_hit_ratio','object_cache_evictions','opcache_memory_pressure','opcache_restarts','purge_frequency_by_scope','high_memcache_sensitivity_routes_24h','high_memcache_sensitivity_routes_7d']
            }).then(function(res){
                if (res && res.success && res.data && res.data.content) {
                    var ext = format === 'csv' ? 'csv' : 'json';
                    var mime = format === 'csv' ? 'text/csv' : 'application/json';
                    var fname = 'pcm-report-' + rangeEl.value + '.' + ext;
                    downloadText(fname, res.data.content, mime);
                    out.innerHTML = '<div class="pcm-inline-success" style="font-size:13px;">✅ Downloaded ' + fname + '</div>';
                }
            }).catch(function(error){ window.pcmHandleError('Export ' + format.toUpperCase(), error, out); });
        }

        document.getElementById('pcm-report-export-json').addEventListener('click', function(){ doExport('json'); });
        document.getElementById('pcm-report-export-csv').addEventListener('click', function(){ doExport('csv'); });

        document.getElementById('pcm-report-load').click();
    })();
    </script>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( $is_settings_tab ) : ?>
    <div class="pcm-card" id="pcm-feature-security-privacy" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">🔐 <?php echo esc_html__( 'Privacy & Security', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Configure retention and redaction policy, then review audit log history for privileged actions.', 'pressable_cache_management' ); ?></p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Privacy Settings', 'pressable_cache_management' ); ?></h4>
                <p><label><?php echo esc_html__( 'Retention Days', 'pressable_cache_management' ); ?> <input type="number" id="pcm-privacy-retention" min="7" max="365" value="<?php echo esc_attr( isset( $privacy_settings['retention_days'] ) ? (int) $privacy_settings['retention_days'] : 90 ); ?>" /></label></p>
                <p><label><?php echo esc_html__( 'Redaction Level', 'pressable_cache_management' ); ?>
                    <select id="pcm-privacy-redaction"><option value="minimal" <?php selected( isset( $privacy_settings['redaction_level'] ) ? $privacy_settings['redaction_level'] : "standard", "minimal" ); ?>>minimal</option><option value="standard" <?php selected( isset( $privacy_settings['redaction_level'] ) ? $privacy_settings['redaction_level'] : "standard", "standard" ); ?>>standard</option><option value="strict" <?php selected( isset( $privacy_settings['redaction_level'] ) ? $privacy_settings['redaction_level'] : "standard", "strict" ); ?>>strict</option></select>
                </label></p>
                <p><label><input type="checkbox" id="pcm-privacy-advanced-scan" <?php checked( ! empty( $privacy_settings['advanced_scan_opt_in'] ) ); ?> /> <?php echo esc_html__( 'Allow advanced scanning workflows', 'pressable_cache_management' ); ?></label></p>
                <p><label><input type="checkbox" id="pcm-privacy-audit-enabled" <?php checked( ! empty( $privacy_settings['audit_log_enabled'] ) ); ?> /> <?php echo esc_html__( 'Enable audit logging', 'pressable_cache_management' ); ?></label></p>
                <p><button type="button" class="button pcm-primary-btn" id="pcm-privacy-save"><?php echo esc_html__( 'Save Privacy Settings', 'pressable_cache_management' ); ?></button>
                <span id="pcm-privacy-status" style="margin-left:8px;color:#374151;"></span></p>
            </div>
            <div>
                <h4 style="margin:8px 0;"><?php echo esc_html__( 'Audit Log', 'pressable_cache_management' ); ?></h4>
                <p><button type="button" class="button" id="pcm-audit-refresh"><?php echo esc_html__( 'Refresh Audit Log', 'pressable_cache_management' ); ?></button></p>
                <div id="pcm-audit-log" class="pcm-audit-panel pcm-skeleton" aria-live="polite"></div>
                <p style="margin-top:8px;"><button type="button" class="button" id="pcm-audit-load-more" style="display:none;"><?php echo esc_html__( 'Load More', 'pressable_cache_management' ); ?></button></p>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'pcm_cacheability_scan' ) ); ?>;
        var initialSettings = <?php echo wp_json_encode( $privacy_settings ); ?> || {};
        var retentionEl = document.getElementById('pcm-privacy-retention');
        var redactionEl = document.getElementById('pcm-privacy-redaction');
        var advancedEl = document.getElementById('pcm-privacy-advanced-scan');
        var auditEnabledEl = document.getElementById('pcm-privacy-audit-enabled');
        var statusEl = document.getElementById('pcm-privacy-status');
        var auditLogEl = document.getElementById('pcm-audit-log');
        var loadMoreEl = document.getElementById('pcm-audit-load-more');

        var post = window.pcmPost;
        var pageSize = 20;
        var currentOffset = 0;
        var allRows = [];

        function renderSettings(s) {
            retentionEl.value = s.retention_days || 90;
            redactionEl.value = s.redaction_level || 'standard';
            advancedEl.checked = !!s.advanced_scan_opt_in;
            auditEnabledEl.checked = !!s.audit_log_enabled;
        }

        function escHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderAuditRows(rows) {
            if (!rows.length) {
                auditLogEl.classList.remove('pcm-skeleton');
                auditLogEl.innerHTML = '<em>No audit entries yet.</em>';
                return;
            }

            var html = '<table class="widefat striped pcm-audit-table"><thead><tr><th>#</th><th>Action</th><th>User</th><th>Timestamp</th></tr></thead><tbody>';
            rows.forEach(function(row){
                html += '<tr>' +
                    '<td>' + escHtml(row.sequence_id || '?') + '</td>' +
                    '<td>' + escHtml(row.action || 'action') + '</td>' +
                    '<td>' + escHtml(row.actor_display || 'System') + '</td>' +
                    '<td>' + escHtml(row.created_at || 'n/a') + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            auditLogEl.classList.remove('pcm-skeleton');
            auditLogEl.innerHTML = html;
        }

        function loadAudit(reset){
            if (reset) {
                currentOffset = 0;
                allRows = [];
                auditLogEl.classList.add('pcm-skeleton');
                loadMoreEl.style.display = 'none';
            }

            return post({ action: 'pcm_audit_log_list', nonce: nonce, limit: pageSize, offset: currentOffset }).then(function(res){
                if (!res || !res.success || !res.data || !Array.isArray(res.data.rows)) throw new Error('audit_failed');
                allRows = allRows.concat(res.data.rows);
                currentOffset += res.data.rows.length;
                renderAuditRows(allRows);
                loadMoreEl.style.display = res.data.has_more ? 'inline-block' : 'none';
            }).catch(function(){
                auditLogEl.classList.remove('pcm-skeleton');
                auditLogEl.innerHTML = '<em>Unable to load audit log.</em>';
            });
        }

        document.getElementById('pcm-privacy-save').addEventListener('click', function(){
            statusEl.textContent = 'Saving…';
            post({
                action: 'pcm_privacy_settings_save',
                nonce: nonce,
                settings: JSON.stringify({
                    retention_days: retentionEl.value,
                    redaction_level: redactionEl.value,
                    advanced_scan_opt_in: advancedEl.checked,
                    audit_log_enabled: auditEnabledEl.checked,
                    export_restrictions: 'admin_only'
                })
            }).then(function(res){
                statusEl.textContent = (res && res.success) ? 'Saved.' : 'Save failed.';
                loadAudit(true);
            }).catch(function(){
                statusEl.textContent = 'Save failed.';
            });
        });

        document.getElementById('pcm-audit-refresh').addEventListener('click', function(){ loadAudit(true); });
        loadMoreEl.addEventListener('click', function(){ loadAudit(false); });

        renderSettings(initialSettings);
        loadAudit(true);
    })();
    </script>
    <?php endif; ?>

    <?php endif; ?>

    <?php if ( $is_object_tab ) : ?>

    <!-- ── 2-column grid ── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- LEFT -->
        <div style="display:flex;flex-direction:column;gap:20px;">

            <!-- Global Controls -->
            <div class="pcm-card">
                <h3 class="pcm-card-title">&#8635; <?php echo esc_html__( 'Global Controls', 'pressable_cache_management' ); ?></h3>
                <p style="font-size:13px;font-weight:600;color:#374151;margin:0 0 10px;"><?php echo esc_html__( 'Flush Object Cache', 'pressable_cache_management' ); ?></p>
                <form method="post">
                    <input type="hidden" name="flush_object_cache_nonce" value="<?php echo wp_create_nonce('flush_object_cache_nonce'); ?>">
                    <input type="submit" value="<?php esc_attr_e('Flush Cache for all Pages','pressable_cache_management'); ?>"
                           class="flushcache" id="pcm-flush-btn">
                </form>
                <?php $ts = get_option('flush-obj-cache-time-stamp'); ?>
                <div style="margin-top:12px;">
                    <span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span><br>
                    <span class="pcm-ts-value"><?php echo $ts ? esc_html($ts) : '—'; ?></span>
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
        <?php submit_button( __( '&#10003;&nbsp; Save Settings', 'pressable_cache_management' ), 'custom-class', 'submit', false, array('form'=>'pcm-main-settings-form') ); ?>
    </div>

    <!-- Chip JS -->
    <script>
    (function(){
        var wrap   = document.getElementById('pcm-chips-wrap');
        var input  = document.getElementById('pcm-exempt-input');
        var hidden = document.getElementById('pcm-exempt-hidden');
        if (!wrap || !input || !hidden) return;

        function getVals(){ return hidden.value ? hidden.value.split(',').map(s=>s.trim()).filter(Boolean) : []; }
        function syncHidden(v){ hidden.value = v.join(', '); }

        function addChip(val){
            val = val.trim(); if (!val) return;
            var vals = getVals();
            if (vals.indexOf(val) !== -1) return;
            vals.push(val); syncHidden(vals); renderChip(val);
        }
        function removeChip(val){ syncHidden(getVals().filter(v=>v!==val)); }

        function renderChip(val){
            var c = document.createElement('span');
            c.className = 'pcm-chip'; c.dataset.value = val;
            c.innerHTML = val + ' <button type="button" class="pcm-chip-remove" title="Remove">&#xD7;</button>';
            c.querySelector('.pcm-chip-remove').addEventListener('click',function(){ removeChip(val); c.remove(); });
            wrap.appendChild(c);
        }

        wrap.querySelectorAll('.pcm-chip-remove').forEach(function(btn){
            btn.addEventListener('click',function(){ var c=btn.closest('.pcm-chip'); removeChip(c.dataset.value); c.remove(); });
        });

        input.addEventListener('keydown',function(e){
            if (e.key==='Enter'||e.key===','){
                e.preventDefault(); var r=input.value.replace(/,/g,'').trim(); if(r){addChip(r);input.value='';}
            }
        });
        input.addEventListener('blur',function(){ var r=input.value.replace(/,/g,'').trim(); if(r){addChip(r);input.value='';} });
        input.addEventListener('paste',function(e){
            e.preventDefault();
            var p=(e.clipboardData||window.clipboardData).getData('text');
            p.split(',').forEach(function(v){ var t=v.trim(); if(t) addChip(t); });
            input.value='';
        });
    })();
    </script>

    <?php endif; ?>

    <?php elseif ( $tab === 'edge_cache_settings_tab' ) : ?>



    <!-- Page heading -->
    <div style="margin-bottom:20px;">
        <h2 style="font-size:20px;font-weight:700;color:#040024;margin:0 0 6px;font-family:'Inter',sans-serif;">
            <?php echo esc_html__( 'Manage Edge Cache Settings', 'pressable_cache_management' ); ?>
        </h2>
    </div>

    <!-- Card -->
    <div style="max-width:680px;">
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
                           class="ec-disabled-btn"
                           style="padding:10px 28px;border:none;border-radius:8px;font-size:14px;font-weight:700;
                                  color:#fff;background:#dd3a03;font-family:'Inter',sans-serif;
                                  transition:background .2s,opacity .2s;">
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

    <script>
    jQuery(document).ready(function($){
        var wrapper  = $('#edge-cache-control-wrapper');
        var purgeBtn = $('#purge-edge-cache-button-input');
        if (wrapper.length && !wrapper.data('ec-checked')) {
            wrapper.data('ec-checked', true);
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'pcm_check_edge_cache_status' },
                success: function(r) {
                    if (r.success && r.data.html_controls_enable_disable) {
                        wrapper.html(r.data.html_controls_enable_disable);
                        if (r.data.enabled) {
                            purgeBtn.removeClass('ec-disabled-btn')
                                    .prop('disabled', false)
                                    .css({ opacity:1, cursor:'pointer', pointerEvents:'auto' });
                        }
                    } else {
                        var msg = (r.data && r.data.message) ? r.data.message : '<?php echo esc_js( __( 'Failed to retrieve status.', 'pressable_cache_management' ) ); ?>';
                        wrapper.html('<p style="color:#ef4444;font-size:13px;margin:0;">'+msg+'</p>');
                    }
                },
                error: function() {
                    wrapper.html('<p style="color:#ef4444;font-size:13px;margin:0;"><?php echo esc_js( __( 'Could not connect to server.', 'pressable_cache_management' ) ); ?></p>');
                }
            });
        }
    });
    </script>
    <?php elseif ( $tab === 'remove_pressable_branding_tab' ) : ?>

    <form action="options.php" method="post">
        <?php
        settings_fields('remove_pressable_branding_tab_options');
        do_settings_sections('remove_pressable_branding_tab');
        submit_button('Save Settings','custom-class');
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
    /* Flush button: red, hover green */
    #pcm-flush-btn,
    input.flushcache[type="submit"] {
        background:#dd3a03 !important;
        color:#fff !important;
        transition:background .2s,box-shadow .2s,transform .1s !important;
    }
    #pcm-flush-btn:hover,
    input.flushcache[type="submit"]:hover {
        background:#03fcc2 !important;
        color:#040024 !important;
        box-shadow:0 4px 14px rgba(3,252,194,.45) !important;
        transform:translateY(-1px) !important;
    }
    .nav-tab-hidden { display:none !important; }

    .pcm-anchor-nav{position:sticky;top:32px;z-index:4;display:flex;gap:8px;overflow:auto;padding:8px 0 12px;margin-bottom:14px}
    .pcm-anchor-nav a{display:inline-flex;white-space:nowrap;padding:6px 10px;border:1px solid #d1d5db;border-radius:999px;text-decoration:none;color:#374151;background:#fff;font-size:12px}
    .pcm-anchor-nav a.is-active{background:#03fcc2;color:#040024;border-color:#03fcc2}
    .pcm-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    .pcm-summary-card{text-decoration:none;color:#111827;padding:12px;display:flex;flex-direction:column;gap:8px;position:relative}
    .pcm-summary-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
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
    .pcm-primary-btn{background:#040024 !important;border-color:#040024 !important;color:#fff !important}
    .pcm-primary-btn:hover{background:#03fcc2 !important;border-color:#03fcc2 !important;color:#040024 !important}
    .pcm-audit-panel{max-height:260px;overflow:auto;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:6px;font-size:12px}
    .pcm-audit-table{margin:0}
    .pcm-skeleton{position:relative;min-height:180px}
    .pcm-skeleton::before{content:'';display:block;height:12px;border-radius:4px;background:linear-gradient(90deg,#e5e7eb 25%,#f3f4f6 37%,#e5e7eb 63%);background-size:400% 100%;animation:pcm-skeleton-pulse 1.2s ease infinite;box-shadow:0 28px 0 #e5e7eb,0 56px 0 #e5e7eb,0 84px 0 #e5e7eb,0 112px 0 #e5e7eb}
    @keyframes pcm-skeleton-pulse{0%{background-position:100% 0}100%{background-position:0 0}}
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
    .pcm-batcache-status .pcm-dot{animation:none}
    .pcm-batcache-status.checking .pcm-dot{animation:pcm-pulse 1s infinite}
    @keyframes pcm-pulse{0%{opacity:.35}50%{opacity:1}100%{opacity:.35}}
    .pcm-wrap .nav-tab:focus-visible,.pcm-wrap button:focus-visible,.pcm-wrap a:focus-visible,.pcm-wrap input:focus-visible,.pcm-wrap select:focus-visible,.pcm-wrap textarea:focus-visible{outline:2px solid #03fcc2;outline-offset:2px}
    @media (max-width:1024px){.pcm-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
    @media (max-width:782px){.pcm-summary-grid{grid-template-columns:1fr}.pcm-anchor-nav{top:0}.pcm-card div[style*='grid-template-columns:1fr 1fr']{display:block !important}.pcm-advisor-grid{grid-template-columns:1fr !important}.pcm-diagnosis-grid{grid-template-columns:1fr}.pcm-sp-two-col-grid,.pcm-sp-insights-grid{grid-template-columns:1fr}.pcm-sp-number-wrap{max-width:100%}}

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
