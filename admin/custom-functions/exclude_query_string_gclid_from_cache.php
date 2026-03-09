<?php // Pressable Cache Management  - Exclude Google Ads URL's with query string gclid from Batcache

// disable direct file access
if (!defined('ABSPATH'))
{

    exit;

}

$options = pcm_get_options();

if (isset($options['exclude_query_string_gclid_checkbox']) && !empty($options['exclude_query_string_gclid_checkbox']))
{

    //Declare variable so that it can be accessed from
    $exclude_query_string_gclid = get_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID->value );

    pcm_sync_mu_plugin(
        plugin_dir_path(__FILE__) . '/exclude_query_string_gclid_from_cache_mu_plugin.php',
        'pcm_exclude_query_string_gclid.php'
    );

     //Display admin notice
    function exclude_query_string_gclid_admin_notice( string $message = '', string $classes = 'notice-success' ): void
    {

        if (!empty($message))
        {
            printf('<div class="notice %2$s">%1$s</div>', $message, $classes);
        }
    }

    function pcm_exclude_query_string_gclid_admin_notice(): void
    {

        $exclude_query_string_gclid_activate_display_notice = get_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value, 'activating');

        if ('activating' === $exclude_query_string_gclid_activate_display_notice && current_user_can('manage_options'))
        {

            add_action('admin_notices', function ()
            {

                $screen = get_current_screen();

                //Display admin notice for this plugin page only
                if ($screen->id !== 'toplevel_page_pressable_cache_management') return;

                $message = '<p> Google Ads URL with query string (gclid) will be excluded from Batcache.</p>';

                exclude_query_string_gclid_admin_notice($message, 'notice notice-success is-dismissible');
            });

            update_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value, 'activated');

        }
    }
    add_action('init', 'pcm_exclude_query_string_gclid_admin_notice');



}
else
{


    /**Update option from the database if the option is deactivated
     used by admin notice to display and remove notice**/
    update_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value, 'activating');

    pcm_remove_mu_plugin( 'pcm_exclude_query_string_gclid.php' );
}
