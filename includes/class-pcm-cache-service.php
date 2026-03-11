<?php
/**
 * Pressable Cache Management - Canonical Cache Service.
 *
 * Every cache flush, purge, or invalidation operation in the plugin routes
 * through this single service so that timestamps, audit entries, Batcache
 * status invalidation, and microcache cleanup stay consistent.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCM_Cache_Service {

	private static ?self $instance = null;

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Flush the full object cache plus all related layers.
	 *
	 * Replaces the scattered wp_cache_flush() + batcache_clear_cache() +
	 * timestamp + transient-delete pattern that was duplicated across
	 * flush-object-cache.php, object-cache-admin-bar.php, and others.
	 *
	 * @param string $trigger Machine-readable label for the trigger source.
	 * @return bool True if wp_cache_flush() succeeded.
	 */
	public function flush_object_cache( string $trigger = 'manual' ): bool {
		$success = (bool) wp_cache_flush();

		if ( function_exists( 'batcache_clear_cache' ) ) {
			batcache_clear_cache();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		do_action( 'pcm_flush_all_cache' );
		do_action( 'pcm_after_object_cache_flush' );
		delete_transient( 'pcm_batcache_status' );

		// Flush microcache when the full object cache is wiped.
		if ( function_exists( 'pcm_microcache_flush_all' ) ) {
			pcm_microcache_flush_all( 'object_cache_flush_' . $trigger );
		}

		if ( $success ) {
			$this->record_timestamp( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP );
		}

		$this->audit( 'object_cache_flushed', $trigger, array( 'success' => $success ) );

		return $success;
	}

	/**
	 * Flush a single URL from Batcache and record the operation.
	 *
	 * The low-level version-increment logic stays in pcm_flush_batcache_url()
	 * (helpers.php). This method wraps it with timestamp and audit tracking.
	 *
	 * @param string      $url              The full URL to flush.
	 * @param string      $trigger          Machine-readable trigger label.
	 * @param PCM_Options $timestamp_option Which timestamp option to update.
	 * @param array       $context          Extra context for audit/timestamp.
	 * @return bool True if the URL was flushed.
	 */
	public function flush_batcache_url(
		string $url,
		string $trigger = 'manual',
		PCM_Options $timestamp_option = PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP,
		array $context = array()
	): bool {
		if ( ! pcm_flush_batcache_url( $url ) ) {
			return false;
		}

		$http_url = set_url_scheme( $url, 'http' );
		update_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value, $http_url );

		$stamp = pcm_format_flush_timestamp();
		if ( ! empty( $context['stamp_suffix'] ) ) {
			$stamp .= $context['stamp_suffix'];
		}
		update_option( $timestamp_option->value, $stamp );

		$this->audit( 'batcache_url_flushed', $url, array_merge( $context, array( 'trigger' => $trigger ) ) );

		return true;
	}

	/**
	 * Purge the entire Edge Cache for the domain.
	 *
	 * @param string $trigger Machine-readable trigger label.
	 * @return bool True on success, false on failure or unavailability.
	 */
	public function purge_edge_cache_domain( string $trigger = 'manual' ): bool {
		if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
			return false;
		}

		$edge_cache = Edge_Cache_Plugin::get_instance();
		if ( ! method_exists( $edge_cache, 'purge_domain_now' ) ) {
			return false;
		}

		$result = $edge_cache->purge_domain_now( $trigger );

		if ( $result ) {
			$this->record_timestamp( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP );
			do_action( 'pcm_after_edge_cache_purge' );
		}

		$this->audit( 'edge_cache_domain_purged', $trigger, array( 'success' => (bool) $result ) );

		return (bool) $result;
	}

	/**
	 * Purge a single URL from Edge Cache.
	 *
	 * @param string $url     The URL to purge.
	 * @param string $trigger Machine-readable trigger label.
	 * @return bool True on success.
	 */
	public function purge_edge_cache_url( string $url, string $trigger = 'manual' ): bool {
		if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
			return false;
		}

		$edge_cache = Edge_Cache_Plugin::get_instance();
		$result     = $edge_cache->purge_uris_now( array( $url ) );

		update_option( PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED->value, $url );
		$this->record_timestamp( PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP );

		$this->audit(
			'edge_cache_url_purged',
			$url,
			array(
				'trigger' => $trigger,
				'success' => (bool) $result,
			)
		);

		return (bool) $result;
	}

	/**
	 * Flush object cache and purge edge cache in one call.
	 *
	 * @param string $trigger Machine-readable trigger label.
	 * @return array{object: bool, edge: bool|null} Results per layer.
	 */
	public function flush_all_layers( string $trigger = 'manual' ): array {
		$results = array(
			'object' => $this->flush_object_cache( $trigger ),
			'edge'   => null,
		);

		if ( class_exists( 'Edge_Cache_Plugin' ) ) {
			$results['edge'] = $this->purge_edge_cache_domain( $trigger );
		}

		return $results;
	}

	/**
	 * Record a formatted UTC timestamp in a wp_option.
	 */
	private function record_timestamp( PCM_Options $option ): string {
		$stamp = pcm_format_flush_timestamp();
		update_option( $option->value, $stamp );
		return $stamp;
	}

	/**
	 * Write an audit log entry when the audit service is available.
	 *
	 * The audit service lives in the security-privacy module which is
	 * lazy-loaded on PCM admin pages and AJAX requests. On front-end
	 * automated flushes (save_post etc.) the class won't exist and
	 * the call is silently skipped; timestamps still record.
	 */
	private function audit( string $action, string $target = '', array $context = array() ): void {
		if ( ! class_exists( 'PCM_Audit_Log_Service' ) ) {
			return;
		}

		( new PCM_Audit_Log_Service() )->log( $action, $target, $context );
	}
}
