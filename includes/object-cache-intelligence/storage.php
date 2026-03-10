<?php
/**
 * Object Cache Intelligence — Snapshot & Route Storage Classes.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Snapshot storage for object cache intelligence (A3.1).
 */
class PCM_Object_Cache_Snapshot_Storage {
    protected string $key = 'pcm_object_cache_snapshots_v1';

    protected int $max_rows = 2000;

    private ?array $rows_cache = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array {
        if ( null !== $this->rows_cache ) {
            return $this->rows_cache;
        }

        $rows              = get_option( $this->key, array() );
        $this->rows_cache  = is_array( $rows ) ? $rows : array();

        return $this->rows_cache;
    }

    /**
     * @param array<string, mixed> $snapshot Snapshot.
     *
     * @return void
     */
    public function append( array $snapshot ): void {
        $rows   = $this->all();
        $rows[] = $snapshot;
        $rows   = array_slice( $rows, -1 * $this->max_rows );
        update_option( $this->key, $rows, false );
        $this->rows_cache = $rows;
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
        $this->rows_cache = $rows;
    }
}

/**
 * Transient key for the latest snapshot quick-access cache.
 */
if ( ! defined( 'PCM_OCI_LATEST_SNAPSHOT_TRANSIENT' ) ) {
    define( 'PCM_OCI_LATEST_SNAPSHOT_TRANSIENT', 'pcm_oci_latest_snapshot' );
}

/**
 * Transient TTL: how long the quick-access snapshot stays fresh (5 minutes).
 * After expiry the next request will trigger a background-style refresh.
 */
if ( ! defined( 'PCM_OCI_SNAPSHOT_TRANSIENT_TTL' ) ) {
    define( 'PCM_OCI_SNAPSHOT_TRANSIENT_TTL', 5 * MINUTE_IN_SECONDS );
}

/**
 * Persist one snapshot and return it.
 *
 * @param float $time_budget Maximum seconds for provider resolution (0 = default).
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_collect_and_store_snapshot( float $time_budget = 0 ): array {
    if ( ! pcm_object_cache_intelligence_is_enabled() ) {
        return array();
    }

    $resolver = $time_budget > 0 ? new PCM_Object_Cache_Stats_Provider_Resolver( $time_budget ) : null;
    $service  = new PCM_Object_Cache_Intelligence_Service( $resolver );
    $snapshot = $service->collect_snapshot();

    if ( empty( $snapshot ) ) {
        return array();
    }

    $storage = new PCM_Object_Cache_Snapshot_Storage();
    $storage->append( $snapshot );
    $storage->cleanup( (int) get_option( PCM_Options::OBJECT_CACHE_RETENTION_DAYS->value, 90 ) );

    update_option( PCM_Options::LATEST_OBJECT_CACHE_HIT_RATIO->value, isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : 0, false );
    update_option( PCM_Options::LATEST_OBJECT_CACHE_EVICTIONS->value, isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : 0, false );

    // Update the quick-access transient so subsequent reads are instant.
    set_transient( PCM_OCI_LATEST_SNAPSHOT_TRANSIENT, $snapshot, PCM_OCI_SNAPSHOT_TRANSIENT_TTL );

    return $snapshot;
}

/**
 * Fetch latest stored snapshot without triggering live collection.
 *
 * Reads from transient or stored option only. Returns empty array when no
 * cached data exists — callers that need a live collection should call
 * pcm_object_cache_collect_and_store_snapshot() explicitly.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_get_cached_snapshot(): array {
    // Fast path: transient holds the latest snapshot for quick reads.
    $cached = get_transient( PCM_OCI_LATEST_SNAPSHOT_TRANSIENT );
    if ( is_array( $cached ) && ! empty( $cached ) ) {
        return $cached;
    }

    // Medium path: read from the full storage option.
    $storage = new PCM_Object_Cache_Snapshot_Storage();
    $rows    = $storage->all();

    if ( ! empty( $rows ) ) {
        $latest = end( $rows );
        if ( is_array( $latest ) && ! empty( $latest ) ) {
            // Re-seed the transient so the next request is fast.
            set_transient( PCM_OCI_LATEST_SNAPSHOT_TRANSIENT, $latest, PCM_OCI_SNAPSHOT_TRANSIENT_TTL );

            return $latest;
        }
    }

    return array();
}

/**
 * Fetch latest stored snapshot, collecting one if missing.
 *
 * Uses a short-lived transient to avoid repeatedly deserializing the full
 * snapshot history option on every admin page load.  When the transient is
 * warm the response is near-instant regardless of how many rows are stored.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_get_latest_snapshot(): array {
    $snapshot = pcm_object_cache_get_cached_snapshot();
    if ( ! empty( $snapshot ) ) {
        return $snapshot;
    }

    // Slow path: no stored data at all — collect now.
    return pcm_object_cache_collect_and_store_snapshot();
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

    private ?array $rows_cache = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array {
        if ( null !== $this->rows_cache ) {
            return $this->rows_cache;
        }

        $rows              = get_option( $this->key, array() );
        $this->rows_cache  = is_array( $rows ) ? $rows : array();

        return $this->rows_cache;
    }

    /**
     * @param array<int, array<string, mixed>> $rows Rows.
     *
     * @return void
     */
    public function replace( array $rows ): void {
        $rows = array_slice( array_values( $rows ), -1 * $this->max_rows );
        update_option( $this->key, $rows, false );
        $this->rows_cache = $rows;
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

        $snapshot = pcm_object_cache_get_cached_snapshot();

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
 * When refresh=1 is requested, we attempt a live collection but fall back to
 * the last-known-good snapshot if the collection fails or times out.  This
 * ensures the UI always has *something* useful to display.
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
    $is_stale      = false;

    if ( $force_collect ) {
        // Attempt a live collection with a 5 s time budget; fall back to
        // cached data on failure so the AJAX response stays within the client
        // timeout (15 s).
        try {
            $snapshot = pcm_object_cache_collect_and_store_snapshot( 5.0 );
        } catch ( \Throwable $e ) {
            $snapshot = array();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[PCM OCI] Live collection failed: ' . $e->getMessage() );
            }
        }

        if ( empty( $snapshot ) ) {
            // Fall back to last-known-good snapshot so the UI isn't blank.
            $snapshot = pcm_object_cache_get_cached_snapshot();
            $is_stale = ! empty( $snapshot );
        }
    } else {
        // Non-refresh: read cached data only (transient / stored option).
        // Never attempt live collection on initial page load — it can block
        // for 10+ seconds if the Memcached server is slow or unreachable,
        // tying up a PHP worker and cascading into timeouts for other AJAX
        // requests.  The hourly cron or an explicit Refresh click will
        // populate the snapshot.
        $snapshot = pcm_object_cache_get_cached_snapshot();
    }

    wp_send_json_success( array(
        'snapshot' => $snapshot,
        'stale'    => $is_stale,
    ) );
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

    // Load sensitivity rows once, derive both 24h and 7d summaries from it.
    $sens_storage = new PCM_Object_Cache_Route_Sensitivity_Storage();
    $all_sens     = $sens_storage->all();
    $cutoff_24h   = time() - DAY_IN_SECONDS;
    $cutoff_7d    = time() - ( 7 * DAY_IN_SECONDS );
    $high_24h     = array();
    $high_7d      = array();

    foreach ( $all_sens as $row ) {
        if ( 'high' !== ( $row['memcache_sensitivity'] ?? '' ) ) {
            continue;
        }
        $ts = isset( $row['assessed_at'] ) ? strtotime( $row['assessed_at'] ) : 0;
        if ( $ts >= $cutoff_7d ) {
            $high_7d[ (string) $row['route'] ] = true;
        }
        if ( $ts >= $cutoff_24h ) {
            $high_24h[ (string) $row['route'] ] = true;
        }
    }

    wp_send_json_success(
        array(
            'run_id'       => isset( $result['run_id'] ) ? (int) $result['run_id'] : 0,
            'assessed_at'  => $result['assessed_at'] ?? '',
            'top_routes'   => array_slice( $routes, 0, 10 ),
            'summary'      => array(
                'high_24h' => count( $high_24h ),
                'high_7d'  => count( $high_7d ),
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
