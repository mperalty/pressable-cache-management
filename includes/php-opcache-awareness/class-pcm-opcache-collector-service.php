<?php
/**
 * PHP OPcache Awareness - Collector service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collector service for OPcache runtime + ini snapshots.
 */
class PCM_OPcache_Collector_Service {
	/**
	 * @return array
	 */
	public function collect(): array {
		if ( ! pcm_opcache_awareness_is_enabled() ) {
			return array();
		}

		$status = $this->safe_get_status();
		$ini    = $this->collect_ini();

		if ( empty( $status ) ) {
			return array(
				'taken_at'        => current_time( 'mysql', true ),
				'enabled'         => false,
				'health'          => 'disabled',
				'memory'          => array(),
				'statistics'      => array(),
				'ini'             => $ini,
				'recommendations' => array(
					array(
						'rule_id'   => 'opcache_unavailable',
						'severity'  => 'warning',
						'message'   => 'OPcache status is unavailable. Confirm OPcache is enabled in this PHP runtime.',
						'checklist' => array(
							'Verify opcache.enable is set to 1.',
							'Confirm opcache extension is loaded for the web SAPI.',
							'Re-check OPcache diagnostics after PHP runtime changes.',
						),
					),
				),
			);
		}

		$snapshot        = $this->normalize_status( $status );
		$snapshot['ini'] = $ini;

		return $snapshot;
	}

	/**
	 * @return array
	 */
	protected function safe_get_status(): array {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporary handler avoids surfacing OPcache warnings while probing availability.
		set_error_handler(
			static function (): bool {
				return true;
			}
		);

		try {
			$result = opcache_get_status( false );
		} finally {
			restore_error_handler();
		}

		if ( ! is_array( $result ) ) {
			return array();
		}

		return $result;
	}

	/**
	 * @return array
	 */
	protected function collect_ini(): array {
		$keys = array(
			'opcache.enable',
			'opcache.memory_consumption',
			'opcache.max_accelerated_files',
			'opcache.revalidate_freq',
			'opcache.validate_timestamps',
			'opcache.max_wasted_percentage',
		);

		$output = array();

		foreach ( $keys as $key ) {
			$value = ini_get( $key );
			if ( false === $value ) {
				continue;
			}

			$output[ $key ] = sanitize_text_field( (string) $value );
		}

		return $output;
	}

	/**
	 * @param array $raw_status Raw OPcache payload.
	 *
	 * @return array
	 */
	protected function normalize_status( array $raw_status ): array {
		$opcache_enabled = ! empty( $raw_status['opcache_enabled'] );
		$memory_usage    = isset( $raw_status['memory_usage'] ) && is_array( $raw_status['memory_usage'] ) ? $raw_status['memory_usage'] : array();
		$statistics      = isset( $raw_status['opcache_statistics'] ) && is_array( $raw_status['opcache_statistics'] ) ? $raw_status['opcache_statistics'] : array();

		$used_memory   = isset( $memory_usage['used_memory'] ) ? absint( $memory_usage['used_memory'] ) : 0;
		$free_memory   = isset( $memory_usage['free_memory'] ) ? absint( $memory_usage['free_memory'] ) : 0;
		$wasted_memory = isset( $memory_usage['wasted_memory'] ) ? absint( $memory_usage['wasted_memory'] ) : 0;

		$restarts = array(
			'oom_restarts'    => isset( $statistics['oom_restarts'] ) ? absint( $statistics['oom_restarts'] ) : 0,
			'hash_restarts'   => isset( $statistics['hash_restarts'] ) ? absint( $statistics['hash_restarts'] ) : 0,
			'manual_restarts' => isset( $statistics['manual_restarts'] ) ? absint( $statistics['manual_restarts'] ) : 0,
		);

		$snapshot = array(
			'taken_at'   => current_time( 'mysql', true ),
			'enabled'    => $opcache_enabled,
			'memory'     => array(
				'used_memory'         => $used_memory,
				'free_memory'         => $free_memory,
				'wasted_memory'       => $wasted_memory,
				'free_memory_percent' => pcm_opcache_percent( $free_memory, $used_memory + $free_memory + $wasted_memory ),
				'wasted_percent'      => pcm_opcache_percent( $wasted_memory, $used_memory + $free_memory + $wasted_memory ),
			),
			'statistics' => array(
				'num_cached_scripts' => isset( $statistics['num_cached_scripts'] ) ? absint( $statistics['num_cached_scripts'] ) : 0,
				'max_cached_keys'    => isset( $statistics['max_cached_keys'] ) ? absint( $statistics['max_cached_keys'] ) : 0,
				'opcache_hit_rate'   => isset( $statistics['opcache_hit_rate'] ) ? round( (float) $statistics['opcache_hit_rate'], 2 ) : null,
				'restarts'           => $restarts,
				'restart_total'      => array_sum( $restarts ),
			),
		);

		$recommendation_engine = new PCM_OPcache_Recommendation_Engine();
		$evaluated             = $recommendation_engine->evaluate( $snapshot );

		return array_merge( $snapshot, $evaluated );
	}
}
