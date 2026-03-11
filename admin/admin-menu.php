<?php // Pressable Cache Management - Admin Menu


// disable direct file access
if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

// add top-level administrative menu
function pressable_cache_management_add_toplevel_menu(): void {

	// Register the plugin top-level menu pages in the admin sidebar.

	//Check if branding Pressable branding is enabled or disabled

	$remove_pressable_branding_tab_options = false;

	$remove_pressable_branding_tab_options = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );

	if ( $remove_pressable_branding_tab_options && 'disable' === $remove_pressable_branding_tab_options['branding_on_off_radio_button'] ) {

		add_menu_page(
			esc_html__( 'Cache Management Settings', 'pressable_cache_management' ),
			esc_html__( 'Cache Control', 'pressable_cache_management' ),
			'manage_options',
			'pressable_cache_management',
			'pressable_cache_management_display_settings_page',
			plugin_dir_url( __FILE__ ) . '/assets/img/cache_control.png',
			2
		);

	} else {

		add_menu_page(
			esc_html__( 'Pressable Cache Management Settings', 'pressable_cache_management' ),
			esc_html__( 'Pressable CM', 'pressable_cache_management' ),
			'manage_options',
			'pressable_cache_management',
			'pressable_cache_management_display_settings_page',
			plugin_dir_url( __FILE__ ) . '/assets/img/pressable-icon-primary.svg',
			2
		);

	}
}
add_action( 'admin_menu', 'pressable_cache_management_add_toplevel_menu' );

//Display admin notices for top level menu
function plugin_admin_notice(): void {
	//get the current screen
	$screen = get_current_screen();

	//return if not plugin settings page
	if ( 'toplevel_page_pressable_cache_management' !== $screen->id ) {
		return;
	}

	// Settings saved notice is handled by settings-page.php (pcm_branded_settings_saved_notice)
	// which outputs a single branded card. This block intentionally left empty.
}
add_action( 'admin_notices', 'plugin_admin_notice' );
