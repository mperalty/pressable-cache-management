<?php
/**
 * Redirect Assistant (Pillar 5).
 *
 * Export-only workflow for custom-redirects.php generation.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_redirect_assistant_dir = plugin_dir_path( __FILE__ );

require_once $pcm_redirect_assistant_dir . 'class-pcm-redirect-assistant-repository.php';
require_once $pcm_redirect_assistant_dir . 'class-pcm-redirect-assistant-candidate-discovery.php';
require_once $pcm_redirect_assistant_dir . 'class-pcm-redirect-assistant-simulation-engine.php';
require_once $pcm_redirect_assistant_dir . 'class-pcm-redirect-assistant-exporter.php';

/**
 * Feature flag for redirect assistant.
 *
 * @return bool
 */
function pcm_redirect_assistant_is_enabled(): bool {
	static $cached = null;
	if ( null === $cached ) {
		$enabled = (bool) get_option( PCM_Options::ENABLE_REDIRECT_ASSISTANT->value, false );
		$cached  = (bool) apply_filters( 'pcm_enable_redirect_assistant', $enabled );
	}

	return $cached;
}

function pcm_redirect_assistant_safe_preg_match( string $pattern, string $subject ): int|false {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Suppress invalid-regex warnings while validating admin-entered patterns.
	set_error_handler(
		static function (): bool {
			return true;
		}
	);

	$result = preg_match( $pattern, $subject );

	restore_error_handler();

	return $result;
}

/**
 * @param array  $rows Rows.
 * @param string $key  Unique key field.
 *
 * @return array
 */
function pcm_redirect_assistant_unique_by_key( array $rows, string $key ): array {
	$unique = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row[ $key ] ) ) {
			continue;
		}

		$unique[ $row[ $key ] ] = $row;
	}

	return array_values( $unique );
}

/**
 * @param mixed $rule Rule payload.
 *
 * @return array
 */
function pcm_redirect_assistant_sanitize_rule( mixed $rule ): array {
	$rule = is_array( $rule ) ? $rule : array();

	$id = isset( $rule['id'] ) ? sanitize_key( $rule['id'] ) : 'rule_' . wp_generate_uuid4();

	return array(
		'id'             => $id,
		'enabled'        => ! empty( $rule['enabled'] ),
		'priority'       => isset( $rule['priority'] ) ? absint( $rule['priority'] ) : 999,
		'match_type'     => isset( $rule['match_type'] ) && in_array( $rule['match_type'], array( 'exact', 'prefix', 'regex' ), true ) ? $rule['match_type'] : 'exact',
		'source_pattern' => isset( $rule['source_pattern'] ) ? sanitize_text_field( $rule['source_pattern'] ) : '/',
		'target_pattern' => isset( $rule['target_pattern'] ) ? esc_url_raw( $rule['target_pattern'] ) : home_url( '/' ),
		'status_code'    => isset( $rule['status_code'] ) ? absint( $rule['status_code'] ) : 301,
		'notes'          => isset( $rule['notes'] ) ? sanitize_text_field( $rule['notes'] ) : '',
		'created_by'     => isset( $rule['created_by'] ) ? absint( $rule['created_by'] ) : get_current_user_id(),
		'updated_at'     => current_time( 'mysql', true ),
	);
}

/**
 * Capability guard for redirect assistant management.
 *
 * @return bool
 */
function pcm_redirect_assistant_can_manage(): bool {
	if ( function_exists( 'pcm_current_user_can' ) ) {
		return pcm_current_user_can( 'pcm_manage_redirect_rules' );
	}

	return current_user_can( 'manage_options' );
}

/**
 * Validate rules for regex safety and wildcard confirmation requirements.
 *
 * @param array $rules Rules.
 * @param bool  $wildcard_confirmed Wildcard confirmation flag.
 *
 * @return array
 */
function pcm_redirect_assistant_validate_rules( array $rules, bool $wildcard_confirmed = false ): array {
	$errors   = array();
	$warnings = array();

	foreach ( $rules as $index => $rule ) {
		$rule   = pcm_redirect_assistant_sanitize_rule( $rule );
		$id     = $rule['id'] ?? 'rule_' . $index;
		$source = (string) ( $rule['source_pattern'] ?? '' );
		$target = (string) ( $rule['target_pattern'] ?? '' );
		$type   = (string) ( $rule['match_type'] ?? 'exact' );

		if ( '' === $target ) {
			$errors[] = array(
				'rule_id' => $id,
				'type'    => 'empty_target',
				'message' => 'Target URL is required.',
			);
		}

		if ( 'regex' === $type ) {
			$regex_valid = pcm_redirect_assistant_safe_preg_match( $source, '/redirect-assistant-test/' );
			if ( false === $regex_valid ) {
				$errors[] = array(
					'rule_id' => $id,
					'type'    => 'invalid_regex',
					'message' => 'Regex pattern failed validation.',
				);
			}

			$looks_wild = str_contains( $source, '.*' ) || str_contains( $source, '.+' ) || str_contains( $source, '(.+)' );
			if ( $looks_wild ) {
				$warnings[] = array(
					'rule_id' => $id,
					'type'    => 'wildcard_regex',
					'message' => 'Regex appears broad and may match many URLs.',
				);
			}
		}

		if ( 'prefix' === $type && '/' === trim( $source ) ) {
			$warnings[] = array(
				'rule_id' => $id,
				'type'    => 'root_prefix',
				'message' => 'Root prefix rule can affect nearly all requests.',
			);
		}
	}

	if ( ! empty( $warnings ) && ! $wildcard_confirmed ) {
		$errors[] = array(
			'rule_id' => 'global',
			'type'    => 'wildcard_confirmation_required',
			'message' => 'Wildcard/regex-like rules require explicit confirmation.',
		);
	}

	return array(
		'is_valid' => empty( $errors ),
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * AJAX: list saved redirect rules.
 *
 * @return void
 */
function pcm_ajax_redirect_assistant_list_rules(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_redirect_assistant', 'pcm_manage_redirect_rules' );
	} else {
		check_ajax_referer( 'pcm_redirect_assistant', 'nonce' );

		if ( ! pcm_redirect_assistant_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$repo = new PCM_Redirect_Assistant_Repository();

	wp_send_json_success( array( 'rules' => $repo->list_rules() ) );
}
add_action( 'wp_ajax_pcm_redirect_assistant_list_rules', 'pcm_ajax_redirect_assistant_list_rules' );

/**
 * AJAX: discover rule candidates from URL input and latest advisor URLs.
 *
 * @return void
 */
function pcm_ajax_redirect_assistant_discover_candidates(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_redirect_assistant', 'pcm_manage_redirect_rules' );
	} else {
		check_ajax_referer( 'pcm_redirect_assistant', 'nonce' );

		if ( ! pcm_redirect_assistant_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$input_urls = filter_input( INPUT_POST, 'urls', FILTER_UNSAFE_RAW );
	$input_urls = is_string( $input_urls ) ? $input_urls : '';
	$urls       = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $input_urls ) ) ) );

	if ( empty( $urls ) ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pcm_scan_urls';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
		$rows = $wpdb->get_col( "SELECT url FROM {$table} ORDER BY id DESC LIMIT 50" );
		$urls = is_array( $rows ) ? $rows : array();
	}

	$discovery  = new PCM_Redirect_Assistant_Candidate_Discovery();
	$candidates = $discovery->discover( $urls );

	wp_send_json_success(
		array(
			'count'      => count( $candidates ),
			'candidates' => $candidates,
		)
	);
}
add_action( 'wp_ajax_pcm_redirect_assistant_discover_candidates', 'pcm_ajax_redirect_assistant_discover_candidates' );

/**
 * AJAX: save rules payload.
 *
 * @return void
 */
function pcm_ajax_redirect_assistant_save_rules(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_redirect_assistant', 'pcm_manage_redirect_rules' );
	} else {
		check_ajax_referer( 'pcm_redirect_assistant', 'nonce' );

		if ( ! pcm_redirect_assistant_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$raw_rules = isset( $_POST['rules'] ) ? (string) wp_unslash( $_POST['rules'] ) : '[]';
	$decoded   = json_decode( $raw_rules, true );
	$rules     = is_array( $decoded ) ? $decoded : array();

	$confirmed  = isset( $_POST['confirm_wildcards'] ) && '1' === (string) wp_unslash( $_POST['confirm_wildcards'] );
	$validation = pcm_redirect_assistant_validate_rules( $rules, $confirmed );

	if ( ! $validation['is_valid'] ) {
		wp_send_json_error(
			array(
				'message'    => 'Rule validation failed.',
				'validation' => $validation,
			),
			400
		);
	}

	$repo  = new PCM_Redirect_Assistant_Repository();
	$saved = array();

	foreach ( $rules as $rule ) {
		$saved[] = $repo->upsert_rule( $rule );
	}

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'redirect_rules_saved', 'redirect_assistant', array( 'rule_count' => count( $saved ) ) );
	}

	wp_send_json_success(
		array(
			'saved_rule_ids' => $saved,
			'validation'     => $validation,
		)
	);
}
add_action( 'wp_ajax_pcm_redirect_assistant_save_rules', 'pcm_ajax_redirect_assistant_save_rules' );

/**
 * AJAX: simulate URLs against current or provided rules.
 *
 * @return void
 */
function pcm_ajax_redirect_assistant_simulate(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_redirect_assistant', 'pcm_manage_redirect_rules' );
	} else {
		check_ajax_referer( 'pcm_redirect_assistant', 'nonce' );

		if ( ! pcm_redirect_assistant_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$raw_urls = isset( $_POST['urls'] ) ? (string) wp_unslash( $_POST['urls'] ) : '';
	$urls     = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw_urls ) ) ) );

	$repo  = new PCM_Redirect_Assistant_Repository();
	$rules = $repo->list_rules();

	$raw_rules = isset( $_POST['rules'] ) ? (string) wp_unslash( $_POST['rules'] ) : '';
	if ( '' !== trim( $raw_rules ) ) {
		$decoded = json_decode( $raw_rules, true );
		if ( is_array( $decoded ) ) {
			$rules = array_values( array_map( 'pcm_redirect_assistant_sanitize_rule', $decoded ) );
		}
	}

	$sim       = new PCM_Redirect_Assistant_Simulation_Engine();
	$results   = $sim->simulate_batch( $urls, $rules );
	$conflicts = $sim->detect_conflicts( $rules );

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'redirect_rules_simulated', 'redirect_assistant', array( 'url_count' => count( $urls ) ) );
	}

	wp_send_json_success(
		array(
			'results'   => $results,
			'conflicts' => $conflicts,
		)
	);
}
add_action( 'wp_ajax_pcm_redirect_assistant_simulate', 'pcm_ajax_redirect_assistant_simulate' );

/**
 * AJAX: export rules with syntax + conflict checks.
 *
 * @return void
 */
function pcm_ajax_redirect_assistant_export(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_redirect_assistant', 'pcm_manage_redirect_rules' );
	} else {
		check_ajax_referer( 'pcm_redirect_assistant', 'nonce' );

		if ( ! pcm_redirect_assistant_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$repo      = new PCM_Redirect_Assistant_Repository();
	$rules     = $repo->list_rules();
	$confirmed = isset( $_POST['confirm_wildcards'] ) && '1' === (string) wp_unslash( $_POST['confirm_wildcards'] );

	$validation = pcm_redirect_assistant_validate_rules( $rules, $confirmed );
	if ( ! $validation['is_valid'] ) {
		wp_send_json_error(
			array(
				'message'    => 'Export blocked by validation guardrails.',
				'validation' => $validation,
			),
			400
		);
	}

	$sim       = new PCM_Redirect_Assistant_Simulation_Engine();
	$conflicts = $sim->detect_conflicts( $rules );

	$exporter = new PCM_Redirect_Assistant_Exporter();
	$export   = $exporter->build_export( $rules );

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'redirect_rules_exported', 'redirect_assistant', array( 'rule_count' => count( $rules ) ) );
	}

	wp_send_json_success(
		array(
			'export'     => $export,
			'meta_json'  => wp_json_encode( $export['meta'] ?? array() ),
			'conflicts'  => $conflicts,
			'validation' => $validation,
		)
	);
}
add_action( 'wp_ajax_pcm_redirect_assistant_export', 'pcm_ajax_redirect_assistant_export' );

/**
 * AJAX: import rules from prior export payload JSON.
 *
 * @return void
 */
function pcm_ajax_redirect_assistant_import(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_redirect_assistant', 'pcm_manage_redirect_rules' );
	} else {
		check_ajax_referer( 'pcm_redirect_assistant', 'nonce' );

		if ( ! pcm_redirect_assistant_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$raw_payload = isset( $_POST['payload'] ) ? (string) wp_unslash( $_POST['payload'] ) : '';
	$decoded     = json_decode( $raw_payload, true );

	if ( ! is_array( $decoded ) ) {
		wp_send_json_error( array( 'message' => 'Payload must be valid JSON export metadata.' ), 400 );
	}

	$rules = isset( $decoded['rules'] ) && is_array( $decoded['rules'] ) ? $decoded['rules'] : array();
	if ( empty( $rules ) ) {
		wp_send_json_error( array( 'message' => 'No rules found in import payload.' ), 400 );
	}

	$repo = new PCM_Redirect_Assistant_Repository();

	$saved = array();
	foreach ( $rules as $rule ) {
		$saved[] = $repo->upsert_rule( $rule );
	}

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'redirect_rules_imported', 'redirect_assistant', array( 'rule_count' => count( $saved ) ) );
	}

	wp_send_json_success(
		array(
			'imported_rule_ids' => $saved,
			'rule_count'        => count( $saved ),
		)
	);
}
add_action( 'wp_ajax_pcm_redirect_assistant_import', 'pcm_ajax_redirect_assistant_import' );
