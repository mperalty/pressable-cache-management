<?php
/**
 * PHP OPcache Awareness (Pillar 4).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Feature flag for OPcache diagnostics.
 *
 * Default aligns with spec rollout: enabled for admins only.
 *
 * @return bool
 */
function pcm_opcache_awareness_is_enabled(): bool {
    static $cached = null;
    if ( $cached === null ) {
        $enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
        $cached  = (bool) apply_filters( 'pcm_enable_opcache_awareness', $enabled );
    }

    return $cached;
}

/**
 * Configurable threshold policy for OPcache recommendations (A4.3).
 *
 * @return array
 */
function pcm_get_opcache_thresholds(): array {
    $defaults = array(
        'free_warning_percent'     => 10.0,
        'wasted_warning_percent'   => 10.0,
        'restart_critical_count'   => 3,
    );

    $stored = get_option( PCM_Options::OPCACHE_THRESHOLDS_V1->value, array() );
    if ( ! is_array( $stored ) ) {
        return $defaults;
    }

    $thresholds = wp_parse_args( $stored, $defaults );

    $thresholds['free_warning_percent']   = max( 1.0, min( 50.0, (float) $thresholds['free_warning_percent'] ) );
    $thresholds['wasted_warning_percent'] = max( 1.0, min( 50.0, (float) $thresholds['wasted_warning_percent'] ) );
    $thresholds['restart_critical_count'] = max( 1, min( 1000, absint( $thresholds['restart_critical_count'] ) ) );

    return $thresholds;
}

/**
 * Collector service for OPcache runtime + ini snapshots.
 */
class PCM_OPcache_Collector_Service {
    /**
     * @return array
     */
    public function collect(): array {
        if ( ! pcm_opcache_awareness_is_enabled() ) {
            return array();
        }

        $status = $this->safe_get_status();
        $ini    = $this->collect_ini();

        if ( empty( $status ) ) {
            return array(
                'taken_at'        => current_time( 'mysql', true ),
                'enabled'         => false,
                'health'          => 'disabled',
                'memory'          => array(),
                'statistics'      => array(),
                'ini'             => $ini,
                'recommendations' => array(
                    array(
                        'rule_id'   => 'opcache_unavailable',
                        'severity'  => 'warning',
                        'message'   => 'OPcache status is unavailable. Confirm OPcache is enabled in this PHP runtime.',
                        'checklist' => array(
                            'Verify opcache.enable is set to 1.',
                            'Confirm opcache extension is loaded for the web SAPI.',
                            'Re-check OPcache diagnostics after PHP runtime changes.',
                        ),
                    ),
                ),
            );
        }

        $snapshot        = $this->normalize_status( $status );
        $snapshot['ini'] = $ini;

        return $snapshot;
    }

    /**
     * @return array
     */
    protected function safe_get_status(): array {
        if ( ! function_exists( 'opcache_get_status' ) ) {
            return array();
        }

        $result = @opcache_get_status( false );

        if ( ! is_array( $result ) ) {
            return array();
        }

        return $result;
    }

    /**
     * @return array
     */
    protected function collect_ini(): array {
        $keys = array(
            'opcache.enable',
            'opcache.memory_consumption',
            'opcache.max_accelerated_files',
            'opcache.revalidate_freq',
            'opcache.validate_timestamps',
            'opcache.max_wasted_percentage',
        );

        $output = array();

        foreach ( $keys as $key ) {
            $value = ini_get( $key );
            if ( false === $value ) {
                continue;
            }

            $output[ $key ] = sanitize_text_field( (string) $value );
        }

        return $output;
    }

    /**
     * @param array $raw_status Raw OPcache payload.
     *
     * @return array
     */
    protected function normalize_status( array $raw_status ): array {
        $opcache_enabled = ! empty( $raw_status['opcache_enabled'] );
        $memory_usage    = isset( $raw_status['memory_usage'] ) && is_array( $raw_status['memory_usage'] ) ? $raw_status['memory_usage'] : array();
        $statistics      = isset( $raw_status['opcache_statistics'] ) && is_array( $raw_status['opcache_statistics'] ) ? $raw_status['opcache_statistics'] : array();

        $used_memory   = isset( $memory_usage['used_memory'] ) ? absint( $memory_usage['used_memory'] ) : 0;
        $free_memory   = isset( $memory_usage['free_memory'] ) ? absint( $memory_usage['free_memory'] ) : 0;
        $wasted_memory = isset( $memory_usage['wasted_memory'] ) ? absint( $memory_usage['wasted_memory'] ) : 0;

        $restarts = array(
            'oom_restarts'       => isset( $statistics['oom_restarts'] ) ? absint( $statistics['oom_restarts'] ) : 0,
            'hash_restarts'      => isset( $statistics['hash_restarts'] ) ? absint( $statistics['hash_restarts'] ) : 0,
            'manual_restarts'    => isset( $statistics['manual_restarts'] ) ? absint( $statistics['manual_restarts'] ) : 0,
        );

        $snapshot = array(
            'taken_at'   => current_time( 'mysql', true ),
            'enabled'    => $opcache_enabled,
            'memory'     => array(
                'used_memory'         => $used_memory,
                'free_memory'         => $free_memory,
                'wasted_memory'       => $wasted_memory,
                'free_memory_percent' => pcm_opcache_percent( $free_memory, $used_memory + $free_memory + $wasted_memory ),
                'wasted_percent'      => pcm_opcache_percent( $wasted_memory, $used_memory + $free_memory + $wasted_memory ),
            ),
            'statistics' => array(
                'num_cached_scripts' => isset( $statistics['num_cached_scripts'] ) ? absint( $statistics['num_cached_scripts'] ) : 0,
                'max_cached_keys'    => isset( $statistics['max_cached_keys'] ) ? absint( $statistics['max_cached_keys'] ) : 0,
                'opcache_hit_rate'   => isset( $statistics['opcache_hit_rate'] ) ? round( (float) $statistics['opcache_hit_rate'], 2 ) : null,
                'restarts'           => $restarts,
                'restart_total'      => array_sum( $restarts ),
            ),
        );

        $recommendation_engine = new PCM_OPcache_Recommendation_Engine();
        $evaluated             = $recommendation_engine->evaluate( $snapshot );

        return array_merge( $snapshot, $evaluated );
    }
}

/**
 * Recommendation engine for OPcache snapshots.
 */
class PCM_OPcache_Recommendation_Engine {
    /**
     * @param array $snapshot Normalized snapshot.
     *
     * @return array
     */
    public function evaluate( array $snapshot ): array {
        $health          = 'healthy';
        $recommendations = array();

        $free_percent   = isset( $snapshot['memory']['free_memory_percent'] ) ? (float) $snapshot['memory']['free_memory_percent'] : 0;
        $wasted_percent = isset( $snapshot['memory']['wasted_percent'] ) ? (float) $snapshot['memory']['wasted_percent'] : 0;
        $restart_total  = isset( $snapshot['statistics']['restart_total'] ) ? absint( $snapshot['statistics']['restart_total'] ) : 0;
        $thresholds     = pcm_get_opcache_thresholds();

        if ( $free_percent < $thresholds['free_warning_percent'] ) {
            $health = 'warning';
            $recommendations[] = array(
                'rule_id'   => 'low_free_memory',
                'severity'  => 'warning',
                'message'   => sprintf( 'OPcache free memory is %s%% (<%s%%). Consider increasing opcache.memory_consumption.', $free_percent, $thresholds['free_warning_percent'] ),
                'checklist' => array(
                    'Increase opcache.memory_consumption incrementally.',
                    'Monitor free memory and restart behavior after change.',
                    'Confirm improved hit rate after tuning.',
                ),
            );
        }

        if ( $wasted_percent > $thresholds['wasted_warning_percent'] ) {
            $health = 'warning';
            $recommendations[] = array(
                'rule_id'   => 'high_wasted_memory',
                'severity'  => 'warning',
                'message'   => sprintf( 'OPcache wasted memory is %s%% (>%s%%). Investigate invalidation churn and deployment frequency.', $wasted_percent, $thresholds['wasted_warning_percent'] ),
                'checklist' => array(
                    'Audit deployment cadence and cache invalidation patterns.',
                    'Review timestamp validation and revalidate frequency settings.',
                    'Verify wasted memory trend drops after adjustments.',
                ),
            );
        }

        if ( $restart_total >= $thresholds['restart_critical_count'] ) {
            $health = 'degraded';
            $recommendations[] = array(
                'rule_id'   => 'frequent_restarts',
                'severity'  => 'critical',
                'message'   => sprintf( 'OPcache restart counters are elevated (%d). Check memory sizing and invalidation storms.', $restart_total ),
                'checklist' => array(
                    'Inspect deployment/reload patterns in the last 24h.',
                    'Tune memory and max file/key settings as needed.',
                    'Re-check restart counters after mitigation.',
                ),
            );
        }

        $recommendations[] = array(
            'rule_id'   => 'timestamp_validation_note',
            'severity'  => 'info',
            'message'   => 'Timestamp validation improves code freshness but may reduce cache efficiency. Tune opcache.validate_timestamps and opcache.revalidate_freq for your deployment model.',
            'checklist' => array(
                'Document current deployment workflow expectations.',
                'Adjust validation settings in small increments.',
                'Verify both correctness and hit-rate trends post-change.',
            ),
        );

        return array(
            'health'          => $health,
            'recommendations' => $recommendations,
        );
    }
}

/**
 * @param int|float $value Numerator.
 * @param int|float $total Denominator.
 *
 * @return float
 */
function pcm_opcache_percent( int|float $value, int|float $total ): float {
    $value = (float) $value;
    $total = (float) $total;

    if ( $total <= 0 ) {
        return 0.0;
    }

    return round( ( $value / $total ) * 100, 2 );
}

/**
 * Redact/sanitize OPcache snapshot for API/export safety (A4.4).
 *
 * @param mixed $snapshot Snapshot.
 *
 * @return array
 */
function pcm_opcache_sanitize_snapshot( mixed $snapshot ): array {
    $snapshot = is_array( $snapshot ) ? $snapshot : array();

    $output = array(
        'taken_at'   => isset( $snapshot['taken_at'] ) ? sanitize_text_field( $snapshot['taken_at'] ) : '',
        'enabled'    => ! empty( $snapshot['enabled'] ),
        'health'     => isset( $snapshot['health'] ) ? sanitize_key( $snapshot['health'] ) : 'unknown',
        'memory'     => array(),
        'statistics' => array(),
        'ini'        => array(),
        'recommendations' => isset( $snapshot['recommendations'] ) && is_array( $snapshot['recommendations'] ) ? $snapshot['recommendations'] : array(),
    );

    $memory = isset( $snapshot['memory'] ) && is_array( $snapshot['memory'] ) ? $snapshot['memory'] : array();
    $stats  = isset( $snapshot['statistics'] ) && is_array( $snapshot['statistics'] ) ? $snapshot['statistics'] : array();
    $ini    = isset( $snapshot['ini'] ) && is_array( $snapshot['ini'] ) ? $snapshot['ini'] : array();

    $output['memory'] = array(
        'used_memory'         => isset( $memory['used_memory'] ) ? absint( $memory['used_memory'] ) : 0,
        'free_memory'         => isset( $memory['free_memory'] ) ? absint( $memory['free_memory'] ) : 0,
        'wasted_memory'       => isset( $memory['wasted_memory'] ) ? absint( $memory['wasted_memory'] ) : 0,
        'free_memory_percent' => isset( $memory['free_memory_percent'] ) ? (float) $memory['free_memory_percent'] : 0,
        'wasted_percent'      => isset( $memory['wasted_percent'] ) ? (float) $memory['wasted_percent'] : 0,
    );

    $output['statistics'] = array(
        'num_cached_scripts' => isset( $stats['num_cached_scripts'] ) ? absint( $stats['num_cached_scripts'] ) : 0,
        'max_cached_keys'    => isset( $stats['max_cached_keys'] ) ? absint( $stats['max_cached_keys'] ) : 0,
        'opcache_hit_rate'   => isset( $stats['opcache_hit_rate'] ) ? (float) $stats['opcache_hit_rate'] : 0,
        'restart_total'      => isset( $stats['restart_total'] ) ? absint( $stats['restart_total'] ) : 0,
        'restarts'           => isset( $stats['restarts'] ) && is_array( $stats['restarts'] ) ? array_map( 'absint', $stats['restarts'] ) : array(),
    );

    $ini_whitelist = array(
        'opcache.enable',
        'opcache.memory_consumption',
        'opcache.max_accelerated_files',
        'opcache.revalidate_freq',
        'opcache.validate_timestamps',
        'opcache.max_wasted_percentage',
    );

    foreach ( $ini_whitelist as $ini_key ) {
        if ( isset( $ini[ $ini_key ] ) ) {
            $output['ini'][ $ini_key ] = sanitize_text_field( (string) $ini[ $ini_key ] );
        }
    }

    return $output;
}

/**
 * OPcache snapshot storage (A4.1).
 */
class PCM_OPcache_Snapshot_Storage {
    protected string $key = 'pcm_opcache_snapshots_v1';

    protected int $max_rows = 2000;

    private ?array $rows_cache = null;

    /**
     * @return array
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
     * @param array $snapshot Snapshot.
     *
     * @return void
     */
    public function append( array $snapshot ): void {
        $rows   = $this->all();
        $rows[] = pcm_opcache_sanitize_snapshot( $snapshot );
        $rows   = array_slice( $rows, -1 * $this->max_rows );
        update_option( $this->key, $rows, false );
        $this->rows_cache = $rows;
    }

    /**
     * @param string $range 24h|7d|30d
     *
     * @return array
     */
    public function query( string $range = '7d' ): array {
        $days_map = array(
            '24h' => 1,
            '7d'  => 7,
            '30d' => 30,
        );

        $days      = $days_map[ $range ] ?? 7;
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
 * Collect and persist OPcache snapshot.
 *
 * @return array
 */
function pcm_opcache_collect_and_store_snapshot(): array {
    if ( ! pcm_opcache_awareness_is_enabled() ) {
        return array();
    }

    $collector = new PCM_OPcache_Collector_Service();
    $snapshot  = $collector->collect();

    if ( empty( $snapshot ) ) {
        return array();
    }

    $snapshot = pcm_opcache_sanitize_snapshot( $snapshot );

    $storage = new PCM_OPcache_Snapshot_Storage();
    $storage->append( $snapshot );
    $storage->cleanup( (int) get_option( PCM_Options::OPCACHE_RETENTION_DAYS->value, 90 ) );

    $memory_pressure = isset( $snapshot['memory']['used_memory'], $snapshot['memory']['free_memory'], $snapshot['memory']['wasted_memory'] )
        ? pcm_opcache_percent(
            $snapshot['memory']['used_memory'] + $snapshot['memory']['wasted_memory'],
            $snapshot['memory']['used_memory'] + $snapshot['memory']['free_memory'] + $snapshot['memory']['wasted_memory']
        )
        : 0;

    update_option( PCM_Options::LATEST_OPCACHE_MEMORY_PRESSURE->value, (float) $memory_pressure, false );
    update_option( PCM_Options::LATEST_OPCACHE_RESTARTS->value, isset( $snapshot['statistics']['restart_total'] ) ? (float) $snapshot['statistics']['restart_total'] : 0, false );

    return $snapshot;
}

/**
 * @return array
 */
function pcm_opcache_get_latest_snapshot(): array {
    $storage = new PCM_OPcache_Snapshot_Storage();
    $rows    = $storage->all();

    if ( empty( $rows ) ) {
        return pcm_opcache_collect_and_store_snapshot();
    }

    $latest = end( $rows );

    return is_array( $latest ) ? $latest : array();
}

/**
 * @param string $range Range.
 *
 * @return array
 */
function pcm_opcache_get_trends( string $range = '7d' ): array {
    $storage   = new PCM_OPcache_Snapshot_Storage();
    $snapshots = $storage->query( $range );
    $points    = array();

    foreach ( $snapshots as $snapshot ) {
        $used   = isset( $snapshot['memory']['used_memory'] ) ? absint( $snapshot['memory']['used_memory'] ) : 0;
        $free   = isset( $snapshot['memory']['free_memory'] ) ? absint( $snapshot['memory']['free_memory'] ) : 0;
        $wasted = isset( $snapshot['memory']['wasted_memory'] ) ? absint( $snapshot['memory']['wasted_memory'] ) : 0;

        $points[] = array(
            'taken_at'        => $snapshot['taken_at'] ?? '',
            'memory_pressure' => pcm_opcache_percent( $used + $wasted, $used + $free + $wasted ),
            'restart_total'   => isset( $snapshot['statistics']['restart_total'] ) ? absint( $snapshot['statistics']['restart_total'] ) : 0,
            'hit_rate'        => isset( $snapshot['statistics']['opcache_hit_rate'] ) ? (float) $snapshot['statistics']['opcache_hit_rate'] : 0,
            'health'          => isset( $snapshot['health'] ) ? sanitize_key( $snapshot['health'] ) : 'unknown',
        );
    }

    return $points;
}

/**
 * AJAX: OPcache latest snapshot.
 *
 * @return void
 */
function pcm_ajax_opcache_snapshot(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $refresh  = isset( $_REQUEST['refresh'] ) && '1' === (string) wp_unslash( $_REQUEST['refresh'] );
    $snapshot = $refresh ? pcm_opcache_collect_and_store_snapshot() : pcm_opcache_get_latest_snapshot();

    wp_send_json_success( array( 'snapshot' => pcm_opcache_sanitize_snapshot( $snapshot ) ) );
}
add_action( 'wp_ajax_pcm_opcache_snapshot', 'pcm_ajax_opcache_snapshot' );

/**
 * AJAX: OPcache trend points.
 *
 * @return void
 */
function pcm_ajax_opcache_trends(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $range = isset( $_REQUEST['range'] ) ? sanitize_key( wp_unslash( $_REQUEST['range'] ) ) : '7d';

    wp_send_json_success(
        array(
            'range'  => $range,
            'points' => pcm_opcache_get_trends( $range ),
        )
    );
}
add_action( 'wp_ajax_pcm_opcache_trends', 'pcm_ajax_opcache_trends' );

/**
 * Schedule daily OPcache snapshots.
 *
 * @return void
 */
function pcm_opcache_maybe_schedule_collection(): void {
    if ( ! pcm_opcache_awareness_is_enabled() ) {
        return;
    }

    if ( ! wp_next_scheduled( 'pcm_opcache_collect_snapshot' ) ) {
        wp_schedule_event( time() + 300, 'daily', 'pcm_opcache_collect_snapshot' );
    }
}
add_action( 'init', 'pcm_opcache_maybe_schedule_collection' );

/**
 * Cron callback.
 *
 * @return void
 */
function pcm_opcache_collect_snapshot(): void {
    pcm_opcache_collect_and_store_snapshot();
}
add_action( 'pcm_opcache_collect_snapshot', 'pcm_opcache_collect_snapshot' );

/**
 * Cache Insights AJAX endpoint — returns a compact overview of the caching stack.
 */
function pcm_ajax_cache_insights() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$insights = array();

	// OPcache status.
	if ( function_exists( 'opcache_get_configuration' ) ) {
		$config  = @opcache_get_configuration();
		$enabled = ! empty( $config['directives']['opcache.enable'] );
		$insights['opcache_enabled'] = $enabled;
	} else {
		$insights['opcache_enabled'] = false;
	}

	// Batcache status.
	$batcache_status = get_transient( 'pcm_batcache_status' );
	$insights['batcache_status'] = $batcache_status ? $batcache_status : 'unknown';

	// Batcache max_age from global.
	global $batcache;
	$insights['batcache_max_age'] = ( is_array( $batcache ) && isset( $batcache['max_age'] ) )
		? (int) $batcache['max_age']
		: ( ( is_object( $batcache ) && isset( $batcache->max_age ) ) ? (int) $batcache->max_age : null );

	// Object cache type detection.
	global $wp_object_cache;
	$oc_type = 'unknown';
	if ( $wp_object_cache ) {
		$class = get_class( $wp_object_cache );
		if ( stripos( $class, 'redis' ) !== false ) {
			$oc_type = 'Redis';
		} elseif ( stripos( $class, 'memcach' ) !== false ) {
			$oc_type = 'Memcached';
		} elseif ( $class !== 'WP_Object_Cache' ) {
			$oc_type = $class;
		} else {
			$oc_type = 'Default (none)';
		}
	}
	$insights['object_cache_type'] = $oc_type;

	// Object cache hit ratio — read cached value only (never trigger live collection).
	$cached_hit_ratio = (float) get_option( PCM_Options::LATEST_OBJECT_CACHE_HIT_RATIO->value, 0 );
	if ( $cached_hit_ratio > 0 ) {
		$insights['object_cache_hit_ratio'] = round( $cached_hit_ratio, 1 );
	} elseif ( function_exists( 'pcm_object_cache_get_latest_snapshot' ) ) {
		// Fall back to transient/stored snapshot without triggering slow collection.
		$snap = get_transient( 'pcm_oci_latest_snapshot' );
		if ( is_array( $snap ) && isset( $snap['hit_ratio'] ) ) {
			$insights['object_cache_hit_ratio'] = round( (float) $snap['hit_ratio'], 1 );
		}
	}

	wp_send_json_success( $insights );
}
add_action( 'wp_ajax_pcm_cache_insights', 'pcm_ajax_cache_insights' );
