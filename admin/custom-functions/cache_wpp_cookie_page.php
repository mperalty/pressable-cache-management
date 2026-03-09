<?php // Pressable Cache Management  - Enable Caching for pages which has wpp_ cookies

// disable direct file access
if (!defined('ABSPATH'))
{

    exit;

}

$options = pcm_get_options();

if (isset($options['cache_wpp_cookies_pages']) && !empty($options['cache_wpp_cookies_pages']))
{

    //Add the option from the textbox into the database
    update_option( PCM_Options::CACHE_WPP_COOKIES_PAGES->value, $options['cache_wpp_cookies_pages']);

    pcm_sync_mu_plugin(
        plugin_dir_path(__FILE__) . '/cache_wpp_cookie_page_mu_plugin.php',
        'pcm_cache_wpp_cookies_pages.php'
    );

    //Display admin notice
    function cache_wpp_cookies_pages_admin_notice( string $message = '', string $classes = 'notice-success' ): void
    {

        if (!empty($message))
        {
            printf('<div class="notice %2$s">%1$s</div>', $message, $classes);
        }
    }

    function pcm_cache_wpp_cookies_pages_admin_notice(): void
    {

        $cache_wpp_cookies_pages_activate_display_notice = get_option( PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value, 'activating');

        if ('activating' === $cache_wpp_cookies_pages_activate_display_notice && current_user_can('manage_options'))
        {

            add_action('admin_notices', function ()
            {

                $screen = get_current_screen();

                //Display admin notice for this plugin page only
                if ($screen->id !== 'toplevel_page_pressable_cache_management') return;

                $message = sprintf('<p>Batcache will now cache pages with wpp_ cookies.</p>');

                cache_wpp_cookies_pages_admin_notice($message, 'notice notice-success is-dismissible');
            });

            update_option( PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value, 'activated');

        }
    }
    add_action('init', 'pcm_cache_wpp_cookies_pages_admin_notice');

}
else
{

    /**Update option from the database if the option is deactivated
     used by admin notice to display and remove notice**/
    update_option( PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value, 'activating');

    pcm_remove_mu_plugin( 'pcm_cache_wpp_cookies_pages.php' );
}
