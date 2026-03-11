<?php
/**
 * Object Cache Intelligence — Stats Provider Interface.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified adapter contract.
 */
interface PCM_Object_Cache_Stats_Provider_Interface {
	/**
	 * Return a normalized metrics payload.
	 *
	 * @return array<string, mixed>
	 */
	public function get_metrics(): array;

	/**
	 * Identifier for active provider.
	 *
	 * @return string
	 */
	public function get_provider_key(): string;
}
