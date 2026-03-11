<?php
/**
 * Object Cache Intelligence - Route sensitivity storage.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for per-route memcache sensitivity assessments.
 */
class PCM_Object_Cache_Route_Sensitivity_Storage {
	protected string $key = 'pcm_route_memcache_sensitivity_v1';

	protected int $max_rows = 5000;

	private ?array $rows_cache = null;

	/**
	 * @return array<int, array<string, mixed>>
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
	 * @param array<int, array<string, mixed>> $rows Rows.
	 *
	 * @return void
	 */
	public function replace( array $rows ): void {
		$rows = array_slice( array_values( $rows ), -1 * $this->max_rows );
		update_option( $this->key, $rows, false );
		$this->rows_cache = $rows;
	}

	/**
	 * @param int    $run_id Run ID.
	 * @param string $route Route key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_for_run_route( int $run_id, string $route ): ?array {
		foreach ( array_reverse( $this->all() ) as $row ) {
			if ( $run_id === (int) $row['run_id'] && $route === (string) $row['route'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $assessments Rows.
	 *
	 * @return void
	 */
	public function upsert_batch( array $assessments ): void {
		$existing = $this->all();
		$index    = array();

		foreach ( $assessments as $row ) {
			$index[ (int) $row['run_id'] . '|' . (string) $row['route'] ] = true;
		}

		$filtered = array_values(
			array_filter(
				$existing,
				static function ( array $row ) use ( $index ): bool {
					$key = (int) ( $row['run_id'] ?? 0 ) . '|' . (string) ( $row['route'] ?? '' );

					return ! isset( $index[ $key ] );
				}
			)
		);

		$this->replace( array_merge( $filtered, $assessments ) );
	}

	/**
	 * @param int $retention_days Days.
	 *
	 * @return void
	 */
	public function cleanup( int $retention_days = 90 ): void {
		$retention = max( 7, min( 365, absint( $retention_days ) ) );
		$cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

		$this->replace(
			array_values(
				array_filter(
					$this->all(),
					static function ( array $row ) use ( $cutoff_ts ): bool {
						$taken_at = isset( $row['assessed_at'] ) ? strtotime( $row['assessed_at'] ) : 0;

						return $taken_at >= $cutoff_ts;
					}
				)
			)
		);
	}
}
