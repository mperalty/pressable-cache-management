<?php
/**
 * Pressable Cache Management — Object Cache Tab Callbacks.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// callback: object cache section description
function pressable_cache_management_callback_section_cache(): void {
    $remove_pressable_branding_tab_options = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );

    if ( $remove_pressable_branding_tab_options && isset( $remove_pressable_branding_tab_options['branding_on_off_radio_button'] ) && 'disable' == $remove_pressable_branding_tab_options['branding_on_off_radio_button'] ) {
        // branding hidden
    } else {
        echo '<div><img width="230" height="50" class="pressablecmlogo" src="' . plugin_dir_url( __FILE__ ) . '/assets/img/pressable-logo-primary.svg' . '" > </div>';
    }

    echo '<p>' . esc_html__( 'These settings enable you to manage the object cache.', 'pressable_cache_management' ) . '</p>';
}

// RESTORED: This function is required by admin/settings-validate.php but was accidentally removed during cleanup.
function pressable_cache_management_options_radio_button(): array {
    return array(
        'enable'  => esc_html__( 'Enable CDN (Recommended)', 'pressable_cache_management' ),
        'disable' => esc_html__( 'Disable CDN', 'pressable_cache_management' ),
    );
}

// Flush object cache button
function pressable_cache_management_callback_field_button( $args ): void {
    $options = pcm_get_options();

    $id    = $args['id'] ?? '';
    $label = $args['label'] ?? '';

    echo '</form>';

    echo '<form method="post" id="flush_object_cache_nonce">

         <span id="flush_cache_button">
        <input id="pressable_cache_management_options_' . esc_attr( $id ) . '" name="pressable_cache_management_options[' . $id . ']" type="submit" size="40" value="' . __( 'Flush Cache', 'pressable_cache_management' ) . '" class="pcm-btn-primary pcm-btn-block"/><input type="hidden" name="flush_object_cache_nonce" value="' . esc_attr( wp_create_nonce( 'flush_object_cache_nonce' ) ) . '" <br/><label class="rad-text for="pressable_cache_management_options_' . esc_attr( $id ) . '">' . $label . '</label>
         </span>

    </form>';

    echo '</br>';
    echo '<small><strong>Last flushed at: </strong></small> ' . wp_kses_post( get_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value ) );
}

// Extend batcache checkbox
function pressable_cache_management_callback_field_extend_cache_checkbox( $args ): void {
    pcm_render_toggle_field( $args );
}

// Flush site cache on Theme/Plugin update checkbox
function pressable_cache_management_callback_field_plugin_theme_update_checkbox( $args ): void {
    pcm_render_toggle_field( $args, PCM_Options::FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP->value );
}

// Flush site object cache on page & post update checkbox
function pressable_cache_management_callback_field_page_edit_checkbox( $args ): void {
    pcm_render_toggle_field( $args, PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP->value );
}

// Flush site object cache when page, post and posttypes are updated checkbox
function pressable_cache_management_callback_field_page_post_delete_checkbox( $args ): void {
    pcm_render_toggle_field( $args, PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP->value );
}

// Flush cache when comment is deleted checkbox
function pressable_cache_management_callback_field_comment_delete_checkbox( $args ): void {
    pcm_render_toggle_field( $args, PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP->value );
}

// Flush cache for a single page
function pressable_cache_management_callback_field_flush_batcache_particular_page_checbox( $args ): void {
    $extra  = '</br>';
    $extra .= '<small><strong>Page URL:</strong></small> ' . esc_html( get_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value ) );
    pcm_render_toggle_field( $args, PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value, $extra );
}

// Flush cache for WooCommerce product single page
function pressable_cache_management_callback_field_flush_batcache_woo_product_page_checbox( $args ): void {
    pcm_render_toggle_field( $args );
}

// Callback: text field to exempt individual page from batcache
function pressable_cache_management_callback_field_exempt_batcache_text( $args ): void {
    $options = pcm_get_options();
    $id      = $args['id'] ?? '';
    $label   = $args['label'] ?? '';
    $value   = isset( $options[ $id ] ) ? sanitize_text_field( $options[ $id ] ) : '';
    echo '<input autocomplete="off" id="pressable_cache_management_options_' . esc_attr( $id ) . '" name="pressable_cache_management_options[' . $id . ']" type="text" placeholder=" Exclude single page ex  /pagename/"  size="70" value="' . esc_attr( $value ) . '"><br/>';
    echo '<label class="rad-text for="pressable_cache_management_options_' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
}
