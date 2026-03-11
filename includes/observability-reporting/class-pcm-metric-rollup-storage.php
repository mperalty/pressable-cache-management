<?php
/**
 * Observability Reporting - Metric rollup storage.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight rollup storage using options in v1 scaffolding.
 */
class PCM_Metric_Rollup_Storage {
	protected string $key = 'pcm_metric_rollups_v1';

	protected int $max_rows = 2000;

	private ?array $rows_cache = null;

	private bool $dirty = false;

	/**
	 * @param array $row Rollup row.
	 */
	public function append_rollup( array $row ): void {
		$rows             = $this->get_rollups();
		$rows[]           = $row;
		$this->rows_cache = $rows;
		$this->dirty      = true;
	}

	/**
	 * Flush any pending writes to the database.
	 */
	public function flush(): void {
		if ( ! $this->dirty || null === $this->rows_cache ) {
			return;
		}

		update_option( $this->key, array_slice( $this->rows_cache, -1 * $this->max_rows ), false );
		$this->rows_cache = array_slice( $this->rows_cache, -1 * $this->max_rows );
		$this->dirty      = false;
	}

	/**
	 * @return array
	 */
	public function get_rollups(): array {
		if ( null !== $this->rows_cache ) {
			return $this->rows_cache;
		}

		$rows             = get_option( $this->key, array() );
		$this->rows_cache = is_array( $rows ) ? $rows : array();

		return $this->rows_cache;
	}

	/**
	 * @param int $retention_days Retention period.
	 */
	public function cleanup( int $retention_days = 90 ): void {
		$this->flush();

		$rows      = $this->get_rollups();
		$retention = max( 7, min( 365, absint( $retention_days ) ) );
		$cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $cutoff_ts ) {
					$bucket_start = isset( $row['bucket_start'] ) ? strtotime( $row['bucket_start'] ) : 0;

					return $bucket_start >= $cutoff_ts;
				}
			)
		);

		update_option( $this->key, $rows, false );
		$this->rows_cache = $rows;
		$this->dirty      = false;
	}
}
