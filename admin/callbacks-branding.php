<?php
/**
 * Pressable Cache Management — Branding Tab Callbacks.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// callback: Hide Pressable branding tab page description
function pressable_cache_management_callback_section_branding(): void {
	echo '<p>' . esc_html__( 'This setting allows you to show or hide the plugin branding.', 'pressable_cache_management' ) . '</p>';
}

// Radio button options
function pressable_cache_management_options_remove_branding_radio_button(): array {
	return array(
		'enable'  => esc_html__( 'Show Pressable Branding', 'pressable_cache_management' ),
		'disable' => esc_html__( 'Hide Pressable Branding', 'pressable_cache_management' ),
	);
}

function pressable_cache_management_callback_field_extend_remove_branding_radio_button( $args ): void {
	$options = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );

	$id    = $args['id'] ?? '';
	$label = $args['label'] ?? '';

	$selected_option = isset( $options[ $id ] ) ? sanitize_text_field( $options[ $id ] ) : '';

	$radio_options = pressable_cache_management_options_remove_branding_radio_button();

	foreach ( $radio_options as $value => $label ) {
		echo '<label class="rad-label">';
		echo '<input type="radio" class="rad-input" name="remove_pressable_branding_tab_options[' . esc_attr( $id ) . ']" value="' . esc_attr( $value ) . '"';
		checked( $selected_option, $value );
		echo ' name="rad">';
		echo '<div class="rad-design"></div>';
		echo '<span class="rad-text">' . esc_html( $label ) . '</span></label>';
		echo '</label>';
	}
}
