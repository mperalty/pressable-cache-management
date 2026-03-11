<?php
/**
 * Permissions, Safety, and Privacy baseline (Pillar 9).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_security_privacy_dir = plugin_dir_path( __FILE__ );

require_once $pcm_security_privacy_dir . 'class-pcm-audit-log-service.php';
require_once $pcm_security_privacy_dir . 'class-pcm-risky-action-confirmation-service.php';
require_once $pcm_security_privacy_dir . 'class-pcm-telemetry-retention-manager.php';

/**
 * Baseline is enabled by default so all modules can rely on it.
 *
 * @return bool
 */
function pcm_security_privacy_is_enabled(): bool {
	return (bool) apply_filters( 'pcm_enable_security_privacy', true );
}

/**
 * Capability map for advanced diagnostics and actions.
 *
 * @return array
 */
function pcm_get_capability_matrix(): array {
	return array(
		'pcm_view_diagnostics'        => __( 'View diagnostics and reports', 'pressable_cache_management' ),
		'pcm_run_scans'               => __( 'Run cacheability scans', 'pressable_cache_management' ),
		'pcm_manage_redirect_rules'   => __( 'Manage redirect assistant rules', 'pressable_cache_management' ),
		'pcm_flush_cache_global'      => __( 'Execute global cache flushes', 'pressable_cache_management' ),
		'pcm_export_reports'          => __( 'Export diagnostic reports', 'pressable_cache_management' ),
		'pcm_manage_privacy_settings' => __( 'Manage privacy and retention settings', 'pressable_cache_management' ),
	);
}

/**
 * Ensure admin role has PCM capabilities.
 *
 * @return void
 */
function pcm_register_default_capabilities(): void {
	if ( ! pcm_security_privacy_is_enabled() || ! is_admin() ) {
		return;
	}

	$version = (string) get_option( PCM_Options::SECURITY_PRIVACY_CAPS_VERSION->value, '' );
	if ( '1.0.0' === $version ) {
		return;
	}

	$role = get_role( 'administrator' );
	if ( ! $role ) {
		return;
	}

	foreach ( array_keys( pcm_get_capability_matrix() ) as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}

	update_option( PCM_Options::SECURITY_PRIVACY_CAPS_VERSION->value, '1.0.0', false );
}
add_action( 'admin_init', 'pcm_register_default_capabilities' );

/**
 * Unified capability guard with admin fallback for compatibility.
 *
 * @param string $capability Capability.
 *
 * @return bool
 */
function pcm_current_user_can( string $capability ): bool {
	if ( current_user_can( $capability ) ) {
		return true;
	}

	// Fallback keeps existing installs functional while capabilities roll out.
	return current_user_can( 'manage_options' );
}

/**
 * Privacy settings defaults.
 *
 * @return array
 */
function pcm_get_privacy_settings(): array {
	$defaults = array(
		'retention_days'       => 90,
		'redaction_level'      => 'standard',
		'export_restrictions'  => 'admin_only',
		'advanced_scan_opt_in' => false,
		'audit_log_enabled'    => true,
		'sensitive_keys'       => array( 'email', 'token', 'auth', 'authorization', 'password', 'pass', 'nonce', 'key', 'secret' ),
	);

	$stored = get_option( PCM_Options::PRIVACY_SETTINGS_V1->value, array() );
	if ( ! is_array( $stored ) ) {
		return $defaults;
	}

	$settings                    = wp_parse_args( $stored, $defaults );
	$settings['retention_days']  = max( 7, min( 365, absint( $settings['retention_days'] ) ) );
	$settings['redaction_level'] = in_array( $settings['redaction_level'], array( 'minimal', 'standard', 'strict' ), true )
		? $settings['redaction_level']
		: 'standard';

	$settings['export_restrictions']  = in_array( $settings['export_restrictions'], array( 'admin_only', 'diagnostics_viewers' ), true )
		? $settings['export_restrictions']
		: 'admin_only';
	$settings['advanced_scan_opt_in'] = ! empty( $settings['advanced_scan_opt_in'] );
	$settings['audit_log_enabled']    = ! empty( $settings['audit_log_enabled'] );
	$settings['sensitive_keys']       = array_values( array_filter( array_map( 'sanitize_key', (array) $settings['sensitive_keys'] ) ) );

	return $settings;
}

/**
 * Recursive redaction middleware for telemetry and exports.
 *
 * @param mixed $value Value to redact.
 * @param array $settings Settings.
 *
 * @return mixed
 */
function pcm_privacy_redact_value( mixed $value, array $settings = array() ): mixed {
	$settings = wp_parse_args( $settings, pcm_get_privacy_settings() );

	if ( is_array( $value ) ) {
		$output = array();
		foreach ( $value as $key => $child ) {
			$normalized_key = sanitize_key( is_string( $key ) ? $key : (string) $key );
			if ( in_array( $normalized_key, $settings['sensitive_keys'], true ) ) {
				$output[ $key ] = pcm_privacy_mask_scalar( $child, $settings['redaction_level'] );
				continue;
			}

			if ( 'set-cookie' === $normalized_key || 'cookie' === $normalized_key ) {
				$output[ $key ] = pcm_privacy_mask_cookie_values( $child );
				continue;
			}

			$output[ $key ] = pcm_privacy_redact_value( $child, $settings );
		}

		return $output;
	}

	return $value;
}

/**
 * @param mixed  $value Value.
 * @param string $redaction_level Level.
 *
 * @return string
 */
function pcm_privacy_mask_scalar( mixed $value, string $redaction_level = 'standard' ): string {
	$string = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	$string = is_string( $string ) ? $string : '';

	if ( 'strict' === $redaction_level ) {
		return '[redacted]';
	}

	if ( '' === $string ) {
		return '[redacted]';
	}

	$hash = hash( 'sha256', $string );

	if ( 'minimal' === $redaction_level ) {
		return '[masked:' . substr( $hash, 0, 6 ) . ']';
	}

	return '[redacted:' . substr( $hash, 0, 12 ) . ']';
}

/**
 * Mask cookie values but keep cookie names.
 *
 * @param mixed $cookie_value Raw cookie header value(s).
 *
 * @return string|array
 */
function pcm_privacy_mask_cookie_values( mixed $cookie_value ): string|array {
	if ( is_array( $cookie_value ) ) {
		return array_map( 'pcm_privacy_mask_cookie_values', $cookie_value );
	}

	$line       = (string) $cookie_value;
	$first_part = strtok( $line, ';' );
	$pair       = explode( '=', (string) $first_part, 2 );

	$name = sanitize_key( trim( $pair[0] ) );

	return $name . '=[redacted]';
}

/**
 * Register daily retention cleanup schedule.
 *
 * @return void
 */
function pcm_security_privacy_maybe_schedule_cleanup(): void {
	if ( ! pcm_security_privacy_is_enabled() ) {
		return;
	}

	if ( ! wp_next_scheduled( 'pcm_security_privacy_cleanup' ) ) {
		wp_schedule_event( time() + 180, 'daily', 'pcm_security_privacy_cleanup' );
	}
}
add_action( 'init', 'pcm_security_privacy_maybe_schedule_cleanup' );

/**
 * Execute retention cleanup.
 *
 * @return void
 */
function pcm_security_privacy_cleanup(): void {
	if ( ! pcm_security_privacy_is_enabled() ) {
		return;
	}

	$manager = new PCM_Telemetry_Retention_Manager();
	$manager->cleanup();
}
add_action( 'pcm_security_privacy_cleanup', 'pcm_security_privacy_cleanup' );

/**
 * Centralized permission + nonce guard for AJAX surfaces.
 *
 * @param string $nonce_action Nonce action.
 * @param string $capability   Required capability.
 *
 * @return void
 */
function pcm_ajax_enforce_permissions( string $nonce_action, string $capability ): void {
	check_ajax_referer( $nonce_action, 'nonce' );

	if ( ! pcm_current_user_can( $capability ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
}

/**
 * Save privacy settings.
 *
 * @param mixed $raw Incoming settings.
 *
 * @return array
 */
function pcm_save_privacy_settings( mixed $raw ): array {
	$raw = is_array( $raw ) ? $raw : array();

	// Merge incoming partial payload with existing settings so that fields
	// not present in the request (e.g. advanced_scan_opt_in, sensitive_keys)
	// are preserved instead of silently reset to defaults.
	$existing = pcm_get_privacy_settings();

	$settings = array(
		'retention_days'       => isset( $raw['retention_days'] ) ? absint( $raw['retention_days'] ) : $existing['retention_days'],
		'redaction_level'      => isset( $raw['redaction_level'] ) ? sanitize_key( $raw['redaction_level'] ) : $existing['redaction_level'],
		'export_restrictions'  => isset( $raw['export_restrictions'] ) ? sanitize_key( $raw['export_restrictions'] ) : $existing['export_restrictions'],
		'advanced_scan_opt_in' => array_key_exists( 'advanced_scan_opt_in', $raw ) ? ! empty( $raw['advanced_scan_opt_in'] ) : $existing['advanced_scan_opt_in'],
		'audit_log_enabled'    => array_key_exists( 'audit_log_enabled', $raw ) ? ! empty( $raw['audit_log_enabled'] ) : $existing['audit_log_enabled'],
		'sensitive_keys'       => isset( $raw['sensitive_keys'] ) ? array_filter( array_map( 'sanitize_key', (array) $raw['sensitive_keys'] ) ) : $existing['sensitive_keys'],
	);

	update_option( PCM_Options::PRIVACY_SETTINGS_V1->value, $settings, false );

	return pcm_get_privacy_settings();
}

/**
 * Write audit event if enabled.
 *
 * @param string $action Action key.
 * @param string $target Target.
 * @param array  $context Context.
 *
 * @return array|null
 */
function pcm_audit_log( string $action, string $target = '', array $context = array() ): ?array {
	$settings = pcm_get_privacy_settings();
	if ( empty( $settings['audit_log_enabled'] ) ) {
		return null;
	}

	$service = new PCM_Audit_Log_Service();

	return $service->log( $action, $target, $context );
}

/**
 * AJAX: Get privacy settings.
 *
 * @return void
 */
function pcm_ajax_privacy_settings_get(): void {
	pcm_ajax_enforce_permissions( 'pcm_privacy_settings', 'pcm_manage_privacy_settings' );

	wp_send_json_success( array( 'settings' => pcm_get_privacy_settings() ) );
}
add_action( 'wp_ajax_pcm_privacy_settings_get', 'pcm_ajax_privacy_settings_get' );

/**
 * AJAX: Save privacy settings.
 *
 * @return void
 */
function pcm_ajax_privacy_settings_save(): void {
	pcm_ajax_enforce_permissions( 'pcm_privacy_settings', 'pcm_manage_privacy_settings' );

	$settings_json = filter_input( INPUT_POST, 'settings', FILTER_UNSAFE_RAW );
	if ( ! is_string( $settings_json ) ) {
		$settings_json = filter_input( INPUT_GET, 'settings', FILTER_UNSAFE_RAW );
	}

	$payload = is_string( $settings_json ) ? json_decode( wp_unslash( $settings_json ), true ) : array();
	if ( ! is_array( $payload ) ) {
		wp_send_json_error( array( 'message' => 'Invalid settings payload.' ), 400 );
	}

	$settings = pcm_save_privacy_settings( $payload );

	pcm_audit_log( 'privacy_settings_updated', 'privacy_settings', array( 'keys' => array_keys( $payload ) ) );

	wp_send_json_success( array( 'settings' => $settings ) );
}
add_action( 'wp_ajax_pcm_privacy_settings_save', 'pcm_ajax_privacy_settings_save' );

/**
 * AJAX: View audit logs.
 *
 * @return void
 */
function pcm_ajax_audit_log_list(): void {
	pcm_ajax_enforce_permissions( 'pcm_privacy_settings', 'pcm_manage_privacy_settings' );

	$limit_input  = filter_input( INPUT_POST, 'limit', FILTER_VALIDATE_INT );
	$offset_input = filter_input( INPUT_POST, 'offset', FILTER_VALIDATE_INT );
	if ( false === $limit_input || null === $limit_input ) {
		$limit_input = filter_input( INPUT_GET, 'limit', FILTER_VALIDATE_INT );
	}
	if ( false === $offset_input || null === $offset_input ) {
		$offset_input = filter_input( INPUT_GET, 'offset', FILTER_VALIDATE_INT );
	}

	$limit    = is_int( $limit_input ) ? max( 1, min( 200, absint( $limit_input ) ) ) : 20;
	$offset   = is_int( $offset_input ) ? max( 0, absint( $offset_input ) ) : 0;
	$service  = new PCM_Audit_Log_Service();
	$all_rows = array_reverse( $service->all() );
	$rows     = array_slice( $all_rows, $offset, $limit );

	foreach ( $rows as $index => $row ) {
		$actor_id = isset( $row['actor_id'] ) ? absint( $row['actor_id'] ) : 0;
		$user     = $actor_id ? get_userdata( $actor_id ) : false;

		$rows[ $index ]['actor_display'] = $user && isset( $user->display_name )
			? (string) $user->display_name
			: __( 'System', 'pressable_cache_management' );
	}

	wp_send_json_success(
		array(
			'rows'            => $rows,
			'offset'          => $offset,
			'limit'           => $limit,
			'total'           => count( $all_rows ),
			'has_more'        => ( $offset + count( $rows ) ) < count( $all_rows ),
			'chain_integrity' => $service->verify_chain(),
		)
	);
}
add_action( 'wp_ajax_pcm_audit_log_list', 'pcm_ajax_audit_log_list' );


/**
 * AJAX: Refresh cacheability nonce for long-lived admin pages.
 *
 * @return void
 */
function pcm_ajax_refresh_cacheability_nonce(): void {
	if ( ! pcm_current_user_can( 'pcm_view_diagnostics' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pressable_cache_management' ) ), 403 );
	}

	wp_send_json_success(
		array(
			'nonce'                  => wp_create_nonce( 'pcm_cacheability_scan' ),
			'privacySettingsNonce'   => wp_create_nonce( 'pcm_privacy_settings' ),
			'redirectAssistantNonce' => wp_create_nonce( 'pcm_redirect_assistant' ),
		)
	);
}
add_action( 'wp_ajax_pcm_refresh_cacheability_nonce', 'pcm_ajax_refresh_cacheability_nonce' );
