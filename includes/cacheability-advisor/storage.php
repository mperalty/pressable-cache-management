<?php
/**
 * Cacheability Advisor storage + services.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION' ) ) {
	define( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION', '1.4.0' );
}

$pcm_cacheability_advisor_dir = plugin_dir_path( __FILE__ );

require_once $pcm_cacheability_advisor_dir . 'class-pcm-cacheability-advisor-repository.php';
require_once $pcm_cacheability_advisor_dir . 'class-pcm-cacheability-probe-client.php';
require_once $pcm_cacheability_advisor_dir . 'class-pcm-cacheability-rule-engine.php';
require_once $pcm_cacheability_advisor_dir . 'class-pcm-cacheability-decision-tracer.php';
require_once $pcm_cacheability_advisor_dir . 'class-pcm-cacheability-url-sampler.php';
require_once $pcm_cacheability_advisor_dir . 'class-pcm-cacheability-advisor-run-service.php';

/**
 * Feature flag for cacheability advisor.
 *
 * Default is disabled to keep rollout safe in WPCloud production sites.
 * Enable through:
 * add_filter( 'pcm_enable_cacheability_advisor', '__return_true' );
 *
 * @return bool
 */
function pcm_cacheability_advisor_is_enabled(): bool {
	static $cached = null;
	if ( null === $cached ) {
		$enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
		$cached  = (bool) apply_filters( 'pcm_enable_cacheability_advisor', $enabled );
	}

	return $cached;
}

if ( ! function_exists( 'pcm_popular_url_hits_table_name' ) ) {
	/**
	 * Get the popular URL hit tracker table name.
	 *
	 * @return string
	 */
	function pcm_popular_url_hits_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'pcm_popular_url_hits';
	}
}

/**
 * Required Cacheability Advisor schema by table.
 *
 * @return array<string, array<int, string>>
 */
function pcm_cacheability_advisor_required_schema(): array {
	global $wpdb;

	return array(
		$wpdb->prefix . 'pcm_scan_runs'       => array( 'id', 'started_at', 'finished_at', 'status', 'sample_count', 'initiated_by', 'created_at' ),
		$wpdb->prefix . 'pcm_scan_urls'       => array( 'id', 'run_id', 'url', 'url_hash', 'template_type', 'status_code', 'score', 'diagnosis_json', 'created_at' ),
		$wpdb->prefix . 'pcm_findings'        => array( 'id', 'run_id', 'url', 'url_hash', 'rule_id', 'severity', 'evidence_json', 'recommendation_id', 'created_at' ),
		$wpdb->prefix . 'pcm_template_scores' => array( 'id', 'run_id', 'template_type', 'score', 'url_count', 'created_at' ),
		pcm_popular_url_hits_table_name()     => array( 'id', 'post_id', 'url', 'url_hash', 'hit_date', 'view_count', 'last_seen_at', 'created_at' ),
	);
}

/**
 * Determine whether a plugin-owned table exists.
 *
 * @param string $table Table name.
 *
 * @return bool
 */
function pcm_cacheability_advisor_table_exists( string $table ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW TABLES LIKE is used only for plugin-owned schema verification.
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	return ! empty( $exists );
}

/**
 * Determine whether a table contains all required columns.
 *
 * @param string            $table Table name.
 * @param array<int,string> $required_columns Required column names.
 *
 * @return bool
 */
function pcm_cacheability_advisor_table_has_required_columns( string $table, array $required_columns ): bool {
	global $wpdb;

	foreach ( $required_columns as $column ) {
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WordPress prefix plus a plugin-owned suffix.
				"SHOW COLUMNS FROM {$table} LIKE %s",
				$column
			)
		);

		if ( empty( $exists ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Determine whether the full Cacheability Advisor schema requires repair.
 *
 * @return bool
 */
function pcm_cacheability_advisor_schema_requires_repair(): bool {
	$current_version = (string) get_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, '' );
	if ( PCM_CACHEABILITY_ADVISOR_DB_VERSION !== $current_version ) {
		return true;
	}

	foreach ( pcm_cacheability_advisor_required_schema() as $table => $columns ) {
		if ( ! pcm_cacheability_advisor_table_exists( $table ) || ! pcm_cacheability_advisor_table_has_required_columns( $table, $columns ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Ensure schema is up to date when feature is enabled.
 *
 * @return void
 */
function pcm_cacheability_advisor_maybe_migrate(): void {
	if ( ! pcm_cacheability_advisor_is_enabled() ) {
		return;
	}

	if ( ! is_admin() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! pcm_cacheability_advisor_schema_requires_repair() ) {
		return;
	}

	pcm_cacheability_advisor_install_tables();
	update_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, PCM_CACHEABILITY_ADVISOR_DB_VERSION, false );
}
add_action( 'admin_init', 'pcm_cacheability_advisor_maybe_migrate' );

/**
 * Retention cleanup — delete scan data older than 90 days.
 *
 * Runs daily via WP-Cron to prevent unbounded table growth.
 */
function pcm_cacheability_advisor_retention_cleanup(): void {
	global $wpdb;

	$cutoff_datetime = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
	$cutoff_date     = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

	$runs_table = $wpdb->prefix . 'pcm_scan_runs';
	if ( pcm_cacheability_advisor_table_exists( $runs_table ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
		$old_run_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
				"SELECT id FROM {$runs_table} WHERE created_at < %s LIMIT 50",
				$cutoff_datetime
			)
		);

		if ( ! empty( $old_run_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $old_run_ids ), '%d' ) );

			foreach ( array( 'pcm_scan_urls', 'pcm_findings', 'pcm_template_scores' ) as $child_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin-owned table name with dynamic placeholder list.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$child_table} WHERE run_id IN ({$id_placeholders})", ...$old_run_ids ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Plugin-owned table name with dynamic placeholder list.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs_table} WHERE id IN ({$id_placeholders})", ...$old_run_ids ) );
		}
	}

	$popular_hits_table = pcm_popular_url_hits_table_name();
	if ( function_exists( 'pcm_popular_url_tracker_cleanup' ) ) {
		pcm_popular_url_tracker_cleanup( 90 );
	} elseif ( pcm_cacheability_advisor_table_exists( $popular_hits_table ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
				"DELETE FROM {$popular_hits_table} WHERE hit_date < %s",
				$cutoff_date
			)
		);
	}
}
add_action( 'pcm_cacheability_retention_cleanup', 'pcm_cacheability_advisor_retention_cleanup' );

/**
 * Schedule daily retention cleanup.
 */
function pcm_cacheability_advisor_schedule_cleanup(): void {
	if ( ! pcm_cacheability_advisor_is_enabled() ) {
		return;
	}
	if ( ! wp_next_scheduled( 'pcm_cacheability_retention_cleanup' ) ) {
		wp_schedule_event( time() + 300, 'daily', 'pcm_cacheability_retention_cleanup' );
	}
}
add_action( 'init', 'pcm_cacheability_advisor_schedule_cleanup' );

/**
 * Install or update plugin tables.
 *
 * @return void
 */
function pcm_cacheability_advisor_install_tables(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$runs_table             = $wpdb->prefix . 'pcm_scan_runs';
	$urls_table             = $wpdb->prefix . 'pcm_scan_urls';
	$findings_table         = $wpdb->prefix . 'pcm_findings';
	$template_scores_table  = $wpdb->prefix . 'pcm_template_scores';
	$popular_hits_table     = pcm_popular_url_hits_table_name();

	$sql_runs = "CREATE TABLE {$runs_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        started_at datetime DEFAULT NULL,
        finished_at datetime DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        sample_count int(11) unsigned NOT NULL DEFAULT 0,
        initiated_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY started_at (started_at)
    ) {$charset_collate};";

	$sql_urls = "CREATE TABLE {$urls_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        url text NOT NULL,
        url_hash char(32) NOT NULL DEFAULT '',
        template_type varchar(50) NOT NULL,
        status_code smallint(5) unsigned DEFAULT NULL,
        score int(3) unsigned DEFAULT NULL,
        diagnosis_json longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY run_id (run_id),
        KEY run_url_hash (run_id, url_hash),
        KEY template_type (template_type),
        KEY score (score)
    ) {$charset_collate};";

	$sql_findings = "CREATE TABLE {$findings_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        url text NOT NULL,
        url_hash char(32) NOT NULL DEFAULT '',
        rule_id varchar(100) NOT NULL,
        severity varchar(20) NOT NULL,
        evidence_json longtext DEFAULT NULL,
        recommendation_id varchar(100) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY run_id (run_id),
        KEY run_url_hash (run_id, url_hash),
        KEY rule_id (rule_id),
        KEY severity (severity)
    ) {$charset_collate};";

	$sql_template_scores = "CREATE TABLE {$template_scores_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        template_type varchar(50) NOT NULL,
        score int(3) unsigned NOT NULL DEFAULT 0,
        url_count int(11) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY run_id (run_id),
        KEY template_type (template_type),
        KEY created_at (created_at)
    ) {$charset_collate};";

	$sql_popular_hits = "CREATE TABLE {$popular_hits_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        url varchar(255) NOT NULL,
        url_hash char(32) NOT NULL DEFAULT '',
        hit_date date NOT NULL,
        view_count int(11) unsigned NOT NULL DEFAULT 0,
        last_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY post_day (post_id, hit_date),
        KEY hit_date (hit_date),
        KEY url_hash (url_hash),
        KEY last_seen_at (last_seen_at)
    ) {$charset_collate};";

	dbDelta( $sql_runs );
	dbDelta( $sql_urls );
	dbDelta( $sql_findings );
	dbDelta( $sql_template_scores );
	dbDelta( $sql_popular_hits );

	delete_transient( 'pcm_popular_url_tracker_schema_ready' );
}

/**
 * Log Cacheability Advisor operational failures.
 *
 * @param string $message Message to log.
 *
 * @return void
 */
function pcm_cacheability_advisor_log_message( string $message ): void {
	if ( '' === $message ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failures are logged for async scan diagnostics and database setup issues.
	error_log( $message );
}

/**
 * Shared permission guard for Cacheability Advisor AJAX endpoints.
 *
 * @return bool
 */
function pcm_cacheability_advisor_ajax_can_manage(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * AJAX: Start an advisor scan run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_start(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_run_scans' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	if ( ! pcm_cacheability_advisor_is_enabled() ) {
		wp_send_json_error( array( 'message' => 'Cacheability Advisor is disabled.' ), 400 );
	}

	if ( ! (bool) get_option( PCM_Options::ENABLE_ADVANCED_SCAN_WORKFLOWS->value, false ) ) {
		wp_send_json_error( array( 'message' => 'Cacheability rescans are disabled. Enable them in Settings > Deep Dive Options.' ), 400 );
	}

	$service = new PCM_Cacheability_Advisor_Run_Service();
	$run_id  = $service->start_scan();

	if ( ! $run_id ) {
		wp_send_json_error( array( 'message' => 'Unable to create scan run.' ), 500 );
	}

	$queue = get_transient( 'pcm_scan_queue_' . $run_id );

	wp_send_json_success(
		array(
			'run_id'    => (int) $run_id,
			'status'    => 'queued',
			'remaining' => is_array( $queue ) ? count( $queue ) : 0,
		)
	);
}
add_action( 'wp_ajax_pcm_cacheability_scan_start', 'pcm_ajax_cacheability_scan_start' );

/**
 * AJAX: Process the next queued URL for a scan run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_process_next(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_run_scans' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
	if ( $run_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Missing run_id.' ), 400 );
	}

	$service = new PCM_Cacheability_Advisor_Run_Service();

	try {
		$result = $service->process_next( $run_id );
	} catch ( \Throwable $e ) {
		pcm_cacheability_advisor_log_message( 'PCM Cacheability scan failed for run ' . $run_id . ': ' . $e->getMessage() );
		$service->mark_failed( $run_id );
		wp_send_json_error( array( 'message' => 'Scan processing failed.' ), 500 );
	}

	wp_send_json_success(
		array(
			'run_id'    => $run_id,
			'done'      => $result['done'],
			'remaining' => $result['remaining'],
			'stored'    => $result['stored'],
		)
	);
}
add_action( 'wp_ajax_pcm_cacheability_scan_process_next', 'pcm_ajax_cacheability_scan_process_next' );

/**
 * AJAX: Get scan run status.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_status(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;

	$repository = new PCM_Cacheability_Advisor_Repository();
	$run        = $run_id ? $repository->get_run( $run_id ) : null;

	if ( ! $run ) {
		$runs = $repository->list_runs( 1 );
		$run  = ! empty( $runs ) ? $runs[0] : null;
	}

	if ( ! $run ) {
		wp_send_json_success( array( 'run' => null ) );
	}

	wp_send_json_success( array( 'run' => $run ) );
}
add_action( 'wp_ajax_pcm_cacheability_scan_status', 'pcm_ajax_cacheability_scan_status' );

/**
 * AJAX: Get findings for a run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_findings(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
	if ( $run_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Missing run_id.' ), 400 );
	}

	$repository = new PCM_Cacheability_Advisor_Repository();
	$findings   = $repository->list_findings( $run_id );

	wp_send_json_success(
		array(
			'run_id'   => $run_id,
			'findings' => $findings,
		)
	);
}
add_action( 'wp_ajax_pcm_cacheability_scan_findings', 'pcm_ajax_cacheability_scan_findings' );

/**
 * AJAX: Get URL results for a run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_results(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
	if ( $run_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Missing run_id.' ), 400 );
	}

	$repository = new PCM_Cacheability_Advisor_Repository();
	$results    = $repository->list_url_results( $run_id );

	wp_send_json_success(
		array(
			'run_id'  => $run_id,
			'results' => $results,
		)
	);
}
add_action( 'wp_ajax_pcm_cacheability_scan_results', 'pcm_ajax_cacheability_scan_results' );

/**
 * AJAX: Get template trends.
 *
 * @return void
 */
function pcm_ajax_cacheability_template_trends(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$range      = isset( $_REQUEST['range'] ) ? sanitize_key( wp_unslash( $_REQUEST['range'] ) ) : '7d';
	$repository = new PCM_Cacheability_Advisor_Repository();

	wp_send_json_success(
		array(
			'range'  => $range,
			'trends' => $repository->list_template_trends( $range ),
		)
	);
}
add_action( 'wp_ajax_pcm_cacheability_template_trends', 'pcm_ajax_cacheability_template_trends' );

/**
 * AJAX: Get route diagnosis for a run + URL.
 *
 * @return void
 */
function pcm_ajax_cacheability_route_diagnosis(): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

		if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
	$url    = isset( $_REQUEST['url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['url'] ) ) : '';

	if ( $run_id <= 0 || '' === $url ) {
		wp_send_json_error( array( 'message' => 'Missing run_id or url.' ), 400 );
	}

	$repository = new PCM_Cacheability_Advisor_Repository();
	$diagnosis  = $repository->get_url_diagnosis( $run_id, $url );

	if ( ! $diagnosis ) {
		wp_send_json_success(
			array(
				'run_id'    => $run_id,
				'url'       => $url,
				'available' => false,
				'message'   => 'No route diagnosis is available for this URL yet. Run a fresh scan to populate diagnosis details.',
			)
		);
	}

	$findings_for_url = array_values(
		array_filter(
			(array) $repository->list_findings( $run_id ),
			function ( $finding ) use ( $url ) {
				return isset( $finding['url'] ) && esc_url_raw( $finding['url'] ) === esc_url_raw( $url );
			}
		)
	);

	$diagnosis = pcm_cacheability_enrich_diagnosis_from_findings( $diagnosis, $findings_for_url );

	wp_send_json_success(
		array(
			'run_id'    => $run_id,
			'url'       => $url,
			'available' => true,
			'diagnosis' => $diagnosis,
		)
	);
}
add_action( 'wp_ajax_pcm_cacheability_route_diagnosis', 'pcm_ajax_cacheability_route_diagnosis' );

/**
 * Backfill route diagnosis hints from findings when diagnosis trace is unavailable.
 *
 * @param array $diagnosis Route diagnosis payload.
 * @param array $findings Findings for the same run + URL.
 *
 * @return array
 */
function pcm_cacheability_enrich_diagnosis_from_findings( array $diagnosis, array $findings ): array {
	$decoded = isset( $diagnosis['diagnosis'] ) && is_array( $diagnosis['diagnosis'] ) ? $diagnosis['diagnosis'] : array();
	$trace   = isset( $decoded['decision_trace'] ) && is_array( $decoded['decision_trace'] ) ? $decoded['decision_trace'] : array();

	$trace = wp_parse_args(
		$trace,
		array(
			'edge_bypass_reasons'     => array(),
			'batcache_bypass_reasons' => array(),
			'poisoning_signals'       => array(),
			'route_risk_labels'       => array(),
		)
	);

	foreach ( (array) $findings as $finding ) {
		$rule_id  = isset( $finding['rule_id'] ) ? sanitize_key( $finding['rule_id'] ) : '';
		$evidence = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
		$headers  = isset( $evidence['headers'] ) && is_array( $evidence['headers'] ) ? $evidence['headers'] : array();

		if ( 'anonymous_set_cookie' === $rule_id && isset( $headers['set-cookie'] ) ) {
			$set_cookie_lines = is_array( $headers['set-cookie'] ) ? $headers['set-cookie'] : array( $headers['set-cookie'] );

			if ( ! pcm_cacheability_trace_has_reason( $trace['batcache_bypass_reasons'], 'set_cookie_present' ) ) {
				$trace['batcache_bypass_reasons'][] = array(
					'reason'   => 'set_cookie_present',
					'evidence' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $set_cookie_lines ) ) ), 0, 5 ),
				);
			}

			foreach ( $set_cookie_lines as $line ) {
				$line  = sanitize_text_field( (string) $line );
				$parts = explode( '=', $line, 2 );
				$name  = sanitize_key( strtolower( trim( $parts[0] ) ) );

				if ( '' === $name ) {
					continue;
				}

				if ( ! pcm_cacheability_trace_has_poison_signal( $trace['poisoning_signals'], 'cookie', $name ) ) {
					$trace['poisoning_signals'][] = array(
						'type'     => 'cookie',
						'key'      => $name,
						'evidence' => $line,
					);
				}
			}
		}

		if ( 'cache_control_not_public' === $rule_id && isset( $headers['cache-control'] ) ) {
			$value = is_array( $headers['cache-control'] ) ? implode( ', ', $headers['cache-control'] ) : (string) $headers['cache-control'];
			if ( ! pcm_cacheability_trace_has_reason( $trace['edge_bypass_reasons'], 'cache_control_non_public' ) ) {
				$trace['edge_bypass_reasons'][] = array(
					'reason'   => 'cache_control_non_public',
					'evidence' => sanitize_text_field( $value ),
				);
			}
		}
	}

	if ( ! empty( $trace['batcache_bypass_reasons'] ) && ! pcm_cacheability_trace_has_risk_label( $trace['route_risk_labels'], 'fragile' ) ) {
		$trace['route_risk_labels'][] = array(
			'label'    => 'fragile',
			'evidence' => 'Bypass indicators detected in route findings',
		);
	}

	$decoded['decision_trace'] = $trace;
	$diagnosis['diagnosis']    = $decoded;

	return $diagnosis;
}

/**
 * @param array  $reasons Reason list.
 * @param string $reason  Reason key.
 *
 * @return bool
 */
function pcm_cacheability_trace_has_reason( array $reasons, string $reason ): bool {
	foreach ( (array) $reasons as $row ) {
		if ( isset( $row['reason'] ) && sanitize_key( (string) $row['reason'] ) === sanitize_key( $reason ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param array  $signals Signal list.
 * @param string $type    Signal type.
 * @param string $key     Signal key.
 *
 * @return bool
 */
function pcm_cacheability_trace_has_poison_signal( array $signals, string $type, string $key ): bool {
	foreach ( (array) $signals as $signal ) {
		if ( isset( $signal['type'], $signal['key'] )
			&& sanitize_key( (string) $signal['type'] ) === sanitize_key( $type )
			&& sanitize_key( (string) $signal['key'] ) === sanitize_key( $key ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param array  $labels Label list.
 * @param string $label  Label key.
 *
 * @return bool
 */
function pcm_cacheability_trace_has_risk_label( array $labels, string $label ): bool {
	foreach ( (array) $labels as $item ) {
		if ( isset( $item['label'] ) && sanitize_key( (string) $item['label'] ) === sanitize_key( $label ) ) {
			return true;
		}
	}

	return false;
}
