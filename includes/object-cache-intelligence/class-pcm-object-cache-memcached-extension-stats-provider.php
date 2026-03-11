<?php
/**
 * Object Cache Intelligence - Memcached extension stats provider.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that uses PHP Memcached extension stats when available.
 */
class PCM_Object_Cache_Memcached_Extension_Stats_Provider implements PCM_Object_Cache_Stats_Provider_Interface {
	/**
	 * @return string
	 */
	public function get_provider_key(): string {
		return 'memcached_extension';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_metrics(): array {
		if ( ! class_exists( 'Memcached' ) ) {
			return array();
		}

		$memcached = new Memcached();
		$memcached->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
		$memcached->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );
		$memcached->setOption( Memcached::OPT_RECV_TIMEOUT, 2000000 );
		$servers   = pcm_object_cache_memcached_servers_from_constant();
		$server_ok = false;

		foreach ( $servers as $server ) {
			$host = $server['host'];
			$port = $server['port'];

			if ( '' === $host ) {
				continue;
			}

			$memcached->addServer( $host, $port );
			$server_ok = true;
		}

		if ( ! $server_ok ) {
			return array();
		}

		try {
			$all_stats = $memcached->getStats();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( empty( $all_stats ) ) {
			return array();
		}

		$hits             = 0;
		$misses           = 0;
		$evictions        = 0;
		$bytes_used       = 0;
		$max_bytes        = 0;
		$curr_items       = 0;
		$total_items      = 0;
		$curr_connections = 0;
		$uptime           = 0;

		foreach ( $all_stats as $server_stats ) {
			if ( ! is_array( $server_stats ) ) {
				continue;
			}

			$hits             += isset( $server_stats['get_hits'] ) ? absint( $server_stats['get_hits'] ) : 0;
			$misses           += isset( $server_stats['get_misses'] ) ? absint( $server_stats['get_misses'] ) : 0;
			$evictions        += isset( $server_stats['evictions'] ) ? absint( $server_stats['evictions'] ) : 0;
			$bytes_used       += isset( $server_stats['bytes'] ) ? absint( $server_stats['bytes'] ) : 0;
			$max_bytes        += isset( $server_stats['limit_maxbytes'] ) ? absint( $server_stats['limit_maxbytes'] ) : 0;
			$curr_items       += isset( $server_stats['curr_items'] ) ? absint( $server_stats['curr_items'] ) : 0;
			$total_items      += isset( $server_stats['total_items'] ) ? absint( $server_stats['total_items'] ) : 0;
			$curr_connections += isset( $server_stats['curr_connections'] ) ? absint( $server_stats['curr_connections'] ) : 0;
			$uptime            = max( $uptime, isset( $server_stats['uptime'] ) ? absint( $server_stats['uptime'] ) : 0 );
		}

		return array(
			'provider'    => $this->get_provider_key(),
			'status'      => 'connected',
			'hits'        => $hits,
			'misses'      => $misses,
			'hit_ratio'   => pcm_calculate_hit_ratio( $hits, $misses ),
			'evictions'   => $evictions,
			'bytes_used'  => $bytes_used,
			'bytes_limit' => $max_bytes,
			'meta'        => array(
				'used_gb'          => pcm_object_cache_bytes_to_gb( $bytes_used ),
				'limit_gb'         => pcm_object_cache_bytes_to_gb( $max_bytes ),
				'curr_items'       => $curr_items,
				'total_items'      => $total_items,
				'curr_connections' => $curr_connections,
				'uptime_seconds'   => $uptime,
				'uptime_human'     => pcm_object_cache_format_uptime( $uptime ),
				'nodes_reported'   => count( $all_stats ),
			),
		);
	}
}
