<?php
/**
 * Observability Reporting - Metric rollup service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rollup service.
 */
class PCM_Metric_Rollup_Service {
	protected readonly PCM_Metric_Registry $registry;

	protected readonly PCM_Metric_Rollup_Storage $storage;

	public function __construct(
		?PCM_Metric_Registry $registry = null,
		?PCM_Metric_Rollup_Storage $storage = null,
	) {
		$this->registry = $registry ?? new PCM_Metric_Registry();
		$this->storage  = $storage ?? new PCM_Metric_Rollup_Storage();
	}

	/**
	 * @param string $metric_key Metric key.
	 * @param float  $value Metric value.
	 * @param array  $dimensions Dimensions.
	 * @param string $bucket_size Bucket size label.
	 */
	public function write_rollup( string $metric_key, float $value, array $dimensions = array(), string $bucket_size = '1d' ): bool {
		$metric_key = sanitize_key( $metric_key );

		if ( ! $this->registry->has_metric( $metric_key ) ) {
			return false;
		}

		$row = array(
			'metric_key'      => $metric_key,
			'bucket_start'    => gmdate( 'Y-m-d 00:00:00' ),
			'bucket_size'     => sanitize_text_field( $bucket_size ),
			'value'           => round( (float) $value, 4 ),
			'dimensions_json' => (array) $dimensions,
		);

		$this->storage->append_rollup( $row );

		return true;
	}

	/**
	 * Flush pending rollup writes to the database.
	 */
	public function flush_storage(): void {
		$this->storage->flush();
	}

	/**
	 * Run cleanup on the rollup storage.
	 *
	 * @param int $retention_days Retention period.
	 */
	public function cleanup_storage( int $retention_days = 90 ): void {
		$this->storage->cleanup( $retention_days );
	}

	/**
	 * @param string $range 24h|7d|30d.
	 * @param array  $metric_keys Metric keys.
	 *
	 * @return array
	 */
	public function query_trends( string $range = '7d', array $metric_keys = array() ): array {
		$range_days = array(
			'24h' => 1,
			'7d'  => 7,
			'30d' => 30,
		);

		$days    = $range_days[ $range ] ?? 7;
		$cutoff  = time() - ( DAY_IN_SECONDS * $days );
		$keys    = array_map( 'sanitize_key', (array) $metric_keys );
		$rollups = $this->storage->get_rollups();

		return array_values(
			array_filter(
				$rollups,
				static function ( $row ) use ( $cutoff, $keys ) {
					$bucket_ts = isset( $row['bucket_start'] ) ? strtotime( $row['bucket_start'] ) : 0;
					if ( $bucket_ts < $cutoff ) {
						return false;
					}

					if ( empty( $keys ) ) {
						return true;
					}

					return isset( $row['metric_key'] ) && in_array( $row['metric_key'], $keys, true );
				}
			)
		);
	}
}
