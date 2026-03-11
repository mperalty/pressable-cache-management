<?php
/**
 * Object Cache Intelligence - Null stats provider.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fallback provider when metrics are unavailable.
 */
class PCM_Object_Cache_Null_Stats_Provider implements PCM_Object_Cache_Stats_Provider_Interface {
	/**
	 * @return string
	 */
	public function get_provider_key(): string {
		return 'none';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_metrics(): array {
		return array(
			'provider'    => $this->get_provider_key(),
			'status'      => 'offline',
			'hits'        => null,
			'misses'      => null,
			'hit_ratio'   => null,
			'evictions'   => null,
			'bytes_used'  => null,
			'bytes_limit' => null,
			'meta'        => array(
				'reason' => 'stats_unavailable',
			),
		);
	}
}
