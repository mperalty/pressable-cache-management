<?php
/**
 * Lightweight popular URL tracker for Cacheability Advisor sampling.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION' ) ) {
	define( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION', '1.4.0' );
}

if ( ! function_exists( 'pcm_popular_url_hits_table_name' ) ) {
	/**
	 * Get the popular URL hit tracker table name.
	 *
	 * @return string
	 */
	function pcm_popular_url_hits_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'pcm_popular_url_hits';
	}
}

/**
 * Determine whether popular URL tracking should run.
 *
 * @return bool
 */
function pcm_popular_url_tracker_is_enabled(): bool {
	if ( function_exists( 'pcm_cacheability_advisor_is_enabled' ) ) {
		return pcm_cacheability_advisor_is_enabled();
	}

	$enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );

	return (bool) apply_filters( 'pcm_enable_cacheability_advisor', $enabled );
}

/**
 * Check whether the popular URL tracker table is available.
 *
 * @return bool
 */
function pcm_popular_url_tracker_schema_ready(): bool {
	static $ready = null;

	if ( null !== $ready ) {
		return $ready;
	}

	$cached = get_transient( 'pcm_popular_url_tracker_schema_ready' );
	if ( '1' === $cached ) {
		$ready = true;
		return true;
	}
	if ( '0' === $cached ) {
		$ready = false;
		return false;
	}

	global $wpdb;

	$table = pcm_popular_url_hits_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW TABLES LIKE is used only for plugin-owned schema verification.
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$ready  = ! empty( $exists );

	set_transient( 'pcm_popular_url_tracker_schema_ready', $ready ? '1' : '0', $ready ? DAY_IN_SECONDS : HOUR_IN_SECONDS );

	return $ready;
}

/**
 * Record a popular URL view for a singular front-end hit.
 *
 * @param int    $post_id Post ID.
 * @param string $url Canonical URL.
 *
 * @return void
 */
function pcm_popular_url_tracker_record_view( int $post_id, string $url ): void {
	global $wpdb;

	if ( $post_id <= 0 || ! pcm_popular_url_tracker_is_enabled() || ! pcm_popular_url_tracker_schema_ready() ) {
		return;
	}

	$safe_url = esc_url_raw( $url );
	if ( '' === $safe_url ) {
		return;
	}

	$table        = pcm_popular_url_hits_table_name();
	$hit_date     = current_time( 'Y-m-d', true );
	$last_seen_at = current_time( 'mysql', true );
	$url_hash     = md5( $safe_url );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			"INSERT INTO {$table} (post_id, url, url_hash, hit_date, view_count, last_seen_at, created_at)
			VALUES (%d, %s, %s, %s, 1, %s, %s)
			ON DUPLICATE KEY UPDATE
				url = VALUES(url),
				url_hash = VALUES(url_hash),
				view_count = view_count + 1,
				last_seen_at = VALUES(last_seen_at)",
			$post_id,
			$safe_url,
			$url_hash,
			$hit_date,
			$last_seen_at,
			$last_seen_at
		)
	);
}

/**
 * Get top tracked URLs for the given rolling time window.
 *
 * @param int $limit Maximum rows.
 * @param int $days Rolling day window.
 *
 * @return array<int, array{url:string, post_id:int, view_count:int}>
 */
function pcm_popular_url_tracker_get_top_urls( int $limit = 10, int $days = 30 ): array {
	global $wpdb;

	if ( ! pcm_popular_url_tracker_schema_ready() ) {
		return array();
	}

	$table       = pcm_popular_url_hits_table_name();
	$posts_table = $wpdb->posts;
	$limit       = max( 1, min( 100, $limit ) );
	$days        = max( 1, min( 90, $days ) );
	$cutoff_date = gmdate( 'Y-m-d', strtotime( sprintf( '-%d days', $days ) ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix and the trusted core posts table cannot use placeholders.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT hits.post_id, MAX(hits.url) AS url, SUM(hits.view_count) AS view_count
			FROM {$table} AS hits
			INNER JOIN {$posts_table} AS posts ON posts.ID = hits.post_id
			WHERE hits.hit_date >= %s
				AND posts.post_status = 'publish'
			GROUP BY hits.post_id
			ORDER BY view_count DESC, MAX(hits.last_seen_at) DESC
			LIMIT %d",
			$cutoff_date,
			$limit
		),
		ARRAY_A
	);
	// phpcs:enable

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$results = array();
	foreach ( $rows as $row ) {
		$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			continue;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			continue;
		}

		$url         = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
		$current_url = get_permalink( $post_id );
		if ( $current_url ) {
			$url = esc_url_raw( $current_url );
		}

		if ( '' === $url ) {
			continue;
		}

		$results[] = array(
			'url'        => $url,
			'post_id'    => $post_id,
			'view_count' => isset( $row['view_count'] ) ? absint( $row['view_count'] ) : 0,
		);
	}

	return $results;
}

/**
 * Remove stale popular URL tracker rows.
 *
 * @param int $days Retention window in days.
 *
 * @return void
 */
function pcm_popular_url_tracker_cleanup( int $days = 90 ): void {
	global $wpdb;

	if ( ! pcm_popular_url_tracker_schema_ready() ) {
		return;
	}

	$table       = pcm_popular_url_hits_table_name();
	$days        = max( 1, min( 365, $days ) );
	$cutoff_date = gmdate( 'Y-m-d', strtotime( sprintf( '-%d days', $days ) ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			"DELETE FROM {$table} WHERE hit_date < %s",
			$cutoff_date
		)
	);
}

/**
 * Track anonymous singular GET requests.
 *
 * @return void
 */
function pcm_popular_url_tracker_handle_template_redirect(): void {
	if ( is_admin() || wp_doing_ajax() || is_user_logged_in() || ! is_singular() || is_preview() || post_password_required() ) {
		return;
	}

	$request_method = filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW );
	if ( ! is_string( $request_method ) || 'GET' !== strtoupper( $request_method ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( $post_id <= 0 ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
		return;
	}

	$url = get_permalink( $post_id );
	if ( ! $url ) {
		return;
	}

	pcm_popular_url_tracker_record_view( $post_id, (string) $url );
}
add_action( 'template_redirect', 'pcm_popular_url_tracker_handle_template_redirect', 20 );
