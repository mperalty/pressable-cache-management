<?php
/**
 * Durable Origin Microcache.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function pcm_durable_origin_microcache_is_enabled(): bool {
    static $cached = null;
    if ( $cached === null ) {
        $enabled = (bool) get_option( PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value, false );
        $cached  = (bool) apply_filters( 'pcm_enable_durable_origin_microcache', $enabled );
    }

    return $cached;
}

function pcm_microcache_artifact_dir(): string {
    $uploads = wp_upload_dir();
    $base    = trailingslashit( $uploads['basedir'] ) . 'pcm-microcache';

    if ( ! wp_mkdir_p( $base ) ) {
        return '';
    }

    return $base;
}

function pcm_microcache_index_option_key(): string {
    return 'pcm_microcache_index_v1';
}

function pcm_microcache_stats_option_key(): string {
    return 'pcm_microcache_stats_v1';
}

function pcm_microcache_events_option_key(): string {
    return 'pcm_microcache_invalidation_events_v1';
}

interface PCM_Microcache_Backend {
    public function get( string $key ): ?array;
    public function set( string $key, mixed $payload, int $ttl, array $tags, string $builder_name ): bool;
    public function invalidate_by_tags( array $tags ): int;
    public function invalidate_key( string $key ): bool;
    public function flush_all(): int;
    public function list_recent_events( int $limit ): array;
}

class PCM_Microcache_Filesystem_Backend implements PCM_Microcache_Backend {
    protected readonly string $index_key;

    private ?array $index_cache = null;

    public function __construct() {
        $this->index_key = pcm_microcache_index_option_key();
    }

    public function get( string $key ): ?array {
        $index = $this->get_index();
        if ( empty( $index[ $key ] ) || ! is_array( $index[ $key ] ) ) {
            return null;
        }

        $row = $index[ $key ];
        if ( empty( $row['artifact_path'] ) || ! file_exists( $row['artifact_path'] ) ) {
            unset( $index[ $key ] );
            $this->save_index( $index );
            return null;
        }

        $raw = file_get_contents( $row['artifact_path'] );
        if ( false === $raw ) {
            return null;
        }

        $row['payload'] = $this->decode_payload( $raw, $row['content_type'] ?? 'json' );

        return $row;
    }

    public function set( string $key, mixed $payload, int $ttl, array $tags, string $builder_name ): bool {
        $dir = pcm_microcache_artifact_dir();
        if ( '' === $dir ) {
            return false;
        }

        $now         = time();
        $content     = is_string( $payload ) ? 'html' : 'json';
        $version     = gmdate( 'YmdHis', $now ) . '-' . wp_generate_password( 6, false, false );
        $artifact    = trailingslashit( $dir ) . sanitize_file_name( $key . '-' . $version . '.' . $content );
        $serialized  = 'html' === $content ? (string) $payload : wp_json_encode( $payload );

        if ( false === $serialized ) {
            error_log( 'PCM Microcache: Failed to serialize payload for key ' . $key );
            return false;
        }

        if ( false === file_put_contents( $artifact, $serialized ) ) {
            error_log( 'PCM Microcache: Failed to write artifact file ' . $artifact );
            return false;
        }

        $index = $this->get_index();
        $old   = isset( $index[ $key ]['artifact_path'] ) ? (string) $index[ $key ]['artifact_path'] : '';

        $index[ $key ] = array(
            'key'           => $key,
            'version'       => $version,
            'expires_at'    => $now + max( 5, absint( $ttl ) ),
            'artifact_path' => $artifact,
            'tags'          => pcm_microcache_normalize_tags( $tags ),
            'content_type'  => $content,
            'builder'       => sanitize_text_field( $builder_name ),
            'updated_at'    => current_time( 'mysql', true ),
        );

        $this->save_index( $index );

        if ( $old && $old !== $artifact && file_exists( $old ) ) {
            @unlink( $old );
        }

        return true;
    }

    public function invalidate_by_tags( array $tags ): int {
        $tags = pcm_microcache_normalize_tags( $tags );
        if ( empty( $tags ) ) {
            return 0;
        }

        $index   = $this->get_index();
        $removed = 0;

        foreach ( $index as $key => $row ) {
            $row_tags = isset( $row['tags'] ) ? (array) $row['tags'] : array();
            if ( empty( array_intersect( $tags, $row_tags ) ) ) {
                continue;
            }

            if ( ! empty( $row['artifact_path'] ) && file_exists( $row['artifact_path'] ) ) {
                @unlink( $row['artifact_path'] );
            }
            unset( $index[ $key ] );
            $removed++;
        }

        $this->save_index( $index );

        return $removed;
    }

    public function invalidate_key( string $key ): bool {
        $index = $this->get_index();
        if ( empty( $index[ $key ] ) ) {
            return false;
        }

        if ( ! empty( $index[ $key ]['artifact_path'] ) && file_exists( $index[ $key ]['artifact_path'] ) ) {
            @unlink( $index[ $key ]['artifact_path'] );
        }

        unset( $index[ $key ] );
        $this->save_index( $index );

        return true;
    }

    public function flush_all(): int {
        $index = $this->get_index();
        foreach ( $index as $row ) {
            if ( ! empty( $row['artifact_path'] ) && file_exists( $row['artifact_path'] ) ) {
                @unlink( $row['artifact_path'] );
            }
        }

        $this->save_index( array() );

        return count( $index );
    }

    public function list_recent_events( int $limit ): array {
        $rows = get_option( pcm_microcache_events_option_key(), array() );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_slice( array_reverse( $rows ), 0, max( 1, absint( $limit ) ) );
    }

    protected function get_index(): array {
        if ( null !== $this->index_cache ) {
            return $this->index_cache;
        }

        $index              = get_option( $this->index_key, array() );
        $this->index_cache  = is_array( $index ) ? $index : array();

        return $this->index_cache;
    }

    protected function save_index( array $index ): void {
        $this->save_index( $index );
        $this->index_cache = $index;
    }

    protected function decode_payload( string $raw, string $content_type ): string|array {
        if ( 'html' === $content_type ) {
            return $raw;
        }

        $decoded = json_decode( $raw, true );

        return ( null === $decoded ) ? $raw : $decoded;
    }
}

class PCM_Microcache_TableIndex_Backend extends PCM_Microcache_Filesystem_Backend {
    protected readonly string $table_name;

    public function __construct() {
        parent::__construct();
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'pcm_microcache_index';
    }

    public function get( string $key ): ?array {
        $row = parent::get( $key );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return $row;
    }

    public function set( string $key, mixed $payload, int $ttl, array $tags, string $builder_name ): bool {
        $saved = parent::set( $key, $payload, $ttl, $tags, $builder_name );
        if ( ! $saved ) {
            return false;
        }

        $index = get_option( pcm_microcache_index_option_key(), array() );
        if ( ! is_array( $index ) || empty( $index[ $key ] ) ) {
            return true;
        }

        $meta = $index[ $key ];

        global $wpdb;
        $result = $wpdb->replace(
            $this->table_name,
            array(
                'cache_key'     => $key,
                'version'       => $meta['version'] ?? '',
                'expires_at'    => isset( $meta['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $meta['expires_at'] ) ) : gmdate( 'Y-m-d H:i:s' ),
                'artifact_path' => $meta['artifact_path'] ?? '',
                'tags'          => wp_json_encode( isset( $meta['tags'] ) ? (array) $meta['tags'] : array() ),
                'updated_at'    => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            error_log( 'PCM Microcache: DB replace failed for key ' . $key . ': ' . $wpdb->last_error );
        }

        return true;
    }

    public function invalidate_by_tags( array $tags ): int {
        $removed = parent::invalidate_by_tags( $tags );

        $tags = pcm_microcache_normalize_tags( $tags );
        if ( empty( $tags ) ) {
            return $removed;
        }

        global $wpdb;
        foreach ( $tags as $tag ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE tags LIKE %s", '%' . $wpdb->esc_like( '"' . $tag . '"' ) . '%' ) );
        }

        return $removed;
    }

    public function invalidate_key( string $key ): bool {
        $removed = parent::invalidate_key( $key );

        global $wpdb;
        $wpdb->delete( $this->table_name, array( 'cache_key' => $key ), array( '%s' ) );

        return $removed;
    }

    public function flush_all(): int {
        $removed = parent::flush_all();

        global $wpdb;
        $result = $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
        if ( false === $result ) {
            error_log( 'PCM Microcache: TRUNCATE TABLE failed: ' . $wpdb->last_error );
        }

        return $removed;
    }
}

function pcm_microcache_get_backend(): PCM_Microcache_Backend {
    static $backend = null;

    if ( null !== $backend ) {
        return $backend;
    }

    $backend = get_option( PCM_Options::MICROCACHE_USE_CUSTOM_TABLE_INDEX->value, false )
        ? new PCM_Microcache_TableIndex_Backend()
        : new PCM_Microcache_Filesystem_Backend();

    return $backend;
}

function pcm_microcache_normalize_tags( array|string $tags ): array {
    return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $tags ) ) ) );
}

function pcm_microcache_request_allowed( string $key, array $tags ): true|\WP_Error {
    $allowed_personalized = (bool) apply_filters( 'pcm_microcache_allow_personalized_request', false, $key, $tags );

    if ( is_user_logged_in() && ! $allowed_personalized ) {
        return new WP_Error( 'pcm_microcache_not_anonymous', 'Microcache rejected: logged-in request context.' );
    }

    if ( ! empty( $_COOKIE ) && ! $allowed_personalized ) {
        $safe_prefixes = (array) apply_filters( 'pcm_microcache_safe_cookies', array( 'wp-settings-time-', 'wp_lang' ) );

        foreach ( array_keys( (array) $_COOKIE ) as $cookie_name ) {
            $cookie_name = (string) $cookie_name;
            $is_safe     = false;

            foreach ( $safe_prefixes as $prefix ) {
                if ( str_starts_with( $cookie_name, (string) $prefix ) ) {
                    $is_safe = true;
                    break;
                }
            }

            if ( ! $is_safe ) {
                return new WP_Error( 'pcm_microcache_cookie_variant', 'Microcache rejected: cookie-variant request context.' );
            }
        }
    }

    return true;
}

function pcm_microcache_resolve_builder_name( callable|string|array $builder ): string {
    if ( is_string( $builder ) ) {
        return $builder;
    }

    if ( is_array( $builder ) ) {
        $lhs = is_object( $builder[0] ) ? get_class( $builder[0] ) : (string) $builder[0];
        return $lhs . '::' . (string) $builder[1];
    }

    if ( $builder instanceof Closure ) {
        return 'closure_builder';
    }

    return 'callable_builder';
}

function pcm_microcache_get_or_build( string $key, callable $builder, int $ttl, array $tags = array() ): mixed {
    if ( ! is_callable( $builder ) ) {
        return new WP_Error( 'pcm_microcache_builder_invalid', 'Microcache builder must be callable.' );
    }

    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return call_user_func( $builder );
    }

    $key  = sanitize_key( $key );
    $ttl  = max( 5, absint( $ttl ) );
    $tags = pcm_microcache_normalize_tags( $tags );

    $guard = pcm_microcache_request_allowed( $key, $tags );
    if ( is_wp_error( $guard ) ) {
        return $guard;
    }

    $backend = pcm_microcache_get_backend();
    $entry   = $backend->get( $key );
    $now     = time();

    if ( is_array( $entry ) && isset( $entry['expires_at'], $entry['payload'] ) ) {
        if ( absint( $entry['expires_at'] ) >= $now ) {
            pcm_microcache_record_stat( 'hit', $key, 0, $entry['builder'] ?? 'unknown' );
            return $entry['payload'];
        }

        $swr_window = (int) apply_filters( 'pcm_microcache_swr_window_seconds', min( $ttl, 120 ), $key, $ttl, $tags );
        if ( absint( $entry['expires_at'] ) + max( 1, $swr_window ) >= $now ) {
            pcm_microcache_record_stat( 'stale', $key, 0, $entry['builder'] ?? 'unknown' );
            pcm_microcache_schedule_async_rebuild( $key, $builder, $ttl, $tags );
            return $entry['payload'];
        }
    }

    return pcm_microcache_build_and_store( $key, $builder, $ttl, $tags, ( is_array( $entry ) ? 'rebuild' : 'miss' ) );
}

function pcm_microcache_build_and_store( string $key, callable $builder, int $ttl, array $tags, string $event = 'miss' ): mixed {
    $backend      = pcm_microcache_get_backend();
    $builder_name = pcm_microcache_resolve_builder_name( $builder );
    $start        = microtime( true );
    $payload      = call_user_func( $builder );
    $build_ms     = (float) round( ( microtime( true ) - $start ) * 1000, 2 );

    if ( is_wp_error( $payload ) ) {
        pcm_microcache_record_stat( 'build_error', $key, $build_ms, $builder_name );
        return $payload;
    }

    $backend->set( $key, $payload, $ttl, $tags, $builder_name );
    pcm_microcache_record_stat( $event, $key, $build_ms, $builder_name );

    return $payload;
}

function pcm_microcache_schedule_async_rebuild( string $key, callable $builder, int $ttl, array $tags ): void {
    $lock_key = 'pcm_microcache_rebuild_' . md5( $key );
    if ( get_transient( $lock_key ) ) {
        return;
    }

    set_transient( $lock_key, 1, 30 );

    add_action(
        'shutdown',
        static function () use ( $key, $builder, $ttl, $tags, $lock_key ): void {
            pcm_microcache_build_and_store( $key, $builder, $ttl, $tags, 'rebuild' );
            delete_transient( $lock_key );
        },
        999
    );
}

function pcm_microcache_record_stat( string $event, string $key, float $build_ms, string $builder_name ): void {
    $stats = get_option( pcm_microcache_stats_option_key(), array() );
    $stats = is_array( $stats ) ? $stats : array();
    $stats = array_merge(
        array(
            'hits'                   => 0,
            'misses'                 => 0,
            'rebuilds'               => 0,
            'stale_while_revalidate' => 0,
            'build_errors'           => 0,
            'total_build_ms'         => 0,
            'builder_costs'          => array(),
            'last_key'               => '',
            'updated_at'             => '',
        ),
        $stats
    );

    if ( 'hit' === $event ) {
        $stats['hits']++;
    } elseif ( 'miss' === $event ) {
        $stats['misses']++;
    } elseif ( 'rebuild' === $event ) {
        $stats['rebuilds']++;
    } elseif ( 'stale' === $event ) {
        $stats['stale_while_revalidate']++;
    } elseif ( 'build_error' === $event ) {
        $stats['build_errors']++;
    }

    $stats['total_build_ms'] += $build_ms;
    $stats['last_key']        = $key;
    $stats['updated_at']      = current_time( 'mysql', true );

    if ( ! isset( $stats['builder_costs'][ $builder_name ] ) ) {
        $stats['builder_costs'][ $builder_name ] = array(
            'calls'    => 0,
            'total_ms' => 0,
        );
    }

    $stats['builder_costs'][ $builder_name ]['calls']++;
    $stats['builder_costs'][ $builder_name ]['total_ms'] += $build_ms;

    update_option( pcm_microcache_stats_option_key(), $stats, false );
}

function pcm_microcache_log_invalidation_event( string $reason, array $tags, int $removed ): void {
    $rows   = get_option( pcm_microcache_events_option_key(), array() );
    $rows   = is_array( $rows ) ? $rows : array();
    $rows[] = array(
        'reason'    => sanitize_key( $reason ),
        'tags'      => pcm_microcache_normalize_tags( $tags ),
        'removed'   => absint( $removed ),
        'timestamp' => current_time( 'mysql', true ),
    );

    update_option( pcm_microcache_events_option_key(), array_slice( $rows, -200 ), false );
}

function pcm_microcache_invalidate_tags( array $tags, string $reason = 'unknown' ): int {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return 0;
    }

    $removed = pcm_microcache_get_backend()->invalidate_by_tags( $tags );
    pcm_microcache_log_invalidation_event( $reason, $tags, $removed );

    return $removed;
}

function pcm_microcache_flush_all( string $reason = 'manual_flush' ): int {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return 0;
    }

    $removed = pcm_microcache_get_backend()->flush_all();
    pcm_microcache_log_invalidation_event( $reason, array( 'all' ), $removed );

    return $removed;
}

function pcm_microcache_get_menu_tree_payload( string $menu_location, int $ttl = 300 ): mixed {
    $location = sanitize_key( $menu_location );

    return pcm_microcache_get_or_build(
        'menu_tree_' . $location,
        static function () use ( $location ): array {
            $locations = get_nav_menu_locations();
            $menu_id   = isset( $locations[ $location ] ) ? absint( $locations[ $location ] ) : 0;
            if ( $menu_id <= 0 ) {
                return array();
            }

            $items = wp_get_nav_menu_items( $menu_id );
            if ( ! is_array( $items ) ) {
                return array();
            }

            return array_map(
                static function ( object $item ): array {
                    return array(
                        'id'        => isset( $item->ID ) ? (int) $item->ID : 0,
                        'title'     => isset( $item->title ) ? wp_strip_all_tags( $item->title ) : '',
                        'url'       => isset( $item->url ) ? esc_url_raw( $item->url ) : '',
                        'parent_id' => isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0,
                    );
                },
                $items
            );
        },
        $ttl,
        array( 'menu', 'menu_location_' . $location )
    );
}

function pcm_microcache_get_archive_cards( array $query_args = array(), int $ttl = 120 ): mixed {
    $args = wp_parse_args(
        $query_args,
        array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 12,
        )
    );

    return pcm_microcache_get_or_build(
        'archive_cards_' . md5( wp_json_encode( $args ) ),
        static function () use ( $args ): array {
            $query = new WP_Query( $args );
            if ( ! $query->have_posts() ) {
                return array();
            }

            $cards = array();
            foreach ( (array) $query->posts as $post ) {
                // Use raw excerpt or trimmed content instead of get_the_excerpt()
                // to avoid triggering the_content filters that may rely on
                // globals (e.g. WooCommerce $product) unavailable during pre-warming.
                $excerpt = $post->post_excerpt
                    ? wp_trim_words( $post->post_excerpt, 55, '&hellip;' )
                    : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55, '&hellip;' );

                $cards[] = array(
                    'id'        => (int) $post->ID,
                    'title'     => get_the_title( $post ),
                    'permalink' => get_permalink( $post ),
                    'excerpt'   => $excerpt,
                    'date'      => get_post_time( 'c', true, $post ),
                );
            }

            return $cards;
        },
        $ttl,
        array( 'archive_cards', 'post_type_' . sanitize_key( $args['post_type'] ) )
    );
}

function pcm_microcache_get_adjacent_links_metadata( int $post_id, int $ttl = 180 ): mixed {
    $post_id = absint( $post_id );

    return pcm_microcache_get_or_build(
        'adjacent_links_' . $post_id,
        static function () use ( $post_id ): array {
            $post = get_post( $post_id );
            if ( ! $post || 'publish' !== $post->post_status ) {
                return array();
            }

            $previous = get_previous_post();
            $next     = get_next_post();

            return array(
                'post_id' => $post_id,
                'prev'    => $previous ? array( 'id' => (int) $previous->ID, 'url' => get_permalink( $previous ) ) : null,
                'next'    => $next ? array( 'id' => (int) $next->ID, 'url' => get_permalink( $next ) ) : null,
            );
        },
        $ttl,
        array( 'adjacent_links', 'post_' . $post_id )
    );
}

// ─── WooCommerce Builders ─────────────────────────────────────────────────────

/**
 * Cache related posts for a given post.
 *
 * Returns an array of related post cards based on shared categories/tags.
 * Falls back gracefully when no related posts exist.
 *
 * @param int $post_id The post to find related content for.
 * @param int $limit   Maximum related posts to return. Default 4.
 * @param int $ttl     Cache lifetime in seconds. Default 300 (5 min).
 */
function pcm_microcache_get_related_posts( int $post_id, int $limit = 4, int $ttl = 300 ): mixed {
    $post_id = absint( $post_id );
    $limit   = max( 1, min( 20, $limit ) );

    return pcm_microcache_get_or_build(
        'related_posts_' . $post_id . '_' . $limit,
        static function () use ( $post_id, $limit ): array {
            $post = get_post( $post_id );
            if ( ! $post || 'publish' !== $post->post_status ) {
                return array();
            }

            // Collect category and tag IDs for the current post
            $cat_ids = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
            $tag_ids = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

            if ( empty( $cat_ids ) && empty( $tag_ids ) ) {
                return array();
            }

            $tax_query = array( 'relation' => 'OR' );
            if ( ! empty( $cat_ids ) ) {
                $tax_query[] = array(
                    'taxonomy' => 'category',
                    'field'    => 'term_id',
                    'terms'    => $cat_ids,
                );
            }
            if ( ! empty( $tag_ids ) ) {
                $tax_query[] = array(
                    'taxonomy' => 'post_tag',
                    'field'    => 'term_id',
                    'terms'    => $tag_ids,
                );
            }

            $query = new WP_Query( array(
                'post_type'      => $post->post_type,
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'post__not_in'   => array( $post_id ),
                'tax_query'      => $tax_query,
                'no_found_rows'  => true,
            ) );

            if ( ! $query->have_posts() ) {
                return array();
            }

            $cards = array();
            foreach ( (array) $query->posts as $related ) {
                $cards[] = array(
                    'id'        => (int) $related->ID,
                    'title'     => get_the_title( $related ),
                    'permalink' => get_permalink( $related ),
                    'excerpt'   => get_the_excerpt( $related ),
                    'date'      => get_post_time( 'c', true, $related ),
                    'thumbnail' => get_the_post_thumbnail_url( $related, 'medium' ) ?: '',
                );
            }

            return $cards;
        },
        $ttl,
        array( 'related_posts', 'post_' . $post_id, 'post_type_' . get_post_type( $post_id ) )
    );
}

/**
 * Cache WooCommerce related products for a given product.
 *
 * Leverages WooCommerce's own related product algorithm (shared categories/tags,
 * upsells, cross-sells) and caches the result as lightweight product cards.
 *
 * @param int $product_id The product to find related products for.
 * @param int $limit      Maximum related products to return. Default 4.
 * @param int $ttl        Cache lifetime in seconds. Default 300 (5 min).
 */
function pcm_microcache_get_related_products( int $product_id, int $limit = 4, int $ttl = 300 ): mixed {
    $product_id = absint( $product_id );
    $limit      = max( 1, min( 20, $limit ) );

    return pcm_microcache_get_or_build(
        'related_products_' . $product_id . '_' . $limit,
        static function () use ( $product_id, $limit ): array {
            if ( ! function_exists( 'wc_get_product' ) ) {
                return array();
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
                return array();
            }

            // WooCommerce's related product IDs (respects upsells/cross-sells + shared cats/tags)
            $related_ids = wc_get_related_products( $product_id, $limit );
            if ( empty( $related_ids ) ) {
                return array();
            }

            $cards = array();
            foreach ( $related_ids as $rid ) {
                $related = wc_get_product( $rid );
                if ( ! $related ) {
                    continue;
                }

                $cards[] = array(
                    'id'            => (int) $rid,
                    'title'         => $related->get_name(),
                    'permalink'     => get_permalink( $rid ),
                    'price_html'    => wp_strip_all_tags( $related->get_price_html() ),
                    'regular_price' => $related->get_regular_price(),
                    'sale_price'    => $related->get_sale_price(),
                    'on_sale'       => $related->is_on_sale(),
                    'in_stock'      => $related->is_in_stock(),
                    'thumbnail'     => get_the_post_thumbnail_url( $rid, 'woocommerce_thumbnail' ) ?: '',
                    'average_rating'=> (float) $related->get_average_rating(),
                );
            }

            return $cards;
        },
        $ttl,
        array( 'related_products', 'woo_product', 'product_' . $product_id )
    );
}

/**
 * Cache WooCommerce product image gallery for a given product.
 *
 * Returns the featured image plus all gallery images with multiple sizes
 * pre-resolved, avoiding repeated attachment queries on every page load.
 *
 * @param int $product_id The product whose gallery to cache.
 * @param int $ttl        Cache lifetime in seconds. Default 600 (10 min).
 */
function pcm_microcache_get_product_gallery( int $product_id, int $ttl = 600 ): mixed {
    $product_id = absint( $product_id );

    return pcm_microcache_get_or_build(
        'product_gallery_' . $product_id,
        static function () use ( $product_id ): array {
            if ( ! function_exists( 'wc_get_product' ) ) {
                return array();
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
                return array();
            }

            $images     = array();
            $featured   = $product->get_image_id();
            $gallery    = $product->get_gallery_image_ids();

            // Build ordered list: featured image first, then gallery
            $all_ids = array();
            if ( $featured ) {
                $all_ids[] = absint( $featured );
            }
            foreach ( $gallery as $gid ) {
                $gid = absint( $gid );
                if ( $gid > 0 && ! in_array( $gid, $all_ids, true ) ) {
                    $all_ids[] = $gid;
                }
            }

            if ( empty( $all_ids ) ) {
                return array();
            }

            foreach ( $all_ids as $index => $attachment_id ) {
                $full   = wp_get_attachment_image_url( $attachment_id, 'full' );
                $large  = wp_get_attachment_image_url( $attachment_id, 'woocommerce_single' );
                $thumb  = wp_get_attachment_image_url( $attachment_id, 'woocommerce_thumbnail' );
                $alt    = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

                if ( ! $full ) {
                    continue;
                }

                $images[] = array(
                    'id'          => $attachment_id,
                    'is_featured' => ( $index === 0 && $attachment_id === $featured ),
                    'full'        => esc_url( $full ),
                    'large'       => esc_url( $large ?: $full ),
                    'thumbnail'   => esc_url( $thumb ?: $full ),
                    'alt'         => wp_strip_all_tags( (string) $alt ),
                    'srcset'      => (string) wp_get_attachment_image_srcset( $attachment_id, 'woocommerce_single' ),
                    'sizes'       => (string) wp_get_attachment_image_sizes( $attachment_id, 'woocommerce_single' ),
                );
            }

            return $images;
        },
        $ttl,
        array( 'product_gallery', 'woo_product', 'product_' . $product_id )
    );
}

// ─── WooCommerce Invalidation Hooks ───────────────────────────────────────────

/**
 * Invalidate WooCommerce product caches when a product is saved.
 *
 * Clears related products, product gallery, and related posts caches
 * for the specific product, plus the shared woo_product tag so that
 * any "related products" lists containing this product also refresh.
 */
function pcm_microcache_invalidate_woo_product( int $product_id ): void {
    $product_id = absint( $product_id );
    if ( $product_id <= 0 ) {
        return;
    }

    $post_type = get_post_type( $product_id );
    if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
        return;
    }

    // For variations, also invalidate the parent product
    $parent_id = wp_get_post_parent_id( $product_id );
    $tags      = array(
        'product_' . $product_id,
        'product_gallery',
        'related_products',
        'woo_product',
    );

    if ( $parent_id > 0 ) {
        $tags[] = 'product_' . $parent_id;
    }

    pcm_microcache_invalidate_tags( $tags, 'woo_product_save' );
}
add_action( 'woocommerce_update_product', 'pcm_microcache_invalidate_woo_product', 20, 1 );
add_action( 'woocommerce_new_product', 'pcm_microcache_invalidate_woo_product', 20, 1 );

/**
 * Invalidate product gallery cache when product images are updated.
 */
function pcm_microcache_invalidate_woo_gallery_on_meta( int $meta_id, int $post_id, string $meta_key ): void {
    if ( '_product_image_gallery' !== $meta_key && '_thumbnail_id' !== $meta_key ) {
        return;
    }

    if ( 'product' !== get_post_type( $post_id ) ) {
        return;
    }

    pcm_microcache_invalidate_tags(
        array( 'product_gallery', 'product_' . absint( $post_id ) ),
        'woo_gallery_meta_update'
    );
}
add_action( 'updated_post_meta', 'pcm_microcache_invalidate_woo_gallery_on_meta', 20, 3 );
add_action( 'added_post_meta', 'pcm_microcache_invalidate_woo_gallery_on_meta', 20, 3 );

/**
 * Invalidate related products when WooCommerce stock status changes.
 *
 * If a product goes out of stock or comes back in stock, the related
 * products cache should refresh so in_stock flags are accurate.
 */
function pcm_microcache_invalidate_woo_stock_change( \WC_Product $product ): void {
    $product_id = $product->get_id();
    if ( $product_id <= 0 ) {
        return;
    }

    pcm_microcache_invalidate_tags(
        array( 'product_' . absint( $product_id ), 'related_products', 'woo_product' ),
        'woo_stock_change'
    );
}
add_action( 'woocommerce_product_set_stock_status', 'pcm_microcache_invalidate_woo_stock_change', 20, 1 );

// ─── Automatic Pre-warming ────────────────────────────────────────────────────
// These hooks fire on template_redirect so the microcache is warm BEFORE
// WooCommerce / WordPress renders templates. On the first anonymous hit the
// builder runs and caches. On subsequent hits the cached artifact is returned
// instantly, AND we prime WordPress's internal object/metadata caches so
// WooCommerce's own template code hits warm memory instead of the database.

/**
 * Auto pre-warm product gallery + related products on WooCommerce single product pages.
 *
 * Hooks template_redirect at priority 5 (before WC templates render at 10+).
 * Only fires for anonymous visitors — logged-in users are excluded by the
 * microcache guard anyway, so we skip them to avoid wasted work.
 */
function pcm_microcache_auto_prewarm_product_page(): void {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return;
    }
    if ( is_user_logged_in() ) {
        return;
    }
    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    $product_id = get_the_ID();
    if ( ! $product_id || $product_id <= 0 ) {
        return;
    }

    // ── Product gallery: pre-warm artifact + prime attachment metadata cache ──
    $gallery = pcm_microcache_get_product_gallery( $product_id );

    if ( is_array( $gallery ) && ! empty( $gallery ) ) {
        // Batch-load all attachment post meta in one query so that
        // WooCommerce's wp_get_attachment_image() / wp_get_attachment_image_srcset()
        // calls hit warm object cache instead of N individual DB queries.
        $attachment_ids = array_column( $gallery, 'id' );
        if ( ! empty( $attachment_ids ) ) {
            update_meta_cache( 'post', $attachment_ids );
        }
    }

    // ── Related products: pre-warm artifact + prime post object caches ──
    $related = pcm_microcache_get_related_products( $product_id );

    if ( is_array( $related ) && ! empty( $related ) ) {
        // Batch-prime post + postmeta + term caches for all related products.
        // When WooCommerce renders the related-products grid, every
        // get_permalink(), get_the_title(), get_price_html() call will
        // hit warm cache instead of running individual queries.
        $related_ids = array_column( $related, 'id' );
        if ( ! empty( $related_ids ) ) {
            _prime_post_caches( $related_ids, true, true );
        }
    }
}
add_action( 'template_redirect', 'pcm_microcache_auto_prewarm_product_page', 5 );

/**
 * Auto pre-warm related posts on single blog/CPT post pages.
 *
 * Skips WooCommerce products (handled by the product-specific hook above).
 */
function pcm_microcache_auto_prewarm_single_post(): void {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return;
    }
    if ( is_user_logged_in() ) {
        return;
    }
    if ( ! is_singular() || is_singular( 'product' ) ) {
        return;
    }

    $post_id = get_the_ID();
    if ( ! $post_id || $post_id <= 0 ) {
        return;
    }

    $related = pcm_microcache_get_related_posts( $post_id );

    if ( is_array( $related ) && ! empty( $related ) ) {
        $related_ids = array_column( $related, 'id' );
        if ( ! empty( $related_ids ) ) {
            _prime_post_caches( $related_ids, true, true );
        }
    }
}
add_action( 'template_redirect', 'pcm_microcache_auto_prewarm_single_post', 5 );

/**
 * Auto pre-warm archive cards on WooCommerce shop/category pages.
 *
 * On the shop page, product category pages, and product tag pages,
 * pre-warm the archive cards cache so post metadata is already in memory
 * when WooCommerce renders the product grid loop.
 */
function pcm_microcache_auto_prewarm_woo_archive(): void {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return;
    }
    if ( is_user_logged_in() ) {
        return;
    }
    if ( ! function_exists( 'is_shop' ) ) {
        return;
    }
    if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
        return;
    }

    // Cache product archive cards using WooCommerce's default ordering
    $paged = max( 1, absint( get_query_var( 'paged', 1 ) ) );
    $cards = pcm_microcache_get_archive_cards(
        array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => absint( get_option( 'posts_per_page', 12 ) ),
            'paged'          => $paged,
        ),
        120
    );

    if ( is_array( $cards ) && ! empty( $cards ) ) {
        $product_ids = array_column( $cards, 'id' );
        if ( ! empty( $product_ids ) ) {
            _prime_post_caches( $product_ids, true, true );
        }
    }
}
add_action( 'template_redirect', 'pcm_microcache_auto_prewarm_woo_archive', 5 );

/**
 * Auto pre-warm navigation menus on every frontend page load.
 *
 * Identifies registered menu locations and warms each one. This runs
 * once per request — subsequent calls to pcm_microcache_get_menu_tree_payload()
 * from themes get instant cache hits.
 */
function pcm_microcache_auto_prewarm_menus(): void {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return;
    }
    if ( is_user_logged_in() || is_admin() ) {
        return;
    }

    $locations = get_nav_menu_locations();
    if ( empty( $locations ) ) {
        return;
    }

    foreach ( array_keys( $locations ) as $location ) {
        pcm_microcache_get_menu_tree_payload( (string) $location );
    }
}
add_action( 'template_redirect', 'pcm_microcache_auto_prewarm_menus', 5 );

function pcm_microcache_public_health_endpoint(): void {
    // Rate-limit unauthenticated requests: max 1 per 10 seconds per IP
    if ( ! is_user_logged_in() ) {
        $ip_hash     = md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $throttle_key = 'pcm_health_throttle_' . $ip_hash;
        if ( get_transient( $throttle_key ) ) {
            wp_send_json_error( array( 'message' => 'Rate limited' ), 429 );
        }
        set_transient( $throttle_key, 1, 10 );
    }

    $data = pcm_microcache_get_or_build(
        'public_health_payload',
        static function (): array {
            return array(
                'status'        => 'ok',
                'generated_at'  => gmdate( 'c' ),
                'batcache_hits' => (int) get_option( PCM_Options::BATCACHE_HITS_24H->value, 0 ),
            );
        },
        60,
        array( 'public_json', 'health' )
    );

    if ( is_wp_error( $data ) ) {
        wp_send_json_error( array( 'message' => $data->get_error_message() ), 400 );
    }

    wp_send_json_success( $data );
}
add_action( 'wp_ajax_nopriv_pcm_microcache_public_health', 'pcm_microcache_public_health_endpoint' );
add_action( 'wp_ajax_pcm_microcache_public_health', 'pcm_microcache_public_health_endpoint' );

function pcm_microcache_invalidate_post_tags( int $post_id ): void {
    $post_id = absint( $post_id );
    if ( $post_id <= 0 ) {
        return;
    }

    $post = get_post( $post_id );
    $tags = array( 'post_' . $post_id, 'archive_cards', 'adjacent_links', 'related_posts' );

    if ( $post ) {
        $post_type = sanitize_key( $post->post_type );
        $tags[]    = 'post_type_' . $post_type;

        // When a WooCommerce product is saved via core save_post, also bust product caches
        if ( 'product' === $post_type || 'product_variation' === $post_type ) {
            $tags[] = 'product_' . $post_id;
            $tags[] = 'product_gallery';
            $tags[] = 'related_products';
            $tags[] = 'woo_product';
        }
    }

    pcm_microcache_invalidate_tags( $tags, 'save_post' );
}
add_action( 'save_post', 'pcm_microcache_invalidate_post_tags', 20, 1 );

function pcm_microcache_invalidate_taxonomy_tags( int $term_id, int $tt_id = 0, string $taxonomy = '' ): void {
    $tags = array( 'taxonomy', 'archive_cards', 'term_' . absint( $term_id ) );

    if ( ! empty( $taxonomy ) ) {
        $tags[] = 'taxonomy_' . sanitize_key( $taxonomy );
    }

    pcm_microcache_invalidate_tags( $tags, 'taxonomy_change' );
}
add_action( 'created_term', 'pcm_microcache_invalidate_taxonomy_tags', 20, 3 );
add_action( 'edited_term', 'pcm_microcache_invalidate_taxonomy_tags', 20, 3 );
add_action( 'delete_term', 'pcm_microcache_invalidate_taxonomy_tags', 20, 3 );

function pcm_microcache_on_manual_purge(): void {
    pcm_microcache_flush_all( 'manual_purge' );
}
add_action( 'pcm_after_object_cache_flush', 'pcm_microcache_on_manual_purge' );
add_action( 'pcm_after_edge_cache_purge', 'pcm_microcache_on_manual_purge' );

function pcm_microcache_maybe_install_table_index(): void {
    if ( ! pcm_durable_origin_microcache_is_enabled() || ! get_option( PCM_Options::MICROCACHE_USE_CUSTOM_TABLE_INDEX->value, false ) ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pcm_microcache_index';
    $charset    = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        cache_key varchar(191) NOT NULL,
        version varchar(64) NOT NULL,
        expires_at datetime NOT NULL,
        artifact_path text NOT NULL,
        tags longtext NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (cache_key),
        KEY expires_at (expires_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'init', 'pcm_microcache_maybe_install_table_index', 20 );

function pcm_microcache_get_health_summary(): array {
    $stats = get_option( pcm_microcache_stats_option_key(), array() );
    $stats = is_array( $stats ) ? $stats : array();
    $stats = array_merge(
        array(
            'hits'                   => 0,
            'misses'                 => 0,
            'rebuilds'               => 0,
            'stale_while_revalidate' => 0,
            'build_errors'           => 0,
            'total_build_ms'         => 0,
            'builder_costs'          => array(),
            'updated_at'             => '',
        ),
        $stats
    );

    $builders = array();
    foreach ( (array) $stats['builder_costs'] as $name => $row ) {
        $calls = max( 1, isset( $row['calls'] ) ? absint( $row['calls'] ) : 0 );
        $total = isset( $row['total_ms'] ) ? (float) $row['total_ms'] : 0;

        $builders[] = array(
            'builder'  => $name,
            'calls'    => $calls,
            'total_ms' => round( $total, 2 ),
            'avg_ms'   => round( $total / $calls, 2 ),
        );
    }

    usort(
        $builders,
        static function ( array $a, array $b ): int {
            return $b['total_ms'] <=> $a['total_ms'];
        }
    );

    return array(
        'stats'         => $stats,
        'top_builders'  => array_slice( $builders, 0, 5 ),
        'recent_events' => array_slice( array_reverse( (array) get_option( pcm_microcache_events_option_key(), array() ) ), 0, 8 ),
    );
}

function pcm_microcache_render_deep_dive_card(): void {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return;
    }

    $summary = pcm_microcache_get_health_summary();
    $stats   = $summary['stats'];
    ?>
    <div class="pcm-card pcm-card-hover" id="pcm-feature-durable-origin-microcache" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title"><span class="dashicons dashicons-superhero pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Durable Origin Microcache', 'pressable_cache_management' ); ?></h3>
        <p style="margin-top:0;color:#4b5563;"><?php echo esc_html__( 'Anonymous-safe microcache hit/miss telemetry, stale-while-revalidate activity, and recent invalidation events.', 'pressable_cache_management' ); ?></p>
        <div style="display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin-bottom:12px;">
            <div><strong><?php echo esc_html__( 'Hits', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( (string) $stats['hits'] ); ?></div>
            <div><strong><?php echo esc_html__( 'Misses', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( (string) $stats['misses'] ); ?></div>
            <div><strong><?php echo esc_html__( 'Rebuilds', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( (string) $stats['rebuilds'] ); ?></div>
            <div><strong><?php echo esc_html__( 'SWR', 'pressable_cache_management' ); ?>:</strong> <?php echo esc_html( (string) $stats['stale_while_revalidate'] ); ?></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <h4 style="margin:0 0 6px;"><?php echo esc_html__( 'Top Expensive Builders', 'pressable_cache_management' ); ?></h4>
                <?php if ( empty( $summary['top_builders'] ) ) : ?>
                    <em><?php echo esc_html__( 'No builder timings captured yet.', 'pressable_cache_management' ); ?></em>
                <?php else : ?>
                    <ul style="margin:0;padding-left:18px;font-size:12px;">
                        <?php foreach ( $summary['top_builders'] as $row ) : ?>
                            <li><?php echo esc_html( $row['builder'] ); ?> — <?php echo esc_html( (string) $row['total_ms'] ); ?>ms (avg <?php echo esc_html( (string) $row['avg_ms'] ); ?>ms)</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div>
                <h4 style="margin:0 0 6px;"><?php echo esc_html__( 'Recent Invalidation Events', 'pressable_cache_management' ); ?></h4>
                <?php if ( empty( $summary['recent_events'] ) ) : ?>
                    <em><?php echo esc_html__( 'No invalidation events yet.', 'pressable_cache_management' ); ?></em>
                <?php else : ?>
                    <ul style="margin:0;padding-left:18px;font-size:12px;max-height:180px;overflow:auto;">
                        <?php foreach ( $summary['recent_events'] as $event ) : ?>
                            <li>
                                <?php echo esc_html( (string) ( $event['timestamp'] ?? '' ) ); ?>
                                — <?php echo esc_html( (string) ( $event['reason'] ?? 'unknown' ) ); ?>
                                (<?php echo esc_html( (string) ( $event['removed'] ?? '0' ) ); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
