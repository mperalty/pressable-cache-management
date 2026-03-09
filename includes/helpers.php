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
function pcm_format_flush_timestamp(): string {
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
function pcm_get_options(): array|false {
    static $cached = null;
    if ( $cached === null ) {
        $cached = get_option( PCM_Options::MAIN_OPTIONS->value );
    }

    return $cached;
}

/**
 * Ensure the MU-plugin infrastructure exists, then sync a single MU-plugin file.
 *
 * If the destination file already exists it is left in place. When the source
 * file is first copied, wp_cache_flush() is called so the change takes effect
 * immediately.
 *
 * @param string $source_file  Absolute path to the source MU-plugin template.
 * @param string $dest_filename Filename (not path) to place inside the PCM mu-plugins dir.
 *
 * @return bool True if the file was already present or was successfully copied.
 */
function pcm_sync_mu_plugin( string $source_file, string $dest_filename ): bool {
    $index_file = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management.php';
    if ( ! file_exists( $index_file ) ) {
        $index_source = dirname( $source_file ) . '/pressable_cache_management_mu_plugin_index.php';
        if ( ! copy( $index_source, $index_file ) ) {
            error_log( 'PCM: Failed to copy MU-plugin index to ' . $index_file );
            return false;
        }
    }

    $mu_dir = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/';
    if ( ! file_exists( $mu_dir ) ) {
        wp_mkdir_p( $mu_dir );
    }

    $dest_path = $mu_dir . $dest_filename;
    if ( file_exists( $dest_path ) ) {
        return true;
    }

    wp_cache_flush();

    if ( ! copy( $source_file, $dest_path ) ) {
        error_log( 'PCM: Failed to copy MU-plugin ' . $source_file . ' to ' . $dest_path );
        return false;
    }

    return true;
}

/**
 * Remove an MU-plugin file and flush the cache so the change takes effect.
 *
 * @param string $dest_filename Filename inside the PCM mu-plugins dir.
 */
function pcm_remove_mu_plugin( string $dest_filename ): void {
    $dest_path = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/' . $dest_filename;
    if ( file_exists( $dest_path ) ) {
        unlink( $dest_path );
        wp_cache_flush();
    }
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
function pcm_verify_request( string $nonce_name, string $action, string $method = 'POST', string $capability = 'manage_options' ): bool {
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
function pcm_verify_ajax_request( string $nonce_name, string $action, string $method = 'POST', string $capability = 'manage_options' ): true {
    if ( ! pcm_verify_request( $nonce_name, $action, $method, $capability ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    return true;
}
