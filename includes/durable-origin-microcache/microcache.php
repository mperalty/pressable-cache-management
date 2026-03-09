<?php
/**
 * Durable Origin Microcache.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function pcm_durable_origin_microcache_is_enabled() {
    $enabled = (bool) get_option( 'pcm_enable_durable_origin_microcache', false );

    return (bool) apply_filters( 'pcm_enable_durable_origin_microcache', $enabled );
}

function pcm_microcache_artifact_dir() {
    $uploads = wp_upload_dir();
    $base    = trailingslashit( $uploads['basedir'] ) . 'pcm-microcache';

    if ( ! wp_mkdir_p( $base ) ) {
        return '';
    }

    return $base;
}

function pcm_microcache_index_option_key() {
    return 'pcm_microcache_index_v1';
}

function pcm_microcache_stats_option_key() {
    return 'pcm_microcache_stats_v1';
}

function pcm_microcache_events_option_key() {
    return 'pcm_microcache_invalidation_events_v1';
}

interface PCM_Microcache_Backend {
    public function get( $key );
    public function set( $key, $payload, $ttl, $tags, $builder_name );
    public function invalidate_by_tags( $tags );
    public function invalidate_key( $key );
    public function flush_all();
    public function list_recent_events( $limit );
}

class PCM_Microcache_Filesystem_Backend implements PCM_Microcache_Backend {
    protected $index_key;

    public function __construct() {
        $this->index_key = pcm_microcache_index_option_key();
    }

    public function get( $key ) {
        $index = $this->get_index();
        if ( empty( $index[ $key ] ) || ! is_array( $index[ $key ] ) ) {
            return null;
        }

        $row = $index[ $key ];
        if ( empty( $row['artifact_path'] ) || ! file_exists( $row['artifact_path'] ) ) {
            unset( $index[ $key ] );
            update_option( $this->index_key, $index, false );
            return null;
        }

        $raw = file_get_contents( $row['artifact_path'] );
        if ( false === $raw ) {
            return null;
        }

        $row['payload'] = $this->decode_payload( $raw, isset( $row['content_type'] ) ? $row['content_type'] : 'json' );

        return $row;
    }

    public function set( $key, $payload, $ttl, $tags, $builder_name ) {
        $dir = pcm_microcache_artifact_dir();
        if ( '' === $dir ) {
            return false;
        }

        $now         = time();
        $content     = is_string( $payload ) ? 'html' : 'json';
        $version     = gmdate( 'YmdHis', $now ) . '-' . wp_generate_password( 6, false, false );
        $artifact    = trailingslashit( $dir ) . sanitize_file_name( $key . '-' . $version . '.' . $content );
        $serialized  = 'html' === $content ? (string) $payload : wp_json_encode( $payload );

        if ( false === $serialized || false === file_put_contents( $artifact, $serialized ) ) {
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

        update_option( $this->index_key, $index, false );

        if ( $old && $old !== $artifact && file_exists( $old ) ) {
            @unlink( $old );
        }

        return true;
    }

    public function invalidate_by_tags( $tags ) {
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

        update_option( $this->index_key, $index, false );

        return $removed;
    }

    public function invalidate_key( $key ) {
        $index = $this->get_index();
        if ( empty( $index[ $key ] ) ) {
            return false;
        }

        if ( ! empty( $index[ $key ]['artifact_path'] ) && file_exists( $index[ $key ]['artifact_path'] ) ) {
            @unlink( $index[ $key ]['artifact_path'] );
        }

        unset( $index[ $key ] );
        update_option( $this->index_key, $index, false );

        return true;
    }

    public function flush_all() {
        $index = $this->get_index();
        foreach ( $index as $row ) {
            if ( ! empty( $row['artifact_path'] ) && file_exists( $row['artifact_path'] ) ) {
                @unlink( $row['artifact_path'] );
            }
        }

        update_option( $this->index_key, array(), false );

        return count( $index );
    }

    public function list_recent_events( $limit ) {
        $rows = get_option( pcm_microcache_events_option_key(), array() );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_slice( array_reverse( $rows ), 0, max( 1, absint( $limit ) ) );
    }

    protected function get_index() {
        $index = get_option( $this->index_key, array() );

        return is_array( $index ) ? $index : array();
    }

    protected function decode_payload( $raw, $content_type ) {
        if ( 'html' === $content_type ) {
            return (string) $raw;
        }

        $decoded = json_decode( $raw, true );

        return ( null === $decoded ) ? $raw : $decoded;
    }
}

class PCM_Microcache_TableIndex_Backend extends PCM_Microcache_Filesystem_Backend {
    protected $table_name;

    public function __construct() {
        parent::__construct();
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'pcm_microcache_index';
    }

    public function get( $key ) {
        $row = parent::get( $key );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return $row;
    }

    public function set( $key, $payload, $ttl, $tags, $builder_name ) {
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
        $wpdb->replace(
            $this->table_name,
            array(
                'cache_key'     => $key,
                'version'       => isset( $meta['version'] ) ? $meta['version'] : '',
                'expires_at'    => isset( $meta['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $meta['expires_at'] ) ) : gmdate( 'Y-m-d H:i:s' ),
                'artifact_path' => isset( $meta['artifact_path'] ) ? $meta['artifact_path'] : '',
                'tags'          => wp_json_encode( isset( $meta['tags'] ) ? (array) $meta['tags'] : array() ),
                'updated_at'    => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        return true;
    }

    public function invalidate_by_tags( $tags ) {
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

    public function invalidate_key( $key ) {
        $removed = parent::invalidate_key( $key );

        global $wpdb;
        $wpdb->delete( $this->table_name, array( 'cache_key' => $key ), array( '%s' ) );

        return $removed;
    }

    public function flush_all() {
        $removed = parent::flush_all();

        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );

        return $removed;
    }
}

function pcm_microcache_get_backend() {
    static $backend = null;

    if ( null !== $backend ) {
        return $backend;
    }

    $backend = get_option( 'pcm_microcache_use_custom_table_index', false )
        ? new PCM_Microcache_TableIndex_Backend()
        : new PCM_Microcache_Filesystem_Backend();

    return $backend;
}

function pcm_microcache_normalize_tags( $tags ) {
    return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $tags ) ) ) );
}

function pcm_microcache_request_allowed( $key, $tags ) {
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
                if ( 0 === strpos( $cookie_name, (string) $prefix ) ) {
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

function pcm_microcache_resolve_builder_name( $builder ) {
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

function pcm_microcache_get_or_build( $key, $builder, $ttl, $tags = array() ) {
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
            pcm_microcache_record_stat( 'hit', $key, 0, isset( $entry['builder'] ) ? $entry['builder'] : 'unknown' );
            return $entry['payload'];
        }

        $swr_window = (int) apply_filters( 'pcm_microcache_swr_window_seconds', min( $ttl, 120 ), $key, $ttl, $tags );
        if ( absint( $entry['expires_at'] ) + max( 1, $swr_window ) >= $now ) {
            pcm_microcache_record_stat( 'stale', $key, 0, isset( $entry['builder'] ) ? $entry['builder'] : 'unknown' );
            pcm_microcache_schedule_async_rebuild( $key, $builder, $ttl, $tags );
            return $entry['payload'];
        }
    }

    return pcm_microcache_build_and_store( $key, $builder, $ttl, $tags, ( is_array( $entry ) ? 'rebuild' : 'miss' ) );
}

function pcm_microcache_build_and_store( $key, $builder, $ttl, $tags, $event = 'miss' ) {
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

function pcm_microcache_schedule_async_rebuild( $key, $builder, $ttl, $tags ) {
    $lock_key = 'pcm_microcache_rebuild_' . md5( $key );
    if ( get_transient( $lock_key ) ) {
        return;
    }

    set_transient( $lock_key, 1, 30 );

    add_action(
        'shutdown',
        static function () use ( $key, $builder, $ttl, $tags, $lock_key ) {
            pcm_microcache_build_and_store( $key, $builder, $ttl, $tags, 'rebuild' );
            delete_transient( $lock_key );
        },
        999
    );
}

function pcm_microcache_record_stat( $event, $key, $build_ms, $builder_name ) {
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

    $stats['total_build_ms'] += (float) $build_ms;
    $stats['last_key']        = $key;
    $stats['updated_at']      = current_time( 'mysql', true );

    if ( ! isset( $stats['builder_costs'][ $builder_name ] ) ) {
        $stats['builder_costs'][ $builder_name ] = array(
            'calls'    => 0,
            'total_ms' => 0,
        );
    }

    $stats['builder_costs'][ $builder_name ]['calls']++;
    $stats['builder_costs'][ $builder_name ]['total_ms'] += (float) $build_ms;

    update_option( pcm_microcache_stats_option_key(), $stats, false );
}

function pcm_microcache_log_invalidation_event( $reason, $tags, $removed ) {
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

function pcm_microcache_invalidate_tags( $tags, $reason = 'unknown' ) {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return 0;
    }

    $removed = pcm_microcache_get_backend()->invalidate_by_tags( $tags );
    pcm_microcache_log_invalidation_event( $reason, $tags, $removed );

    return $removed;
}

function pcm_microcache_flush_all( $reason = 'manual_flush' ) {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return 0;
    }

    $removed = pcm_microcache_get_backend()->flush_all();
    pcm_microcache_log_invalidation_event( $reason, array( 'all' ), $removed );

    return $removed;
}

function pcm_microcache_get_menu_tree_payload( $menu_location, $ttl = 300 ) {
    $location = sanitize_key( $menu_location );

    return pcm_microcache_get_or_build(
        'menu_tree_' . $location,
        static function () use ( $location ) {
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
                static function ( $item ) {
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

function pcm_microcache_get_archive_cards( $query_args = array(), $ttl = 120 ) {
    $args = wp_parse_args(
        (array) $query_args,
        array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 12,
        )
    );

    return pcm_microcache_get_or_build(
        'archive_cards_' . md5( wp_json_encode( $args ) ),
        static function () use ( $args ) {
            $query = new WP_Query( $args );
            if ( ! $query->have_posts() ) {
                return array();
            }

            $cards = array();
            foreach ( (array) $query->posts as $post ) {
                $cards[] = array(
                    'id'        => (int) $post->ID,
                    'title'     => get_the_title( $post ),
                    'permalink' => get_permalink( $post ),
                    'excerpt'   => get_the_excerpt( $post ),
                    'date'      => get_post_time( 'c', true, $post ),
                );
            }

            return $cards;
        },
        $ttl,
        array( 'archive_cards', 'post_type_' . sanitize_key( $args['post_type'] ) )
    );
}

function pcm_microcache_get_adjacent_links_metadata( $post_id, $ttl = 180 ) {
    $post_id = absint( $post_id );

    return pcm_microcache_get_or_build(
        'adjacent_links_' . $post_id,
        static function () use ( $post_id ) {
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

function pcm_microcache_public_health_endpoint() {
    $data = pcm_microcache_get_or_build(
        'public_health_payload',
        static function () {
            return array(
                'status'        => 'ok',
                'generated_at'  => gmdate( 'c' ),
                'batcache_hits' => (int) get_option( 'pcm_batcache_hits_24h', 0 ),
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

function pcm_microcache_invalidate_post_tags( $post_id ) {
    $post_id = absint( $post_id );
    if ( $post_id <= 0 ) {
        return;
    }

    $post = get_post( $post_id );
    $tags = array( 'post_' . $post_id, 'archive_cards', 'adjacent_links' );

    if ( $post ) {
        $tags[] = 'post_type_' . sanitize_key( $post->post_type );
    }

    pcm_microcache_invalidate_tags( $tags, 'save_post' );
}
add_action( 'save_post', 'pcm_microcache_invalidate_post_tags', 20, 1 );

function pcm_microcache_invalidate_taxonomy_tags( $term_id, $tt_id = 0, $taxonomy = '' ) {
    $tags = array( 'taxonomy', 'archive_cards', 'term_' . absint( $term_id ) );

    if ( ! empty( $taxonomy ) ) {
        $tags[] = 'taxonomy_' . sanitize_key( $taxonomy );
    }

    pcm_microcache_invalidate_tags( $tags, 'taxonomy_change' );
}
add_action( 'created_term', 'pcm_microcache_invalidate_taxonomy_tags', 20, 3 );
add_action( 'edited_term', 'pcm_microcache_invalidate_taxonomy_tags', 20, 3 );
add_action( 'delete_term', 'pcm_microcache_invalidate_taxonomy_tags', 20, 3 );

function pcm_microcache_on_manual_purge() {
    pcm_microcache_flush_all( 'manual_purge' );
}
add_action( 'pcm_after_object_cache_flush', 'pcm_microcache_on_manual_purge' );
add_action( 'pcm_after_edge_cache_purge', 'pcm_microcache_on_manual_purge' );

function pcm_microcache_maybe_install_table_index() {
    if ( ! pcm_durable_origin_microcache_is_enabled() || ! get_option( 'pcm_microcache_use_custom_table_index', false ) ) {
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

function pcm_microcache_get_health_summary() {
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
        static function ( $a, $b ) {
            return $b['total_ms'] <=> $a['total_ms'];
        }
    );

    return array(
        'stats'         => $stats,
        'top_builders'  => array_slice( $builders, 0, 5 ),
        'recent_events' => array_slice( array_reverse( (array) get_option( pcm_microcache_events_option_key(), array() ) ), 0, 8 ),
    );
}

function pcm_microcache_render_deep_dive_card() {
    if ( ! pcm_durable_origin_microcache_is_enabled() ) {
        return;
    }

    $summary = pcm_microcache_get_health_summary();
    $stats   = $summary['stats'];
    ?>
    <div class="pcm-card pcm-card-hover" id="pcm-feature-durable-origin-microcache" style="margin-bottom:20px;scroll-margin-top:20px;">
        <h3 class="pcm-card-title">⚡ <?php echo esc_html__( 'Durable Origin Microcache', 'pressable_cache_management' ); ?></h3>
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
                                <?php echo esc_html( isset( $event['timestamp'] ) ? (string) $event['timestamp'] : '' ); ?>
                                — <?php echo esc_html( isset( $event['reason'] ) ? (string) $event['reason'] : 'unknown' ); ?>
                                (<?php echo esc_html( isset( $event['removed'] ) ? (string) $event['removed'] : '0' ); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
