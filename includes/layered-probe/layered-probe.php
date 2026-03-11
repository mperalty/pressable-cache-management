<?php
/**
 * Layered Probe Runner — Edge, Origin & Object-Cache side-by-side diagnostics.
 *
 * For a given URL, runs three probes in sequence and returns their results
 * so the admin can compare CDN/edge behaviour, origin behaviour, and the
 * current object-cache state in one view.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize WP_HTTP headers to a plain associative array.
 *
 * @param mixed $headers Raw header container from WP_HTTP.
 * @return array<string, mixed>
 */
function pcm_layered_probe_normalize_response_headers( mixed $headers ): array {
	if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary ) {
		return $headers->getAll();
	}

	if (
		is_object( $headers )
		&& class_exists( 'Requests_Utility_CaseInsensitiveDictionary', false )
		&& is_a( $headers, 'Requests_Utility_CaseInsensitiveDictionary' )
		&& method_exists( $headers, 'getAll' )
	) {
		/** @var array<string, mixed> $legacy_headers */
		$legacy_headers = $headers->getAll();
		return $legacy_headers;
	}

	return (array) $headers;
}

/**
 * AJAX: Run the layered probe for a single URL.
 *
 * Expects POST with:
 *   - nonce  (pcm_cacheability_scan action)
 *   - url    (the page URL to probe)
 *
 * Returns JSON with edge, origin, and object_cache probe results.
 */
function pcm_ajax_layered_probe(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	$raw_url = filter_input( INPUT_POST, 'url', FILTER_UNSAFE_RAW );
	$raw_url = esc_url_raw( is_string( $raw_url ) ? $raw_url : '' );

	if ( empty( $raw_url ) ) {
		wp_send_json_error( array( 'message' => __( 'A valid URL is required.', 'pressable_cache_management' ) ) );
	}

	// Restrict probes to the current site to prevent SSRF.
	$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );
	$url_host  = wp_parse_url( $raw_url, PHP_URL_HOST );

	if ( ! $site_host || ! $url_host || strcasecmp( $site_host, $url_host ) !== 0 ) {
		wp_send_json_error( array( 'message' => __( 'URL must belong to this site.', 'pressable_cache_management' ) ) );
	}

	$edge_result   = pcm_layered_probe_edge( $raw_url );
	$origin_result = pcm_layered_probe_origin( $raw_url );
	$oc_result     = pcm_layered_probe_object_cache();

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'layered_probe_run', 'diagnostics', array( 'url' => $raw_url ) );
	}

	wp_send_json_success(
		array(
			'url'          => $raw_url,
			'probed_at'    => gmdate( 'j M Y, g:ia' ) . ' UTC',
			'edge'         => $edge_result,
			'origin'       => $origin_result,
			'object_cache' => $oc_result,
		)
	);
}
add_action( 'wp_ajax_pcm_layered_probe', 'pcm_ajax_layered_probe' );

/**
 * Edge-visible probe: fetch the URL as a normal browser would see it
 * (through CDN / Batcache / edge cache).
 *
 * @param string $url The URL to probe.
 * @return array Probe result.
 */
function pcm_layered_probe_edge( string $url ): array {
	$start = microtime( true );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'sslverify'  => false,
			'user-agent' => 'PCM-Layered-Probe/1.0 (edge)',
			'headers'    => array(
				'Accept' => 'text/html',
			),
		)
	);

	$elapsed_ms = round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return array(
			'status'     => 'error',
			'error'      => $response->get_error_message(),
			'elapsed_ms' => $elapsed_ms,
		);
	}

	$code    = wp_remote_retrieve_response_code( $response );
	$headers = wp_remote_retrieve_headers( $response );
	$headers = pcm_layered_probe_normalize_response_headers( $headers );

	return array(
		'status'      => 'ok',
		'http_code'   => $code,
		'elapsed_ms'  => $elapsed_ms,
		'headers'     => pcm_layered_probe_extract_cache_headers( $headers ),
		'raw_headers' => pcm_layered_probe_sanitise_headers( $headers ),
	);
}

/**
 * Origin / server probe: fetch the URL with cache-bypass signals
 * so we see what the origin PHP actually returns.
 *
 * @param string $url The URL to probe.
 * @return array Probe result.
 */
function pcm_layered_probe_origin( string $url ): array {
	// Add a cache-busting query parameter to bypass edge/CDN caches.
	$bust_url = add_query_arg( 'pcm_probe_bust', wp_rand( 100000, 999999 ), $url );

	$start = microtime( true );

	$response = wp_remote_get(
		$bust_url,
		array(
			'timeout'    => 15,
			'sslverify'  => false,
			'user-agent' => 'PCM-Layered-Probe/1.0 (origin)',
			'cookies'    => array(),
			'headers'    => array(
				'Accept'        => 'text/html',
				'Cache-Control' => 'no-cache, no-store, must-revalidate',
				'Pragma'        => 'no-cache',
			),
		)
	);

	$elapsed_ms = round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return array(
			'status'     => 'error',
			'error'      => $response->get_error_message(),
			'elapsed_ms' => $elapsed_ms,
		);
	}

	$code    = wp_remote_retrieve_response_code( $response );
	$headers = wp_remote_retrieve_headers( $response );
	$headers = pcm_layered_probe_normalize_response_headers( $headers );

	return array(
		'status'      => 'ok',
		'http_code'   => $code,
		'elapsed_ms'  => $elapsed_ms,
		'headers'     => pcm_layered_probe_extract_cache_headers( $headers ),
		'raw_headers' => pcm_layered_probe_sanitise_headers( $headers ),
	);
}

/**
 * Object-cache snapshot: current hit ratio, memory, evictions.
 *
 * @return array Snapshot data.
 */
function pcm_layered_probe_object_cache(): array {
	if ( ! function_exists( 'pcm_object_cache_intelligence_is_enabled' ) || ! pcm_object_cache_intelligence_is_enabled() ) {
		// Fallback: provide basic wp_object_cache info.
		global $wp_object_cache;

		$info = array(
			'status'    => 'basic',
			'available' => is_object( $wp_object_cache ),
			'class'     => is_object( $wp_object_cache ) ? get_class( $wp_object_cache ) : 'none',
		);

		return $info;
	}

	// Use the existing OCI snapshot infrastructure.
	$snapshot = function_exists( 'pcm_object_cache_get_cached_snapshot' )
		? pcm_object_cache_get_cached_snapshot()
		: array();

	if ( empty( $snapshot ) && function_exists( 'pcm_object_cache_collect_and_store_snapshot' ) ) {
		$snapshot = pcm_object_cache_collect_and_store_snapshot( 3.0 );
	}

	return array(
		'status'          => ! empty( $snapshot ) ? 'ok' : 'empty',
		'hit_ratio'       => isset( $snapshot['hit_ratio'] ) ? round( (float) $snapshot['hit_ratio'], 2 ) : null,
		'memory_pressure' => isset( $snapshot['memory_pressure'] ) ? round( (float) $snapshot['memory_pressure'], 1 ) : null,
		'evictions'       => isset( $snapshot['evictions'] ) ? (int) $snapshot['evictions'] : null,
		'uptime'          => $snapshot['uptime'] ?? null,
		'provider'        => $snapshot['provider'] ?? null,
		'taken_at'        => $snapshot['taken_at'] ?? null,
	);
}

/**
 * Extract the cache-relevant headers into a structured summary.
 *
 * @param array $headers Raw response headers.
 * @return array Structured cache header info.
 */
function pcm_layered_probe_extract_cache_headers( array $headers ): array {
	$get = function ( string $key ) use ( $headers ): string {
		// Headers may be string or array (multiple values).
		foreach ( $headers as $k => $v ) {
			if ( strcasecmp( $k, $key ) === 0 ) {
				return is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}
		return '';
	};

	$x_cache    = $get( 'x-cache' );
	$x_nananana = $get( 'x-nananana' );
	$cf_status  = $get( 'cf-cache-status' );
	$cache_ctrl = $get( 'cache-control' );
	$age        = $get( 'age' );
	$vary       = $get( 'vary' );
	$set_cookie = $get( 'set-cookie' );
	$x_powered  = $get( 'x-powered-by' );
	$server     = $get( 'server' );

	// Determine cache layer verdict.
	$verdict = 'unknown';
	if ( '' !== $x_nananana ) {
		$verdict = str_contains( strtolower( $x_nananana ), 'batcache' ) ? 'batcache_hit' : 'batcache_present';
	}
	if ( '' !== $cf_status ) {
		$verdict = strtolower( $cf_status );
	}
	if ( '' !== $x_cache ) {
		$low = strtolower( $x_cache );
		if ( str_contains( $low, 'hit' ) ) {
			$verdict = 'edge_hit';
		} elseif ( str_contains( $low, 'miss' ) ) {
			$verdict = 'edge_miss';
		}
	}

	return array(
		'verdict'        => $verdict,
		'x_cache'        => $x_cache,
		'x_nananana'     => $x_nananana,
		'cf_status'      => $cf_status,
		'cache_control'  => $cache_ctrl,
		'age'            => $age,
		'vary'           => $vary,
		'has_set_cookie' => '' !== $set_cookie,
		'server'         => $server,
		'x_powered_by'   => $x_powered,
	);
}

/**
 * Sanitise raw headers for safe display (strip sensitive values, limit size).
 *
 * @param array $headers Raw headers.
 * @return array Sanitised key => value pairs.
 */
function pcm_layered_probe_sanitise_headers( array $headers ): array {
	$safe = array();
	$skip = array( 'set-cookie', 'authorization', 'cookie' );

	foreach ( $headers as $name => $value ) {
		$lower = strtolower( (string) $name );
		if ( in_array( $lower, $skip, true ) ) {
			$safe[ $name ] = '[redacted]';
			continue;
		}
		$val           = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		$safe[ $name ] = mb_substr( $val, 0, 500 );
	}

	return $safe;
}
