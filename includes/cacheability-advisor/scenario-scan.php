<?php
/**
 * Scenario-Based Scan Mode — multi-variant cache diagnostics.
 *
 * Probes a set of URLs (from sitemap, top pages, or custom list)
 * through multiple scenario variants: warm/cold, cookie/no-cookie,
 * mobile/desktop UA, and query-param permutations. Results are
 * returned side-by-side so cache behaviour differences are obvious.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Variant definitions ──────────────────────────────────────────────────────

/**
 * User-Agent strings for device variants.
 */
if ( ! defined( 'PCM_SCENARIO_UA_DESKTOP' ) ) {
	define( 'PCM_SCENARIO_UA_DESKTOP', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' );
}
if ( ! defined( 'PCM_SCENARIO_UA_MOBILE' ) ) {
	define( 'PCM_SCENARIO_UA_MOBILE', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' );
}

/**
 * Maximum URLs per scenario scan to prevent abuse.
 */
if ( ! defined( 'PCM_SCENARIO_MAX_URLS' ) ) {
	define( 'PCM_SCENARIO_MAX_URLS', 20 );
}

/**
 * Normalize WP_HTTP headers to a plain associative array.
 *
 * @param mixed $headers Raw header container from WP_HTTP.
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

// ── URL source helpers ───────────────────────────────────────────────────────

/**
 * Discover URLs from the site's XML sitemap.
 *
 * Reads the WP core sitemap index and extracts URLs from sub-sitemaps.
 *
 * @param int $limit Maximum URLs to return.
 * @return string[]
 */
function pcm_scenario_urls_from_sitemap( int $limit = 20 ): array {
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

	// Parse sitemap index to find sub-sitemaps.
	$sub_sitemaps = array();
	if ( preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/i', $body, $matches ) ) {
		$sub_sitemaps = array_slice( $matches[1], 0, 5 );
	}

	// If the body itself contains <url> entries, it's a flat sitemap.
	if ( empty( $sub_sitemaps ) || str_contains( $body, '<url>' ) ) {
		if ( preg_match_all( '/<url>\s*<loc>\s*(.*?)\s*<\/loc>/i', $body, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
	}

	// Fetch sub-sitemaps for URLs.
	foreach ( $sub_sitemaps as $sub_url ) {
		if ( count( $urls ) >= $limit ) {
			break;
		}
		$sub_url = trim( $sub_url );
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

	// Deduplicate and limit.
	$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );

	return array_slice( $urls, 0, $limit );
}

/**
 * Get top URLs by recent page views (posts/pages ordered by modified date).
 *
 * @param int $limit Maximum URLs to return.
 * @return string[]
 */
function pcm_scenario_urls_top_pages( int $limit = 10 ): array {
	$urls = array( home_url( '/' ) );

	$posts = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => $limit - 1,
			'post_status'    => 'publish',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		)
	);

	foreach ( $posts as $post ) {
		$url = get_permalink( $post );
		if ( $url ) {
			$urls[] = $url;
		}
	}

	return array_slice( array_values( array_unique( $urls ) ), 0, $limit );
}

// ── Variant probe engine ─────────────────────────────────────────────────────

/**
 * Build the list of variant specs from a configuration array.
 *
 * @param array $config Variant toggles from the frontend.
 * @return array[] Each element: { id, label, args_modifier }
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

	// If nothing selected, default to a single baseline probe.
	if ( ! $warm && ! $cold && ! $cookie && ! $no_cookie && ! $mobile && ! $desktop && empty( $query_params ) ) {
		$warm      = true;
		$desktop   = true;
		$no_cookie = true;
	}

	// Temperature variants.
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

	// Device variants.
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

	// Cookie variants.
	$cookie_modes = array();
	if ( $no_cookie ) {
		$cookie_modes[] = array(
			'id'    => 'no_cookie',
			'label' => 'No Cookie',
		);
	}
	if ( $cookie ) {
		$cookie_modes[] = array(
			'id'    => 'cookie',
			'label' => 'With Cookie',
		);
	}
	if ( empty( $cookie_modes ) ) {
		$cookie_modes[] = array(
			'id'    => 'no_cookie',
			'label' => 'No Cookie',
		);
	}

	// Build the cross-product of all dimensions.
	foreach ( $temps as $temp ) {
		foreach ( $devices as $device ) {
			foreach ( $cookie_modes as $ck ) {
				$variants[] = array(
					'id'          => $temp['id'] . '_' . $device['id'] . '_' . $ck['id'],
					'label'       => $temp['label'] . ' / ' . $device['label'] . ' / ' . $ck['label'],
					'temp'        => $temp['id'],
					'ua'          => $device['ua'],
					'cookie_mode' => $ck['id'],
				);
			}
		}
	}

	// Query-param variants: each gets its own additional probe.
	foreach ( $query_params as $param ) {
		$param = trim( $param );
		if ( '' === $param ) {
			continue;
		}
		$variants[] = array(
			'id'           => 'qp_' . sanitize_key( $param ),
			'label'        => 'Query: ?' . $param,
			'temp'         => 'warm',
			'ua'           => PCM_SCENARIO_UA_DESKTOP,
			'cookie_mode'  => 'no_cookie',
			'query_append' => $param,
		);
	}

	return $variants;
}

/**
 * Probe a single URL with a specific variant.
 *
 * @param string $url     The URL to probe.
 * @param array  $variant The variant spec from pcm_scenario_build_variants().
 * @return array Probe result.
 */
function pcm_scenario_probe_variant( string $url, array $variant ): array {
	// Apply query-param variant.
	if ( ! empty( $variant['query_append'] ) ) {
		$qs = $variant['query_append'];
		// Support both "key=value" and "key" forms.
		if ( str_contains( $qs, '=' ) ) {
			$parts = explode( '=', $qs, 2 );
			$url   = add_query_arg( sanitize_key( $parts[0] ), sanitize_text_field( $parts[1] ), $url );
		} else {
			$url = add_query_arg( sanitize_key( $qs ), '1', $url );
		}
	}

	// Cold run: add a cache-busting param to force origin.
	if ( 'cold' === ( $variant['temp'] ?? 'warm' ) ) {
		$url = add_query_arg( 'pcm_bust', wp_rand( 100000, 999999 ), $url );
	}

	$headers = array(
		'Accept'     => 'text/html',
		'User-Agent' => $variant['ua'] ?? PCM_SCENARIO_UA_DESKTOP,
	);

	// Cold: also send cache-bypass headers.
	if ( 'cold' === ( $variant['temp'] ?? 'warm' ) ) {
		$headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
		$headers['Pragma']        = 'no-cache';
	}

	$cookies = array();
	if ( 'cookie' === ( $variant['cookie_mode'] ?? 'no_cookie' ) ) {
		// Send a common WP cookie to trigger logged-in-like behaviour.
		$cookies[] = new WP_Http_Cookie(
			array(
				'name'  => 'wordpress_logged_in_pcm_scenario',
				'value' => 'scenario_test_' . wp_rand( 1000, 9999 ),
			)
		);
	}

	$start = microtime( true );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 12,
			'sslverify'   => false,
			'headers'     => $headers,
			'cookies'     => $cookies,
			'redirection' => 3,
		)
	);

	$elapsed_ms = round( ( microtime( true ) - $start ) * 1000 );

	if ( is_wp_error( $response ) ) {
		return array(
			'variant_id' => $variant['id'] ?? 'unknown',
			'label'      => $variant['label'] ?? '',
			'url'        => $url,
			'status'     => 'error',
			'error'      => $response->get_error_message(),
			'elapsed_ms' => $elapsed_ms,
		);
	}

	$code        = wp_remote_retrieve_response_code( $response );
	$raw_headers = wp_remote_retrieve_headers( $response );
	$raw_headers = pcm_scenario_normalize_response_headers( $raw_headers );

	$cache_headers = pcm_scenario_extract_cache_headers( $raw_headers );

	return array(
		'variant_id'    => $variant['id'] ?? 'unknown',
		'label'         => $variant['label'] ?? '',
		'url'           => $url,
		'status'        => 'ok',
		'http_code'     => $code,
		'elapsed_ms'    => $elapsed_ms,
		'cache_headers' => $cache_headers,
	);
}

/**
 * Extract cache-relevant headers.
 *
 * @param array $headers Raw headers.
 * @return array
 */
function pcm_scenario_extract_cache_headers( array $headers ): array {
	$get = function ( string $key ) use ( $headers ): string {
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

	// Determine cache verdict.
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
	);
}

// ── AJAX handlers ────────────────────────────────────────────────────────────

/**
 * AJAX: Resolve URL source (sitemap, top pages).
 */
function pcm_ajax_scenario_scan_resolve_urls(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	$source = filter_input( INPUT_POST, 'source', FILTER_UNSAFE_RAW );
	$source = is_string( $source ) ? sanitize_key( wp_unslash( $source ) ) : '';

	$urls = match ( $source ) {
		'sitemap'   => pcm_scenario_urls_from_sitemap( PCM_SCENARIO_MAX_URLS ),
		'top_pages' => pcm_scenario_urls_top_pages( PCM_SCENARIO_MAX_URLS ),
		default     => array(),
	};

	wp_send_json_success(
		array(
			'urls'   => $urls,
			'source' => $source,
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_resolve_urls', 'pcm_ajax_scenario_scan_resolve_urls' );

/**
 * AJAX: Start a scenario scan — validates inputs, stores the queue, returns a scan token.
 */
function pcm_ajax_scenario_scan_run(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	if ( ! pcm_cacheability_advisor_is_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'Cacheability Advisor is disabled.', 'pressable_cache_management' ) ), 400 );
	}

	// Parse URLs.
	$raw_urls  = filter_input( INPUT_POST, 'urls', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
	$raw_urls  = is_array( $raw_urls )
		? array_map(
			static function ( $raw_url ) {
				return wp_unslash( (string) $raw_url );
			},
			$raw_urls
		)
		: array();
	$urls      = array();
	$site_host = wp_parse_url( get_site_url(), PHP_URL_HOST );

	foreach ( $raw_urls as $raw ) {
		$url      = esc_url_raw( (string) $raw );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $url && $url_host && strcasecmp( $site_host, $url_host ) === 0 ) {
			$urls[] = $url;
		}
	}

	$urls = array_slice( array_unique( $urls ), 0, PCM_SCENARIO_MAX_URLS );

	if ( empty( $urls ) ) {
		wp_send_json_error( array( 'message' => __( 'No valid URLs provided. URLs must belong to this site.', 'pressable_cache_management' ) ) );
	}

	// Parse variant config.
	$raw_variants   = filter_input( INPUT_POST, 'variants', FILTER_UNSAFE_RAW );
	$variant_config = is_string( $raw_variants ) ? json_decode( wp_unslash( $raw_variants ), true ) : array();
	if ( ! is_array( $variant_config ) ) {
		$variant_config = array();
	}

	$variants = pcm_scenario_build_variants( $variant_config );

	// Cap total probes.
	$max_total = 60;
	$total     = count( $urls ) * count( $variants );
	if ( $total > $max_total ) {
		$allowed_variants = max( 1, (int) floor( $max_total / count( $urls ) ) );
		$variants         = array_slice( $variants, 0, $allowed_variants );
	}

	// Store only the URL queue and variant config in the transient — results
	// are returned per-step in the AJAX response so we avoid rewriting a
	// growing blob to object-cache on every iteration.
	$scan_token = 'pcm_scenario_' . wp_generate_uuid4();
	set_transient(
		$scan_token,
		array(
			'urls'     => $urls,
			'variants' => $variants,
			'total'    => count( $urls ),
		),
		HOUR_IN_SECONDS
	);

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log(
			'scenario_scan_run',
			'diagnostics',
			array(
				'url_count'     => count( $urls ),
				'variant_count' => count( $variants ),
			)
		);
	}

	wp_send_json_success(
		array(
			'scan_token'    => $scan_token,
			'total'         => count( $urls ),
			'variant_count' => count( $variants ),
			'variant_ids'   => array_map(
				function ( $v ) {
					return array(
						'id'    => $v['id'],
						'label' => $v['label'],
					); },
				$variants
			),
		)
	);
}
add_action( 'wp_ajax_pcm_scenario_scan_run', 'pcm_ajax_scenario_scan_run' );

/**
 * AJAX: Process the next URL in a scenario scan queue.
 */
function pcm_ajax_scenario_scan_next(): void {
	pcm_verify_ajax_request( 'nonce', 'pcm_cacheability_scan' );

	$scan_token = filter_input( INPUT_POST, 'scan_token', FILTER_UNSAFE_RAW );
	$scan_token = is_string( $scan_token ) ? sanitize_text_field( wp_unslash( $scan_token ) ) : '';
	if ( '' === $scan_token ) {
		wp_send_json_error( array( 'message' => 'Missing scan_token.' ), 400 );
	}

	$state = get_transient( $scan_token );
	if ( ! is_array( $state ) || empty( $state['urls'] ) ) {
		wp_send_json_error( array( 'message' => 'Scan queue not found or already completed.' ), 404 );
	}

	$url      = array_shift( $state['urls'] );
	$variants = $state['variants'];

	$url_results = array();
	foreach ( $variants as $variant ) {
		$url_results[] = pcm_scenario_probe_variant( $url, $variant );
	}

	$step_result = array(
		'url'      => $url,
		'variants' => $url_results,
	);

	$done      = empty( $state['urls'] );
	$remaining = count( $state['urls'] );

	if ( $done ) {
		// Clean up — results are accumulated client-side, not stored here.
		delete_transient( $scan_token );

		wp_send_json_success(
			array(
				'done'          => true,
				'remaining'     => 0,
				'scanned_at'    => gmdate( 'j M Y, g:ia' ) . ' UTC',
				'url_count'     => $state['total'],
				'variant_count' => count( $variants ),
				'variant_ids'   => array_map(
					function ( $v ) {
						return array(
							'id'    => $v['id'],
							'label' => $v['label'],
						); },
					$variants
				),
				'result'        => $step_result,
			)
		);
	} else {
		// Only store the remaining URL queue — no accumulated results.
		set_transient( $scan_token, $state, HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'done'      => false,
				'remaining' => $remaining,
				'result'    => $step_result,
			)
		);
	}
}
add_action( 'wp_ajax_pcm_scenario_scan_next', 'pcm_ajax_scenario_scan_next' );
