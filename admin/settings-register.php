<?php
// Pressable Cache Management - Register Settings

// Disable direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Register plugin settings
function pressable_cache_management_register_settings()
{
    // Save options for object cache tab
    register_setting(
        'pressable_cache_management_options',
        'pressable_cache_management_options',
        'pressable_cache_management_callback_validate_options'
    );

    // Save options for edge cache tab
    register_setting(
        'edge_cache_tab_options',
        'edge_cache_settings_tab_options',
        'edge_cache_settings_tab_callback_validate_options'
    );

    // Save options for branding tab
    register_setting(
        'remove_pressable_branding_tab_options',
        'remove_pressable_branding_tab_options',
        'remove_pressable_branding_tab_callback_validate_options'
    );

    // Remove Pressable branding tab page
    add_settings_section(
        'pressable_cache_management_section_branding',
        esc_html__('Show or Hide Plugin Branding', 'pressable_cache_management'),
        'pressable_cache_management_callback_section_branding',
        'remove_pressable_branding_tab'
    );

    // Verify if the options exist
    if (false == get_option('pressable_cache_management_options')) {
        add_option('pressable_cache_management_options');
    }

    /*
     * Remove Pressable Branding Tab
     */
    add_settings_field(
        'branding_on_off_radio_button',
        esc_html__('Hide or Show Plugin Branding', 'pressable_cache_management'),
        'pressable_cache_management_callback_field_extend_remove_branding_radio_button',
        'remove_pressable_branding_tab',
        'pressable_cache_management_section_branding',
        ['id' => 'branding_on_off_radio_button', 'label' => esc_html__('Hide or show plugin branding', 'pressable_cache_management')]
    );
}

add_action('admin_init', 'pressable_cache_management_register_settings');
