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

    function pcm_cache_wpp_cookies_pages_admin_notice(): void
    {
        $state = get_option( PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value, 'activating');
        if ('activating' !== $state || ! current_user_can('manage_options')) return;

        add_action('admin_notices', function () {
            $screen = get_current_screen();
            if ( ! $screen || $screen->id !== 'toplevel_page_pressable_cache_management') return;
            pcm_admin_notice( __( 'Batcache will now cache pages with wpp_ cookies.', 'pressable_cache_management' ), 'success' );
        });
        update_option( PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value, 'activated');
    }
    add_action('admin_notices', 'pcm_cache_wpp_cookies_pages_admin_notice');

}
else
{

    /**Update option from the database if the option is deactivated
     used by admin notice to display and remove notice**/
    update_option( PCM_Options::CACHE_WPP_COOKIES_PAGES_NOTICE->value, 'activating');

    pcm_remove_mu_plugin( 'pcm_cache_wpp_cookies_pages.php' );
}
