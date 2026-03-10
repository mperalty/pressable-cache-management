<?php
/**
 * Pressable Cache Management — Flush Batcache for the individual page/post on edit.
 *
 * Instead of flushing the entire object cache on every save, this targets only
 * the Batcache entry for the URL of the post that was just saved — the same
 * technique used by flush_batcache_for_particular_page.php (column link) and
 * flush_single_page_toolbar.php (toolbar button).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$options = pcm_get_options();

if ( isset( $options['flush_cache_page_edit_checkbox'] ) && ! empty( $options['flush_cache_page_edit_checkbox'] ) ) {

    /**
     * Flush Batcache only for the URL of the post that was just saved.
     *
     * Fires on save_post (covers pages, posts, and all custom post types,
     * including WooCommerce products saved via the REST API).
     *
     * @param int     $post_id  The saved post ID.
     * @param WP_Post $post     The saved post object.
     * @param bool    $update   True if this is an update, false for a new post.
     */
    function pcm_flush_batcache_on_page_edit( int $post_id, \WP_Post $post, bool $update ): void {

        // Skip auto-saves, revisions, and non-published posts
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

        // Record the flush for display on the settings page
        $post_type_name = get_post_type_object( $post->post_type )?->labels->singular_name
            ?? $post->post_type;

        $stamp = pcm_format_flush_timestamp()
               . '<b> — cache flushed for ' . esc_html( $post_type_name )
               . ' edit: ' . esc_html( $post->post_title ) . '</b>';

        update_option( PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP->value, $stamp );
        update_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value, set_url_scheme( $url, 'http' ) );
    }

    add_action( 'save_post', 'pcm_flush_batcache_on_page_edit', 10, 3 );

}
