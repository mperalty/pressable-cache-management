<?php
/**
 * Security & Privacy - Telemetry retention manager.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention manager for existing telemetry stores.
 */
class PCM_Telemetry_Retention_Manager {
	/**
	 * @return void
	 */
	public function cleanup(): void {
		$settings = pcm_get_privacy_settings();
		$days     = $settings['retention_days'];

		$this->prune_option_rows( 'pcm_metric_rollups_v1', 'bucket_start', $days );
		$this->prune_option_rows( 'pcm_audit_log_v1', 'created_at', $days );
	}

	/**
	 * @param string $option_key Option key.
	 * @param string $date_key Date field key.
	 * @param int    $days Retention days.
	 *
	 * @return void
	 */
	protected function prune_option_rows( string $option_key, string $date_key, int $days ): void {
		$rows = get_option( $option_key, array() );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$cutoff = time() - ( DAY_IN_SECONDS * max( 7, min( 365, absint( $days ) ) ) );

		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $date_key, $cutoff ): bool {
					$ts = isset( $row[ $date_key ] ) ? strtotime( (string) $row[ $date_key ] ) : 0;
					return $ts >= $cutoff;
				}
			)
		);

		update_option( $option_key, $rows, false );
	}
}
