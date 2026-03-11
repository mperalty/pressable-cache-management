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
	if ( null === $cached ) {
		$cached = get_option( PCM_Options::MAIN_OPTIONS->value );
	}

	return $cached;
}

/**
 * Log operational warnings for plugin file sync and cache safety events.
 *
 * @param string $message Message to log.
 *
 * @return void
 */
function pcm_log_operational_warning( string $message ): void {
	if ( '' === $message ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational issues are logged to aid MU-plugin sync and cache circuit-breaker diagnostics.
	error_log( $message );
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
		$index_source = dirname( $source_file ) . '/pressable-cache-management-mu-plugin-index.php';
		if ( ! copy( $index_source, $index_file ) ) {
			pcm_log_operational_warning( 'PCM: Failed to copy MU-plugin index to ' . $index_file );
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

	if ( ! copy( $source_file, $dest_path ) ) {
		pcm_log_operational_warning( 'PCM: Failed to copy MU-plugin ' . $source_file . ' to ' . $dest_path );
		set_transient( 'pcm_mu_plugin_sync_failure', basename( $source_file ), 60 );
		return false;
	}

	// Defer the cache flush to avoid stampedes when multiple MU-plugins sync
	pcm_schedule_deferred_flush();

	return true;
}

/**
 * Remove an MU-plugin file and schedule a deferred cache flush.
 *
 * @param string $dest_filename Filename inside the PCM mu-plugins dir.
 */
function pcm_remove_mu_plugin( string $dest_filename ): void {
	$dest_path = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/' . $dest_filename;
	if ( file_exists( $dest_path ) ) {
		wp_delete_file( $dest_path );
		pcm_schedule_deferred_flush();
	}
}

/**
 * Schedule a single deferred wp_cache_flush() on the shutdown hook.
 *
 * When multiple MU-plugin files are synced or removed during a single
 * request (e.g. settings save), this batches them into one flush instead
 * of triggering one per file. Includes a 5-second rate limit via transient
 * to prevent cache stampedes on WPCloud shared infrastructure.
 */
function pcm_schedule_deferred_flush(): void {
	static $scheduled = false;
	if ( $scheduled ) {
		return;
	}
	$scheduled = true;

	add_action(
		'shutdown',
		function (): void {
			// Rate limit: skip if a flush happened within the last 5 seconds
			if ( get_transient( 'pcm_last_cache_flush' ) ) {
				return;
			}
			wp_cache_flush();
			set_transient( 'pcm_last_cache_flush', 1, 5 );
		}
	);
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
	$input_type  = ( 'GET' === strtoupper( $method ) ) ? INPUT_GET : INPUT_POST;
	$nonce_value = filter_input( $input_type, $nonce_name, FILTER_UNSAFE_RAW );

	if ( ! is_string( $nonce_value ) || '' === $nonce_value ) {
		return false;
	}

	$nonce_value = sanitize_text_field( $nonce_value );

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

/**
 * Render a toggle (checkbox) field for the settings page.
 *
 * Consolidates the repeated checkbox rendering pattern used by 7+ callbacks
 * in settings-callbacks.php into a single reusable function.
 *
 * @param array       $args           Field arguments ('id', 'label').
 * @param string|null $timestamp_key  Optional PCM_Options case value for "last flushed" timestamp.
 * @param string      $extra_html     Optional extra HTML to append after the timestamp.
 */
function pcm_render_toggle_field( array $args, ?string $timestamp_key = null, string $extra_html = '' ): void {
	$options = pcm_get_options();
	$id      = $args['id'] ?? '';
	$label   = $args['label'] ?? '';

	echo '<div class="container">';
	echo '<label class="switch">';
	echo '<input type="checkbox" id="pressable_cache_management_options_' . esc_attr( $id ) . '" name="pressable_cache_management_options[' . esc_attr( $id ) . ']" value="1"';
	checked( isset( $options[ $id ] ) ? $options[ $id ] : '', 1 );
	echo ' aria-label="' . esc_attr( $label ) . '" />';
	echo '<span class="slider round"></span></label>';
	echo '<label class="rad-text" for="pressable_cache_management_options_' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

	if ( $timestamp_key ) {
		echo '<br>';
		echo '<br>';
		echo '<small><strong>Last flushed at: </strong></small>' . wp_kses_post( get_option( $timestamp_key ) );
	}

	if ( $extra_html ) {
		echo wp_kses_post( $extra_html );
	}
}

/**
 * Flush a single URL from Batcache.
 *
 * Consolidates the repeated Batcache URL-flushing pattern used in
 * flush_cache_on_page_edit.php, flush_batcache_for_particular_page.php,
 * and flush_single_page_toolbar.php into a single canonical function.
 *
 * @param string $url The full URL to flush from Batcache.
 *
 * @return bool True if the URL was flushed, false if Batcache is unavailable.
 */
function pcm_flush_batcache_url( string $url ): bool {
	global $batcache, $wp_object_cache;

	// Skip if circuit breaker has tripped due to repeated failures
	if ( pcm_cache_circuit_breaker() ) {
		return false;
	}

	if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
		pcm_cache_circuit_breaker( true );
		return false;
	}

	$batcache->configure_groups();

	$url = apply_filters( 'batcache_manager_link', $url );
	if ( empty( $url ) ) {
		return false;
	}

	do_action( 'batcache_manager_before_flush', $url );

	$url     = set_url_scheme( $url, 'http' );
	$url_key = md5( $url );

	wp_cache_add( "{$url_key}_version", 0, $batcache->group );
	wp_cache_incr( "{$url_key}_version", 1, $batcache->group );

	// Handle sites where the Batcache group is excluded from remote sync.
	// Temporarily allow remote writes for the group, re-set the version so
	// it propagates to remote memcached nodes, then restore the exclusion.
	if ( pcm_batcache_has_remote_groups() && is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'no_remote_groups' ) ) {
		$no_remote_groups = is_array( $wp_object_cache->no_remote_groups ) ? $wp_object_cache->no_remote_groups : array();
		$k                = array_search( $batcache->group, $no_remote_groups, true );
		if ( false !== $k ) {
			unset( $no_remote_groups[ $k ] );
			$current_version = wp_cache_get( "{$url_key}_version", $batcache->group );
			wp_cache_set( "{$url_key}_version", $current_version, $batcache->group );
			$no_remote_groups[ $k ]            = $batcache->group;
			$wp_object_cache->no_remote_groups = $no_remote_groups;
		}
	}

	do_action( 'batcache_manager_after_flush', $url );

	return true;
}

/**
 * Check whether the object cache has a no_remote_groups property.
 *
 * @return bool
 */
function pcm_batcache_has_remote_groups(): bool {
	global $wp_object_cache;
	return is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'no_remote_groups' );
}

/**
 * Render a branded admin notice on the PCM settings page.
 *
 * Consolidates the 4+ bespoke notice implementations scattered across
 * custom-functions files into a single, consistent renderer.
 *
 * @param string $message      The notice message text.
 * @param string $type         'success', 'error', or 'info'.
 * @param bool   $dismissible  Whether to show a dismiss button.
 */
function pcm_admin_notice( string $message, string $type = 'info', bool $dismissible = true ): void {
	$border_colors = array(
		'success' => '#03fcc2',
		'error'   => '#dd3a03',
		'info'    => '#03fcc2',
	);
	$border        = $border_colors[ $type ] ?? '#03fcc2';
	$nid           = 'pcm-notice-' . substr( md5( $message . microtime() ), 0, 8 );

	echo '<div style="max-width:1120px;margin:0 auto;padding:0 20px;box-sizing:border-box;">';
	echo '<div id="' . esc_attr( $nid ) . '" class="pcm-branded-notice pcm-branded-notice-wide" style="border-left-color:' . esc_attr( $border ) . ';">';
	echo '<div class="pcm-branded-notice-body">';
	echo '<p class="pcm-branded-notice-text">' . esc_html( $message ) . '</p>';
	echo '</div>';
	if ( $dismissible ) {
		echo '<button type="button" onclick="document.getElementById(\'' . esc_js( $nid ) . '\').remove();" class="pcm-branded-notice-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button>';
	}
	echo '</div>';
	echo '</div>';
}

/**
 * Show an admin notice when an MU-plugin sync failed.
 *
 * Checks for a transient set by pcm_sync_mu_plugin() on copy failure
 * and displays a dismissible error notice on the PCM settings page.
 */
function pcm_mu_plugin_sync_failure_notice(): void {
	$failed_file = get_transient( 'pcm_mu_plugin_sync_failure' );
	if ( ! $failed_file || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'toplevel_page_pressable_cache_management' !== $screen->id ) {
		return;
	}
	pcm_admin_notice(
		sprintf(
			/* translators: %s: MU-plugin filename */
			__( 'MU-plugin sync failed for "%s". Check file permissions on wp-content/mu-plugins/.', 'pressable_cache_management' ),
			$failed_file
		),
		'error'
	);
	delete_transient( 'pcm_mu_plugin_sync_failure' );
}
add_action( 'admin_notices', 'pcm_mu_plugin_sync_failure_notice' );

/**
 * Verify that expected PCM MU-plugin files exist.
 *
 * Returns an array of missing filenames. An empty array means all
 * expected MU-plugins are present.
 *
 * @return string[] List of missing MU-plugin filenames.
 */
function pcm_check_mu_plugin_health(): array {
	$options = pcm_get_options();
	$mu_dir  = WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/';
	$missing = array();

	$checks = array(
		'extend_batcache_checkbox'                       => 'pcm_extend_batcache.php',
		'exclude_pages_from_batcache_checkbox'           => 'pcm_exclude_pages_from_batcache.php',
		'cache_wpp_cookie_page_checkbox'                 => 'pcm_cache_wpp_cookie_page.php',
		'exclude_query_string_gclid_from_cache_checkbox' => 'pcm_exclude_query_string_gclid_from_cache.php',
	);

	foreach ( $checks as $option_key => $filename ) {
		if ( ! empty( $options[ $option_key ] ) && ! file_exists( $mu_dir . $filename ) ) {
			$missing[] = $filename;
		}
	}

	return $missing;
}

/**
 * Circuit-breaker for cache operations.
 *
 * Tracks consecutive cache operation failures. After 3 failures,
 * returns true to signal callers to skip cache operations until
 * the next page load (when the static state resets).
 *
 * @param bool $failed Whether the current operation failed.
 * @return bool True if the circuit is open (callers should skip).
 */
function pcm_cache_circuit_breaker( bool $failed = false ): bool {
	static $failure_count = 0;
	static $circuit_open  = false;

	if ( $circuit_open ) {
		return true;
	}

	if ( $failed ) {
		++$failure_count;
		if ( $failure_count >= 3 ) {
			$circuit_open = true;
			pcm_log_operational_warning( 'PCM: Circuit breaker tripped after 3 consecutive cache failures. Skipping cache operations until next page load.' );
		}
	} else {
		$failure_count = 0;
	}

	return $circuit_open;
}
