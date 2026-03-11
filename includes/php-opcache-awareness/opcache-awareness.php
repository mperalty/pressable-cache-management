<?php
/**
 * PHP OPcache Awareness (Pillar 4).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_opcache_dir = plugin_dir_path( __FILE__ );

require_once $pcm_opcache_dir . 'class-pcm-opcache-recommendation-engine.php';
require_once $pcm_opcache_dir . 'class-pcm-opcache-collector-service.php';
require_once $pcm_opcache_dir . 'class-pcm-opcache-snapshot-storage.php';

/**
 * Feature flag for OPcache diagnostics.
 *
 * Default aligns with spec rollout: enabled for admins only.
 *
 * @return bool
 */
function pcm_opcache_awareness_is_enabled(): bool {
	static $cached = null;
	if ( null === $cached ) {
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
		'free_warning_percent'   => 10.0,
		'wasted_warning_percent' => 10.0,
		'restart_critical_count' => 3,
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
		'taken_at'        => isset( $snapshot['taken_at'] ) ? sanitize_text_field( $snapshot['taken_at'] ) : '',
		'enabled'         => ! empty( $snapshot['enabled'] ),
		'health'          => isset( $snapshot['health'] ) ? sanitize_key( $snapshot['health'] ) : 'unknown',
		'memory'          => array(),
		'statistics'      => array(),
		'ini'             => array(),
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
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
	} else {
		check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
	}

	try {
		$insights = array();

		// OPcache status.
		if ( function_exists( 'opcache_get_configuration' ) ) {
			$config                      = @opcache_get_configuration(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$enabled                     = ! empty( $config['directives']['opcache.enable'] );
			$insights['opcache_enabled'] = $enabled;
		} else {
			$insights['opcache_enabled'] = false;
		}

		// Batcache status — try transient first, fall back to drop-in detection.
		$batcache_status = get_transient( 'pcm_batcache_status' );
		if ( ! $batcache_status || 'unknown' === $batcache_status ) {
			// Check if advanced-cache.php drop-in exists and loads Batcache.
			if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
				global $batcache;
				$batcache_status = ( is_array( $batcache ) || is_object( $batcache ) ) ? 'active' : 'installed';
			} else {
				$batcache_status = 'not installed';
			}
		}
		$insights['batcache_status'] = $batcache_status;

		// Batcache max_age from global.
		global $batcache;
		$insights['batcache_max_age'] = ( is_array( $batcache ) && isset( $batcache['max_age'] ) )
			? (int) $batcache['max_age']
			: ( ( is_object( $batcache ) && isset( $batcache->max_age ) ) ? (int) $batcache->max_age : null );

		// Object cache type detection.
		global $wp_object_cache;
		$oc_type = 'unknown';
		if ( is_object( $wp_object_cache ) ) {
			$class = get_class( $wp_object_cache );
			if ( stripos( $class, 'redis' ) !== false ) {
				$oc_type = 'Redis';
			} elseif ( stripos( $class, 'memcach' ) !== false ) {
				$oc_type = 'Memcached';
			} elseif ( 'WP_Object_Cache' !== $class ) {
				$oc_type = $class;
			} else {
				// Class is WP_Object_Cache — could be the Automattic/Pressable
				// Memcached drop-in which overrides the default class name.
				// Check for known Memcached client properties.
				$has_mc_client = false;
				$mc_props      = array( 'm', 'mc', 'memcache', 'memcached', 'client' );

				// Use Reflection to access protected/private properties
				// (Automattic/Pressable drop-in declares $mc as protected).
				try {
					$ref = new ReflectionClass( $wp_object_cache );
					foreach ( $mc_props as $prop ) {
						if ( ! $ref->hasProperty( $prop ) ) {
							continue;
						}
						$rp = $ref->getProperty( $prop );
						$rp->setAccessible( true );
						$val = $rp->getValue( $wp_object_cache );
						if ( $val instanceof Memcached || $val instanceof Memcache ) {
							$has_mc_client = true;
							break;
						}
						if ( is_array( $val ) ) {
							foreach ( $val as $v ) {
								if ( $v instanceof Memcached || $v instanceof Memcache ) {
									$has_mc_client = true;
									break 2;
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					$has_mc_client = false;
					// Reflection failed — fall through to extension check.
				}

				if ( $has_mc_client ) {
					$oc_type = 'Memcached';
				} elseif ( class_exists( 'Memcached' ) || class_exists( 'Memcache' ) ) {
					$oc_type = 'Memcached (extension available)';
				} elseif ( class_exists( 'Redis' ) ) {
					$oc_type = 'Redis (extension available)';
				} else {
					$oc_type = 'Default (none)';
				}
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
	} catch ( \Throwable $e ) {
		wp_send_json_error(
			array( 'message' => 'Cache insights collection failed: ' . $e->getMessage() ),
			500
		);
	}
}
add_action( 'wp_ajax_pcm_cache_insights', 'pcm_ajax_cache_insights' );
