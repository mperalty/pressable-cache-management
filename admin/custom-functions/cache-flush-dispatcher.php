<?php
/**
 * Pressable Cache Management — Unified Cache Flush Dispatcher.
 *
 * Single entry point for cache invalidation events. Consolidates logic
 * previously scattered across:
 *   - flush_cache_on_page_edit.php      (single URL batcache flush on save_post)
 *   - flush_cache_on_page_post_delete.php (full flush on trash/untrash)
 *   - flush_cache_on_comment_delete.php (full flush on comment trash/delete)
 *
 * The Batcache_Manager class still handles its own `clean_post_cache` /
 * `clean_comment_cache` hooks for URL-level invalidation. Smart Purge and
 * Microcache modules hook independently at priority 20.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCM_Cache_Flush_Dispatcher {

    private static ?self $instance = null;

    /** @var array<string, true> Guard against duplicate comment flushes per request. */
    private array $flushed_comments = array();

    private function __construct() {}

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register all flush hooks based on current plugin options.
     */
    public function register_hooks(): void {
        $options = pcm_get_options();

        // ── save_post: single URL batcache flush ─────────────────────────
        if ( ! empty( $options['flush_cache_page_edit_checkbox'] ) ) {
            add_action( 'save_post', array( $this, 'handle_post_edit' ), 10, 3 );
        }

        // ── post_updated: full flush on trash / untrash ──────────────────
        if ( ! empty( $options['flush_cache_on_page_post_delete_checkbox'] ) ) {
            add_action( 'post_updated', array( $this, 'handle_post_status_change' ), 10, 3 );
        }

        // ── Comment removal: full flush on trash / delete ────────────────
        if ( ! empty( $options['flush_cache_on_comment_delete_checkbox'] ) ) {
            add_action( 'trash_comment',  array( $this, 'handle_comment_removal' ), 10, 2 );
            add_action( 'delete_comment', array( $this, 'handle_comment_removal' ), 10, 2 );
        }
    }

    // ─── Post Edit Handler ───────────────────────────────────────────────────

    /**
     * Flush Batcache for the URL of the post that was just saved.
     *
     * @param int     $post_id The saved post ID.
     * @param WP_Post $post    The saved post object.
     * @param bool    $update  True if this is an update.
     */
    public function handle_post_edit( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( $post->post_status !== 'publish' ) {
            return;
        }

        $url = get_permalink( $post_id );
        if ( empty( $url ) ) {
            return;
        }

        if ( ! pcm_flush_batcache_url( $url ) ) {
            return;
        }

        $post_type_name = get_post_type_object( $post->post_type )?->labels->singular_name
            ?? $post->post_type;

        $stamp = pcm_format_flush_timestamp()
               . '<b> — cache flushed for ' . esc_html( $post_type_name )
               . ' edit: ' . esc_html( $post->post_title ) . '</b>';

        update_option( PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP->value, $stamp );
        update_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value, set_url_scheme( $url, 'http' ) );
    }

    // ─── Post Status Change Handler ──────────────────────────────────────────

    /**
     * Full cache flush when a post transitions to/from trash.
     *
     * @param int     $post_ID    Post ID.
     * @param WP_Post $post_after Post after update.
     * @param WP_Post $post_before Post before update.
     */
    public function handle_post_status_change( int $post_ID, \WP_Post $post_after, \WP_Post $post_before ): void {
        $should_flush = false;

        if ( $post_after->post_status === 'trash' && $post_before->post_status === 'publish' ) {
            $should_flush = true;
        } elseif ( $post_after->post_status === 'publish' && $post_before->post_status === 'trash' ) {
            $should_flush = true;
        }

        if ( ! $should_flush ) {
            return;
        }

        pcm_schedule_deferred_flush();
        update_option( PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP->value, pcm_format_flush_timestamp() );
        set_transient( 'pcm-page-post-delete-notice', true, 9 );
    }

    // ─── Comment Removal Handler ─────────────────────────────────────────────

    /**
     * Flush the object cache when a comment is trashed or permanently deleted.
     *
     * @param string     $comment_id The comment ID.
     * @param WP_Comment $comment    The comment object.
     */
    public function handle_comment_removal( string $comment_id, \WP_Comment $comment ): void {
        // Prevent duplicate flushes if both trash_comment and delete_comment
        // fire for the same comment during a single request.
        if ( isset( $this->flushed_comments[ $comment_id ] ) ) {
            return;
        }
        $this->flushed_comments[ $comment_id ] = true;

        pcm_schedule_deferred_flush();

        update_option( PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP->value, pcm_format_flush_timestamp() );
    }
}

// ─── Initialize dispatcher ───────────────────────────────────────────────────
PCM_Cache_Flush_Dispatcher::get_instance()->register_hooks();
