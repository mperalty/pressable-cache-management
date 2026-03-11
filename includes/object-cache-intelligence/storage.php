<?php
/**
 * Object Cache Intelligence - Snapshot and route bootstrap.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_oci_dir = plugin_dir_path( __FILE__ );

require_once $pcm_oci_dir . 'class-pcm-object-cache-snapshot-storage.php';
require_once $pcm_oci_dir . 'class-pcm-object-cache-route-sensitivity-storage.php';
require_once $pcm_oci_dir . 'class-pcm-object-cache-route-correlation-service.php';

/**
 * Transient key for the latest snapshot quick-access cache.
 */
if ( ! defined( 'PCM_OCI_LATEST_SNAPSHOT_TRANSIENT' ) ) {
	define( 'PCM_OCI_LATEST_SNAPSHOT_TRANSIENT', 'pcm_oci_latest_snapshot' );
}

/**
 * Transient TTL: how long the quick-access snapshot stays fresh.
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

	set_transient( PCM_OCI_LATEST_SNAPSHOT_TRANSIENT, $snapshot, PCM_OCI_SNAPSHOT_TRANSIENT_TTL );

	return $snapshot;
}

/**
 * Fetch latest stored snapshot without triggering live collection.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_get_cached_snapshot(): array {
	$cached = get_transient( PCM_OCI_LATEST_SNAPSHOT_TRANSIENT );
	if ( is_array( $cached ) && ! empty( $cached ) ) {
		return $cached;
	}

	$storage = new PCM_Object_Cache_Snapshot_Storage();
	$rows    = $storage->all();

	if ( ! empty( $rows ) ) {
		$latest = end( $rows );
		if ( ! empty( $latest ) ) {
			set_transient( PCM_OCI_LATEST_SNAPSHOT_TRANSIENT, $latest, PCM_OCI_SNAPSHOT_TRANSIENT_TTL );

			return $latest;
		}
	}

	return array();
}

/**
 * Fetch latest stored snapshot, collecting one if missing.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_get_latest_snapshot(): array {
	$snapshot = pcm_object_cache_get_cached_snapshot();
	if ( ! empty( $snapshot ) ) {
		return $snapshot;
	}

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
	$points    = array();

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
 * @param int                           $score Cacheability score.
 * @param array<int, array<string,mixed>> $findings Findings for URL.
 * @param array<string, mixed>          $snapshot Object cache snapshot.
 *
 * @return array<string, mixed>
 */
function pcm_object_cache_calculate_memcache_sensitivity( int $score, array $findings, array $snapshot ): array {
	$score     = max( 0, min( 100, absint( $score ) ) );
	$hit_ratio = isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : 0.0;
	$evictions = isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : 0.0;
	$pressure  = isset( $snapshot['memory_pressure'] ) ? (float) $snapshot['memory_pressure'] : 0.0;

	$critical          = 0;
	$warning           = 0;
	$expensive_signals = 0;

	foreach ( $findings as $finding ) {
		$severity = isset( $finding['severity'] ) ? sanitize_key( $finding['severity'] ) : '';
		$rule_id  = isset( $finding['rule_id'] ) ? sanitize_key( $finding['rule_id'] ) : '';
		$count    = isset( $finding['count'] ) ? max( 0, absint( $finding['count'] ) ) : 1;

		if ( 'critical' === $severity ) {
			$critical          += $count;
			$expensive_signals += ( 2 * $count );
		} elseif ( 'warning' === $severity ) {
			$warning           += $count;
			$expensive_signals += $count;
		}

		if ( str_contains( $rule_id, 'cookie' ) || str_contains( $rule_id, 'vary' ) || str_contains( $rule_id, 'query' ) || str_contains( $rule_id, 'nocache' ) ) {
			++$expensive_signals;
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

	$ajax_start    = microtime( true );
	$force_collect = isset( $_REQUEST['refresh'] ) && '1' === (string) wp_unslash( $_REQUEST['refresh'] );
	$is_stale      = false;
	$debug_info    = array();

	if ( $force_collect ) {
		$debug_info[] = 'path:refresh_click';
		try {
			$collect_start = microtime( true );
			$snapshot      = pcm_object_cache_collect_and_store_snapshot( 5.0 );
			$collect_ms    = round( ( microtime( true ) - $collect_start ) * 1000 );
			$debug_info[]  = 'collect_ms:' . $collect_ms;
			if ( empty( $snapshot ) ) {
				$debug_info[] = 'collect_returned_empty';
			}
		} catch ( \Throwable $e ) {
			$snapshot     = array();
			$debug_info[] = 'collect_threw:' . $e->getMessage();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[PCM OCI] Live collection failed: ' . $e->getMessage() );
			}
		}

		if ( empty( $snapshot ) ) {
			$snapshot = pcm_object_cache_get_cached_snapshot();
			$is_stale = ! empty( $snapshot );
			if ( empty( $snapshot ) ) {
				$debug_info[] = 'cached_snapshot_also_empty';
			}
		}
	} else {
		$debug_info[] = 'path:initial_load';
		$snapshot     = pcm_object_cache_get_cached_snapshot();
		if ( ! empty( $snapshot ) ) {
			$debug_info[] = 'cached_snapshot_found';
		} else {
			$debug_info[] = 'no_cache_collecting_live';
			try {
				$collect_start = microtime( true );
				$snapshot      = pcm_object_cache_collect_and_store_snapshot( 5.0 );
				$collect_ms    = round( ( microtime( true ) - $collect_start ) * 1000 );
				$debug_info[]  = 'collect_ms:' . $collect_ms;
				if ( empty( $snapshot ) ) {
					$debug_info[] = 'live_collect_returned_empty';
				} else {
					$debug_info[] = 'live_collect_ok';
				}
			} catch ( \Throwable $e ) {
				$snapshot     = array();
				$debug_info[] = 'live_collect_threw:' . $e->getMessage();
			}
		}
	}

	$feature_disabled = ! pcm_object_cache_intelligence_is_enabled();
	if ( $feature_disabled ) {
		$debug_info[] = 'feature_flag_off';
	}

	global $wp_object_cache;
	$debug_info[] = 'dropin_class:' . ( is_object( $wp_object_cache ) ? get_class( $wp_object_cache ) : 'none' );

	if ( is_object( $wp_object_cache ) ) {
		try {
			$ref = new ReflectionClass( $wp_object_cache );
			foreach ( array( 'm', 'mc', 'memcache', 'memcached', 'client', 'daemon', 'connection' ) as $p ) {
				if ( ! $ref->hasProperty( $p ) ) {
					continue;
				}
				$rp  = $ref->getProperty( $p );
				$vis = $rp->isPublic() ? 'pub' : ( $rp->isProtected() ? 'prot' : 'priv' );
				try {
					$rp->setAccessible( true );
					$val          = $rp->getValue( $wp_object_cache );
					$type_label   = is_object( $val ) ? get_class( $val ) : ( is_array( $val ) ? 'array(' . count( $val ) . ')' : gettype( $val ) );
					$debug_info[] = 'prop_' . $p . '(' . $vis . '):' . $type_label;
				} catch ( \Throwable $e ) {
					$debug_info[] = 'prop_' . $p . '(' . $vis . '):inaccessible';
				}
			}
		} catch ( \Throwable $e ) {
			$debug_info[] = 'reflection_failed:' . $e->getMessage();
		}
		if ( method_exists( $wp_object_cache, 'stats' ) ) {
			$debug_info[] = 'has_stats_method';
		}
	}

	$debug_info[] = 'ext_memcached:' . ( class_exists( 'Memcached' ) ? 'yes' : 'no' );
	$debug_info[] = 'ext_memcache:' . ( class_exists( 'Memcache' ) ? 'yes' : 'no' );

	if ( defined( 'WP_MEMCACHED_SERVERS' ) ) {
		$debug_info[] = 'WP_MEMCACHED_SERVERS:defined';
	}
	if ( defined( 'MEMCACHED_SERVERS' ) ) {
		$debug_info[] = 'MEMCACHED_SERVERS:defined';
	}

	$debug_info[] = 'total_ms:' . round( ( microtime( true ) - $ajax_start ) * 1000 );

	wp_send_json_success(
		array(
			'snapshot'         => $snapshot,
			'stale'            => $is_stale,
			'feature_disabled' => $feature_disabled,
			'debug'            => $debug_info,
		)
	);
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
			$rank = array(
				'high'   => 3,
				'medium' => 2,
				'low'    => 1,
			);
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
			'run_id'      => isset( $result['run_id'] ) ? (int) $result['run_id'] : 0,
			'assessed_at' => $result['assessed_at'] ?? '',
			'top_routes'  => array_slice( $routes, 0, 10 ),
			'summary'     => array(
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
