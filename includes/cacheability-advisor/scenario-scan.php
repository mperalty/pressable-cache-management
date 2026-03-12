<?php
/**
 * Scenario Scan for multi-variant cache diagnostics.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PCM_SCENARIO_UA_DESKTOP' ) ) {
	define( 'PCM_SCENARIO_UA_DESKTOP', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' );
}

if ( ! defined( 'PCM_SCENARIO_UA_MOBILE' ) ) {
	define( 'PCM_SCENARIO_UA_MOBILE', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' );
}

if ( ! defined( 'PCM_SCENARIO_MAX_URLS' ) ) {
	define( 'PCM_SCENARIO_MAX_URLS', 20 );
}

if ( ! defined( 'PCM_SCENARIO_MAX_TOTAL_PROBES' ) ) {
	define( 'PCM_SCENARIO_MAX_TOTAL_PROBES', 60 );
}

if ( ! defined( 'PCM_SCENARIO_MAX_BATCH_SIZE' ) ) {
	define( 'PCM_SCENARIO_MAX_BATCH_SIZE', 3 );
}

if ( ! defined( 'PCM_SCENARIO_QUEUE_TTL' ) ) {
	define( 'PCM_SCENARIO_QUEUE_TTL', 2 * HOUR_IN_SECONDS );
}

if ( ! defined( 'PCM_SCENARIO_WARMUP_DELAY_US' ) ) {
	define( 'PCM_SCENARIO_WARMUP_DELAY_US', 500000 );
}

if ( ! defined( 'PCM_SCENARIO_MAX_RECENT_RUNS' ) ) {
	define( 'PCM_SCENARIO_MAX_RECENT_RUNS', 10 );
}

/**
 * Normalize WP_HTTP headers to a plain associative array.
 *
 * @param mixed $headers Raw header container from WP_HTTP.
 *
 * @return array<string, mixed>
 */
function pcm_scenario_normalize_response_headers( mixed $headers ): array {
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
 * Send a consistent Scenario Scan AJAX error response.
 *
 * @param string               $message Error message.
 * @param int                  $status HTTP status code.
 * @param string               $code Stable error code.
 * @param array<string, mixed> $extra Extra payload fields.
 *
 * @return never
 */
function pcm_scenario_ajax_error( string $message, int $status = 400, string $code = 'bad_request', array $extra = array() ): never {
	wp_send_json_error(
		array_merge(
			array(
				'message' => $message,
				'code'    => $code,
			),
			$extra
		),
		$status
	);
}

/**
 * Whether Scenario Scan is enabled.
 *
 * @return bool
 */
function pcm_scenario_scan_is_enabled(): bool {
	return function_exists( 'pcm_cacheability_advisor_is_enabled' ) && pcm_cacheability_advisor_is_enabled();
}

/**
 * Get the current site host for same-site validation.
 *
 * @return string
 */
function pcm_scenario_current_site_host(): string {
	$host = wp_parse_url( get_site_url(), PHP_URL_HOST );

	return is_string( $host ) ? strtolower( $host ) : '';
}

/**
 * Ensure Scenario Scan tables are available.
 *
 * @return void
 */
function pcm_scenario_ensure_storage_schema(): void {
	if ( function_exists( 'pcm_cacheability_advisor_install_tables' ) ) {
		pcm_cacheability_advisor_install_tables();
	}

	if ( defined( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION' ) && class_exists( 'PCM_Options' ) ) {
		update_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, PCM_CACHEABILITY_ADVISOR_DB_VERSION, false );
	}
}

/**
 * Normalize one resolved URL candidate.
 *
 * @param mixed $candidate Candidate row or URL string.
 *
 * @return array<string, int|string>|null
 */
function pcm_scenario_normalize_resolved_candidate( mixed $candidate ): ?array {
	$url   = '';
	$views = 0;

	if ( is_string( $candidate ) ) {
		$url = esc_url_raw( $candidate );
	} elseif ( is_array( $candidate ) ) {
		$url = isset( $candidate['url'] ) ? esc_url_raw( (string) $candidate['url'] ) : '';
		if ( isset( $candidate['view_count'] ) ) {
			$views = absint( $candidate['view_count'] );
		} elseif ( isset( $candidate['views'] ) ) {
			$views = absint( $candidate['views'] );
		}
	}

	if ( '' === $url ) {
		return null;
	}

	return array(
		'url'   => $url,
		'views' => $views,
	);
}

/**
 * Deduplicate, same-host filter, and clamp resolved URL candidates.
 *
 * @param array<int, mixed> $items Raw URL candidates.
 * @param int               $limit Maximum URLs to return.
 *
 * @return array<int, array<string, int|string>>
 */
function pcm_scenario_normalize_resolved_candidates( array $items, int $limit ): array {
	$limit     = max( 1, min( PCM_SCENARIO_MAX_URLS, absint( $limit ) ) );
	$site_host = pcm_scenario_current_site_host();
	$seen      = array();
	$output    = array();

	foreach ( $items as $item ) {
		$normalized = pcm_scenario_normalize_resolved_candidate( $item );
		if ( ! is_array( $normalized ) ) {
			continue;
		}

		$url      = (string) $normalized['url'];
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || ! is_string( $url_host ) || '' === $site_host || 0 !== strcasecmp( $site_host, $url_host ) ) {
			continue;
		}

		if ( isset( $seen[ $url ] ) ) {
			continue;
		}

		$seen[ $url ] = true;
		$output[]     = array(
			'url'   => $url,
			'views' => absint( $normalized['views'] ?? 0 ),
		);

		if ( count( $output ) >= $limit ) {
			break;
		}
	}

	return $output;
}

/**
 * Discover URLs from the site's XML sitemap.
 *
 * @param int $limit Maximum URLs to return.
 *
 * @return array<int, array<string, int|string>>
 */
function pcm_scenario_urls_from_sitemap( int $limit = PCM_SCENARIO_MAX_URLS ): array {
	$sitemap_url = home_url( '/wp-sitemap.xml' );
	$response    = wp_remote_get(
		$sitemap_url,
		array(
			'timeout'   => 8,
			'sslverify' => false,
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$body = wp_remote_retrieve_body( $response );
	$urls = array();

	$sub_sitemaps = array();
	if ( preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/i', $body, $matches ) ) {
		$sub_sitemaps = array_slice( $matches[1], 0, 5 );
	}

	if ( empty( $sub_sitemaps ) || str_contains( $body, '<url>' ) ) {
		if ( preg_match_all( '/<url>\s*<loc>\s*(.*?)\s*<\/loc>/i', $body, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
	}

	foreach ( $sub_sitemaps as $sub_url ) {
		if ( count( $urls ) >= $limit ) {
			break;
		}

		$sub_url = trim( (string) $sub_url );
		if ( ! str_contains( $sub_url, 'sitemap' ) ) {
			continue;
		}

		$sub_response = wp_remote_get(
			$sub_url,
			array(
				'timeout'   => 6,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $sub_response ) || 200 !== wp_remote_retrieve_response_code( $sub_response ) ) {
			continue;
		}

		$sub_body = wp_remote_retrieve_body( $sub_response );
		if ( preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/i', $sub_body, $sub_matches ) ) {
			$urls = array_merge( $urls, $sub_matches[1] );
		}
	}

	return pcm_scenario_normalize_resolved_candidates( $urls, $limit );
}

/**
 * Get top URLs ordered by tracked page views from the last 30 days.
 *
 * Falls back to recently modified published posts/pages when no view data
 * exists yet. Developers can override the tracked URL source with the
 * pcm_popular_urls_source filter.
 *
 * @param int $limit Maximum URLs to return.
 *
 * @return array<int, array<string, int|string>>
 */
function pcm_scenario_urls_top_pages( int $limit = 10 ): array {
	$limit        = max( 1, min( PCM_SCENARIO_MAX_URLS, absint( $limit ) ) );
	$tracked_rows = function_exists( 'pcm_popular_url_tracker_get_top_urls' ) ? pcm_popular_url_tracker_get_top_urls( $limit, 30 ) : array();
	$tracked_rows = apply_filters( 'pcm_popular_urls_source', $tracked_rows, $limit );
	$tracked      = pcm_scenario_normalize_resolved_candidates( is_array( $tracked_rows ) ? $tracked_rows : array(), $limit );

	if ( ! empty( $tracked ) ) {
		return $tracked;
	}

	$posts = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		)
	);

	$fallback = array();
	foreach ( $posts as $post ) {
		$url = get_permalink( $post );
		if ( ! $url ) {
			continue;
		}

		$fallback[] = array(
			'url'   => $url,
			'views' => 0,
		);
	}

	return pcm_scenario_normalize_resolved_candidates( $fallback, $limit );
}

/**
 * Get the cookie name used to trigger logged-in cache-bypass behavior.
 *
 * The value is intentionally not a valid session token. It only mirrors the
 * real logged-in cookie name so cache layers that key off the exact cookie
 * name take the bypass path without authenticating the request.
 *
 * @return string
 */
function pcm_scenario_logged_in_cookie_name(): string {
	if ( defined( 'LOGGED_IN_COOKIE' ) && is_string( LOGGED_IN_COOKIE ) && '' !== LOGGED_IN_COOKIE ) {
		return LOGGED_IN_COOKIE;
	}

	if ( defined( 'COOKIEHASH' ) && is_string( COOKIEHASH ) && '' !== COOKIEHASH ) {
		return 'wordpress_logged_in_' . COOKIEHASH;
	}

	return 'wordpress_logged_in_pcm_scenario';
}

/**
 * Build the variant matrix from a configuration array.
 *
 * @param array<string, mixed> $config Variant toggles from the frontend.
 *
 * @return array<int, array<string, mixed>>
 */
function pcm_scenario_build_variants( array $config ): array {
	$variants = array();

	$warm         = ! empty( $config['warm'] );
	$cold         = ! empty( $config['cold'] );
	$cookie       = ! empty( $config['cookie'] );
	$no_cookie    = ! empty( $config['no_cookie'] );
	$mobile       = ! empty( $config['mobile'] );
	$desktop      = ! empty( $config['desktop'] );
	$query_params = isset( $config['query_params'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $config['query_params'] ) ) : array();

	if ( ! $warm && ! $cold && ! $cookie && ! $no_cookie && ! $mobile && ! $desktop && empty( $query_params ) ) {
		$warm      = true;
		$desktop   = true;
		$no_cookie = true;
	}

	$temps = array();
	if ( $warm ) {
		$temps[] = array(
			'id'    => 'warm',
			'label' => 'Warm',
		);
	}
	if ( $cold ) {
		$temps[] = array(
			'id'    => 'cold',
			'label' => 'Cold',
		);
	}
	if ( empty( $temps ) ) {
		$temps[] = array(
			'id'    => 'warm',
			'label' => 'Warm',
		);
	}

	$devices = array();
	if ( $desktop ) {
		$devices[] = array(
			'id'    => 'desktop',
			'label' => 'Desktop',
			'ua'    => PCM_SCENARIO_UA_DESKTOP,
		);
	}
	if ( $mobile ) {
		$devices[] = array(
			'id'    => 'mobile',
			'label' => 'Mobile',
			'ua'    => PCM_SCENARIO_UA_MOBILE,
		);
	}
	if ( empty( $devices ) ) {
		$devices[] = array(
			'id'    => 'desktop',
			'label' => 'Desktop',
			'ua'    => PCM_SCENARIO_UA_DESKTOP,
		);
	}

	$cookie_modes = array();
	if ( $no_cookie ) {
		$cookie_modes[] = array(
			'id'    => 'no_cookie',
			'label' => 'Anonymous',
		);
	}
	if ( $cookie ) {
		$cookie_modes[] = array(
			'id'    => 'cookie',
			'label' => 'Logged-in Cookie',
		);
	}
	if ( empty( $cookie_modes ) ) {
		$cookie_modes[] = array(
			'id'    => 'no_cookie',
			'label' => 'Anonymous',
		);
	}

	foreach ( $temps as $temp ) {
		foreach ( $devices as $device ) {
			foreach ( $cookie_modes as $cookie_mode ) {
				$variants[] = array(
					'id'          => $temp['id'] . '_' . $device['id'] . '_' . $cookie_mode['id'],
					'label'       => $temp['label'] . ' / ' . $device['label'] . ' / ' . $cookie_mode['label'],
					'temp'        => $temp['id'],
					'device'      => $device['id'],
					'ua'          => $device['ua'],
					'cookie_mode' => $cookie_mode['id'],
				);
			}
		}
	}

	foreach ( $query_params as $param ) {
		$param = trim( $param );
		if ( '' === $param ) {
			continue;
		}

		$variants[] = array(
			'id'           => 'qp_' . sanitize_key( $param ),
			'label'        => 'Query: ?' . $param,
			'temp'         => 'warm',
			'device'       => 'desktop',
			'ua'           => PCM_SCENARIO_UA_DESKTOP,
			'cookie_mode'  => 'no_cookie',
			'query_append' => $param,
		);
	}

	return $variants;
}

/**
 * Reduce a variant definition to the UI payload shape.
 *
 * @param array<string, mixed> $variant Variant definition.
 *
 * @return array<string, string>
 */
function pcm_scenario_variant_payload( array $variant ): array {
	return array(
		'id'          => isset( $variant['id'] ) ? (string) $variant['id'] : '',
		'label'       => isset( $variant['label'] ) ? (string) $variant['label'] : '',
		'temp'        => isset( $variant['temp'] ) ? (string) $variant['temp'] : '',
		'device'      => isset( $variant['device'] ) ? (string) $variant['device'] : '',
		'cookie_mode' => isset( $variant['cookie_mode'] ) ? (string) $variant['cookie_mode'] : '',
	);
}

/**
 * Whether selected variants need a warm-up phase.
 *
 * @param array<int, array<string, mixed>> $variants Selected variants.
 *
 * @return bool
 */
function pcm_scenario_variants_need_warmup( array $variants ): bool {
	foreach ( $variants as $variant ) {
		if ( isset( $variant['temp'] ) && 'warm' === $variant['temp'] ) {
			return true;
		}
	}

	return false;
}

/**
 * Probe one URL with one variant.
 *
 * @param string               $url Base URL to probe.
 * @param array<string, mixed> $variant Variant definition.
 *
 * @return array<string, mixed>
 */
function pcm_scenario_probe_variant( string $url, array $variant ): array {
	$probe_url = $url;

	if ( ! empty( $variant['query_append'] ) ) {
		$query_append = (string) $variant['query_append'];
		if ( str_contains( $query_append, '=' ) ) {
			$parts     = explode( '=', $query_append, 2 );
			$probe_url = add_query_arg( sanitize_key( $parts[0] ), sanitize_text_field( $parts[1] ), $probe_url );
		} else {
			$probe_url = add_query_arg( sanitize_key( $query_append ), '1', $probe_url );
		}
	}

	if ( 'cold' === ( $variant['temp'] ?? 'warm' ) ) {
		$probe_url = add_query_arg( 'pcm_bust', wp_rand( 100000, 999999 ), $probe_url );
	}

	$headers = array(
		'Accept'     => 'text/html',
		'User-Agent' => isset( $variant['ua'] ) ? (string) $variant['ua'] : PCM_SCENARIO_UA_DESKTOP,
	);

	if ( 'cold' === ( $variant['temp'] ?? 'warm' ) ) {
		$headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
		$headers['Pragma']        = 'no-cache';
	}

	$cookies = array();
	if ( 'cookie' === ( $variant['cookie_mode'] ?? 'no_cookie' ) ) {
		$cookies[] = new WP_Http_Cookie(
			array(
				'name'  => pcm_scenario_logged_in_cookie_name(),
				'value' => 'pcm_scenario_probe_not_a_session',
			)
		);
	}

	$start    = microtime( true );
	$response = wp_remote_get(
		$probe_url,
		array(
			'timeout'     => 12,
			'sslverify'   => false,
			'headers'     => $headers,
			'cookies'     => $cookies,
			'redirection' => 3,
		)
	);

	$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return array(
			'variant_id'    => isset( $variant['id'] ) ? (string) $variant['id'] : 'unknown',
			'label'         => isset( $variant['label'] ) ? (string) $variant['label'] : '',
			'url'           => $url,
			'status'        => 'error',
			'error'         => $response->get_error_message(),
			'elapsed_ms'    => $elapsed_ms,
			'http_code'     => 0,
			'cache_headers' => array(),
			'temp'          => isset( $variant['temp'] ) ? (string) $variant['temp'] : '',
			'device'        => isset( $variant['device'] ) ? (string) $variant['device'] : '',
			'cookie_mode'   => isset( $variant['cookie_mode'] ) ? (string) $variant['cookie_mode'] : '',
		);
	}

	$http_code   = absint( wp_remote_retrieve_response_code( $response ) );
	$raw_headers = pcm_scenario_normalize_response_headers( wp_remote_retrieve_headers( $response ) );

	return array(
		'variant_id'    => isset( $variant['id'] ) ? (string) $variant['id'] : 'unknown',
		'label'         => isset( $variant['label'] ) ? (string) $variant['label'] : '',
		'url'           => $url,
		'status'        => 'ok',
		'http_code'     => $http_code,
		'elapsed_ms'    => $elapsed_ms,
		'cache_headers' => pcm_scenario_extract_cache_headers( $raw_headers ),
		'temp'          => isset( $variant['temp'] ) ? (string) $variant['temp'] : '',
		'device'        => isset( $variant['device'] ) ? (string) $variant['device'] : '',
		'cookie_mode'   => isset( $variant['cookie_mode'] ) ? (string) $variant['cookie_mode'] : '',
	);
}

/**
 * Warm one URL with a HEAD preflight request.
 *
 * @param string $url URL to preflight.
 *
 * @return array<string, mixed>
 */
function pcm_scenario_preflight_url( string $url ): array {
	$response = wp_remote_request(
		$url,
		array(
			'method'      => 'HEAD',
			'timeout'     => 8,
			'sslverify'   => false,
			'redirection' => 3,
			'headers'     => array(
				'Accept'     => 'text/html',
				'User-Agent' => PCM_SCENARIO_UA_DESKTOP,
			),
			'cookies'     => array(),
		)
	);

	$status = 0;
	if ( ! is_wp_error( $response ) ) {
		$status = absint( wp_remote_retrieve_response_code( $response ) );
	}

	usleep( PCM_SCENARIO_WARMUP_DELAY_US );

	return array(
		'url'       => $url,
		'success'   => ! is_wp_error( $response ),
		'http_code' => $status,
	);
}

/**
 * Extract cache-relevant headers from a response.
 *
 * @param array<string, mixed> $headers Raw response headers.
 *
 * @return array<string, mixed>
 */
function pcm_scenario_extract_cache_headers( array $headers ): array {
	$get = static function ( string $key ) use ( $headers ): string {
		foreach ( $headers as $header_name => $header_value ) {
			if ( 0 === strcasecmp( (string) $header_name, $key ) ) {
				return is_array( $header_value ) ? implode( ', ', $header_value ) : (string) $header_value;
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
	$verdict    = 'unknown';

	if ( '' !== $x_nananana ) {
		$verdict = str_contains( strtolower( $x_nananana ), 'batcache' ) ? 'batcache_hit' : 'batcache_present';
	}

	if ( '' !== $cf_status ) {
		$verdict = strtolower( $cf_status );
	}

	if ( '' !== $x_cache ) {
		$lower_x_cache = strtolower( $x_cache );
		if ( str_contains( $lower_x_cache, 'bypass' ) ) {
			$verdict = 'bypass';
		} elseif ( str_contains( $lower_x_cache, 'hit' ) ) {
			$verdict = 'edge_hit';
		} elseif ( str_contains( $lower_x_cache, 'miss' ) ) {
			$verdict = 'edge_miss';
		}
	}

	if (
		'unknown' === $verdict
		&& '' !== $cache_ctrl
		&& (
			str_contains( strtolower( $cache_ctrl ), 'private' )
			|| str_contains( strtolower( $cache_ctrl ), 'no-store' )
		)
	) {
		$verdict = 'bypass';
	}

	return array(
		'verdict'        => $verdict,
		'x_cache'        => $x_cache,
		'x_nananana'     => $x_nananana,
		'cf_status'      => $cf_status,
		'cache_control'  => $cache_ctrl,
		'age'            => $age,
		'vary'           => $vary,
		'set_cookie'     => $set_cookie,
		'has_set_cookie' => '' !== $set_cookie,
	);
}

/**
 * Validate the submitted URL list against the current site host.
 *
 * @param array<int, mixed> $raw_urls Raw request values.
 *
 * @return array{valid: array<int, string>, rejected: array<int, string>}
 */
function pcm_scenario_validate_requested_urls( array $raw_urls ): array {
	$site_host = pcm_scenario_current_site_host();
	$seen      = array();
	$valid     = array();
	$rejected  = array();

	foreach ( $raw_urls as $raw_url ) {
		$original = trim( wp_unslash( (string) $raw_url ) );
		$url      = esc_url_raw( $original );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $url || ! is_string( $url_host ) || '' === $site_host || 0 !== strcasecmp( $site_host, $url_host ) ) {
			if ( '' !== $original ) {
				$rejected[] = $original;
			}
			continue;
		}

		if ( isset( $seen[ $url ] ) ) {
			continue;
		}

		$seen[ $url ] = true;

		if ( count( $valid ) < PCM_SCENARIO_MAX_URLS ) {
			$valid[] = $url;
		}
	}

	return array(
		'valid'    => $valid,
		'rejected' => $rejected,
	);
}

/**
 * Build the lock option key for a scan token.
 *
 * @param string $scan_token Scan token.
 *
 * @return string
 */
function pcm_scenario_lock_option_name( string $scan_token ): string {
	return 'pcm_scenario_lock_' . substr( md5( $scan_token ), 0, 24 );
}

/**
 * Acquire the scan queue lock.
 *
 * @param string $scan_token Scan token.
 *
 * @return bool
 */
function pcm_scenario_acquire_lock( string $scan_token ): bool {
	$option_name = pcm_scenario_lock_option_name( $scan_token );
	$started_at  = microtime( true );

	while ( ( microtime( true ) - $started_at ) < 2 ) {
		if ( add_option( $option_name, time(), '', false ) ) {
			return true;
		}

		$existing = (int) get_option( $option_name, 0 );
		if ( $existing > 0 && ( time() - $existing ) > 60 ) {
			delete_option( $option_name );
			continue;
		}

		usleep( 100000 );
	}

	return false;
}

/**
 * Release the scan queue lock.
 *
 * @param string $scan_token Scan token.
 *
 * @return void
 */
function pcm_scenario_release_lock( string $scan_token ): void {
	delete_option( pcm_scenario_lock_option_name( $scan_token ) );
}

/**
 * Reserve the next URLs to process and mark them as in-flight.
 *
 * @param string $scan_token Scan token.
 * @param int    $batch_size Number of URLs to reserve.
 *
 * @return array<string, mixed>|null
 */
function pcm_scenario_reserve_batch( string $scan_token, int $batch_size ): ?array {
	if ( ! pcm_scenario_acquire_lock( $scan_token ) ) {
		return null;
	}

	try {
		$state = get_transient( $scan_token );
		if ( ! is_array( $state ) ) {
			return array(
				'state'     => null,
				'reserved'  => array(),
				'remaining' => 0,
				'inflight'  => 0,
			);
		}

		$urls = isset( $state['urls'] ) && is_array( $state['urls'] ) ? array_values( $state['urls'] ) : array();
		if ( empty( $urls ) ) {
			return array(
				'state'     => $state,
				'reserved'  => array(),
				'remaining' => 0,
				'inflight'  => isset( $state['inflight'] ) ? absint( $state['inflight'] ) : 0,
			);
		}

		$reserved          = array_splice( $urls, 0, max( 1, $batch_size ) );
		$state['urls']     = $urls;
		$state['inflight'] = absint( $state['inflight'] ?? 0 ) + count( $reserved );

		set_transient( $scan_token, $state, PCM_SCENARIO_QUEUE_TTL );

		return array(
			'state'     => $state,
			'reserved'  => $reserved,
			'remaining' => count( $urls ),
			'inflight'  => absint( $state['inflight'] ),
		);
	} finally {
		pcm_scenario_release_lock( $scan_token );
	}
}

/**
 * Mark one reserved batch as finished and determine completion state.
 *
 * @param string $scan_token Scan token.
 * @param int    $processed_count Number of processed URLs.
 *
 * @return array<string, mixed>|null
 */
function pcm_scenario_finalize_batch( string $scan_token, int $processed_count ): ?array {
	if ( ! pcm_scenario_acquire_lock( $scan_token ) ) {
		return null;
	}

	try {
		$state = get_transient( $scan_token );
		if ( ! is_array( $state ) ) {
			return array(
				'cancelled' => true,
				'done'      => false,
				'remaining' => 0,
				'inflight'  => 0,
			);
		}

		$state['inflight'] = max( 0, absint( $state['inflight'] ?? 0 ) - max( 0, $processed_count ) );
		$remaining         = isset( $state['urls'] ) && is_array( $state['urls'] ) ? count( $state['urls'] ) : 0;
		$done              = 0 === $remaining && 0 === absint( $state['inflight'] );

		if ( $done ) {
			delete_transient( $scan_token );
		} else {
			set_transient( $scan_token, $state, PCM_SCENARIO_QUEUE_TTL );
		}

		return array(
			'cancelled' => false,
			'done'      => $done,
			'remaining' => $remaining,
			'inflight'  => absint( $state['inflight'] ),
			'state'     => $state,
		);
	} finally {
		pcm_scenario_release_lock( $scan_token );
	}
}

/**
 * Load one saved Scenario Scan run and format it for the UI.
 *
 * @param int $run_id Run ID.
 *
 * @return array<string, mixed>|null
 */
function pcm_scenario_load_run_payload( int $run_id ): ?array {
	global $wpdb;

	pcm_scenario_ensure_storage_schema();

	$runs_table = pcm_scenario_runs_table_name();
	$run        = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with trusted prefix.
			"SELECT id, scan_token, started_at, completed_at, url_count, variant_count, variant_config, status FROM {$runs_table} WHERE id = %d LIMIT 1",
			absint( $run_id )
		),
		ARRAY_A
	);

	if ( ! is_array( $run ) || empty( $run ) ) {
		return null;
	}

	$config            = ! empty( $run['variant_config'] ) ? json_decode( (string) $run['variant_config'], true ) : array();
	$variant_ids       = isset( $config['variant_ids'] ) && is_array( $config['variant_ids'] ) ? $config['variant_ids'] : array();
	$dropped_variants  = isset( $config['dropped_variants'] ) && is_array( $config['dropped_variants'] ) ? $config['dropped_variants'] : array();
	$variant_positions = array();
	$variant_map       = array();

	foreach ( $variant_ids as $index => $variant_row ) {
		if ( ! isset( $variant_row['id'] ) ) {
			continue;
		}

		$variant_id                       = (string) $variant_row['id'];
		$variant_positions[ $variant_id ] = (int) $index;
		$variant_map[ $variant_id ]       = array(
			'id'          => $variant_id,
			'label'       => isset( $variant_row['label'] ) ? (string) $variant_row['label'] : $variant_id,
			'temp'        => isset( $variant_row['temp'] ) ? (string) $variant_row['temp'] : '',
			'device'      => isset( $variant_row['device'] ) ? (string) $variant_row['device'] : '',
			'cookie_mode' => isset( $variant_row['cookie_mode'] ) ? (string) $variant_row['cookie_mode'] : '',
		);
	}

	$rows = function_exists( 'pcm_scenario_storage_get_run_results' ) ? pcm_scenario_storage_get_run_results( $run_id ) : array();

	$grouped = array();
	foreach ( (array) $rows as $row ) {
		$url = isset( $row['url'] ) ? (string) $row['url'] : '';
		if ( '' === $url ) {
			continue;
		}

		if ( ! isset( $grouped[ $url ] ) ) {
			$grouped[ $url ] = array(
				'url'      => $url,
				'variants' => array(),
			);
		}

		$variant_id   = isset( $row['variant_id'] ) ? (string) $row['variant_id'] : '';
		$variant_meta = isset( $variant_map[ $variant_id ] ) ? $variant_map[ $variant_id ] : array(
			'id'          => $variant_id,
			'label'       => $variant_id,
			'temp'        => '',
			'device'      => '',
			'cookie_mode' => '',
		);

		$grouped[ $url ]['variants'][] = array(
			'variant_id'    => $variant_meta['id'],
			'label'         => isset( $row['variant_label'] ) && '' !== (string) $row['variant_label'] ? (string) $row['variant_label'] : $variant_meta['label'],
			'status'        => isset( $row['status'] ) ? (string) $row['status'] : 'ok',
			'error'         => isset( $row['error_message'] ) ? (string) $row['error_message'] : '',
			'http_code'     => isset( $row['http_code'] ) ? absint( $row['http_code'] ) : 0,
			'elapsed_ms'    => isset( $row['elapsed_ms'] ) ? absint( $row['elapsed_ms'] ) : 0,
			'cache_headers' => isset( $row['cache_headers'] ) && is_array( $row['cache_headers'] ) ? $row['cache_headers'] : array(),
			'temp'          => $variant_meta['temp'],
			'device'        => $variant_meta['device'],
			'cookie_mode'   => $variant_meta['cookie_mode'],
		);
	}

	foreach ( $grouped as $url => $group ) {
		usort(
			$group['variants'],
			static function ( array $left, array $right ) use ( $variant_positions ): int {
				$left_position  = isset( $variant_positions[ $left['variant_id'] ] ) ? $variant_positions[ $left['variant_id'] ] : PHP_INT_MAX;
				$right_position = isset( $variant_positions[ $right['variant_id'] ] ) ? $variant_positions[ $right['variant_id'] ] : PHP_INT_MAX;
				return $left_position <=> $right_position;
			}
		);

		$grouped[ $url ] = $group;
	}

	return array(
		'run_id'           => absint( $run['id'] ),
		'url_count'        => isset( $run['url_count'] ) ? absint( $run['url_count'] ) : count( $grouped ),
		'variant_count'    => isset( $run['variant_count'] ) ? absint( $run['variant_count'] ) : count( $variant_ids ),
		'variant_ids'      => array_values( $variant_map ),
		'dropped_variants' => $dropped_variants,
		'scanned_at'       => isset( $run['completed_at'] ) && '' !== (string) $run['completed_at'] ? (string) $run['completed_at'] : (string) $run['started_at'],
		'status'           => isset( $run['status'] ) ? (string) $run['status'] : 'complete',
		'results'          => array_values( $grouped ),
	);
}

/**
 * AJAX: Resolve source URLs for the Scenario Scan UI.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_resolve_urls(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_scenario_scan_is_enabled() ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is disabled until the Caching Suite feature flag is enabled.', 'pressable_cache_management' ), 400, 'feature_disabled' );
	}

	$source = filter_input( INPUT_POST, 'source', FILTER_UNSAFE_RAW );
	$source = is_string( $source ) ? sanitize_key( wp_unslash( $source ) ) : '';

	$urls = match ( $source ) {
		'sitemap'   => pcm_scenario_urls_from_sitemap( PCM_SCENARIO_MAX_URLS ),
		'top_pages' => pcm_scenario_urls_top_pages( PCM_SCENARIO_MAX_URLS ),
		default     => array(),
	};

	wp_send_json_success(
		array(
			'urls'      => $urls,
			'source'    => $source,
			'max_urls'  => PCM_SCENARIO_MAX_URLS,
			'site_host' => pcm_scenario_current_site_host(),
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_resolve_urls', 'pcm_ajax_scenario_scan_resolve_urls' );

/**
 * AJAX: Start a Scenario Scan run.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_run(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_scenario_scan_is_enabled() ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is disabled until the Caching Suite feature flag is enabled.', 'pressable_cache_management' ), 400, 'feature_disabled' );
	}

	pcm_scenario_ensure_storage_schema();

	$raw_urls = filter_input( INPUT_POST, 'urls', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
	$parsed   = pcm_scenario_validate_requested_urls( is_array( $raw_urls ) ? $raw_urls : array() );
	$urls     = $parsed['valid'];

	if ( empty( $urls ) ) {
		pcm_scenario_ajax_error(
			__( 'No valid URLs were selected. URLs must use HTTP(S) and match this site.', 'pressable_cache_management' ),
			400,
			'no_valid_urls',
			array(
				'rejected_urls' => $parsed['rejected'],
			)
		);
	}

	$raw_variants   = filter_input( INPUT_POST, 'variants', FILTER_UNSAFE_RAW );
	$variant_config = is_string( $raw_variants ) ? json_decode( wp_unslash( $raw_variants ), true ) : array();
	$variant_config = is_array( $variant_config ) ? $variant_config : array();

	$all_variants      = pcm_scenario_build_variants( $variant_config );
	$variants          = $all_variants;
	$dropped_variants  = array();
	$total_probe_count = count( $urls ) * count( $variants );

	if ( $total_probe_count > PCM_SCENARIO_MAX_TOTAL_PROBES ) {
		$allowed_variants = max( 1, (int) floor( PCM_SCENARIO_MAX_TOTAL_PROBES / max( 1, count( $urls ) ) ) );
		$dropped_variants = array_map( 'pcm_scenario_variant_payload', array_slice( $variants, $allowed_variants ) );
		$variants         = array_slice( $variants, 0, $allowed_variants );
	}

	if ( empty( $variants ) ) {
		pcm_scenario_ajax_error( __( 'At least one scan variant must be selected.', 'pressable_cache_management' ), 400, 'no_variants' );
	}

	$warmup_required = empty( $variant_config['skip_warmup'] ) && pcm_scenario_variants_need_warmup( $variants );
	$scan_token      = 'pcm_scenario_' . wp_generate_uuid4();
	$run_id          = function_exists( 'pcm_scenario_storage_create_run' )
		? pcm_scenario_storage_create_run(
			$scan_token,
			count( $urls ),
			count( $variants ),
			array(
				'config'           => $variant_config,
				'variant_ids'      => array_map( 'pcm_scenario_variant_payload', $variants ),
				'dropped_variants' => $dropped_variants,
			),
			'running'
		)
		: false;

	if ( ! $run_id ) {
		pcm_scenario_ajax_error( __( 'Unable to create a saved Scenario Scan run.', 'pressable_cache_management' ), 500, 'run_create_failed' );
	}

	$state = array(
		'run_id'           => (int) $run_id,
		'urls'             => array_values( $urls ),
		'variants'         => array_values( $variants ),
		'total'            => count( $urls ),
		'variant_ids'      => array_map( 'pcm_scenario_variant_payload', $variants ),
		'dropped_variants' => $dropped_variants,
		'warmup_urls'      => $warmup_required ? array_values( $urls ) : array(),
		'warmup_total'     => $warmup_required ? count( $urls ) : 0,
		'inflight'         => 0,
	);

	set_transient( $scan_token, $state, PCM_SCENARIO_QUEUE_TTL );

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log(
			'scenario_scan_started',
			'diagnostics',
			array(
				'run_id'         => (int) $run_id,
				'url_count'      => count( $urls ),
				'variant_count'  => count( $variants ),
				'warmup_enabled' => $warmup_required,
			)
		);
	}

	wp_send_json_success(
		array(
			'run_id'           => (int) $run_id,
			'scan_token'       => $scan_token,
			'total'            => count( $urls ),
			'variant_count'    => count( $variants ),
			'variant_ids'      => $state['variant_ids'],
			'dropped_variants' => $dropped_variants,
			'max_total'        => PCM_SCENARIO_MAX_TOTAL_PROBES,
			'warmup_required'  => $warmup_required,
			'warmup_total'     => $warmup_required ? count( $urls ) : 0,
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_run', 'pcm_ajax_scenario_scan_run' );

/**
 * AJAX: Warm the next queued URL before warm probes.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_warmup(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_scenario_scan_is_enabled() ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is disabled until the Caching Suite feature flag is enabled.', 'pressable_cache_management' ), 400, 'feature_disabled' );
	}

	$scan_token = filter_input( INPUT_POST, 'scan_token', FILTER_UNSAFE_RAW );
	$scan_token = is_string( $scan_token ) ? sanitize_text_field( wp_unslash( $scan_token ) ) : '';

	if ( '' === $scan_token ) {
		pcm_scenario_ajax_error( __( 'Missing scan token.', 'pressable_cache_management' ), 400, 'missing_scan_token' );
	}

	if ( ! pcm_scenario_acquire_lock( $scan_token ) ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is busy. Please retry.', 'pressable_cache_management' ), 409, 'scan_busy' );
	}

	try {
		$state = get_transient( $scan_token );
		if ( ! is_array( $state ) ) {
			pcm_scenario_ajax_error( __( 'Scenario Scan queue not found or already finished.', 'pressable_cache_management' ), 404, 'scan_not_found' );
		}

		$warmup_urls = isset( $state['warmup_urls'] ) && is_array( $state['warmup_urls'] ) ? array_values( $state['warmup_urls'] ) : array();
		$total       = isset( $state['warmup_total'] ) ? absint( $state['warmup_total'] ) : count( $warmup_urls );

		if ( empty( $warmup_urls ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'remaining' => 0,
					'warmed'    => $total,
					'total'     => $total,
					'current'   => '',
				)
			);
		}

		$current              = array_shift( $warmup_urls );
		$state['warmup_urls'] = $warmup_urls;
		set_transient( $scan_token, $state, PCM_SCENARIO_QUEUE_TTL );
	} finally {
		pcm_scenario_release_lock( $scan_token );
	}

	pcm_scenario_preflight_url( (string) $current );

	$remaining = count( $warmup_urls );
	wp_send_json_success(
		array(
			'done'      => 0 === $remaining,
			'remaining' => $remaining,
			'warmed'    => max( 0, $total - $remaining ),
			'total'     => $total,
			'current'   => (string) $current,
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_warmup', 'pcm_ajax_scenario_scan_warmup' );

/**
 * AJAX: Process the next one or more URLs in the Scenario Scan queue.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_next(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_scenario_scan_is_enabled() ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is disabled until the Caching Suite feature flag is enabled.', 'pressable_cache_management' ), 400, 'feature_disabled' );
	}

	$scan_token = filter_input( INPUT_POST, 'scan_token', FILTER_UNSAFE_RAW );
	$scan_token = is_string( $scan_token ) ? sanitize_text_field( wp_unslash( $scan_token ) ) : '';

	if ( '' === $scan_token ) {
		pcm_scenario_ajax_error( __( 'Missing scan token.', 'pressable_cache_management' ), 400, 'missing_scan_token' );
	}

	$batch_size = filter_input( INPUT_POST, 'batch_size', FILTER_VALIDATE_INT );
	$batch_size = is_int( $batch_size ) && $batch_size > 0 ? $batch_size : 1;
	$batch_size = min( PCM_SCENARIO_MAX_BATCH_SIZE, $batch_size );

	$reservation = pcm_scenario_reserve_batch( $scan_token, $batch_size );
	if ( ! is_array( $reservation ) ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is busy. Please retry.', 'pressable_cache_management' ), 409, 'scan_busy' );
	}

	if ( empty( $reservation['state'] ) ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan queue not found or already finished.', 'pressable_cache_management' ), 404, 'scan_not_found' );
	}

	/** @var array<string, mixed> $state */
	$state = $reservation['state'];

	$reserved_urls = isset( $reservation['reserved'] ) && is_array( $reservation['reserved'] ) ? $reservation['reserved'] : array();
	if ( empty( $reserved_urls ) ) {
		wp_send_json_success(
			array(
				'run_id'           => isset( $state['run_id'] ) ? absint( $state['run_id'] ) : 0,
				'done'             => false,
				'processed'        => 0,
				'remaining'        => isset( $reservation['remaining'] ) ? absint( $reservation['remaining'] ) : 0,
				'inflight'         => isset( $reservation['inflight'] ) ? absint( $reservation['inflight'] ) : 0,
				'total'            => isset( $state['total'] ) ? absint( $state['total'] ) : 0,
				'results'          => array(),
				'variant_count'    => isset( $state['variants'] ) && is_array( $state['variants'] ) ? count( $state['variants'] ) : 0,
				'variant_ids'      => isset( $state['variant_ids'] ) && is_array( $state['variant_ids'] ) ? $state['variant_ids'] : array(),
				'dropped_variants' => isset( $state['dropped_variants'] ) && is_array( $state['dropped_variants'] ) ? $state['dropped_variants'] : array(),
				'time_guard_hit'   => false,
				'url_count'        => isset( $state['total'] ) ? absint( $state['total'] ) : 0,
				'scanned_at'       => '',
			)
		);
	}

	$start_time = microtime( true );
	$results    = array();
	$processed  = 0;
	$last_url   = '';
	$variants   = isset( $state['variants'] ) && is_array( $state['variants'] ) ? $state['variants'] : array();
	$run_id     = isset( $state['run_id'] ) ? absint( $state['run_id'] ) : 0;

	foreach ( $reserved_urls as $url ) {
		$last_url = is_string( $url ) ? $url : '';
		if ( '' === $last_url ) {
			continue;
		}

		$url_results = array();
		foreach ( $variants as $variant ) {
			$url_results[] = pcm_scenario_probe_variant( $last_url, $variant );
		}

		$step_result = array(
			'url'      => $last_url,
			'variants' => $url_results,
		);

		if ( $run_id > 0 && function_exists( 'pcm_scenario_storage_store_results' ) ) {
			pcm_scenario_storage_store_results( $run_id, $last_url, $url_results );
		}

		$results[] = $step_result;
		++$processed;

		if ( ( microtime( true ) - $start_time ) >= 50 ) {
			break;
		}
	}

	if ( $processed < count( $reserved_urls ) ) {
		$requeue = array_slice( $reserved_urls, $processed );
		if ( pcm_scenario_acquire_lock( $scan_token ) ) {
			try {
				$current_state = get_transient( $scan_token );
				if ( is_array( $current_state ) ) {
					$current_queue             = isset( $current_state['urls'] ) && is_array( $current_state['urls'] ) ? $current_state['urls'] : array();
					$current_state['urls']     = array_values( array_merge( $requeue, $current_queue ) );
					$current_state['inflight'] = max( 0, absint( $current_state['inflight'] ?? 0 ) - count( $requeue ) );
					set_transient( $scan_token, $current_state, PCM_SCENARIO_QUEUE_TTL );
				}
			} finally {
				pcm_scenario_release_lock( $scan_token );
			}
		}
	}

	$finalized = pcm_scenario_finalize_batch( $scan_token, $processed );
	if ( ! is_array( $finalized ) ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is busy. Please retry.', 'pressable_cache_management' ), 409, 'scan_busy' );
	}

	if ( ! empty( $finalized['done'] ) && $run_id > 0 && function_exists( 'pcm_scenario_storage_update_run_status' ) ) {
		pcm_scenario_storage_update_run_status( $run_id, 'complete' );
	}

	wp_send_json_success(
		array(
			'run_id'           => $run_id,
			'done'             => ! empty( $finalized['done'] ),
			'processed'        => $processed,
			'remaining'        => isset( $finalized['remaining'] ) ? absint( $finalized['remaining'] ) : 0,
			'inflight'         => isset( $finalized['inflight'] ) ? absint( $finalized['inflight'] ) : 0,
			'total'            => isset( $state['total'] ) ? absint( $state['total'] ) : 0,
			'current'          => $last_url,
			'results'          => $results,
			'variant_count'    => count( $variants ),
			'variant_ids'      => isset( $state['variant_ids'] ) && is_array( $state['variant_ids'] ) ? $state['variant_ids'] : array(),
			'dropped_variants' => isset( $state['dropped_variants'] ) && is_array( $state['dropped_variants'] ) ? $state['dropped_variants'] : array(),
			'time_guard_hit'   => $processed < count( $reserved_urls ),
			'url_count'        => isset( $state['total'] ) ? absint( $state['total'] ) : 0,
			'scanned_at'       => ! empty( $finalized['done'] ) ? gmdate( 'Y-m-d H:i:s' ) . ' UTC' : '',
			'cancelled'        => ! empty( $finalized['cancelled'] ),
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_next', 'pcm_ajax_scenario_scan_next' );

/**
 * AJAX: Cancel an in-progress Scenario Scan.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_cancel(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	$scan_token = filter_input( INPUT_POST, 'scan_token', FILTER_UNSAFE_RAW );
	$scan_token = is_string( $scan_token ) ? sanitize_text_field( wp_unslash( $scan_token ) ) : '';

	if ( '' === $scan_token ) {
		pcm_scenario_ajax_error( __( 'Missing scan token.', 'pressable_cache_management' ), 400, 'missing_scan_token' );
	}

	$state = get_transient( $scan_token );
	if ( is_array( $state ) ) {
		$run_id = isset( $state['run_id'] ) ? absint( $state['run_id'] ) : 0;
		delete_transient( $scan_token );
		if ( $run_id > 0 && function_exists( 'pcm_scenario_storage_update_run_status' ) ) {
			pcm_scenario_storage_update_run_status( $run_id, 'cancelled' );
		}
	}

	wp_send_json_success(
		array(
			'cancelled' => true,
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_cancel', 'pcm_ajax_scenario_scan_cancel' );

/**
 * AJAX: Load one saved Scenario Scan run.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_load(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_scenario_scan_is_enabled() ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is disabled until the Caching Suite feature flag is enabled.', 'pressable_cache_management' ), 400, 'feature_disabled' );
	}

	$run_id = filter_input( INPUT_POST, 'run_id', FILTER_VALIDATE_INT );
	$run_id = is_int( $run_id ) ? $run_id : 0;

	if ( $run_id <= 0 ) {
		pcm_scenario_ajax_error( __( 'Missing run ID.', 'pressable_cache_management' ), 400, 'missing_run_id' );
	}

	$payload = pcm_scenario_load_run_payload( $run_id );
	if ( ! is_array( $payload ) ) {
		pcm_scenario_ajax_error( __( 'Saved Scenario Scan run not found.', 'pressable_cache_management' ), 404, 'run_not_found' );
	}

	wp_send_json_success( $payload );
}
add_action( 'wp_ajax_pcm_scenario_scan_load', 'pcm_ajax_scenario_scan_load' );

/**
 * AJAX: List recent saved Scenario Scan runs.
 *
 * @return void
 */
function pcm_ajax_scenario_scan_recent(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_scenario_scan_is_enabled() ) {
		pcm_scenario_ajax_error( __( 'Scenario Scan is disabled until the Caching Suite feature flag is enabled.', 'pressable_cache_management' ), 400, 'feature_disabled' );
	}

	pcm_scenario_ensure_storage_schema();

	wp_send_json_success(
		array(
			'runs' => function_exists( 'pcm_scenario_storage_get_recent_runs' ) ? pcm_scenario_storage_get_recent_runs( PCM_SCENARIO_MAX_RECENT_RUNS ) : array(),
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_recent', 'pcm_ajax_scenario_scan_recent' );
