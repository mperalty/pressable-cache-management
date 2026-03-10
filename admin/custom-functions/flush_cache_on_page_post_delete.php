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
   add_action( 'post_updated', 'pcm_fire_on_page_post_delete', 10, 3 );

 }

