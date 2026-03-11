<?php
/**
 * Object Cache Intelligence - Provider bootstrap.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_oci_dir = plugin_dir_path( __FILE__ );

require_once $pcm_oci_dir . 'class-pcm-object-cache-dropin-stats-provider.php';
require_once $pcm_oci_dir . 'class-pcm-object-cache-memcached-extension-stats-provider.php';
require_once $pcm_oci_dir . 'class-pcm-object-cache-memcache-extension-stats-provider.php';
require_once $pcm_oci_dir . 'class-pcm-object-cache-null-stats-provider.php';
require_once $pcm_oci_dir . 'class-pcm-object-cache-stats-provider-resolver.php';
