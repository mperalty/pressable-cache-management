<?php
/**
 * Pressable Cache Management — Shared helper functions.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Return a consistently formatted UTC timestamp string.
 *
 * Format: "9 Mar 2026, 3:45pm UTC"
 *
 * @return string
 */
function pcm_format_flush_timestamp() {
    return gmdate( 'j M Y, g:ia' ) . ' UTC';
}

/**
 * Return the main plugin options array, cached for the current request.
 *
 * Avoids redundant get_option() calls across the many custom-functions
 * files that each need to check a checkbox flag from this option.
 *
 * @return array|false
 */
function pcm_get_options() {
    static $cached = null;
    if ( $cached === null ) {
        $cached = get_option( PCM_Options::MAIN_OPTIONS );
    }

    return $cached;
}

/**
 * Verify a nonce from a request and check user capability.
 *
 * Consolidates the repeated nonce-verification pattern used throughout the
 * plugin so that every check sanitises, unslashes, and validates the nonce
 * in exactly the same way.
 *
 * @param string $nonce_name  The key name in $_POST / $_GET that holds the nonce value.
 * @param string $action      The nonce action passed to wp_verify_nonce().
 * @param string $method      HTTP method to read from: 'POST' (default) or 'GET'.
 * @param string $capability  The capability to check. Default 'manage_options'.
 *
 * @return bool True when the nonce is present, valid, and the current user
 *              has the required capability; false otherwise.
 */
function pcm_verify_request( $nonce_name, $action, $method = 'POST', $capability = 'manage_options' ) {
    $input = ( 'GET' === strtoupper( $method ) ) ? $_GET : $_POST;

    if ( ! isset( $input[ $nonce_name ] ) ) {
        return false;
    }

    $nonce_value = sanitize_text_field( wp_unslash( $input[ $nonce_name ] ) );

    if ( ! wp_verify_nonce( $nonce_value, $action ) ) {
        return false;
    }

    if ( ! current_user_can( $capability ) ) {
        return false;
    }

    return true;
}

/**
 * Verify a nonce for an AJAX request and check user capability.
 *
 * Same checks as pcm_verify_request() but instead of returning false on
 * failure it immediately sends a JSON error response with a 403 status
 * and halts execution — the standard pattern for wp_ajax_ handlers.
 *
 * @param string $nonce_name  The key name in $_POST / $_GET that holds the nonce value.
 * @param string $action      The nonce action passed to wp_verify_nonce().
 * @param string $method      HTTP method to read from: 'POST' (default) or 'GET'.
 * @param string $capability  The capability to check. Default 'manage_options'.
 *
 * @return true Always returns true; on failure the function dies via wp_send_json_error().
 */
function pcm_verify_ajax_request( $nonce_name, $action, $method = 'POST', $capability = 'manage_options' ) {
    if ( ! pcm_verify_request( $nonce_name, $action, $method, $capability ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    return true;
}
