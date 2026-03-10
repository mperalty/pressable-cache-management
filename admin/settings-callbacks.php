<?php
/**
 * Pressable Cache Management — Settings Callbacks (loader).
 *
 * Split into focused files by tab for maintainability:
 *   - callbacks-object-cache.php — Object Cache Management tab
 *   - callbacks-edge-cache.php   — Edge Cache Management tab
 *   - callbacks-branding.php     — Remove Branding tab
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcm_callbacks_dir = plugin_dir_path( __FILE__ );
require_once $pcm_callbacks_dir . 'callbacks-object-cache.php';
require_once $pcm_callbacks_dir . 'callbacks-edge-cache.php';
require_once $pcm_callbacks_dir . 'callbacks-branding.php';
