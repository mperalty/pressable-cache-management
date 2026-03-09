<?php
/**
 * Object Cache + Memcached Intelligence (Pillar 3).
 *
 * Read-only diagnostics designed for WPCloud safety.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Feature flag for object cache intelligence.
 *
 * @return bool
 */
function pcm_object_cache_intelligence_is_enabled(): bool {
    $enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );

    return (bool) apply_filters( 'pcm_enable_object_cache_intelligence', $enabled );
}

/**
 * Unified adapter contract.
 */
interface PCM_Object_Cache_Stats_Provider_Interface {
    /**
     * Return a normalized metrics payload.
     *
     * @return array<string, mixed>
     */
    public function get_metrics(): array;

    /**
     * Identifier for active provider.
     *
     * @return string
     */
    public function get_provider_key(): string;
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

        if ( ! is_object( $wp_object_cache ) ) {
            return array();
        }

        $hits   = $this->read_metric_from_dropin( $wp_object_cache, array( 'cache_hits', 'get_hits', 'hits' ) );
        $misses = $this->read_metric_from_dropin( $wp_object_cache, array( 'cache_misses', 'get_misses', 'misses' ) );

        $evictions = $this->read_metric_from_dropin( $wp_object_cache, array( 'evictions', 'evicted', 'evicted_unfetched' ) );
        $bytes     = $this->read_metric_from_dropin( $wp_object_cache, array( 'bytes', 'bytes_used', 'used_bytes' ) );
        $limit     = $this->read_metric_from_dropin( $wp_object_cache, array( 'limit_maxbytes', 'maxbytes', 'bytes_limit' ) );

        if ( method_exists( $wp_object_cache, 'stats' ) ) {
            ob_start();
            $stats_result = $wp_object_cache->stats();
            $stats_text   = (string) ob_get_clean();

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

        $client_stats = $this->get_client_stats( $wp_object_cache );
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
        foreach ( array( 'm', 'mc', 'memcache', 'memcached', 'client' ) as $prop ) {
            if ( ! isset( $wp_object_cache->{$prop} ) || ! is_object( $wp_object_cache->{$prop} ) ) {
                continue;
            }

            if ( $wp_object_cache->{$prop} instanceof Memcached || $wp_object_cache->{$prop} instanceof Memcache ) {
                return $wp_object_cache->{$prop};
            }
        }

        return null;
    }

    /**
     * Read normalized stats from an active drop-in client connection.
     *
     * @param object $wp_object_cache Active object cache drop-in instance.
     *
     * @return array<string, int|null>
     */
    protected function get_client_stats( object $wp_object_cache ): array {
        $client = $this->get_underlying_client( $wp_object_cache );
        if ( ! $client ) {
            return array();
        }

        if ( $client instanceof Memcached ) {
            $all_stats = $client->getStats();
        } else {
            $all_stats = $client->getExtendedStats();
        }

        if ( ! is_array( $all_stats ) || empty( $all_stats ) ) {
            return array();
        }

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

        $all_stats = $memcached->getStats();
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
            $connected = @$client->connect( $host, $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( ! $connected ) {
                continue;
            }

            $node_stats = $client->getExtendedStats();
            if ( is_array( $node_stats ) ) {
                $all_stats = array_merge( $all_stats, $node_stats );
            }

            $client->close();
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
     * @return PCM_Object_Cache_Stats_Provider_Interface
     */
    public function resolve(): PCM_Object_Cache_Stats_Provider_Interface {
        $providers = array(
            new PCM_Object_Cache_Dropin_Stats_Provider(),
            new PCM_Object_Cache_Memcached_Extension_Stats_Provider(),
            new PCM_Object_Cache_Memcache_Extension_Stats_Provider(),
        );

        foreach ( $providers as $provider ) {
            $metrics = $provider->get_metrics();
            if ( ! empty( $metrics ) ) {
                return $provider;
            }
        }

        return new PCM_Object_Cache_Null_Stats_Provider();
    }
}

/**
 * Evaluate health heuristics and generate recommendations.
 */
class PCM_Object_Cache_Health_Evaluator {
    /**
     * @param array<string, mixed> $metrics Metrics payload.
     *
     * @return array{health: string, recommendations: array<int, array<string, mixed>>}
     */
    public function evaluate( array $metrics ): array {
        $health = 'connected';
        $recommendations = array();

        if ( empty( $metrics ) || 'offline' === $metrics['status'] ) {
            return array(
                'health' => 'offline',
                'recommendations' => array(
                    array(
                        'rule_id'  => 'memcache_unavailable',
                        'severity' => 'critical',
                        'message'  => 'Object cache statistics are unavailable. Verify object-cache drop-in and Memcached connectivity.',
                        'checklist'=> array(
                            'Confirm object-cache.php drop-in exists and loads without warnings.',
                            'Confirm Memcached service endpoint is reachable from WP runtime.',
                            'Re-check stats after infrastructure validation.',
                        ),
                    ),
                ),
            );
        }

        $hit_ratio = isset( $metrics['hit_ratio'] ) ? $metrics['hit_ratio'] : null;
        $evictions = isset( $metrics['evictions'] ) ? absint( $metrics['evictions'] ) : 0;

        if ( null !== $hit_ratio && $hit_ratio < 70 ) {
            $health = 'degraded';
            $recommendations[] = array(
                'rule_id'  => 'low_hit_ratio',
                'severity' => 'warning',
                'message'  => sprintf( 'Hit ratio is %s%% (below 70%% warning threshold). Investigate short TTLs and high key churn.', $hit_ratio ),
                'checklist'=> array(
                    'Review frequent cache flush triggers in plugin/theme workflow.',
                    'Verify cache key normalization for anonymous requests.',
                    'Measure hit ratio again after configuration changes.',
                ),
            );
        }

        if ( $evictions > 0 ) {
            $memory_pressure = pcm_calculate_memory_pressure( $metrics );

            if ( $evictions >= 100 || $memory_pressure >= 90 ) {
                $health = 'degraded';
                $recommendations[] = array(
                    'rule_id'  => 'high_evictions_or_pressure',
                    'severity' => 'critical',
                    'message'  => sprintf( 'Evictions (%d) and/or memory pressure (%s%%) indicate cache churn and likely reduced effectiveness.', $evictions, $memory_pressure ),
                    'checklist'=> array(
                        'Check object cache memory allocation and slab usage.',
                        'Reduce oversized or low-value cache entries where possible.',
                        'Verify lower eviction trend after adjustments.',
                    ),
                );
            }
        }

        return array(
            'health'          => $health,
            'recommendations' => $recommendations,
        );
    }
}

/**
 * Facade service used by admin UI/scheduler in future slices.
 */
class PCM_Object_Cache_Intelligence_Service {
    protected PCM_Object_Cache_Stats_Provider_Resolver $resolver;

    protected PCM_Object_Cache_Health_Evaluator $evaluator;

    /**
     * @param PCM_Object_Cache_Stats_Provider_Resolver|null $resolver  Resolver dependency.
     * @param PCM_Object_Cache_Health_Evaluator|null        $evaluator Evaluator dependency.
     */
    public function __construct(
        ?PCM_Object_Cache_Stats_Provider_Resolver $resolver = null,
        ?PCM_Object_Cache_Health_Evaluator $evaluator = null,
    ) {
        $this->resolver  = $resolver ?? new PCM_Object_Cache_Stats_Provider_Resolver();
        $this->evaluator = $evaluator ?? new PCM_Object_Cache_Health_Evaluator();
    }

    /**
     * Collect one normalized intelligence payload.
     *
     * @return array<string, mixed>
     */
    public function collect_snapshot(): array {
        if ( ! pcm_object_cache_intelligence_is_enabled() ) {
            return array();
        }

        $provider = $this->resolver->resolve();
        $metrics  = $provider->get_metrics();
        $derived  = $this->evaluator->evaluate( $metrics );

        return array(
            'taken_at'         => current_time( 'mysql', true ),
            'provider'         => $provider->get_provider_key(),
            'status'           => $metrics['status'] ?? 'offline',
            'health'           => $derived['health'],
            'hits'             => $metrics['hits'] ?? null,
            'misses'           => $metrics['misses'] ?? null,
            'hit_ratio'        => $metrics['hit_ratio'] ?? null,
            'evictions'        => $metrics['evictions'] ?? null,
            'bytes_used'       => $metrics['bytes_used'] ?? null,
            'bytes_limit'      => $metrics['bytes_limit'] ?? null,
            'memory_pressure'  => pcm_calculate_memory_pressure( $metrics ),
            'recommendations'  => $derived['recommendations'],
            'meta'             => $metrics['meta'] ?? array(),
        );
    }
}

/**
 * @param int|null $hits Hits.
 * @param int|null $misses Misses.
 *
 * @return float|null
 */
function pcm_calculate_hit_ratio( ?int $hits, ?int $misses ): ?float {
    if ( null === $hits || null === $misses ) {
        return null;
    }

    $total = absint( $hits ) + absint( $misses );
    if ( 0 === $total ) {
        return null;
    }

    return round( ( absint( $hits ) / $total ) * 100, 2 );
}

/**
 * @param array<string, mixed> $metrics Metrics payload.
 *
 * @return float
 */
function pcm_calculate_memory_pressure( array $metrics ): float {
    $used  = isset( $metrics['bytes_used'] ) ? absint( $metrics['bytes_used'] ) : 0;
    $limit = isset( $metrics['bytes_limit'] ) ? absint( $metrics['bytes_limit'] ) : 0;

    if ( $limit <= 0 ) {
        return 0.0;
    }

    return round( ( $used / $limit ) * 100, 2 );
}

/**
 * @return array<int,array{host:string,port:int}>
 */
function pcm_object_cache_memcached_servers_from_constant(): array {
    $servers = array();

    if ( ! defined( 'WP_MEMCACHED_SERVERS' ) || ! is_array( WP_MEMCACHED_SERVERS ) ) {
        return $servers;
    }

    foreach ( WP_MEMCACHED_SERVERS as $server_group ) {
        if ( is_array( $server_group ) ) {
            foreach ( $server_group as $server_def ) {
                $parsed = pcm_object_cache_parse_memcache_server( $server_def );
                if ( ! empty( $parsed ) ) {
                    $servers[] = $parsed;
                }
            }
            continue;
        }

        $parsed = pcm_object_cache_parse_memcache_server( $server_group );
        if ( ! empty( $parsed ) ) {
            $servers[] = $parsed;
        }
    }

    return $servers;
}

/**
 * @param mixed $server_def Memcached server definition.
 *
 * @return array<string,int|string>
 */
function pcm_object_cache_parse_memcache_server( mixed $server_def ): array {
    $parts = explode( ':', (string) $server_def );
    $host  = isset( $parts[0] ) ? sanitize_text_field( $parts[0] ) : '';
    $port  = isset( $parts[1] ) ? absint( $parts[1] ) : 11211;

    if ( '' === $host ) {
        return array();
    }

    return array(
        'host' => $host,
        'port' => $port > 0 ? $port : 11211,
    );
}

/**
 * @param int $bytes Bytes.
 *
 * @return float
 */
function pcm_object_cache_bytes_to_gb( int $bytes ): float {
    return round( absint( $bytes ) / 1024 / 1024 / 1024, 2 );
}

/**
 * @param int $uptime_seconds Uptime in seconds.
 *
 * @return string
 */
function pcm_object_cache_format_uptime( int $uptime_seconds ): string {
    $uptime = absint( $uptime_seconds );
    if ( $uptime <= 0 ) {
        return 'n/a';
    }

    $days = floor( $uptime / DAY_IN_SECONDS );
    $remaining = $uptime % DAY_IN_SECONDS;
    $hours = floor( $remaining / HOUR_IN_SECONDS );
    $minutes = floor( ( $remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

    return sprintf( '%dd %dh %dm', $days, $hours, $minutes );
}

/**
 * Snapshot storage for object cache intelligence (A3.1).
 */
class PCM_Object_Cache_Snapshot_Storage {
    protected string $key = 'pcm_object_cache_snapshots_v1';

    protected int $max_rows = 2000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array {
        $rows = get_option( $this->key, array() );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param array<string, mixed> $snapshot Snapshot.
     *
     * @return void
     */
    public function append( array $snapshot ): void {
        $rows   = $this->all();
        $rows[] = $snapshot;
        update_option( $this->key, array_slice( $rows, -1 * $this->max_rows ), false );
    }

    /**
     * @param string $range 24h|7d|30d
     *
     * @return array<int, array<string, mixed>>
     */
    public function query( string $range = '7d' ): array {
        $days_map = array(
            '24h' => 1,
            '7d'  => 7,
            '30d' => 30,
        );

        $days      = isset( $days_map[ $range ] ) ? $days_map[ $range ] : 7;
        $cutoff_ts = time() - ( DAY_IN_SECONDS * $days );

        return array_values(
            array_filter(
                $this->all(),
                static function ( array $row ) use ( $cutoff_ts ): bool {
                    $taken_at = isset( $row['taken_at'] ) ? strtotime( $row['taken_at'] ) : 0;

                    return $taken_at >= $cutoff_ts;
                }
            )
        );
    }

    /**
     * @param int $retention_days Days.
     *
     * @return void
     */
    public function cleanup( int $retention_days = 90 ): void {
        $retention = max( 7, min( 365, absint( $retention_days ) ) );
        $cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

        $rows = array_values(
            array_filter(
                $this->all(),
                static function ( array $row ) use ( $cutoff_ts ): bool {
                    $taken_at = isset( $row['taken_at'] ) ? strtotime( $row['taken_at'] ) : 0;

                    return $taken_at >= $cutoff_ts;
                }
            )
        );

        update_option( $this->key, $rows, false );
    }
}

/**
 * Persist one snapshot and return it.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_collect_and_store_snapshot(): array {
    if ( ! pcm_object_cache_intelligence_is_enabled() ) {
        return array();
    }

    $service  = new PCM_Object_Cache_Intelligence_Service();
    $snapshot = $service->collect_snapshot();

    if ( empty( $snapshot ) ) {
        return array();
    }

    $storage = new PCM_Object_Cache_Snapshot_Storage();
    $storage->append( $snapshot );
    $storage->cleanup( (int) get_option( PCM_Options::OBJECT_CACHE_RETENTION_DAYS->value, 90 ) );

    update_option( PCM_Options::LATEST_OBJECT_CACHE_HIT_RATIO->value, isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : 0, false );
    update_option( PCM_Options::LATEST_OBJECT_CACHE_EVICTIONS->value, isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : 0, false );

    return $snapshot;
}

/**
 * Fetch latest stored snapshot, collecting one if missing.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_get_latest_snapshot(): array {
    $storage = new PCM_Object_Cache_Snapshot_Storage();
    $rows    = $storage->all();

    if ( empty( $rows ) ) {
        return pcm_object_cache_collect_and_store_snapshot();
    }

    $latest = end( $rows );

    return is_array( $latest ) ? $latest : array();
}

/**
 * Build summary trends for object cache diagnostics UI.
 *
 * @param string $range Range.
 *
 * @return array<int, array<string, mixed>>
 */
function pcm_object_cache_get_trends( string $range = '7d' ): array {
    $storage   = new PCM_Object_Cache_Snapshot_Storage();
    $snapshots = $storage->query( $range );

    $points = array();

    foreach ( $snapshots as $snapshot ) {
        $points[] = array(
            'taken_at'        => $snapshot['taken_at'] ?? '',
            'hit_ratio'       => isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : null,
            'evictions'       => isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : null,
            'memory_pressure' => isset( $snapshot['memory_pressure'] ) ? (float) $snapshot['memory_pressure'] : 0,
        );
    }

    return $points;
}

/**
 * Storage for per-route memcache sensitivity assessments.
 */
class PCM_Object_Cache_Route_Sensitivity_Storage {
    protected string $key = 'pcm_route_memcache_sensitivity_v1';

    protected int $max_rows = 5000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array {
        $rows = get_option( $this->key, array() );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param array<int, array<string, mixed>> $rows Rows.
     *
     * @return void
     */
    public function replace( array $rows ): void {
        update_option( $this->key, array_slice( array_values( $rows ), -1 * $this->max_rows ), false );
    }

    /**
     * @param int    $run_id Run ID.
     * @param string $route Route key.
     *
     * @return array<string, mixed>|null
     */
    public function get_for_run_route( int $run_id, string $route ): ?array {
        foreach ( array_reverse( $this->all() ) as $row ) {
            if ( $run_id === (int) $row['run_id'] && $route === (string) $row['route'] ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $assessments Rows.
     *
     * @return void
     */
    public function upsert_batch( array $assessments ): void {
        $existing = $this->all();
        $index    = array();

        foreach ( $assessments as $row ) {
            $index[ (int) $row['run_id'] . '|' . (string) $row['route'] ] = true;
        }

        $filtered = array_values(
            array_filter(
                $existing,
                static function ( array $row ) use ( $index ): bool {
                    $key = (int) ( $row['run_id'] ?? 0 ) . '|' . (string) ( $row['route'] ?? '' );

                    return ! isset( $index[ $key ] );
                }
            )
        );

        $this->replace( array_merge( $filtered, $assessments ) );
    }

    /**
     * @param int $retention_days Days.
     *
     * @return void
     */
    public function cleanup( int $retention_days = 90 ): void {
        $retention = max( 7, min( 365, absint( $retention_days ) ) );
        $cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

        $this->replace(
            array_values(
                array_filter(
                    $this->all(),
                    static function ( array $row ) use ( $cutoff_ts ): bool {
                        $taken_at = isset( $row['assessed_at'] ) ? strtotime( $row['assessed_at'] ) : 0;

                        return $taken_at >= $cutoff_ts;
                    }
                )
            )
        );
    }
}

/**
 * Correlate object-cache state to cacheability URL findings and score route sensitivity.
 */
class PCM_Object_Cache_Route_Correlation_Service {
    /**
     * @param int $run_id Run ID. 0 means latest completed run.
     *
     * @return array<string, mixed>
     */
    public function correlate_latest( int $run_id = 0 ): array {
        global $wpdb;

        $snapshot = pcm_object_cache_get_latest_snapshot();

        $runs_table = $wpdb->prefix . 'pcm_scan_runs';
        $urls_table = $wpdb->prefix . 'pcm_scan_urls';
        $find_table = $wpdb->prefix . 'pcm_findings';

        $selected_run_id = absint( $run_id );
        if ( $selected_run_id <= 0 ) {
            $selected_run_id = (int) $wpdb->get_var( "SELECT id FROM {$runs_table} WHERE status = 'completed' ORDER BY id DESC LIMIT 1" );
        }

        if ( $selected_run_id <= 0 ) {
            return array(
                'run_id'          => 0,
                'snapshot'        => $snapshot,
                'assessed_at'     => current_time( 'mysql', true ),
                'routes'          => array(),
                'high_route_count'=> 0,
            );
        }

        $url_rows = $wpdb->get_results( $wpdb->prepare( "SELECT url, score FROM {$urls_table} WHERE run_id = %d", $selected_run_id ), ARRAY_A );
        $findings = $wpdb->get_results( $wpdb->prepare( "SELECT url, rule_id, severity FROM {$find_table} WHERE run_id = %d", $selected_run_id ), ARRAY_A );

        $grouped_findings = array();
        foreach ( (array) $findings as $finding ) {
            $url = isset( $finding['url'] ) ? (string) $finding['url'] : '';
            if ( '' === $url ) {
                continue;
            }

            if ( ! isset( $grouped_findings[ $url ] ) ) {
                $grouped_findings[ $url ] = array();
            }
            $grouped_findings[ $url ][] = $finding;
        }

        $assessed_at   = current_time( 'mysql', true );
        $route_groups  = array();

        foreach ( (array) $url_rows as $url_row ) {
            $url   = isset( $url_row['url'] ) ? esc_url_raw( $url_row['url'] ) : '';
            $score = isset( $url_row['score'] ) ? (int) $url_row['score'] : 0;
            if ( '' === $url ) {
                continue;
            }

            $route = pcm_object_cache_route_from_url( $url );
            $calc  = pcm_object_cache_calculate_memcache_sensitivity( $score, $grouped_findings[ $url ] ?? array(), $snapshot );

            if ( ! isset( $route_groups[ $route ] ) ) {
                $route_groups[ $route ] = array(
                    'route'             => $route,
                    'sample_url'        => $url,
                    'scores'            => array(),
                    'critical_findings' => 0,
                    'warning_findings'  => 0,
                    'expensive_signals' => 0,
                    'reasons'           => array(),
                );
            }

            $route_groups[ $route ]['scores'][]            = $score;
            $route_groups[ $route ]['critical_findings']  += (int) $calc['critical_findings'];
            $route_groups[ $route ]['warning_findings']   += (int) $calc['warning_findings'];
            $route_groups[ $route ]['expensive_signals']  += (int) $calc['expensive_signals'];
            $route_groups[ $route ]['reasons']             = array_values( array_unique( array_merge( $route_groups[ $route ]['reasons'], (array) $calc['reasons'] ) ) );
        }

        $rows = array();
        foreach ( $route_groups as $route => $group ) {
            $avg_score = ! empty( $group['scores'] ) ? (int) round( array_sum( $group['scores'] ) / count( $group['scores'] ) ) : 0;
            $calc      = pcm_object_cache_calculate_memcache_sensitivity(
                $avg_score,
                array(
                    array( 'severity' => 'critical', 'rule_id' => 'route_aggregate', 'count' => (int) $group['critical_findings'] ),
                    array( 'severity' => 'warning', 'rule_id' => 'route_aggregate', 'count' => (int) $group['warning_findings'] ),
                ),
                $snapshot
            );

            $rows[] = array(
                'run_id'               => $selected_run_id,
                'url'                  => $group['sample_url'],
                'route'                => $route,
                'memcache_sensitivity' => $calc['sensitivity'],
                'assessed_at'          => $assessed_at,
                'metrics'              => array(
                    'score'             => $avg_score,
                    'url_count'         => count( $group['scores'] ),
                    'expensive_signals' => (int) $group['expensive_signals'],
                    'critical_findings' => (int) $group['critical_findings'],
                    'warning_findings'  => (int) $group['warning_findings'],
                    'hit_ratio'         => isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : null,
                    'evictions'         => isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : null,
                    'memory_pressure'   => isset( $snapshot['memory_pressure'] ) ? (float) $snapshot['memory_pressure'] : 0,
                    'reasons'           => array_values( array_unique( array_merge( (array) $group['reasons'], (array) $calc['reasons'] ) ) ),
                ),
            );
        }

        $storage = new PCM_Object_Cache_Route_Sensitivity_Storage();
        $storage->upsert_batch( $rows );
        $storage->cleanup( (int) get_option( PCM_Options::OBJECT_CACHE_RETENTION_DAYS->value, 90 ) );

        $high_count = 0;
        foreach ( $rows as $row ) {
            if ( 'high' === $row['memcache_sensitivity'] ) {
                $high_count++;
            }
        }

        return array(
            'run_id'           => $selected_run_id,
            'snapshot'         => $snapshot,
            'assessed_at'      => $assessed_at,
            'routes'           => $rows,
            'high_route_count' => $high_count,
        );
    }
}

/**
 * @param string $url URL.
 *
 * @return string
 */
function pcm_object_cache_route_from_url( string $url ): string {
    $path = wp_parse_url( $url, PHP_URL_PATH );
    if ( ! is_string( $path ) || '' === $path ) {
        return '/';
    }

    return '/' . ltrim( untrailingslashit( $path ), '/' );
}

/**
 * @param int                          $score    Cacheability score.
 * @param array<int, array<string, mixed>> $findings Findings for URL.
 * @param array<string, mixed>         $snapshot Object cache snapshot.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_calculate_memcache_sensitivity( int $score, array $findings, array $snapshot ): array {
    $score     = max( 0, min( 100, absint( $score ) ) );
    $hit_ratio = isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : 0.0;
    $evictions = isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : 0.0;
    $pressure  = isset( $snapshot['memory_pressure'] ) ? (float) $snapshot['memory_pressure'] : 0.0;

    $critical = 0;
    $warning  = 0;
    $expensive_signals = 0;

    foreach ( $findings as $finding ) {
        $severity = isset( $finding['severity'] ) ? sanitize_key( $finding['severity'] ) : '';
        $rule_id  = isset( $finding['rule_id'] ) ? sanitize_key( $finding['rule_id'] ) : '';

        $count = isset( $finding['count'] ) ? max( 0, absint( $finding['count'] ) ) : 1;

        if ( 'critical' === $severity ) {
            $critical += $count;
            $expensive_signals += ( 2 * $count );
        } elseif ( 'warning' === $severity ) {
            $warning += $count;
            $expensive_signals += $count;
        }

        if ( str_contains( $rule_id, 'cookie' ) || str_contains( $rule_id, 'vary' ) || str_contains( $rule_id, 'query' ) || str_contains( $rule_id, 'nocache' ) ) {
            $expensive_signals += 1;
        }
    }

    $low_score            = $score <= 55;
    $below_hit_threshold  = $hit_ratio > 0 && $hit_ratio < 70;
    $eviction_pressure    = $evictions >= 100 || $pressure >= 90;
    $has_expensive_signal = $expensive_signals >= 2;
    $reasons              = array();

    if ( $low_score ) {
        $reasons[] = 'low_cacheability_score';
    }
    if ( $has_expensive_signal ) {
        $reasons[] = 'expensive_signals';
    }
    if ( $below_hit_threshold ) {
        $reasons[] = 'low_object_cache_hit_ratio';
    }
    if ( $eviction_pressure ) {
        $reasons[] = 'eviction_or_memory_pressure';
    }

    if ( $low_score && $has_expensive_signal && ( $below_hit_threshold || $eviction_pressure ) ) {
        $sensitivity = 'high';
    } elseif ( ( $low_score && $expensive_signals >= 1 ) || ( $has_expensive_signal && ( $below_hit_threshold || $eviction_pressure ) ) ) {
        $sensitivity = 'medium';
    } else {
        $sensitivity = 'low';
    }

    return array(
        'sensitivity'       => $sensitivity,
        'expensive_signals' => $expensive_signals,
        'critical_findings' => $critical,
        'warning_findings'  => $warning,
        'reasons'           => $reasons,
    );
}

/**
 * @param string $range 24h|7d|30d
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_memcache_sensitivity_summary( string $range = '24h' ): array {
    $storage = new PCM_Object_Cache_Route_Sensitivity_Storage();
    $rows    = $storage->all();

    $days_map = array(
        '24h' => 1,
        '7d'  => 7,
        '30d' => 30,
    );
    $days     = isset( $days_map[ $range ] ) ? $days_map[ $range ] : 1;
    $cutoff   = time() - ( DAY_IN_SECONDS * $days );
    $filtered = array_values(
        array_filter(
            $rows,
            static function ( array $row ) use ( $cutoff ): bool {
                $ts = isset( $row['assessed_at'] ) ? strtotime( $row['assessed_at'] ) : 0;

                return $ts >= $cutoff;
            }
        )
    );

    $high_routes = array();
    foreach ( $filtered as $row ) {
        if ( 'high' !== ( $row['memcache_sensitivity'] ?? '' ) ) {
            continue;
        }
        $high_routes[ (string) $row['route'] ] = true;
    }

    return array(
        'range'            => $range,
        'high_route_count' => count( $high_routes ),
        'rows'             => $filtered,
    );
}

/**
 * AJAX: latest object cache diagnostics snapshot.
 *
 * @return void
 */
function pcm_ajax_object_cache_snapshot(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( function_exists( 'pcm_current_user_can' ) ) {
            $can = pcm_current_user_can( 'pcm_view_diagnostics' );
        } else {
            $can = current_user_can( 'manage_options' );
        }

        if ( ! $can ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $force_collect = isset( $_REQUEST['refresh'] ) && '1' === (string) wp_unslash( $_REQUEST['refresh'] );
    $snapshot      = $force_collect ? pcm_object_cache_collect_and_store_snapshot() : pcm_object_cache_get_latest_snapshot();

    wp_send_json_success( array( 'snapshot' => $snapshot ) );
}
add_action( 'wp_ajax_pcm_object_cache_snapshot', 'pcm_ajax_object_cache_snapshot' );

/**
 * AJAX: object cache trends.
 *
 * @return void
 */
function pcm_ajax_object_cache_trends(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( function_exists( 'pcm_current_user_can' ) ) {
            $can = pcm_current_user_can( 'pcm_view_diagnostics' );
        } else {
            $can = current_user_can( 'manage_options' );
        }

        if ( ! $can ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $range = isset( $_REQUEST['range'] ) ? sanitize_key( wp_unslash( $_REQUEST['range'] ) ) : '7d';

    wp_send_json_success(
        array(
            'range'  => $range,
            'points' => pcm_object_cache_get_trends( $range ),
        )
    );
}
add_action( 'wp_ajax_pcm_object_cache_trends', 'pcm_ajax_object_cache_trends' );

/**
 * AJAX: per-route memcache sensitivity for latest or provided run.
 *
 * @return void
 */
function pcm_ajax_route_memcache_sensitivity(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        $can = function_exists( 'pcm_current_user_can' ) ? pcm_current_user_can( 'pcm_view_diagnostics' ) : current_user_can( 'manage_options' );
        if ( ! $can ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $run_id  = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
    $service = new PCM_Object_Cache_Route_Correlation_Service();
    $result  = $service->correlate_latest( $run_id );

    $routes = isset( $result['routes'] ) ? (array) $result['routes'] : array();
    usort(
        $routes,
        static function ( array $a, array $b ): int {
            $rank = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
            $a_s  = $a['memcache_sensitivity'] ?? 'low';
            $b_s  = $b['memcache_sensitivity'] ?? 'low';
            $a_r  = $rank[ $a_s ] ?? 0;
            $b_r  = $rank[ $b_s ] ?? 0;

            if ( $a_r === $b_r ) {
                $a_score = isset( $a['metrics']['score'] ) ? (int) $a['metrics']['score'] : 0;
                $b_score = isset( $b['metrics']['score'] ) ? (int) $b['metrics']['score'] : 0;

                return $a_score <=> $b_score;
            }

            return $b_r <=> $a_r;
        }
    );

    $summary_24h = pcm_object_cache_memcache_sensitivity_summary( '24h' );
    $summary_7d  = pcm_object_cache_memcache_sensitivity_summary( '7d' );

    wp_send_json_success(
        array(
            'run_id'       => isset( $result['run_id'] ) ? (int) $result['run_id'] : 0,
            'assessed_at'  => $result['assessed_at'] ?? '',
            'top_routes'   => array_slice( $routes, 0, 10 ),
            'summary'      => array(
                'high_24h' => isset( $summary_24h['high_route_count'] ) ? (int) $summary_24h['high_route_count'] : 0,
                'high_7d'  => isset( $summary_7d['high_route_count'] ) ? (int) $summary_7d['high_route_count'] : 0,
            ),
        )
    );
}
add_action( 'wp_ajax_pcm_route_memcache_sensitivity', 'pcm_ajax_route_memcache_sensitivity' );

/**
 * Ensure recurring object-cache snapshot collection is scheduled.
 *
 * @return void
 */
function pcm_object_cache_maybe_schedule_snapshot_collection(): void {
    if ( ! pcm_object_cache_intelligence_is_enabled() ) {
        return;
    }

    if ( ! wp_next_scheduled( 'pcm_object_cache_collect_snapshot' ) ) {
        wp_schedule_event( time() + 180, 'hourly', 'pcm_object_cache_collect_snapshot' );
    }
}
add_action( 'init', 'pcm_object_cache_maybe_schedule_snapshot_collection' );

/**
 * Cron hook callback for snapshot collection.
 *
 * @return void
 */
function pcm_object_cache_collect_snapshot(): void {
    pcm_object_cache_collect_and_store_snapshot();
}
add_action( 'pcm_object_cache_collect_snapshot', 'pcm_object_cache_collect_snapshot' );
