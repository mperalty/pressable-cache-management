<?php
/**
 * Cacheability Advisor - Repository.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for Cacheability Advisor run lifecycle and findings.
 */
class PCM_Cacheability_Advisor_Repository {
	/**
	 * Ensure all required tables and columns exist, creating or repairing them if needed.
	 *
	 * @return bool True if all required schema exists after the check.
	 */
	public function ensure_tables_exist(): bool {
		$required_schema = $this->required_schema();
		$needs_repair    = PCM_CACHEABILITY_ADVISOR_DB_VERSION !== (string) get_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, '' );

		foreach ( $required_schema as $table => $columns ) {
			if ( ! $this->table_exists( $table ) || ! $this->table_has_required_columns( $table, $columns ) ) {
				$needs_repair = true;
				break;
			}
		}

		if ( $needs_repair ) {
			pcm_cacheability_advisor_log_message( 'PCM Cacheability Advisor: schema check detected missing tables or columns; attempting repair.' );
			pcm_cacheability_advisor_install_tables();
			update_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, PCM_CACHEABILITY_ADVISOR_DB_VERSION, false );
		}

		foreach ( $required_schema as $table => $columns ) {
			if ( ! $this->table_exists( $table ) || ! $this->table_has_required_columns( $table, $columns ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Required Cacheability Advisor schema by table.
	 *
	 * @return array<string, array<int, string>>
	 */
	protected function required_schema(): array {
		global $wpdb;

		return array(
			$wpdb->prefix . 'pcm_scan_runs'       => array( 'id', 'started_at', 'finished_at', 'status', 'sample_count', 'initiated_by', 'created_at' ),
			$wpdb->prefix . 'pcm_scan_urls'       => array( 'id', 'run_id', 'url', 'url_hash', 'template_type', 'status_code', 'score', 'diagnosis_json', 'created_at' ),
			$wpdb->prefix . 'pcm_findings'        => array( 'id', 'run_id', 'url', 'url_hash', 'rule_id', 'severity', 'evidence_json', 'recommendation_id', 'created_at' ),
			$wpdb->prefix . 'pcm_template_scores' => array( 'id', 'run_id', 'template_type', 'score', 'url_count', 'created_at' ),
		);
	}

	/**
	 * Determine whether a plugin-owned table exists.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW TABLES LIKE is used only for plugin-owned schema verification.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return ! empty( $exists );
	}

	/**
	 * Determine whether a plugin-owned table includes all required columns.
	 *
	 * @param string            $table Table name.
	 * @param array<int,string> $required_columns Required column names.
	 *
	 * @return bool
	 */
	protected function table_has_required_columns( string $table, array $required_columns ): bool {
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
	 * Create a scan run.
	 *
	 * @param int $initiated_by User ID who started the run.
	 * @param int $sample_count Number of URLs sampled.
	 *
	 * @return int|false Run ID or false on failure.
	 */
	public function create_run( int $initiated_by = 0, int $sample_count = 0 ): int|false {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_runs';
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'started_at'   => $now,
				'status'       => 'running',
				'sample_count' => absint( $sample_count ),
				'initiated_by' => absint( $initiated_by ),
				'created_at'   => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update run completion state.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $status pending|running|completed|failed
	 *
	 * @return bool
	 */
	public function complete_run( int $run_id, string $status = 'completed' ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_runs';
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->update(
			$table,
			array(
				'status'      => sanitize_key( $status ),
				'finished_at' => $now,
			),
			array( 'id' => absint( $run_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Add URL score row.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $url URL probed.
	 * @param string $template_type Template grouping.
	 * @param int    $status_code HTTP status code.
	 * @param int    $score URL score.
	 * @param array  $diagnosis Diagnosis payload.
	 *
	 * @return int|false
	 */
	public function add_url_result( int $run_id, string $url, string $template_type, int $status_code = 0, int $score = 0, array $diagnosis = array() ): int|false {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_urls';
		$now   = current_time( 'mysql', true );

		$safe_url = esc_url_raw( $url );
		$inserted = $wpdb->insert(
			$table,
			array(
				'run_id'         => absint( $run_id ),
				'url'            => $safe_url,
				'url_hash'       => md5( $safe_url ),
				'template_type'  => sanitize_key( $template_type ),
				'status_code'    => absint( $status_code ),
				'score'          => max( 0, min( 100, absint( $score ) ) ),
				'diagnosis_json' => wp_json_encode( $diagnosis ),
				'created_at'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Add finding row.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $url URL where finding was detected.
	 * @param string $rule_id Rule identifier.
	 * @param string $severity critical|warning|opportunity.
	 * @param array  $evidence Associative evidence payload.
	 * @param string $recommendation_id Recommendation key.
	 *
	 * @return int|false
	 */
	public function add_finding( int $run_id, string $url, string $rule_id, string $severity, array $evidence = array(), string $recommendation_id = '' ): int|false {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_findings';
		$now   = current_time( 'mysql', true );

		$safe_url = esc_url_raw( $url );
		$inserted = $wpdb->insert(
			$table,
			array(
				'run_id'            => absint( $run_id ),
				'url'               => $safe_url,
				'url_hash'          => md5( $safe_url ),
				'rule_id'           => sanitize_key( $rule_id ),
				'severity'          => sanitize_key( $severity ),
				'evidence_json'     => wp_json_encode( $evidence ),
				'recommendation_id' => sanitize_key( $recommendation_id ),
				'created_at'        => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Persist template score for trend history.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $template_type Template type.
	 * @param int    $score Averaged score.
	 * @param int    $url_count URL count used.
	 *
	 * @return int|false
	 */
	public function add_template_score( int $run_id, string $template_type, int $score, int $url_count ): int|false {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_template_scores';
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'run_id'        => absint( $run_id ),
				'template_type' => sanitize_key( $template_type ),
				'score'         => max( 0, min( 100, absint( $score ) ) ),
				'url_count'     => max( 0, absint( $url_count ) ),
				'created_at'    => $now,
			),
			array( '%d', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a run by ID.
	 *
	 * @param int $run_id Run ID.
	 *
	 * @return array|null
	 */
	public function get_run( int $run_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_runs';

		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $run_id ) ),
			ARRAY_A
		);
	}

	/**
	 * List recent runs.
	 *
	 * @param int $limit Result size.
	 *
	 * @return array
	 */
	public function list_runs( int $limit = 10 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_runs';
		$limit = max( 1, min( 100, absint( $limit ) ) );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List URL results for a run.
	 *
	 * @param int $run_id Run ID.
	 *
	 * @return array
	 */
	public function list_url_results( int $run_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_urls';

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", absint( $run_id ) ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$decoded                     = ! empty( $row['diagnosis_json'] ) ? json_decode( $row['diagnosis_json'], true ) : array();
			$rows[ $index ]['diagnosis'] = is_array( $decoded ) ? $decoded : array();
			unset( $rows[ $index ]['diagnosis_json'] );
		}

		return $rows;
	}

	/**
	 * List findings for a run.
	 *
	 * @param int $run_id Run ID.
	 *
	 * @return array
	 */
	public function list_findings( int $run_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_findings';

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", absint( $run_id ) ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$decoded                    = ! empty( $row['evidence_json'] ) ? json_decode( $row['evidence_json'], true ) : array();
			$rows[ $index ]['evidence'] = is_array( $decoded ) ? $decoded : array();
			unset( $rows[ $index ]['evidence_json'] );
		}

		return $rows;
	}

	/**
	 * List template trends.
	 *
	 * @param string $range 24h|7d|30d.
	 *
	 * @return array
	 */
	public function list_template_trends( string $range = '7d' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_template_scores';

		$days_by_range = array(
			'24h' => 1,
			'7d'  => 7,
			'30d' => 30,
		);

		$days   = isset( $days_by_range[ $range ] ) ? $days_by_range[ $range ] : 7;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
				"SELECT run_id, template_type, score, url_count, created_at FROM {$table} WHERE created_at >= %s ORDER BY created_at ASC, id ASC",
				$cutoff
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch URL diagnosis detail for a run + URL.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $url URL.
	 *
	 * @return array|null
	 */
	public function get_url_diagnosis( int $run_id, string $url ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'pcm_scan_urls';

		$safe_url = esc_url_raw( $url );
		$row      = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
				"SELECT id, run_id, url, template_type, status_code, score, diagnosis_json, created_at FROM {$table} WHERE run_id = %d AND url_hash = %s AND url = %s ORDER BY id DESC LIMIT 1",
				absint( $run_id ),
				md5( $safe_url ),
				$safe_url
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$decoded          = ! empty( $row['diagnosis_json'] ) ? json_decode( $row['diagnosis_json'], true ) : array();
		$row['diagnosis'] = is_array( $decoded ) ? $decoded : array();
		unset( $row['diagnosis_json'] );

		return $row;
	}
}
