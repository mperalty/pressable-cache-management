<?php
/**
 * Observability Reporting - Report export service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export service for JSON / CSV with capability checks and basic redaction.
 */
class PCM_Report_Export_Service {
	protected readonly PCM_Metric_Rollup_Service $rollup_service;

	public function __construct(
		?PCM_Metric_Rollup_Service $rollup_service = null,
	) {
		$this->rollup_service = $rollup_service ?? new PCM_Metric_Rollup_Service();
	}

	/**
	 * @param string $format json|csv
	 * @param string $range Date range key.
	 * @param array  $metric_keys Filters.
	 *
	 * @return array
	 */
	public function export( string $format = 'json', string $range = '7d', array $metric_keys = array() ): array {
		if ( function_exists( 'pcm_get_privacy_settings' ) ) {
			$privacy = pcm_get_privacy_settings();
			if ( 'admin_only' === $privacy['export_restrictions'] && ! current_user_can( 'manage_options' ) ) {
				return array(
					'success' => false,
					'error'   => 'permission_denied',
				);
			}
		}

		if ( function_exists( 'pcm_current_user_can' ) ) {
			$can_export = pcm_current_user_can( 'pcm_export_reports' );
		} else {
			$can_export = current_user_can( 'manage_options' );
		}

		if ( ! $can_export ) {
			return array(
				'success' => false,
				'error'   => 'permission_denied',
			);
		}

		$rows = $this->rollup_service->query_trends( $range, $metric_keys );
		$rows = $this->redact_rows( $rows );

		if ( 'csv' === $format ) {
			return array(
				'success' => true,
				'format'  => 'csv',
				'content' => $this->to_csv( $rows ),
			);
		}

		return array(
			'success' => true,
			'format'  => 'json',
			'content' => wp_json_encode( $rows ),
		);
	}

	/**
	 * @param array $rows Rows.
	 *
	 * @return array
	 */
	protected function redact_rows( array $rows ): array {
		foreach ( $rows as $index => $row ) {
			$dimensions = isset( $row['dimensions_json'] ) ? (array) $row['dimensions_json'] : array();

			if ( function_exists( 'pcm_privacy_redact_value' ) ) {
				$rows[ $index ]['dimensions_json'] = pcm_privacy_redact_value( $dimensions );
				continue;
			}

			if ( isset( $dimensions['email'] ) ) {
				unset( $dimensions['email'] );
			}
			if ( isset( $dimensions['user_login'] ) ) {
				unset( $dimensions['user_login'] );
			}

			$rows[ $index ]['dimensions_json'] = $dimensions;
		}

		return $rows;
	}

	/**
	 * @param array $rows Rows.
	 *
	 * @return string
	 */
	protected function to_csv( array $rows ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is used to build a CSV stream for fputcsv.
		$fp = fopen( 'php://temp', 'r+' );
		fputcsv( $fp, array( 'metric_key', 'bucket_start', 'bucket_size', 'value', 'dimensions_json' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$fp,
				array(
					$row['metric_key'] ?? '',
					$row['bucket_start'] ?? '',
					$row['bucket_size'] ?? '',
					$row['value'] ?? '',
					wp_json_encode( $row['dimensions_json'] ?? array() ),
				)
			);
		}

		rewind( $fp );
		$csv = stream_get_contents( $fp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temporary CSV stream releases the native resource.
		fclose( $fp );

		return (string) $csv;
	}
}
