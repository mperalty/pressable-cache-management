<?php // Pressable Cache Management  - Exclude pages from Batcache

// disable direct file access
if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

$options = pcm_get_options();


if ( isset( $options['exempt_from_batcache'] ) && ! empty( $options['exempt_from_batcache'] ) ) {

	//Add the option from the textbox into the database
	update_option( PCM_Options::EXEMPT_FROM_BATCACHE->value, $options['exempt_from_batcache'] );

	pcm_sync_mu_plugin(
		plugin_dir_path( __FILE__ ) . '/exclude-pages-from-batcache-mu-plugin.php',
		'pcm_exclude_pages_from_batcache.php'
	);

} else {
	pcm_remove_mu_plugin( 'pcm_exclude_pages_from_batcache.php' );
}
