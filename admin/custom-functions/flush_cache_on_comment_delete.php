<?php // Pressable Cache Management  - Flush cache when comment is deleted

$options = pcm_get_options();


if (isset($options['flush_cache_on_comment_delete_checkbox']) && !empty($options['flush_cache_on_comment_delete_checkbox']))
{

add_action( 'trash_comment', 'pcm_flush_cache_on_comment_removal', 10, 2 );
add_action( 'delete_comment', 'pcm_flush_cache_on_comment_removal', 10, 2 );

/**
 * Flush the object cache when a comment is trashed or permanently deleted.
 *
 * Hooked into both `trash_comment` and `delete_comment` so that cache is
 * flushed regardless of whether the comment goes to trash or is hard-deleted.
 * A static guard prevents duplicate flushes when both hooks fire for the
 * same comment (e.g. trash then delete in the same request).
 *
 * @param string     $comment_id The comment ID as a numeric string.
 * @param WP_Comment $comment    The comment to be trashed or deleted.
 *
 * @return void
 */
function pcm_flush_cache_on_comment_removal( string $comment_id, \WP_Comment $comment ): void {

	// Prevent duplicate flushes if both trash_comment and delete_comment
	// fire for the same comment during a single request.
	static $flushed_comments = array();

	if ( isset( $flushed_comments[ $comment_id ] ) ) {
		return;
	}

	$flushed_comments[ $comment_id ] = true;

	wp_cache_flush();

	// Save timestamp to database when cache is flushed on comment removal.
	$object_cache_flush_time = pcm_format_flush_timestamp();
	update_option( PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP->value, $object_cache_flush_time );
}

}
