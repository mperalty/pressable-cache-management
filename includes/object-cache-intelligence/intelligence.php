<?php
/**
 * Object Cache + Memcached Intelligence (Pillar 3).
 *
 * Read-only diagnostics designed for WPCloud safety.
 * Split into focused modules for maintainability:
 *   - interface.php  — PCM_Object_Cache_Stats_Provider_Interface
 *   - providers.php  — All stats provider implementations + resolver
 *   - evaluator.php  — PCM_Object_Cache_Health_Evaluator + Intelligence Service
 *   - storage.php    — Snapshot and route storage classes + AJAX handlers
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
    static $cached = null;
    if ( $cached === null ) {
        $enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
        $cached  = (bool) apply_filters( 'pcm_enable_object_cache_intelligence', $enabled );
    }

    return $cached;
}

// ─── Helper functions (used by providers, evaluator, and storage) ────────────

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

// ─── Load split modules (order matters: interface → providers → evaluator → storage) ─
$pcm_oci_dir = plugin_dir_path( __FILE__ );
require_once $pcm_oci_dir . 'interface.php';
require_once $pcm_oci_dir . 'providers.php';
require_once $pcm_oci_dir . 'evaluator.php';
require_once $pcm_oci_dir . 'storage.php';
