<?php
/**
 * Pressable Cache Management - Canonical cache service bootstrap.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-pcm-cache-service.php';

/**
 * Global accessor for the canonical cache service.
 *
 * @return PCM_Cache_Service
 */
function pcm_cache_service(): PCM_Cache_Service {
	return PCM_Cache_Service::get_instance();
}
