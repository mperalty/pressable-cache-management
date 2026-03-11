<?php
/**
 * Cache Busters - Event storage.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent storage for detected cache-buster events (A2.1).
 */
class PCM_Cache_Buster_Event_Storage {
	/** @var string */
	protected string $key = 'pcm_cache_buster_events_v1';

	/** @var int */
	protected int $max_rows = 1000;

	/**
	 * Persist detector events with timestamp and run context.
	 *
	 * @param array $events Events.
	 * @param int   $run_id Run ID.
	 *
	 * @return array
	 */
	public function persist_events( array $events, int $run_id = 0 ): array {
		$rows = $this->all();
		$now  = current_time( 'mysql', true );

		$existing = array();
		foreach ( $rows as $row ) {
			$sig = isset( $row['signature'] ) ? $row['signature'] : '';
			if ( '' !== $sig ) {
				$existing[ $sig ] = true;
			}
		}

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$signature = isset( $event['signature'] ) ? sanitize_text_field( $event['signature'] ) : 'unknown';

			if ( isset( $existing[ $signature ] ) ) {
				continue;
			}

			$rows[] = array(
				'event_id'         => 'cbe_' . wp_generate_uuid4(),
				'run_id'           => absint( $run_id ),
				'category'         => isset( $event['category'] ) ? sanitize_key( $event['category'] ) : 'unknown',
				'signature'        => $signature,
				'confidence'       => isset( $event['confidence'] ) ? sanitize_key( $event['confidence'] ) : 'low',
				'count'            => isset( $event['count'] ) ? absint( $event['count'] ) : 0,
				'likely_source'    => isset( $event['likely_source'] ) ? sanitize_text_field( $event['likely_source'] ) : 'unknown',
				'affected_urls'    => isset( $event['affected_urls'] ) ? (array) $event['affected_urls'] : array(),
				'evidence_samples' => isset( $event['evidence_samples'] ) ? (array) $event['evidence_samples'] : array(),
				'detected_at'      => $now,
			);

			$existing[ $signature ] = true;
		}

		$rows = array_slice( $rows, -1 * $this->max_rows );
		update_option( $this->key, $rows, false );

		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$rows = get_option( $this->key, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $range 24h|7d|30d
	 *
	 * @return array
	 */
	public function query_by_range( string $range = '7d' ): array {
		$rows = $this->all();

		$days_by_range = array(
			'24h' => 1,
			'7d'  => 7,
			'30d' => 30,
		);

		$days      = isset( $days_by_range[ $range ] ) ? $days_by_range[ $range ] : 7;
		$cutoff_ts = time() - ( DAY_IN_SECONDS * $days );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $cutoff_ts ) {
					$ts = isset( $row['detected_at'] ) ? strtotime( $row['detected_at'] ) : 0;

					return $ts >= $cutoff_ts;
				}
			)
		);
	}
}
