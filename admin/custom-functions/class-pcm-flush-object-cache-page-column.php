<?php
/**
 * Pressable Cache Management - Admin list table single-page cache flush column.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCM_Flush_Object_Cache_Page_Column {

	public function add(): void {
		add_filter( 'post_row_actions', array( $this, 'add_flush_object_cache_link' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_flush_object_cache_link' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_js' ) );
		add_action( 'wp_ajax_pcm_flush_object_cache_column', array( $this, 'flush_object_cache_column' ) );
	}

	public function add_flush_object_cache_link( array $actions, \WP_Post $post ): array {
		if ( pcm_can_flush_single_page_cache() ) {
			$post_id = (string) $post->ID;

			$actions['flush_object_cache_url'] =
				'<a data-id="' . esc_attr( $post_id ) . '"'
				. ' data-nonce="' . esc_attr( wp_create_nonce( 'flush-object-cache_' . $post_id ) ) . '"'
				. ' id="flush-object-cache-url-' . esc_attr( $post_id ) . '"'
				. ' style="cursor:pointer;">'
				. esc_html__( 'Flush Cache', 'pressable_cache_management' ) . '</a>';
		}
		return $actions;
	}

	public function flush_object_cache_column(): void {
		if ( ! pcm_can_flush_single_page_cache() ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_id      = filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT );
		$post_id      = is_int( $post_id ) ? $post_id : 0;
		$nonce_action = 'flush-object-cache_' . $post_id;
		pcm_verify_ajax_request( 'nonce', $nonce_action, method: 'GET', capability: 'edit_posts' );

		$url        = get_permalink( $post_id );
		$page_title = get_the_title( $post_id );
		update_option( PCM_Options::PAGE_TITLE->value, $page_title );

		if ( ! $url || ! pcm_cache_service()->flush_batcache_url( $url, 'column-single-page' ) ) {
			wp_send_json_error( array( 'reason' => 'Batcache not available' ) );
		}

		wp_send_json_success( array( 'flushed' => true ) );
	}

	public function load_js(): void {
		$js_base_path = plugin_dir_path( __DIR__ ) . 'public/js/';
		$js_base_url  = plugin_dir_url( __DIR__ ) . 'public/js/';

		$a11y_path = $js_base_path . 'pcm-modal-a11y.js';
		wp_enqueue_script(
			'pcm-modal-a11y',
			$js_base_url . 'pcm-modal-a11y.js',
			array(),
			file_exists( $a11y_path ) ? (string) filemtime( $a11y_path ) : '3.0.0',
			true
		);

		$js_path = $js_base_path . 'column.js';
		wp_enqueue_script(
			'flush-object-cache-column',
			$js_base_url . 'column.js',
			array( 'pcm-modal-a11y' ),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : '3.0.0',
			true
		);
	}
}
