<?php
/**
 * Observability Reporting - WP-CLI command.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI reporting commands (A7.4).
 */
class PCM_Reporting_CLI_Command {
	/**
	 * Trigger daily rollup.
	 *
	 * ## EXAMPLES
	 *     wp pcm-report rollup
	 */
	public function rollup(): void {
		pcm_reporting_daily_rollup();
		\WP_CLI::success( 'Daily rollup executed.' );
	}

	/**
	 * Show trend rows.
	 *
	 * ## OPTIONS
	 * [--range=<range>]
	 * : 24h, 7d, or 30d.
	 *
	 * ## EXAMPLES
	 *     wp pcm-report trends --range=7d
	 */
	public function trends( array $args, array $assoc_args ): void {
		unset( $args );

		$range   = isset( $assoc_args['range'] ) ? sanitize_key( $assoc_args['range'] ) : '7d';
		$service = new PCM_Metric_Rollup_Service();
		$rows    = $service->query_trends( $range );

		\WP_CLI::line( wp_json_encode( $rows ) );
	}

	/**
	 * Export reporting rows.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : json or csv
	 * [--range=<range>]
	 * : 24h, 7d, or 30d.
	 *
	 * ## EXAMPLES
	 *     wp pcm-report export --format=csv --range=7d
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? sanitize_key( $assoc_args['format'] ) : 'json';
		$range  = isset( $assoc_args['range'] ) ? sanitize_key( $assoc_args['range'] ) : '7d';

		$service = new PCM_Report_Export_Service();
		$result  = $service->export( $format, $range );

		if ( empty( $result['success'] ) ) {
			\WP_CLI::error( 'Export failed: ' . ( $result['error'] ?? 'unknown' ) );
			return;
		}

		\WP_CLI::line( isset( $result['content'] ) ? (string) $result['content'] : '' );
	}
}
