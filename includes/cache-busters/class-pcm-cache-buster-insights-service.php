<?php
/**
 * Cache Busters - Insights service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query service for leaderboard + trends (A2.2).
 */
class PCM_Cache_Buster_Insights_Service {
	protected readonly PCM_Cache_Buster_Event_Storage $storage;

	/**
	 * @param PCM_Cache_Buster_Event_Storage|null $storage Storage.
	 */
	public function __construct(
		?PCM_Cache_Buster_Event_Storage $storage = null,
	) {
		$this->storage = $storage ?? new PCM_Cache_Buster_Event_Storage();
	}

	/**
	 * @param string $range Range.
	 * @param int    $limit Limit.
	 *
	 * @return array
	 */
	public function top_sources( string $range = '7d', int $limit = 10 ): array {
		$rows = $this->storage->query_by_range( $range );
		$agg  = array();

		foreach ( $rows as $row ) {
			$key = ( isset( $row['category'] ) ? $row['category'] : 'unknown' ) . '|' . ( isset( $row['signature'] ) ? $row['signature'] : 'unknown' );
			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = array(
					'category'      => isset( $row['category'] ) ? $row['category'] : 'unknown',
					'signature'     => isset( $row['signature'] ) ? $row['signature'] : 'unknown',
					'likely_source' => isset( $row['likely_source'] ) ? $row['likely_source'] : 'unknown',
					'confidence'    => isset( $row['confidence'] ) ? $row['confidence'] : 'low',
					'event_count'   => 0,
					'incidence'     => 0,
				);
			}

			$agg[ $key ]['event_count'] += 1;
			$agg[ $key ]['incidence']   += isset( $row['count'] ) ? absint( $row['count'] ) : 0;
		}

		$items = array_values( $agg );
		usort(
			$items,
			static function ( $a, $b ) {
				if ( $a['incidence'] === $b['incidence'] ) {
					return $b['event_count'] <=> $a['event_count'];
				}

				return $b['incidence'] <=> $a['incidence'];
			}
		);

		return array_slice( $items, 0, max( 1, min( 100, absint( $limit ) ) ) );
	}

	/**
	 * @param string $range Range.
	 *
	 * @return array
	 */
	public function trend_points( string $range = '7d' ): array {
		$rows = $this->storage->query_by_range( $range );
		$agg  = array();

		foreach ( $rows as $row ) {
			$bucket = isset( $row['detected_at'] ) ? gmdate( 'Y-m-d', strtotime( $row['detected_at'] ) ) : gmdate( 'Y-m-d' );
			if ( ! isset( $agg[ $bucket ] ) ) {
				$agg[ $bucket ] = 0;
			}
			$agg[ $bucket ] += isset( $row['count'] ) ? absint( $row['count'] ) : 0;
		}

		ksort( $agg );

		$points = array();
		foreach ( $agg as $day => $incidence ) {
			$points[] = array(
				'bucket_start' => $day . ' 00:00:00',
				'incidence'    => $incidence,
			);
		}

		return $points;
	}

	/**
	 * @param string $range Range.
	 *
	 * @return int
	 */
	public function total_incidence( string $range = '7d' ): int {
		$rows = $this->storage->query_by_range( $range );
		$sum  = 0;

		foreach ( $rows as $row ) {
			$sum += isset( $row['count'] ) ? absint( $row['count'] ) : 0;
		}

		return $sum;
	}
}
