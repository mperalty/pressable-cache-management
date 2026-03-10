<?php
/**
 * Object Cache Intelligence — Stats Provider Implementations & Resolver.
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
            error_log( sprintf( '[PCM OCI dropin] Property reads: hits=%s, misses=%s, evictions=%s, bytes=%s, limit=%s',
                var_export( $hits, true ), var_export( $misses, true ),
                var_export( $evictions, true ), var_export( $bytes, true ), var_export( $limit, true )
            ) );
        }

        // Only call the potentially-blocking stats() method when we couldn't
        // read basic metrics from the drop-in properties above.
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
                // stats() can block or throw — clean up output buffer and continue.
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
            'provider'      => $this->get_provider_key(),
            'status'        => 'connected',
            'hits'          => $hits,
            'misses'        => $misses,
            'hit_ratio'     => pcm_calculate_hit_ratio( $hits, $misses ),
            'evictions'     => $evictions,
            'bytes_used'    => $bytes,
            'bytes_limit'   => $limit,
            'meta'          => array(
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

        // First pass: try direct property access (works for public props).
        foreach ( $props_to_check as $prop ) {
            if ( ! isset( $wp_object_cache->{$prop} ) ) {
                continue;
            }

            $found = $this->extract_client_from_value( $wp_object_cache->{$prop} );
            if ( $found ) {
                return $found;
            }
        }

        // Second pass: use Reflection to reach protected/private properties.
        // The Automattic/Pressable drop-in declares $mc as protected, so
        // isset() returns false from outside the class.
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
            // Reflection failed — nothing more we can do.
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

        // Automattic/Pressable drop-in stores an array of clients keyed
        // by server group: $this->mc = ['default' => Memcached, ...].
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
     * Read normalized stats via a FRESH Memcached connection.
     *
     * IMPORTANT: We must NOT call getStats() on the drop-in's existing
     * Memcached client because it uses persistent connections.  Socket-level
     * timeouts set via setOption() only apply when a NEW connection is
     * established, not to already-open persistent sockets.  Calling
     * getStats() on the existing client can block PHP indefinitely.
     *
     * Instead we: (1) read the server list from the existing client
     * (non-blocking), (2) create a fresh non-persistent Memcached instance
     * with timeouts applied BEFORE connecting, (3) call getStats() on the
     * fresh instance where the timeouts are actually honoured.
     *
     * @param object $wp_object_cache Active object cache drop-in instance.
     *
     * @return array<string, int|null>
     */
    protected function get_client_stats( object $wp_object_cache ): array {
        $client = $this->get_underlying_client( $wp_object_cache );

        // Build a server list: prefer extracting from the existing client,
        // fall back to the WP_MEMCACHED_SERVERS constant.
        $servers = array();

        if ( $client instanceof Memcached ) {
            try {
                $server_list = $client->getServerList();
                if ( is_array( $server_list ) ) {
                    foreach ( $server_list as $srv ) {
                        $host = $srv['host'] ?? '';
                        $port = $srv['port'] ?? 11211;
                        if ( '' !== $host ) {
                            $servers[] = array( 'host' => $host, 'port' => (int) $port );
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // getServerList() should never throw, but guard anyway.
            }
        } elseif ( $client instanceof Memcache ) {
            // Legacy Memcache extension — no reliable getServerList().
            // Fall through to constant-based servers below.
        }

        // Fallback: read server definitions from the constant.
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

        // Create a fresh, non-persistent Memcached instance with timeouts
        // applied BEFORE adding servers so they govern the new connections.
        if ( ! class_exists( 'Memcached' ) ) {
            return array();
        }

        $fresh = new Memcached(); // No persistent_id → non-persistent.
        $fresh->setOption( Memcached::OPT_CONNECT_TIMEOUT, 2000 );    // 2 s connect.
        $fresh->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );    // 1 s send (μs).
        $fresh->setOption( Memcached::OPT_RECV_TIMEOUT, 2000000 );    // 2 s receive (μs).
        $fresh->setOption( Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT );
        $fresh->setOption( Memcached::OPT_BINARY_PROTOCOL, true );

        foreach ( $servers as $srv ) {
            $fresh->addServer( $srv['host'], (int) ( $srv['port'] ?? 11211 ) );
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
            error_log( sprintf(
                '[PCM OCI] get_client_stats: getStats() returned %s, result_code=%d (%s).',
                ( is_array( $all_stats ) ? 'array(' . count( $all_stats ) . ')' : gettype( $all_stats ) ),
                $result_code,
                $fresh->getResultMessage()
            ) );
        }

        if ( ! is_array( $all_stats ) || empty( $all_stats ) ) {
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
            if ( ! is_array( $server_stats ) || empty( $server_stats ) ) {
                continue;
            }

            $totals['nodes_reported'] += 1;
            $totals['hits']   += isset( $server_stats['get_hits'] ) ? absint( $server_stats['get_hits'] ) : 0;
            $totals['misses'] += isset( $server_stats['get_misses'] ) ? absint( $server_stats['get_misses'] ) : 0;

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
     * Read one metric from known drop-in properties.
     *
     * @param object        $wp_object_cache Active object cache drop-in instance.
     * @param array<string> $keys            Candidate keys.
     *
     * @return int|null
     */
    protected function read_metric_from_dropin( object $wp_object_cache, array $keys ): ?int {
        $value = $this->extract_metric_value( $wp_object_cache, $keys );

        return null === $value ? null : absint( $value );
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
            'hits'       => $this->extract_metric_value( $stats_result, array( 'get_hits', 'cache_hits', 'hits' ) ),
            'misses'     => $this->extract_metric_value( $stats_result, array( 'get_misses', 'cache_misses', 'misses' ) ),
            'evictions'  => $this->extract_metric_value( $stats_result, array( 'evictions', 'evicted', 'evicted_unfetched' ) ),
            'bytes_used' => $this->extract_metric_value( $stats_result, array( 'bytes', 'bytes_used', 'used_bytes' ) ),
            'bytes_limit'=> $this->extract_metric_value( $stats_result, array( 'limit_maxbytes', 'maxbytes', 'bytes_limit' ) ),
        );
    }

    /**
     * Recursively read numeric metric values from nested array/object structures.
     *
     * @param mixed          $source Value to inspect.
     * @param array<string>  $keys   Candidate keys.
     *
     * @return int|null
     */
    protected function extract_metric_value( mixed $source, array $keys ): ?int {
        if ( is_scalar( $source ) || null === $source ) {
            return null;
        }

        $normalized_keys = array_map( 'strtolower', array_map( 'strval', $keys ) );
        $stack           = array( $source );
        $sum             = null;

        while ( ! empty( $stack ) ) {
            $node = array_pop( $stack );
            if ( is_object( $node ) ) {
                $node = get_object_vars( $node );
            }

            if ( ! is_array( $node ) ) {
                continue;
            }

            foreach ( $node as $key => $value ) {
                if ( is_array( $value ) || is_object( $value ) ) {
                    $stack[] = $value;
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
            'hits'       => null,
            'misses'     => null,
            'evictions'  => null,
            'bytes_used' => null,
            'bytes_limit'=> null,
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
        $memcached->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );    // 1 s connect.
        $memcached->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );    // 1 s send (μs).
        $memcached->setOption( Memcached::OPT_RECV_TIMEOUT, 2000000 );    // 2 s receive (μs).
        $servers   = pcm_object_cache_memcached_servers_from_constant();
        $server_ok = false;

        foreach ( $servers as $server ) {
            $host = $server['host'] ?? '';
            $port = $server['port'] ?? 11211;

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
        if ( ! is_array( $all_stats ) || empty( $all_stats ) ) {
            return array();
        }

        $hits       = 0;
        $misses     = 0;
        $evictions  = 0;
        $bytes_used = 0;
        $max_bytes  = 0;
        $curr_items = 0;
        $total_items = 0;
        $curr_connections = 0;
        $uptime = 0;

        foreach ( $all_stats as $server_stats ) {
            if ( ! is_array( $server_stats ) ) {
                continue;
            }

            $hits       += isset( $server_stats['get_hits'] ) ? absint( $server_stats['get_hits'] ) : 0;
            $misses     += isset( $server_stats['get_misses'] ) ? absint( $server_stats['get_misses'] ) : 0;
            $evictions  += isset( $server_stats['evictions'] ) ? absint( $server_stats['evictions'] ) : 0;
            $bytes_used += isset( $server_stats['bytes'] ) ? absint( $server_stats['bytes'] ) : 0;
            $max_bytes  += isset( $server_stats['limit_maxbytes'] ) ? absint( $server_stats['limit_maxbytes'] ) : 0;
            $curr_items += isset( $server_stats['curr_items'] ) ? absint( $server_stats['curr_items'] ) : 0;
            $total_items += isset( $server_stats['total_items'] ) ? absint( $server_stats['total_items'] ) : 0;
            $curr_connections += isset( $server_stats['curr_connections'] ) ? absint( $server_stats['curr_connections'] ) : 0;
            $uptime = max( $uptime, isset( $server_stats['uptime'] ) ? absint( $server_stats['uptime'] ) : 0 );
        }

        return array(
            'provider'      => $this->get_provider_key(),
            'status'        => 'connected',
            'hits'          => $hits,
            'misses'        => $misses,
            'hit_ratio'     => pcm_calculate_hit_ratio( $hits, $misses ),
            'evictions'     => $evictions,
            'bytes_used'    => $bytes_used,
            'bytes_limit'   => $max_bytes,
            'meta'          => array(
                'used_gb'              => pcm_object_cache_bytes_to_gb( $bytes_used ),
                'limit_gb'             => pcm_object_cache_bytes_to_gb( $max_bytes ),
                'curr_items'           => $curr_items,
                'total_items'          => $total_items,
                'curr_connections'     => $curr_connections,
                'uptime_seconds'       => $uptime,
                'uptime_human'         => pcm_object_cache_format_uptime( $uptime ),
                'nodes_reported'       => count( $all_stats ),
            ),
        );
    }
}

/**
 * Provider that uses legacy PHP Memcache extension stats when available.
 */
class PCM_Object_Cache_Memcache_Extension_Stats_Provider implements PCM_Object_Cache_Stats_Provider_Interface {
    /**
     * @return string
     */
    public function get_provider_key(): string {
        return 'memcache_extension';
    }

    /**
     * @return array<string, mixed>
     */
    public function get_metrics(): array {
        if ( ! class_exists( 'Memcache' ) ) {
            return array();
        }

        $servers = pcm_object_cache_memcached_servers_from_constant();
        if ( empty( $servers ) ) {
            return array();
        }

        $all_stats = array();

        foreach ( $servers as $server ) {
            $host = $server['host'] ?? '';
            $port = isset( $server['port'] ) ? absint( $server['port'] ) : 11211;

            if ( '' === $host ) {
                continue;
            }

            $client = new Memcache();
            $connected = @$client->connect( $host, $port, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 1s timeout
            if ( ! $connected ) {
                continue;
            }

            try {
                $node_stats = $client->getExtendedStats();
                if ( is_array( $node_stats ) ) {
                    $all_stats = array_merge( $all_stats, $node_stats );
                }
            } catch ( \Throwable $e ) {
                // getExtendedStats() can block — skip this server.
            }

            @$client->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( empty( $all_stats ) ) {
            return array();
        }

        $hits       = 0;
        $misses     = 0;
        $evictions  = 0;
        $bytes_used = 0;
        $max_bytes  = 0;
        $curr_items = 0;
        $total_items = 0;
        $curr_connections = 0;
        $uptime = 0;

        foreach ( $all_stats as $server_stats ) {
            if ( ! is_array( $server_stats ) ) {
                continue;
            }

            $hits       += isset( $server_stats['get_hits'] ) ? absint( $server_stats['get_hits'] ) : 0;
            $misses     += isset( $server_stats['get_misses'] ) ? absint( $server_stats['get_misses'] ) : 0;
            $evictions  += isset( $server_stats['evictions'] ) ? absint( $server_stats['evictions'] ) : 0;
            $bytes_used += isset( $server_stats['bytes'] ) ? absint( $server_stats['bytes'] ) : 0;
            $max_bytes  += isset( $server_stats['limit_maxbytes'] ) ? absint( $server_stats['limit_maxbytes'] ) : 0;
            $curr_items += isset( $server_stats['curr_items'] ) ? absint( $server_stats['curr_items'] ) : 0;
            $total_items += isset( $server_stats['total_items'] ) ? absint( $server_stats['total_items'] ) : 0;
            $curr_connections += isset( $server_stats['curr_connections'] ) ? absint( $server_stats['curr_connections'] ) : 0;
            $uptime = max( $uptime, isset( $server_stats['uptime'] ) ? absint( $server_stats['uptime'] ) : 0 );
        }

        return array(
            'provider'      => $this->get_provider_key(),
            'status'        => 'connected',
            'hits'          => $hits,
            'misses'        => $misses,
            'hit_ratio'     => pcm_calculate_hit_ratio( $hits, $misses ),
            'evictions'     => $evictions,
            'bytes_used'    => $bytes_used,
            'bytes_limit'   => $max_bytes,
            'meta'          => array(
                'used_gb'              => pcm_object_cache_bytes_to_gb( $bytes_used ),
                'limit_gb'             => pcm_object_cache_bytes_to_gb( $max_bytes ),
                'curr_items'           => $curr_items,
                'total_items'          => $total_items,
                'curr_connections'     => $curr_connections,
                'uptime_seconds'       => $uptime,
                'uptime_human'         => pcm_object_cache_format_uptime( $uptime ),
                'nodes_reported'       => count( $all_stats ),
            ),
        );
    }
}

/**
 * Fallback provider when metrics are unavailable.
 */
class PCM_Object_Cache_Null_Stats_Provider implements PCM_Object_Cache_Stats_Provider_Interface {
    /**
     * @return string
     */
    public function get_provider_key(): string {
        return 'none';
    }

    /**
     * @return array<string, mixed>
     */
    public function get_metrics(): array {
        return array(
            'provider'      => $this->get_provider_key(),
            'status'        => 'offline',
            'hits'          => null,
            'misses'        => null,
            'hit_ratio'     => null,
            'evictions'     => null,
            'bytes_used'    => null,
            'bytes_limit'   => null,
            'meta'          => array(
                'reason' => 'stats_unavailable',
            ),
        );
    }
}

/**
 * Select best available provider.
 */
class PCM_Object_Cache_Stats_Provider_Resolver {
    /**
     * Cached resolve result: [ provider, metrics ].
     *
     * @var array{0: PCM_Object_Cache_Stats_Provider_Interface, 1: array<string, mixed>}|null
     */
    protected ?array $cached = null;

    /**
     * Maximum seconds allowed for the entire provider resolution.
     *
     * @var float
     */
    protected float $time_budget;

    /**
     * @param float $time_budget Maximum seconds for provider resolution (default 5s).
     */
    public function __construct( float $time_budget = 5.0 ) {
        $this->time_budget = $time_budget;
    }

    /**
     * @return PCM_Object_Cache_Stats_Provider_Interface
     */
    public function resolve(): PCM_Object_Cache_Stats_Provider_Interface {
        $result = $this->resolve_with_metrics();

        return $result[0];
    }

    /**
     * Resolve the best provider and return both provider and its metrics.
     *
     * Avoids the cost of calling get_metrics() twice (once during resolution,
     * once from the caller).  Each provider is given a time budget; if a
     * provider exceeds it the resolver moves on to the next one.
     *
     * @return array{0: PCM_Object_Cache_Stats_Provider_Interface, 1: array<string, mixed>}
     */
    public function resolve_with_metrics(): array {
        if ( null !== $this->cached ) {
            return $this->cached;
        }

        $start_time = microtime( true );

        $providers = array(
            new PCM_Object_Cache_Dropin_Stats_Provider(),
            new PCM_Object_Cache_Memcached_Extension_Stats_Provider(),
            new PCM_Object_Cache_Memcache_Extension_Stats_Provider(),
        );

        $log = defined( 'WP_DEBUG' ) && WP_DEBUG;

        foreach ( $providers as $provider ) {
            $elapsed = microtime( true ) - $start_time;
            if ( $elapsed >= $this->time_budget ) {
                if ( $log ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( sprintf( '[PCM OCI] Time budget exhausted (%.2fs/%.2fs) before trying %s.', $elapsed, $this->time_budget, $provider->get_provider_key() ) );
                }
                break;
            }

            $provider_start = microtime( true );

            if ( $log ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( sprintf( '[PCM OCI] Trying provider: %s (elapsed: %.2fs)', $provider->get_provider_key(), $elapsed ) );
            }

            try {
                $metrics = $provider->get_metrics();
            } catch ( \Throwable $e ) {
                if ( $log ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( sprintf( '[PCM OCI] Provider %s threw %s: %s (took %.2fs)', $provider->get_provider_key(), get_class( $e ), $e->getMessage(), microtime( true ) - $provider_start ) );
                }
                continue;
            }

            $provider_elapsed = microtime( true ) - $provider_start;

            if ( ! empty( $metrics ) ) {
                if ( $log ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( sprintf( '[PCM OCI] Provider %s succeeded in %.2fs. Status: %s, hits: %s', $provider->get_provider_key(), $provider_elapsed, $metrics['status'] ?? '?', $metrics['hits'] ?? 'null' ) );
                }
                $this->cached = array( $provider, $metrics );

                return $this->cached;
            }

            if ( $log ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( sprintf( '[PCM OCI] Provider %s returned empty in %.2fs.', $provider->get_provider_key(), $provider_elapsed ) );
            }
        }

        $fallback       = new PCM_Object_Cache_Null_Stats_Provider();
        $this->cached   = array( $fallback, $fallback->get_metrics() );

        return $this->cached;
    }
}
