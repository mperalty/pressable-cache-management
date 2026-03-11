<?php
/**
 * Observability Reporting - Report digest service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly digest scheduler and sender.
 */
class PCM_Report_Digest_Service {
	protected readonly PCM_Metric_Rollup_Service $rollup_service;

	public function __construct(
		?PCM_Metric_Rollup_Service $rollup_service = null,
	) {
		$this->rollup_service = $rollup_service ?? new PCM_Metric_Rollup_Service();
	}

	/**
	 * @return void
	 */
	public function send_weekly_digest(): void {
		if ( ! pcm_reporting_is_enabled() ) {
			return;
		}

		$recipients = get_option( PCM_Options::REPORT_DIGEST_RECIPIENTS->value, get_option( 'admin_email' ) );
		$recipients = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $recipients ) ) ) );

		if ( empty( $recipients ) ) {
			return;
		}

		$rows = $this->rollup_service->query_trends( '7d' );

		$subject = __( '[Pressable Cache Management] Weekly cache report', 'pressable_cache_management' );
		$body    = $this->build_digest_body( $rows );

		foreach ( $recipients as $email ) {
			wp_mail( $email, $subject, $body );
		}
	}

	/**
	 * @param array $rows Rows.
	 *
	 * @return string
	 */
	protected function build_digest_body( array $rows ): string {
		$lines   = array();
		$lines[] = 'Pressable Cache Management Weekly Digest';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = '';
		$lines[] = 'Top metric snapshots (last 7 days):';

		$top = array_slice( $rows, -10 );
		foreach ( $top as $row ) {
			$lines[] = sprintf(
				'- %s @ %s = %s',
				$row['metric_key'] ?? 'unknown_metric',
				$row['bucket_start'] ?? 'unknown_time',
				$row['value'] ?? 'n/a'
			);
		}

		$lines[] = '';
		$lines[] = 'Review full diagnostics in WP Admin > Pressable Cache Management > Reports.';

		return implode( "\n", $lines );
	}
}
