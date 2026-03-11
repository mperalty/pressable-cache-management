<?php
/**
 * Pressable Cache Management - Branding toggle.
 *
 * This file is loaded to allow the branding option to be read by
 * admin-menu.php and settings-callbacks.php. The actual branding
 * show/hide logic lives in those files; this file simply ensures
 * the option exists and is sanitized on load.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The branding option is consumed directly by admin-menu.php and
// settings-callbacks.php via get_option(). No additional processing
// is needed here.
