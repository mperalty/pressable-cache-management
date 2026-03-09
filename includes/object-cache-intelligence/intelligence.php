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
function pcm_object_cache_intelligence_is_enabled() {
    $enabled = (bool) get_option( 'pcm_enable_caching_suite_features', false );

    return (bool) apply_filters( 'pcm_enable_object_cache_intelligence', $enabled );
}

/**
 * Unified adapter contract.
 */
interface PCM_Object_Cache_Stats_Provider_Interface {
    /**
     * Return a normalized metrics payload.
     *
     * @return array
     */
    public function get_metrics();

    /**
     * Identifier for active provider.
     *
     * @return string
     */
    public function get_provider_key();
}

/**
 * Provider that attempts to read stats from the object cache drop-in.
 */
class PCM_Object_Cache_Dropin_Stats_Provider implements PCM_Object_Cache_Stats_Provider_Interface {
    /**
     * @return string
     */
    public function get_provider_key() {
        return 'dropin';
    }

    /**
     * @return array
     */
    public function get_metrics() {
        global $wp_object_cache;

        if ( ! is_object( $wp_object_cache ) ) {
            return array();
        }

        $hits   = property_exists( $wp_object_cache, 'cache_hits' ) ? absint( $wp_object_cache->cache_hits ) : null;
        $misses = property_exists( $wp_object_cache, 'cache_misses' ) ? absint( $wp_object_cache->cache_misses ) : null;

        $evictions = null;
        $bytes     = null;
        $limit     = null;

        if ( method_exists( $wp_object_cache, 'stats' ) ) {
            ob_start();
            $wp_object_cache->stats();
            $stats_text = (string) ob_get_clean();

            $parsed = $this->parse_dropin_stats_text( $stats_text );
            $hits   = null !== $parsed['hits'] ? $parsed['hits'] : $hits;
            $misses = null !== $parsed['misses'] ? $parsed['misses'] : $misses;
            $evictions = $parsed['evictions'];
            $bytes     = $parsed['bytes_used'];
            $limit     = $parsed['bytes_limit'];
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
            'meta'          => array(),
        );
    }

    /**
     * Parse plain-text output from drop-in stats methods.
     *
     * @param string $stats_text Raw output.
     *
     * @return array
     */
    protected function parse_dropin_stats_text( $stats_text ) {
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
    public function get_provider_key() {
        return 'memcached_extension';
    }

    /**
     * @return array
     */
    public function get_metrics() {
        if ( ! class_exists( 'Memcached' ) ) {
            return array();
        }

        $memcached = new Memcached();
        $servers   = pcm_object_cache_memcached_servers_from_constant();
        $server_ok = false;

        foreach ( $servers as $server ) {
            $host = isset( $server['host'] ) ? $server['host'] : '';
            $port = isset( $server['port'] ) ? $server['port'] : 11211;

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
    public function get_provider_key() {
        return 'memcache_extension';
    }

    /**
     * @return array
     */
    public function get_metrics() {
        if ( ! class_exists( 'Memcache' ) ) {
            return array();
        }

        $servers = pcm_object_cache_memcached_servers_from_constant();
        if ( empty( $servers ) ) {
            return array();
        }

        $all_stats = array();

        foreach ( $servers as $server ) {
            $host = isset( $server['host'] ) ? $server['host'] : '';
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
    public function get_provider_key() {
        return 'none';
    }

    /**
     * @return array
     */
    public function get_metrics() {
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
    public function resolve() {
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
     * @param array $metrics Metrics payload.
     *
     * @return array
     */
    public function evaluate( $metrics ) {
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
    /** @var PCM_Object_Cache_Stats_Provider_Resolver */
    protected $resolver;

    /** @var PCM_Object_Cache_Health_Evaluator */
    protected $evaluator;

    /**
     * @param PCM_Object_Cache_Stats_Provider_Resolver|null $resolver Resolver dependency.
     * @param PCM_Object_Cache_Health_Evaluator|null        $evaluator Evaluator dependency.
     */
    public function __construct( $resolver = null, $evaluator = null ) {
        $this->resolver  = $resolver ? $resolver : new PCM_Object_Cache_Stats_Provider_Resolver();
        $this->evaluator = $evaluator ? $evaluator : new PCM_Object_Cache_Health_Evaluator();
    }

    /**
     * Collect one normalized intelligence payload.
     *
     * @return array
     */
    public function collect_snapshot() {
        if ( ! pcm_object_cache_intelligence_is_enabled() ) {
            return array();
        }

        $provider = $this->resolver->resolve();
        $metrics  = $provider->get_metrics();
        $derived  = $this->evaluator->evaluate( $metrics );

        return array(
            'taken_at'         => current_time( 'mysql', true ),
            'provider'         => $provider->get_provider_key(),
            'status'           => isset( $metrics['status'] ) ? $metrics['status'] : 'offline',
            'health'           => $derived['health'],
            'hits'             => isset( $metrics['hits'] ) ? $metrics['hits'] : null,
            'misses'           => isset( $metrics['misses'] ) ? $metrics['misses'] : null,
            'hit_ratio'        => isset( $metrics['hit_ratio'] ) ? $metrics['hit_ratio'] : null,
            'evictions'        => isset( $metrics['evictions'] ) ? $metrics['evictions'] : null,
            'bytes_used'       => isset( $metrics['bytes_used'] ) ? $metrics['bytes_used'] : null,
            'bytes_limit'      => isset( $metrics['bytes_limit'] ) ? $metrics['bytes_limit'] : null,
            'memory_pressure'  => pcm_calculate_memory_pressure( $metrics ),
            'recommendations'  => $derived['recommendations'],
            'meta'             => isset( $metrics['meta'] ) ? $metrics['meta'] : array(),
        );
    }
}

/**
 * @param int|null $hits Hits.
 * @param int|null $misses Misses.
 *
 * @return float|null
 */
function pcm_calculate_hit_ratio( $hits, $misses ) {
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
 * @param array $metrics Metrics payload.
 *
 * @return float
 */
function pcm_calculate_memory_pressure( $metrics ) {
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
function pcm_object_cache_memcached_servers_from_constant() {
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
function pcm_object_cache_parse_memcache_server( $server_def ) {
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
function pcm_object_cache_bytes_to_gb( $bytes ) {
    return round( absint( $bytes ) / 1024 / 1024 / 1024, 2 );
}

/**
 * @param int $uptime_seconds Uptime in seconds.
 *
 * @return string
 */
function pcm_object_cache_format_uptime( $uptime_seconds ) {
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
    /** @var string */
    protected $key = 'pcm_object_cache_snapshots_v1';

    /** @var int */
    protected $max_rows = 2000;

    /**
     * @return array
     */
    public function all() {
        $rows = get_option( $this->key, array() );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param array $snapshot Snapshot.
     *
     * @return void
     */
    public function append( $snapshot ) {
        $rows   = $this->all();
        $rows[] = $snapshot;
        update_option( $this->key, array_slice( $rows, -1 * $this->max_rows ), false );
    }

    /**
     * @param string $range 24h|7d|30d
     *
     * @return array
     */
    public function query( $range = '7d' ) {
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
                static function ( $row ) use ( $cutoff_ts ) {
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
    public function cleanup( $retention_days = 90 ) {
        $retention = max( 7, min( 365, absint( $retention_days ) ) );
        $cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

        $rows = array_values(
            array_filter(
                $this->all(),
                static function ( $row ) use ( $cutoff_ts ) {
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
 * @return array
 */
function pcm_object_cache_collect_and_store_snapshot() {
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
    $storage->cleanup( (int) get_option( 'pcm_object_cache_retention_days', 90 ) );

    update_option( 'pcm_latest_object_cache_hit_ratio', isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : 0, false );
    update_option( 'pcm_latest_object_cache_evictions', isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : 0, false );

    return $snapshot;
}

/**
 * Fetch latest stored snapshot, collecting one if missing.
 *
 * @return array
 */
function pcm_object_cache_get_latest_snapshot() {
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
 * @return array
 */
function pcm_object_cache_get_trends( $range = '7d' ) {
    $storage   = new PCM_Object_Cache_Snapshot_Storage();
    $snapshots = $storage->query( $range );

    $points = array();

    foreach ( $snapshots as $snapshot ) {
        $points[] = array(
            'taken_at'        => isset( $snapshot['taken_at'] ) ? $snapshot['taken_at'] : '',
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
    /** @var string */
    protected $key = 'pcm_route_memcache_sensitivity_v1';

    /** @var int */
    protected $max_rows = 5000;

    /**
     * @return array
     */
    public function all() {
        $rows = get_option( $this->key, array() );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param array $rows Rows.
     *
     * @return void
     */
    public function replace( $rows ) {
        update_option( $this->key, array_slice( array_values( (array) $rows ), -1 * $this->max_rows ), false );
    }

    /**
     * @param int    $run_id Run ID.
     * @param string $route Route key.
     *
     * @return array|null
     */
    public function get_for_run_route( $run_id, $route ) {
        foreach ( array_reverse( $this->all() ) as $row ) {
            if ( (int) $run_id === (int) $row['run_id'] && (string) $route === (string) $row['route'] ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array $assessments Rows.
     *
     * @return void
     */
    public function upsert_batch( $assessments ) {
        $existing = $this->all();
        $index    = array();

        foreach ( $assessments as $row ) {
            $index[ (int) $row['run_id'] . '|' . (string) $row['route'] ] = true;
        }

        $filtered = array_values(
            array_filter(
                $existing,
                static function ( $row ) use ( $index ) {
                    $key = (int) ( isset( $row['run_id'] ) ? $row['run_id'] : 0 ) . '|' . (string) ( isset( $row['route'] ) ? $row['route'] : '' );

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
    public function cleanup( $retention_days = 90 ) {
        $retention = max( 7, min( 365, absint( $retention_days ) ) );
        $cutoff_ts = time() - ( DAY_IN_SECONDS * $retention );

        $this->replace(
            array_values(
                array_filter(
                    $this->all(),
                    static function ( $row ) use ( $cutoff_ts ) {
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
     * @return array
     */
    public function correlate_latest( $run_id = 0 ) {
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
            $calc  = pcm_object_cache_calculate_memcache_sensitivity( $score, isset( $grouped_findings[ $url ] ) ? $grouped_findings[ $url ] : array(), $snapshot );

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
        $storage->cleanup( (int) get_option( 'pcm_object_cache_retention_days', 90 ) );

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
function pcm_object_cache_route_from_url( $url ) {
    $path = wp_parse_url( (string) $url, PHP_URL_PATH );
    if ( ! is_string( $path ) || '' === $path ) {
        return '/';
    }

    return '/' . ltrim( untrailingslashit( $path ), '/' );
}

/**
 * @param int   $score Cacheability score.
 * @param array $findings Findings for URL.
 * @param array $snapshot Object cache snapshot.
 *
 * @return array
 */
function pcm_object_cache_calculate_memcache_sensitivity( $score, $findings, $snapshot ) {
    $score     = max( 0, min( 100, absint( $score ) ) );
    $hit_ratio = isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : 0.0;
    $evictions = isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : 0.0;
    $pressure  = isset( $snapshot['memory_pressure'] ) ? (float) $snapshot['memory_pressure'] : 0.0;

    $critical = 0;
    $warning  = 0;
    $expensive_signals = 0;

    foreach ( (array) $findings as $finding ) {
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

        if ( false !== strpos( $rule_id, 'cookie' ) || false !== strpos( $rule_id, 'vary' ) || false !== strpos( $rule_id, 'query' ) || false !== strpos( $rule_id, 'nocache' ) ) {
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
 * @return array
 */
function pcm_object_cache_memcache_sensitivity_summary( $range = '24h' ) {
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
            static function ( $row ) use ( $cutoff ) {
                $ts = isset( $row['assessed_at'] ) ? strtotime( $row['assessed_at'] ) : 0;

                return $ts >= $cutoff;
            }
        )
    );

    $high_routes = array();
    foreach ( $filtered as $row ) {
        if ( 'high' !== ( isset( $row['memcache_sensitivity'] ) ? $row['memcache_sensitivity'] : '' ) ) {
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
function pcm_ajax_object_cache_snapshot() {
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
function pcm_ajax_object_cache_trends() {
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
function pcm_ajax_route_memcache_sensitivity() {
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
        static function ( $a, $b ) {
            $rank = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
            $a_s  = isset( $a['memcache_sensitivity'] ) ? $a['memcache_sensitivity'] : 'low';
            $b_s  = isset( $b['memcache_sensitivity'] ) ? $b['memcache_sensitivity'] : 'low';
            $a_r  = isset( $rank[ $a_s ] ) ? $rank[ $a_s ] : 0;
            $b_r  = isset( $rank[ $b_s ] ) ? $rank[ $b_s ] : 0;

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
            'assessed_at'  => isset( $result['assessed_at'] ) ? $result['assessed_at'] : '',
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
function pcm_object_cache_maybe_schedule_snapshot_collection() {
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
function pcm_object_cache_collect_snapshot() {
    pcm_object_cache_collect_and_store_snapshot();
}
add_action( 'pcm_object_cache_collect_snapshot', 'pcm_object_cache_collect_snapshot' );
