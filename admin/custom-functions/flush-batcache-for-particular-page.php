<?php
/**
 * Pressable Cache Management - Flush cache for a particular page (column link).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-pcm-flush-object-cache-page-column.php';

$options = pcm_get_options();

if ( isset( $options['flush_object_cache_for_single_page'] ) && ! empty( $options['flush_object_cache_for_single_page'] ) ) {
	add_action( 'init', 'pcm_show_flush_cache_column' );
	add_action( 'admin_notices', 'flush_object_cache_for_single_page_notice' );
} else {
	update_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value, 'activating' );
}

function pcm_can_flush_single_page_cache(): bool {
	return current_user_can( 'manage_options' )
		|| current_user_can( 'edit_others_posts' )
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability for shop managers.
		|| current_user_can( 'manage_woocommerce' );
}

function pcm_show_flush_cache_column(): void {
	if ( pcm_can_flush_single_page_cache() ) {
		$column = new PCM_Flush_Object_Cache_Page_Column();
		$column->add();
	}
}

function flush_object_cache_for_single_page_notice(): void {
	$state = get_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value, 'activating' );
	if ( 'activating' !== $state ) {
		return;
	}
	if ( ! pcm_can_flush_single_page_cache() ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'toplevel_page_pressable_cache_management' !== $screen->id ) {
		return;
	}

	pcm_admin_notice(
		__( 'You can Flush Cache for Individual page or post from page preview.', 'pressable_cache_management' ),
		'success'
	);
	update_option( PCM_Options::FLUSH_SINGLE_PAGE_NOTICE->value, 'activated' );
}
