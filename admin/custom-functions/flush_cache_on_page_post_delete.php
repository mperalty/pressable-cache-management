<?php // Custom function - Add custom functions to flush cache when page or post is deleted


// disable direct file access
if (!defined('ABSPATH'))
{

    exit;

}


$options = pcm_get_options();

if (isset($options['flush_cache_on_page_post_delete_checkbox']) && !empty($options['flush_cache_on_page_post_delete_checkbox']))
{

     function pcm_fire_on_page_post_delete( int $post_ID, \WP_Post $post_after, \WP_Post $post_before ): void {
   if ( $post_after->post_status == 'trash' && $post_before->post_status == 'publish' ) {
        // Flush site cache if post or page is trashed after publishing
        wp_cache_flush();
   }
   if ( $post_after->post_status == 'publish' && $post_before->post_status == 'trash' ) {
        // Flush site cache if post or page is published after being trash (post undelete)
        wp_cache_flush();
   }

       // Save time stamp to database if cache is flushed when a post or page was daleted.
        $object_cache_flush_time = pcm_format_flush_timestamp();
        update_option( PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP->value, $object_cache_flush_time);

        //Set transient for admin notice for 9 seconds
        set_transient('pcm-page-post-delete-notice', true, 9);


   }
   add_action( 'post_updated', 'pcm_fire_on_page_post_delete', 10, 3 );

 }

