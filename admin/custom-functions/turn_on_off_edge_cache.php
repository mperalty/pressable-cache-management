<?php
/**
 * Pressable Cache Management - Turn On/Off Edge Cache
 * Based directly on the official repo's turn-on-off-edge-cache.php
 * with branded admin notices applied.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Notice: Edge Cache Enabled ─────────────────────────────────────────────
if ( ! function_exists( 'pressable_edge_cache_notice_success_enable' ) ) {
    function pressable_edge_cache_notice_success_enable(): void {
        $screen = get_current_screen();
        if ( isset( $screen ) && 'toplevel_page_pressable_cache_management' !== $screen->id ) return;

        $html  = '<h3 class="pcm-ec-notice-title">'
               . '<span class="dashicons dashicons-yes-alt" aria-hidden="true" style="color:#03fcc2;margin-right:4px;"></span> ' . esc_html__( 'Edge Cache Enabled!', 'pressable_cache_management' ) . '</h3>';
        $html .= '<p class="pcm-ec-notice-desc">'
               . esc_html__( 'Edge Cache provides performance improvements, particularly for Time to First Byte (TTFB), by serving page cache from the nearest server to your website visitors.', 'pressable_cache_management' )
               . '</p>';
        $html .= '<a href="https://pressable.com/knowledgebase/edge-cache/" target="_blank" '
               . 'rel="noopener noreferrer" class="pcm-ec-notice-link">'
               . esc_html__( 'Learn more about Edge Cache.', 'pressable_cache_management' ) . '</a>';

        $nid  = 'pcm-ec-enabled-' . substr( md5( microtime() ), 0, 8 );
        echo '<div class="pcm-ec-notice-outer">';
        echo '<div id="' . esc_attr( $nid ) . '" class="pcm-branded-notice pcm-branded-notice-edge">';
        echo '<div class="pcm-branded-notice-body">' . $html . '</div>';
        echo '<button type="button" onclick="document.getElementById(\'' . esc_js( $nid ) . '\').remove();" class="pcm-branded-notice-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button>';
        echo '</div>';
        echo '</div>';
    }
}

// ─── Notice: Edge Cache Disabled ────────────────────────────────────────────
if ( ! function_exists( 'pressable_edge_cache_notice_success_disable' ) ) {
    function pressable_edge_cache_notice_success_disable(): void {
        $screen = get_current_screen();
        if ( isset( $screen ) && 'toplevel_page_pressable_cache_management' !== $screen->id ) return;
        pcm_admin_notice( esc_html__( 'Edge Cache Deactivated.', 'pressable_cache_management' ), 'success' );
    }
}

// ─── Notice: Error ───────────────────────────────────────────────────────────
if ( ! function_exists( 'pcm_pressable_edge_cache_error_msg' ) ) {
    function pcm_pressable_edge_cache_error_msg( string $error_message = '' ): void {
        $screen = get_current_screen();
        if ( isset( $screen ) && 'toplevel_page_pressable_cache_management' !== $screen->id ) return;
        $msg = empty( $error_message )
            ? esc_html__( 'Something went wrong trying to communicate with the Edge Cache system. Try again.', 'pressable_cache_management' )
            : esc_html( $error_message );
        pcm_admin_notice( $msg, 'error' );
    }
}

// ─── Shared core: send enable/disable command to Edge Cache backend ─────────
/**
 * Send an enable or disable command to the Edge Cache backend.
 *
 * Shared by both form-based handlers and the AJAX toggle endpoint.
 *
 * @param string $action 'enable' or 'disable'.
 * @return true|\WP_Error True on success, WP_Error on failure.
 */
function pcm_edge_cache_set_state( string $action ): true|\WP_Error {
    if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
        return new \WP_Error( 'pcm_ec_missing', 'Required Edge Cache dependency is not available.' );
    }

    $edge_cache = Edge_Cache_Plugin::get_instance();
    $command    = ( 'enable' === $action ) ? 'on' : 'off';
    $result     = $edge_cache->query_ec_backend( $command, array( 'wp_action' => 'manual_dashboard_set' ) );

    if ( is_wp_error( $result ) ) {
        // On error, keep the previous enabled/disabled state
        update_option( PCM_Options::EDGE_CACHE_STATUS->value, 'Error' );
        return $result;
    }

    $new_state = ( 'enable' === $action ) ? 'enabled' : 'disabled';
    update_option( PCM_Options::EDGE_CACHE_STATUS->value, 'Success' );
    update_option( PCM_Options::EDGE_CACHE_ENABLED->value, $new_state );
    delete_transient( 'pcm_ec_status_cache' );

    return true;
}

// ─── Enable Edge Cache (form POST handler) ──────────────────────────────────
function pcm_pressable_enable_edge_cache(): void {
    if ( pcm_verify_request( 'enable_edge_cache_nonce', 'enable_edge_cache_nonce' ) ) {
        $result = pcm_edge_cache_set_state( 'enable' );

        if ( is_wp_error( $result ) ) {
            add_action( 'admin_notices', function() use ( $result ) {
                pcm_pressable_edge_cache_error_msg( $result->get_error_message() );
            });
        } elseif ( true === $result ) {
            add_action( 'admin_notices', 'pressable_edge_cache_notice_success_enable' );
        }
    }
}
add_action( 'init', 'pcm_pressable_enable_edge_cache' );

// ─── Disable Edge Cache (form POST handler) ─────────────────────────────────
function pcm_pressable_disable_edge_cache(): void {
    if ( pcm_verify_request( 'disable_edge_cache_nonce', 'disable_edge_cache_nonce' ) ) {
        $result = pcm_edge_cache_set_state( 'disable' );

        if ( is_wp_error( $result ) ) {
            add_action( 'admin_notices', function() use ( $result ) {
                pcm_pressable_edge_cache_error_msg( $result->get_error_message() );
            });
        } elseif ( true === $result ) {
            add_action( 'admin_notices', 'pressable_edge_cache_notice_success_disable' );
        }
    }
}
add_action( 'init', 'pcm_pressable_disable_edge_cache' );

// ─── AJAX: Toggle Edge Cache (replaces form POST for race-condition-free UX) ─
function pcm_ajax_toggle_edge_cache(): void {
    pcm_verify_ajax_request( 'nonce', 'pcm_edge_cache_toggle' );

    $desired = isset( $_POST['desired_state'] ) ? sanitize_text_field( wp_unslash( $_POST['desired_state'] ) ) : '';
    if ( ! in_array( $desired, array( 'enable', 'disable' ), true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid desired state.' ) );
    }

    $result = pcm_edge_cache_set_state( $desired );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Do NOT rely on the option update above for the UI — the caller will poll
    // until the live status matches the expected state.
    wp_send_json_success( array(
        'submitted'      => true,
        'desired_state'  => $desired,
        'message'        => ( 'enable' === $desired )
            ? __( 'Edge Cache enable request submitted. Waiting for propagation...', 'pressable_cache_management' )
            : __( 'Edge Cache disable request submitted. Waiting for propagation...', 'pressable_cache_management' ),
    ) );
}
add_action( 'wp_ajax_pcm_toggle_edge_cache', 'pcm_ajax_toggle_edge_cache' );

// ─── AJAX: Poll live Edge Cache status (bypasses transient cache) ────────────
function pcm_ajax_poll_edge_cache_status(): void {
    pcm_verify_ajax_request( 'nonce', 'pcm_edge_cache_toggle' );

    if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
        wp_send_json_error( array( 'message' => 'Edge Cache dependency is not available.' ) );
    }

    $edge_cache    = Edge_Cache_Plugin::get_instance();
    $server_status = $edge_cache->get_ec_status(); // live API call — no transient

    $is_enabled  = false;
    $status_text = 'Unknown';

    if ( $server_status === Edge_Cache_Plugin::EC_ENABLED ) {
        $is_enabled  = true;
        $status_text = 'Enabled';
    } elseif ( $server_status === Edge_Cache_Plugin::EC_DISABLED ) {
        $is_enabled  = false;
        $status_text = 'Disabled';
    } elseif ( $server_status === Edge_Cache_Plugin::EC_DDOS ) {
        $status_text = 'Defensive Mode (DDoS)';
    }

    $desired = isset( $_POST['desired_state'] ) ? sanitize_text_field( wp_unslash( $_POST['desired_state'] ) ) : '';
    $expected_enabled = ( 'enable' === $desired );
    $propagated       = ( $is_enabled === $expected_enabled );

    // If propagated, update options and refresh the transient so the page is consistent.
    if ( $propagated ) {
        update_option( PCM_Options::EDGE_CACHE_STATUS->value,  'Success' );
        update_option( PCM_Options::EDGE_CACHE_ENABLED->value, $is_enabled ? 'enabled' : 'disabled' );
        delete_transient( 'pcm_ec_status_cache' );
    }

    wp_send_json_success( array(
        'propagated'                   => $propagated,
        'enabled'                      => $is_enabled,
        'status_text'                  => $status_text,
        'html_controls_enable_disable' => pressable_cache_management_generate_enable_disable_content( $is_enabled ),
    ) );
}
add_action( 'wp_ajax_pcm_poll_edge_cache_status', 'pcm_ajax_poll_edge_cache_status' );
