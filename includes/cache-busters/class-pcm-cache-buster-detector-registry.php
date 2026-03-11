<?php
/**
 * Cache Busters - Detector registry.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detector registry and execution.
 */
class PCM_Cache_Buster_Detector_Registry {
	/** @var array<string, PCM_Cache_Buster_Detector_Interface> */
	protected array $detectors = array();

	/**
	 * @param PCM_Cache_Buster_Detector_Interface $detector Detector instance.
	 *
	 * @return void
	 */
	public function register( PCM_Cache_Buster_Detector_Interface $detector ): void {
		$this->detectors[ $detector->get_key() ] = $detector;
	}

	/**
	 * @return array<string, PCM_Cache_Buster_Detector_Interface>
	 */
	public function get_detectors(): array {
		return $this->detectors;
	}

	/**
	 * @param array<string, mixed> $snapshot Snapshot payload.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function run_all( array $snapshot ): array {
		$events = array();

		foreach ( $this->detectors as $detector ) {
			foreach ( $detector->detect( $snapshot ) as $event ) {
				$events[] = $event->to_array();
			}
		}

		return $events;
	}
}
