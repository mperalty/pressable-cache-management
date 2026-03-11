<?php
/**
 * Cache Busters - Detector engine.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Engine service for single-pass detector execution.
 */
class PCM_Cache_Buster_Detector_Engine {
	protected readonly PCM_Cache_Buster_Detector_Registry $registry;

	protected readonly PCM_Cache_Buster_Snapshot_Provider $snapshot_provider;

	/**
	 * @param PCM_Cache_Buster_Detector_Registry|null $registry Registry dependency.
	 * @param PCM_Cache_Buster_Snapshot_Provider|null $snapshot_provider Snapshot provider dependency.
	 */
	public function __construct(
		?PCM_Cache_Buster_Detector_Registry $registry = null,
		?PCM_Cache_Buster_Snapshot_Provider $snapshot_provider = null,
	) {
		$this->registry          = $registry ?? new PCM_Cache_Buster_Detector_Registry();
		$this->snapshot_provider = $snapshot_provider ?? new PCM_Cache_Buster_Snapshot_Provider();

		$this->registry->register( new PCM_Cache_Buster_Cookie_Detector() );
		$this->registry->register( new PCM_Cache_Buster_Query_Detector() );
		$this->registry->register( new PCM_Cache_Buster_Vary_Detector() );
		$this->registry->register( new PCM_Cache_Buster_No_Cache_Detector() );
		$this->registry->register( new PCM_Cache_Buster_Purge_Detector() );
	}

	/**
	 * Execute detectors once using latest completed scan snapshot.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function detect_latest(): array {
		if ( ! pcm_cache_busters_is_enabled() ) {
			return array();
		}

		$snapshot = $this->snapshot_provider->get_latest_snapshot();
		$events   = $this->registry->run_all( $snapshot );

		$storage = new PCM_Cache_Buster_Event_Storage();
		$run_id  = isset( $snapshot['run']['id'] ) ? absint( $snapshot['run']['id'] ) : 0;
		$storage->persist_events( $events, $run_id );

		return $events;
	}
}
