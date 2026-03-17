<?php // Pressable Cache Management  - Exclude Google Ads URL's with query string gclid from Batcache

// disable direct file access
if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

$options = pcm_get_options();

if ( isset( $options['exclude_query_string_gclid_checkbox'] ) && ! empty( $options['exclude_query_string_gclid_checkbox'] ) ) {

	//Declare variable so that it can be accessed from
	$exclude_query_string_gclid = get_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID->value );

	pcm_sync_mu_plugin(
		plugin_dir_path( __FILE__ ) . '/exclude-query-string-gclid-from-cache-mu-plugin.php',
		'pcm_exclude_query_string_gclid.php'
	);

	function pcm_exclude_query_string_gclid_admin_notice(): void {
		$state = get_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value, 'activating' );
		if ( 'activating' !== $state || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			function () {
				$screen = get_current_screen();
				if ( ! $screen || 'toplevel_page_pressable_cache_management' !== $screen->id ) {
					return;
				}
				pcm_admin_notice( __( 'Google Ads URL with query string (gclid) will be excluded from Batcache.', 'pressable_cache_management' ), 'success' );
			}
		);
		update_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value, 'activated' );
	}
	add_action( 'admin_notices', 'pcm_exclude_query_string_gclid_admin_notice' );



} else {


	/**Update option from the database if the option is deactivated
	used by admin notice to display and remove notice**/
	update_option( PCM_Options::EXCLUDE_QUERY_STRING_GCLID_NOTICE->value, 'activating' );

	pcm_remove_mu_plugin( 'pcm_exclude_query_string_gclid.php' );
}
