<?php
/**
 * Object Cache Intelligence - Drop-in stats provider.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that attempts to read stats from the object cache drop-in.
 */
class PCM_Object_Cache_Dropin_Stats_Provider implements PCM_Object_Cache_Stats_Provider_Interface {
	/**
	 * @param mixed $value Value to format for debug logging.
	 *
	 * @return string
	 */
	protected function format_debug_value( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$encoded = wp_json_encode( $value );

		return false === $encoded ? gettype( $value ) : $encoded;
	}

	/**
	 * @return string
	 */
	public function get_provider_key(): string {
		return 'dropin';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_metrics(): array {
		global $wp_object_cache;

		$log = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( ! is_object( $wp_object_cache ) ) {
			if ( $log ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[PCM OCI dropin] $wp_object_cache is not an object.' );
			}
			return array();
		}

		if ( $log ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[PCM OCI dropin] Starting metrics collection. Class: ' . get_class( $wp_object_cache ) );
		}

		$hits   = $this->read_metric_from_dropin( $wp_object_cache, array( 'cache_hits', 'get_hits', 'hits' ) );
		$misses = $this->read_metric_from_dropin( $wp_object_cache, array( 'cache_misses', 'get_misses', 'misses' ) );

		$evictions = $this->read_metric_from_dropin( $wp_object_cache, array( 'evictions', 'evicted', 'evicted_unfetched' ) );
		$bytes     = $this->read_metric_from_dropin( $wp_object_cache, array( 'bytes', 'bytes_used', 'used_bytes' ) );
		$limit     = $this->read_metric_from_dropin( $wp_object_cache, array( 'limit_maxbytes', 'maxbytes', 'bytes_limit' ) );

		if ( $log ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[PCM OCI dropin] Property reads: hits=%s, misses=%s, evictions=%s, bytes=%s, limit=%s',
					$this->format_debug_value( $hits ),
					$this->format_debug_value( $misses ),
					$this->format_debug_value( $evictions ),
					$this->format_debug_value( $bytes ),
					$this->format_debug_value( $limit )
				)
			);
		}

		$have_basics = ( null !== $hits && null !== $misses );

		if ( ! $have_basics && method_exists( $wp_object_cache, 'stats' ) ) {
			if ( $log ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[PCM OCI dropin] No basics from properties, calling $wp_object_cache->stats()...' );
			}

			try {
				ob_start();
				$stats_result = $wp_object_cache->stats();
				$stats_text   = (string) ob_get_clean();
			} catch ( \Throwable $e ) {
				if ( ob_get_level() ) {
					ob_end_clean();
				}
				$stats_text   = '';
				$stats_result = null;

				if ( $log ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[PCM OCI dropin] stats() threw: ' . $e->getMessage() );
				}
			}

			$parsed_text   = $this->parse_dropin_stats_text( $stats_text );
			$parsed_struct = $this->parse_dropin_stats_struct( $stats_result );

			$hits      = $parsed_text['hits'] ?? $hits;
			$hits      = $parsed_struct['hits'] ?? $hits;
			$misses    = $parsed_text['misses'] ?? $misses;
			$misses    = $parsed_struct['misses'] ?? $misses;
			$evictions = $parsed_text['evictions'] ?? $evictions;
			$evictions = $parsed_struct['evictions'] ?? $evictions;
			$bytes     = $parsed_text['bytes_used'] ?? $bytes;
			$bytes     = $parsed_struct['bytes_used'] ?? $bytes;
			$limit     = $parsed_text['bytes_limit'] ?? $limit;
			$limit     = $parsed_struct['bytes_limit'] ?? $limit;
		}

		if ( $log ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[PCM OCI dropin] Before client stats: have_basics=' . ( $have_basics ? 'yes' : 'no' ) . '. Calling get_client_stats()...' );
		}

		$client_stats = $this->get_client_stats( $wp_object_cache );

		if ( $log ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[PCM OCI dropin] get_client_stats() returned ' . ( empty( $client_stats ) ? 'empty' : 'nodes=' . ( $client_stats['nodes_reported'] ?? '?' ) ) );
		}

		if ( ! empty( $client_stats ) ) {
			$hits      = $client_stats['hits'] ?? $hits;
			$misses    = $client_stats['misses'] ?? $misses;
			$evictions = $client_stats['evictions'] ?? $evictions;
			$bytes     = $client_stats['bytes_used'] ?? $bytes;
			$limit     = $client_stats['bytes_limit'] ?? $limit;
		}

		return array(
			'provider'    => $this->get_provider_key(),
			'status'      => 'connected',
			'hits'        => $hits,
			'misses'      => $misses,
			'hit_ratio'   => pcm_calculate_hit_ratio( $hits, $misses ),
			'evictions'   => $evictions,
			'bytes_used'  => $bytes,
			'bytes_limit' => $limit,
			'meta'        => array(
				'curr_items'       => $client_stats['curr_items'] ?? null,
				'total_items'      => $client_stats['total_items'] ?? null,
				'curr_connections' => $client_stats['curr_connections'] ?? null,
				'uptime_seconds'   => $client_stats['uptime'] ?? null,
				'uptime_human'     => isset( $client_stats['uptime'] ) ? pcm_object_cache_format_uptime( $client_stats['uptime'] ) : null,
				'nodes_reported'   => $client_stats['nodes_reported'] ?? null,
			),
		);
	}

	/**
	 * Attempt to resolve low-level cache client from known drop-in properties.
	 *
	 * @param object $wp_object_cache Active object cache drop-in instance.
	 *
	 * @return object|null
	 */
	protected function get_underlying_client( object $wp_object_cache ): ?object {
		$props_to_check = array( 'm', 'mc', 'memcache', 'memcached', 'client', 'daemon', 'connection' );

		foreach ( $props_to_check as $prop ) {
			if ( ! isset( $wp_object_cache->{$prop} ) ) {
				continue;
			}

			$found = $this->extract_client_from_value( $wp_object_cache->{$prop} );
			if ( $found ) {
				return $found;
			}
		}

		try {
			$ref = new ReflectionClass( $wp_object_cache );
			foreach ( $props_to_check as $prop ) {
				if ( ! $ref->hasProperty( $prop ) ) {
					continue;
				}

				$rp = $ref->getProperty( $prop );
				$rp->setAccessible( true );
				$value = $rp->getValue( $wp_object_cache );

				$found = $this->extract_client_from_value( $value );
				if ( $found ) {
					return $found;
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Extract a Memcached/Memcache client from a property value.
	 *
	 * @param mixed $value Property value.
	 *
	 * @return object|null
	 */
	protected function extract_client_from_value( mixed $value ): ?object {
		if ( $value instanceof Memcached || $value instanceof Memcache ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $group_client ) {
				if ( $group_client instanceof Memcached || $group_client instanceof Memcache ) {
					return $group_client;
				}
			}
		}

		return null;
	}

	/**
	 * Read normalized stats via a fresh Memcached connection.
	 *
	 * @param object $wp_object_cache Active object cache drop-in instance.
	 *
	 * @return array<string, int|null>
	 */
	protected function get_client_stats( object $wp_object_cache ): array {
		$client  = $this->get_underlying_client( $wp_object_cache );
		$servers = array();

		if ( $client instanceof Memcached ) {
			try {
				$server_list = $client->getServerList();
				foreach ( $server_list as $srv ) {
					$host = $srv['host'] ?? '';
					$port = $srv['port'] ?? 11211;
					if ( '' !== $host ) {
						$servers[] = array(
							'host' => $host,
							'port' => (int) $port,
						);
					}
				}
			} catch ( \Throwable $e ) {
				$servers = array();
			}
		} elseif ( $client instanceof Memcache ) {
			$servers = array();
		}

		if ( empty( $servers ) ) {
			$servers = pcm_object_cache_memcached_servers_from_constant();
		}

		if ( empty( $servers ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[PCM OCI] get_client_stats: no servers found from client or constant.' );
			}
			return array();
		}

		if ( ! class_exists( 'Memcached' ) ) {
			return array();
		}

		$fresh = new Memcached();
		$fresh->setOption( Memcached::OPT_CONNECT_TIMEOUT, 2000 );
		$fresh->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );
		$fresh->setOption( Memcached::OPT_RECV_TIMEOUT, 2000000 );
		$fresh->setOption( Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT );
		$fresh->setOption( Memcached::OPT_BINARY_PROTOCOL, true );

		foreach ( $servers as $srv ) {
			$fresh->addServer( $srv['host'], (int) $srv['port'] );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[PCM OCI] get_client_stats: querying %d server(s) via fresh connection.', count( $servers ) ) );
		}

		try {
			$all_stats = $fresh->getStats();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[PCM OCI] get_client_stats: getStats() threw: ' . $e->getMessage() );
			}
			return array();
		}

		$result_code = $fresh->getResultCode();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[PCM OCI] get_client_stats: getStats() returned %s, result_code=%d (%s).',
					'array(' . count( $all_stats ) . ')',
					$result_code,
					$fresh->getResultMessage()
				)
			);
		}

		if ( empty( $all_stats ) ) {
			return array();
		}

		return $this->aggregate_server_stats( $all_stats );
	}

	/**
	 * Aggregate per-server stats into totals.
	 *
	 * @param array<string, array<string, mixed>> $all_stats Per-server stats.
	 *
	 * @return array<string, int|null>
	 */
	protected function aggregate_server_stats( array $all_stats ): array {
		$totals = array(
			'hits'             => 0,
			'misses'           => 0,
			'evictions'        => null,
			'bytes_used'       => null,
			'bytes_limit'      => null,
			'curr_items'       => null,
			'total_items'      => null,
			'curr_connections' => null,
			'uptime'           => 0,
			'nodes_reported'   => 0,
		);

		foreach ( $all_stats as $server_stats ) {
			if ( empty( $server_stats ) ) {
				continue;
			}

			$totals['nodes_reported'] += 1;
			$totals['hits']           += isset( $server_stats['get_hits'] ) ? absint( $server_stats['get_hits'] ) : 0;
			$totals['misses']         += isset( $server_stats['get_misses'] ) ? absint( $server_stats['get_misses'] ) : 0;

			if ( isset( $server_stats['evictions'] ) ) {
				$totals['evictions'] = ( null === $totals['evictions'] ? 0 : $totals['evictions'] ) + absint( $server_stats['evictions'] );
			}

			if ( isset( $server_stats['bytes'] ) ) {
				$totals['bytes_used'] = ( null === $totals['bytes_used'] ? 0 : $totals['bytes_used'] ) + absint( $server_stats['bytes'] );
			}

			if ( isset( $server_stats['limit_maxbytes'] ) ) {
				$totals['bytes_limit'] = ( null === $totals['bytes_limit'] ? 0 : $totals['bytes_limit'] ) + absint( $server_stats['limit_maxbytes'] );
			}

			if ( isset( $server_stats['curr_items'] ) ) {
				$totals['curr_items'] = ( null === $totals['curr_items'] ? 0 : $totals['curr_items'] ) + absint( $server_stats['curr_items'] );
			}

			if ( isset( $server_stats['total_items'] ) ) {
				$totals['total_items'] = ( null === $totals['total_items'] ? 0 : $totals['total_items'] ) + absint( $server_stats['total_items'] );
			}

			if ( isset( $server_stats['curr_connections'] ) ) {
				$totals['curr_connections'] = ( null === $totals['curr_connections'] ? 0 : $totals['curr_connections'] ) + absint( $server_stats['curr_connections'] );
			}

			$totals['uptime'] = max( $totals['uptime'], isset( $server_stats['uptime'] ) ? absint( $server_stats['uptime'] ) : 0 );
		}

		if ( 0 === $totals['nodes_reported'] ) {
			return array();
		}

		return $totals;
	}

	/**
	 * Read one metric from known drop-in public properties.
	 *
	 * @param object        $wp_object_cache Active object cache drop-in instance.
	 * @param array<string> $keys Candidate property names.
	 *
	 * @return int|null
	 */
	protected function read_metric_from_dropin( object $wp_object_cache, array $keys ): ?int {
		foreach ( $keys as $key ) {
			if ( property_exists( $wp_object_cache, $key ) ) {
				try {
					$val = $wp_object_cache->{$key};
					if ( is_numeric( $val ) ) {
						return absint( $val );
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		return null;
	}

	/**
	 * Parse structured output returned by drop-in stats methods.
	 *
	 * @param mixed $stats_result Return value from $wp_object_cache->stats().
	 *
	 * @return array<string, int|null>
	 */
	protected function parse_dropin_stats_struct( mixed $stats_result ): array {
		return array(
			'hits'        => $this->extract_metric_value( $stats_result, array( 'get_hits', 'cache_hits', 'hits' ) ),
			'misses'      => $this->extract_metric_value( $stats_result, array( 'get_misses', 'cache_misses', 'misses' ) ),
			'evictions'   => $this->extract_metric_value( $stats_result, array( 'evictions', 'evicted', 'evicted_unfetched' ) ),
			'bytes_used'  => $this->extract_metric_value( $stats_result, array( 'bytes', 'bytes_used', 'used_bytes' ) ),
			'bytes_limit' => $this->extract_metric_value( $stats_result, array( 'limit_maxbytes', 'maxbytes', 'bytes_limit' ) ),
		);
	}

	/**
	 * Read numeric metric from a small, bounded array/object (stats results).
	 *
	 * @param mixed         $source Value to inspect.
	 * @param array<string> $keys Candidate keys.
	 *
	 * @return int|null
	 */
	protected function extract_metric_value( mixed $source, array $keys ): ?int {
		if ( is_scalar( $source ) || null === $source ) {
			return null;
		}

		$normalized_keys = array_map( 'strtolower', array_map( 'strval', $keys ) );
		$stack           = array( array( $source, 0 ) );
		$sum             = null;
		$max_depth       = 3;
		$nodes_visited   = 0;
		$max_nodes       = 200;

		while ( ! empty( $stack ) && $nodes_visited < $max_nodes ) {
			list( $node, $depth ) = array_pop( $stack );
			++$nodes_visited;

			if ( is_object( $node ) ) {
				$node = get_object_vars( $node );
			}

			if ( ! is_array( $node ) ) {
				continue;
			}

			foreach ( $node as $key => $value ) {
				if ( $depth < $max_depth && ( is_array( $value ) || is_object( $value ) ) ) {
					$stack[] = array( $value, $depth + 1 );
				}

				if ( ! is_string( $key ) ) {
					continue;
				}

				if ( ! in_array( strtolower( $key ), $normalized_keys, true ) || ! is_numeric( $value ) ) {
					continue;
				}

				$sum = ( null === $sum ? 0 : $sum ) + absint( $value );
			}
		}

		return $sum;
	}

	/**
	 * Parse text output from drop-in stats method.
	 *
	 * @param string $stats_text Stats text output.
	 *
	 * @return array<string, int|null>
	 */
	protected function parse_dropin_stats_text( string $stats_text ): array {
		$output = array(
			'hits'        => null,
			'misses'      => null,
			'evictions'   => null,
			'bytes_used'  => null,
			'bytes_limit' => null,
		);

		if ( '' === trim( $stats_text ) ) {
			return $output;
		}

		if ( preg_match( '/\bget_hits\D+(\d+)/i', $stats_text, $matches ) ) {
			$output['hits'] = absint( $matches[1] );
		}

		if ( preg_match( '/\bget_misses\D+(\d+)/i', $stats_text, $matches ) ) {
			$output['misses'] = absint( $matches[1] );
		}

		if ( preg_match( '/\bevictions\D+(\d+)/i', $stats_text, $matches ) ) {
			$output['evictions'] = absint( $matches[1] );
		}

		if ( preg_match( '/\bbytes\D+(\d+)/i', $stats_text, $matches ) ) {
			$output['bytes_used'] = absint( $matches[1] );
		}

		if ( preg_match( '/\blimit_maxbytes\D+(\d+)/i', $stats_text, $matches ) ) {
			$output['bytes_limit'] = absint( $matches[1] );
		}

		return $output;
	}
}
