<?php
/**
 * Cache Busters - Detector interface.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detector contract.
 */
interface PCM_Cache_Buster_Detector_Interface {
	/**
	 * Unique detector key.
	 *
	 * @return string
	 */
	public function get_key(): string;

	/**
	 * Detect cache busters from one snapshot.
	 *
	 * @param array<string, mixed> $snapshot Snapshot payload.
	 *
	 * @return PCM_Cache_Buster_Event[]
	 */
	public function detect( array $snapshot ): array;
}
