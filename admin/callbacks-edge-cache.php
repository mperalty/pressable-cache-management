<?php
/**
 * Pressable Cache Management — Edge Cache Tab Callbacks.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pressable_cache_management_callback_section_edge_cache(): void {
	echo '<p>' . esc_html__( 'These settings enable you to manage Edge Cache settings.', 'pressable_cache_management' ) . '</p>';
}

// Radio button options to turn on/off Edge Cache
function pressable_cache_management_options_radio_edge_cache_button(): array {
	return array(
		'enable' => esc_html__( 'Enable Edge Cache', 'pressable_cache_management' ),
	);
}

// ─── Edge Cache Status Helpers (extracted from monolithic AJAX handler) ──────

/**
 * Query the live Edge Cache status from the platform API.
 *
 * @return array{is_enabled: bool, status_text: string, status_flag: string, ttl: int}
 */
function pcm_get_edge_cache_status(): array {
	$result = array(
		'is_enabled'  => false,
		'status_text' => 'Unknown',
		'status_flag' => 'Error',
		'ttl'         => 60,
	);

	if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
		return $result;
	}

	$edge_cache    = Edge_Cache_Plugin::get_instance();
	$server_status = $edge_cache->get_ec_status();

	if ( Edge_Cache_Plugin::EC_ENABLED === $server_status ) {
		$result['is_enabled']  = true;
		$result['status_text'] = 'Enabled';
		$result['status_flag'] = 'Success';
		$result['ttl']         = 300;
	} elseif ( Edge_Cache_Plugin::EC_DISABLED === $server_status ) {
		$result['is_enabled']  = false;
		$result['status_text'] = 'Disabled';
		$result['status_flag'] = 'Success';
		$result['ttl']         = 300;
	} elseif ( Edge_Cache_Plugin::EC_DDOS === $server_status ) {
		$result['status_text'] = 'Defensive Mode (DDoS)';
		$result['status_flag'] = 'Warning';
		$result['ttl']         = 60;
	}

	return $result;
}

/**
 * Persist edge cache status to transient cache and wp_options.
 *
 * @param array{is_enabled: bool, status_text: string, status_flag: string, ttl: int} $status Status from pcm_get_edge_cache_status().
 */
function pcm_update_edge_cache_status_cache( array $status ): void {
	set_transient(
		'pcm_ec_status_cache',
		array(
			'is_enabled'  => $status['is_enabled'],
			'status_text' => $status['status_text'],
			'status_flag' => $status['status_flag'],
		),
		$status['ttl']
	);

	update_option( PCM_Options::EDGE_CACHE_STATUS->value, $status['status_flag'] );
	update_option( PCM_Options::EDGE_CACHE_ENABLED->value, $status['is_enabled'] ? 'enabled' : 'disabled' );
}

/**
 * Format the AJAX success response for edge cache status.
 *
 * @param array{is_enabled: bool, status_text: string, status_flag: string} $status Status data.
 * @param bool $from_cache Whether this came from the transient cache.
 *
 * @return array<string, mixed>
 */
function pcm_format_edge_cache_status_response( array $status, bool $from_cache = false ): array {
	return array(
		'enabled'                      => $status['is_enabled'],
		'status_text'                  => $status['status_text'],
		'status_flag'                  => $status['status_flag'],
		'from_cache'                   => $from_cache,
		'html_controls_enable_disable' => pressable_cache_management_generate_enable_disable_content( $status['is_enabled'] ),
	);
}

// ─── AJAX handler (refactored to use helpers above) ─────────────────────────

function pcm_ajax_check_edge_cache_status(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	// Try transient cache first
	$cached = get_transient( 'pcm_ec_status_cache' );
	if ( false !== $cached ) {
		wp_send_json_success( pcm_format_edge_cache_status_response( $cached, true ) );
	}

	if ( ! class_exists( 'Edge_Cache_Plugin' ) ) {
		wp_send_json_error(
			array(
				'message'                      => 'Edge Cache dependency is not available.',
				'html_controls_enable_disable' => '<p class="notice notice-error" class="pcm-notice-error-inline">' . esc_html__( 'Error: Edge Cache dependency is missing.', 'pressable_cache_management' ) . '</p>',
			)
		);
	}

	$status = pcm_get_edge_cache_status();
	pcm_update_edge_cache_status_cache( $status );
	wp_send_json_success( pcm_format_edge_cache_status_response( $status, false ) );
}
add_action( 'wp_ajax_pcm_check_edge_cache_status', 'pcm_ajax_check_edge_cache_status' );

/**
 * Clear the edge cache status transient whenever the state changes.
 */
function pcm_clear_ec_status_cache(): void {
	delete_transient( 'pcm_ec_status_cache' );
}
add_action( 'pcm_after_edge_cache_enable', 'pcm_clear_ec_status_cache' );
add_action( 'pcm_after_edge_cache_disable', 'pcm_clear_ec_status_cache' );
add_action( 'pcm_after_edge_cache_purge', 'pcm_clear_ec_status_cache' );

/**
 * Helper: generate only the Enable/Disable button form HTML.
 *
 * @param bool $is_enabled Whether the Edge Cache is currently enabled.
 * @return string The HTML containing only the Enable/Disable control.
 */
function pressable_cache_management_generate_enable_disable_content( bool $is_enabled ): string {
	ob_start();

	if ( $is_enabled ) {
		$id           = 'disable_edge_cache_nonce';
		$value        = __( 'Disable Edge Cache', 'pressable_cache_management' );
		$submit_class = 'pcm-btn-secondary';

		echo '</form>';
		echo '<form method="post" id="' . esc_attr( $id ) . '">';
		echo '<span id="' . esc_attr( $id ) . '">';
		echo '<input id="edge_cache_settings_tab_options_disable" name="edge_cache_settings_tab_options[edge_cache_on_off_radio_button]" type="submit" size="40" value="' . esc_attr( $value ) . '" class="' . esc_attr( $submit_class ) . '" data-pcm-ec-action="disable"/>';
		echo '<input type="hidden" name="' . esc_attr( $id ) . '" value="' . esc_attr( wp_create_nonce( $id ) ) . '" />';
		echo '</span>';
		echo '</form>';
	} else {
		$id           = 'enable_edge_cache_nonce';
		$value        = __( 'Enable Edge Cache', 'pressable_cache_management' );
		$submit_class = 'pcm-btn-secondary';

		echo '</form>';
		echo '<form method="post" id="' . esc_attr( $id ) . '">';
		echo '<span id="' . esc_attr( $id ) . '">';
		echo '<input id="edge_cache_settings_tab_options_enable" name="edge_cache_settings_tab_options[edge_cache_on_off_radio_button]" type="submit" size="40" value="' . esc_attr( $value ) . '" class="' . esc_attr( $submit_class ) . '" data-pcm-ec-action="enable"/>';
		echo '<input type="hidden" name="' . esc_attr( $id ) . '" value="' . esc_attr( wp_create_nonce( $id ) ) . '" />';
		echo '</span>';
		echo '</form>';
	}

	echo '<div id="pcm-ec-propagation-status" aria-live="polite" style="margin-top:8px;"></div>';

	return ob_get_clean();
}

// Renders the placeholder div for the Edge Cache enable/disable control.
// The actual status check is handled by settings.js which calls
// pcm_check_edge_cache_status via AJAX and hydrates this wrapper.
function pressable_cache_management_callback_field_extend_edge_cache_radio_button( $args ): void {
	$field_id = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : 'edge-cache-control';
	?>
	<div id="edge-cache-control-wrapper" class="pcm-ec-control-min" data-field-id="<?php echo esc_attr( $field_id ); ?>">
		<div class="edge-cache-loader"><?php esc_html_e( 'Checking Edge Cache status...', 'pressable_cache_management' ); ?></div>
	</div>
	<?php
}

// Purge Edge Cache
function pressable_edge_cache_flush_management_callback_field_button( $args ): void {
	$options = get_option( PCM_Options::EDGE_CACHE_SETTINGS_OPTIONS->value );

	$id = $args['id'] ?? '';

	$disabled_attr  = ' disabled="disabled"';
	$disabled_class = ' disabled-button-style';
	$submit_class   = 'pcm-btn-danger' . $disabled_class;

	echo '</form>';

	echo '<form method="post" id="purge_edge_cache_nonce_form_static">
         <span id="purge_edge_cache_button_span_static">
              <input id="purge-edge-cache-button-input"
                     name="edge_cache_settings_tab_options[' . esc_attr( $id ) . ']"
                     type="submit"
                     size="40"
                     value="' . esc_attr__( 'Purge Edge Cache', 'pressable_cache_management' ) . '"
                     class="' . esc_attr( $submit_class ) . '" disabled="disabled"/>';

	echo '<input type="hidden" name="purge_edge_cache_nonce" value="' . esc_attr( wp_create_nonce( 'purge_edge_cache_nonce' ) ) . '" />';
	echo '</span>
    </form>';
	echo '<br/>';
	echo '<small><strong>' . esc_html__( 'Last purged at:', 'pressable_cache_management' ) . ' </strong></small>' . esc_html( get_option( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value ) );
	echo '<br/>';
	echo '<br/>';
	echo '<small><strong>' . esc_html__( 'Single URL last purged at:', 'pressable_cache_management' ) . '</strong></small> ' . esc_html( get_option( PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP->value ) );
	echo '<br/>';
	echo '<small><strong>' . esc_html__( 'Single URL:', 'pressable_cache_management' ) . '</strong></small> ' . esc_html( get_option( PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED->value ) );
	echo '<br/>';
	echo '<br/>';
	echo '<p class="pcm-purge-warning-text">'
		. esc_html__( 'Purging cache will temporarily slow down your site for all visitors while the cache rebuilds.', 'pressable_cache_management' ) . '</p>';
}
