<?php
/**
 * PHP OPcache Awareness - Snapshot storage.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OPcache snapshot storage (A4.1).
 */
class PCM_OPcache_Snapshot_Storage {
	protected string $key = 'pcm_opcache_snapshots_v1';

	protected int $max_rows = 2000;

	private ?array $rows_cache = null;

	/**
	 * @return array
	 */
	public function all(): array {
		if ( null !== $this->rows_cache ) {
			return $this->rows_cache;
		}

		$rows             = get_option( $this->key, array() );
		$this->rows_cache = is_array( $rows ) ? $rows : array();

		return $this->rows_cache;
	}

	/**
	 * @param array $snapshot Snapshot.
	 *
	 * @return void
	 */
	public function append( array $snapshot ): void {
		$rows   = $this->all();
		$rows[] = pcm_opcache_sanitize_snapshot( $snapshot );
		$rows   = array_slice( $rows, -1 * $this->max_rows );
		update_option( $this->key, $rows, false );
		$this->rows_cache = $rows;
	}

	/**
	 * @param string $range 24h|7d|30d
	 *
	 * @return array
	 */
	public function query( string $range = '7d' ): array {
		$days_map = array(
			'24h' => 1,
			'7d'  => 7,
			'30d' => 30,
		);

		$days      = $days_map[ $range ] ?? 7;
		$cutoff_ts = time() - ( DAY_IN_SECONDS * $days );

		return array_values(
			array_filter(
				$this->all(),
				static function ( array $row ) use ( $cutoff_ts ): bool {
					$taken_at = isset( $row['taken_at'] ) ? strtotime( $row['taken_at'] ) : 0;

					return $taken_at >= $cutoff_ts;
				}
			)
		);
	}

	/**
	 * @param int $retention_days Days.
	 *
	 * @return void
	 */
	public function cleanup( int $retention_days = 90 ): void {
		$retention = max( 7, min( 365, absint( $retention_days ) ) );
		$cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

		$rows = array_values(
			array_filter(
				$this->all(),
				static function ( array $row ) use ( $cutoff_ts ): bool {
					$taken_at = isset( $row['taken_at'] ) ? strtotime( $row['taken_at'] ) : 0;

					return $taken_at >= $cutoff_ts;
				}
			)
		);

		update_option( $this->key, $rows, false );
		$this->rows_cache = $rows;
	}
}
