<?php
/**
 * Pressable Cache Management - Flush Object Cache + Page Cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'pcm_flush_all_caches' ) ) {
	/**
	 * Flush all object-cache layers. Delegates to the canonical cache service.
	 *
	 * Kept as a thin wrapper for backward compatibility — callers that already
	 * reference pcm_flush_all_caches() continue to work without changes.
	 */
	function pcm_flush_all_caches(): bool {
		return pcm_cache_service()->flush_object_cache( 'dashboard' );
	}
}

if ( ! function_exists( 'pcm_ajax_flush_object_cache' ) ) {
	function pcm_ajax_flush_object_cache(): void {
		pcm_verify_ajax_request( 'flush_object_cache_nonce', 'flush_object_cache_nonce' );

		if ( ! pcm_flush_all_caches() ) {
			wp_send_json_error( array( 'message' => __( 'Object cache flush failed. Please try again.', 'pressable_cache_management' ) ) );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Object Cache Flushed Successfully.', 'pressable_cache_management' ),
				'timestamp' => (string) get_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value, '' ),
			)
		);
	}
}
add_action( 'wp_ajax_pcm_flush_object_cache', 'pcm_ajax_flush_object_cache' );

if ( null !== filter_input( INPUT_POST, 'flush_object_cache_nonce', FILTER_UNSAFE_RAW ) ) {
	if ( pcm_verify_request( 'flush_object_cache_nonce', 'flush_object_cache_nonce' ) && pcm_flush_all_caches() ) {
		add_action(
			'admin_notices',
			function (): void {
				pcm_admin_notice( __( 'Object Cache Flushed Successfully.', 'pressable_cache_management' ), 'success' );
			}
		);
	}
}
