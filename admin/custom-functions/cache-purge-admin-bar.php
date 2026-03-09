<?php  //Pressable Cache Purge Adds a Cache Purge button to the admin bar


// disable direct file access
if (!defined('ABSPATH'))
{

    exit;

}

add_action( 'admin_bar_menu', 'pcm_cache_add_item', 100 );

function pcm_cache_add_item( \WP_Admin_Bar $admin_bar ): void {

	if ( is_admin() ) {
		global $pagenow;

		$admin_bar->add_menu(
			array(
				'id'    => 'cache-purge',
				'title' => 'Object Cache Purge',
				'href'  => '#',
			)
		);
		// $admin_bar->add_menu( array( 'id'=>'settings','title'=>'Cache Settings', 'parent'=> 'cache-purge', 'href'=>'admin.php?page=pressable_cache_management' ) );

	}
}



add_action( 'admin_footer', 'pcm_cache_purge_action_js' );

function pcm_cache_purge_action_js(): void { ?>
  <script type="text/javascript" >
	 jQuery("li#wp-admin-bar-cache-purge .ab-item").on( "click", function() {
		var data = {
					  'action': 'pressable_cache_purge',
					  '_ajax_nonce': '<?php echo esc_js( wp_create_nonce( 'pressable_cache_purge' ) ); ?>',
					};

		jQuery.post(ajaxurl, data, function(response) {
		   alert( response );
		});

	  });
  </script>


<style type="text/css">
	
/*#wp-admin-bar-cache-purge .ab-item { 
  background-color: #0AD8C7;
}
*/
</style>


	<?php
}

add_action( 'wp_ajax_pressable_cache_purge', 'pcm_pressable_cache_purge_callback' );


function pcm_pressable_cache_purge_callback(): void {
	pcm_verify_ajax_request( '_ajax_nonce', 'pressable_cache_purge', 'POST', 'manage_options' );

	wp_cache_flush();

	//Save time stamp to database if cache is flushed.
	$object_cache_flush_time = pcm_format_flush_timestamp();

	update_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value, $object_cache_flush_time );
	$response = 'Object Cache Purged';
	echo $response;
	wp_die();
}
