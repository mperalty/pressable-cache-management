<?php
/**
 * Guided Remediation Playbooks (Pillar 8).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pcm_playbooks_dir = plugin_dir_path( __FILE__ );

require_once $pcm_playbooks_dir . 'class-pcm-playbook-repository.php';
require_once $pcm_playbooks_dir . 'class-pcm-playbook-progress-store.php';
require_once $pcm_playbooks_dir . 'class-pcm-playbook-lookup-service.php';
require_once $pcm_playbooks_dir . 'class-pcm-playbook-renderer.php';

/**
 * Feature gate for guided remediation playbooks.
 *
 * @return bool
 */
function pcm_guided_playbooks_is_enabled(): bool {
	static $cached = null;
	if ( null === $cached ) {
		$enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
		$cached  = (bool) apply_filters( 'pcm_enable_guided_playbooks', $enabled );
	}

	return $cached;
}

/**
 * Capability guard for playbook AJAX handlers.
 *
 * @return bool
 */
function pcm_playbooks_ajax_can_manage(): bool {
	if ( function_exists( 'pcm_current_user_can' ) ) {
		return (bool) pcm_current_user_can( 'pcm_view_diagnostics' );
	}

	return current_user_can( 'manage_options' );
}

/**
 * Verify nonce and capability for a playbook AJAX request.
 *
 * Uses pcm_ajax_enforce_permissions() when available (security-privacy module),
 * otherwise falls back to check_ajax_referer() + capability check.
 *
 * @param string $capability Custom capability to check (e.g. 'pcm_view_diagnostics').
 */
function pcm_playbooks_verify_ajax( string $capability = 'pcm_view_diagnostics' ): void {
	if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
		pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', $capability );
		return;
	}

	check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

	if ( ! pcm_playbooks_ajax_can_manage() ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}
}

/**
 * Fetch an AJAX request value from POST, then GET.
 *
 * @param string $key Request key.
 *
 * @return string
 */
function pcm_playbooks_request_value( string $key ): string {
	$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );
	if ( ! is_string( $value ) ) {
		$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW );
	}

	return is_string( $value ) ? wp_unslash( $value ) : '';
}

/**
 * Render helper with tabs and localStorage checklist wiring.
 *
 * @param array $playbook Playbook.
 *
 * @return string
 */
function pcm_render_playbook_panel( array $playbook ): string {
	if ( empty( $playbook['meta']['playbook_id'] ) ) {
		return '';
	}

	$renderer    = new PCM_Playbook_Renderer();
	$playbook_id = $playbook['meta']['playbook_id'];
	$title       = $playbook['meta']['title'] ?? $playbook_id;
	$content     = $renderer->render( $playbook['body'] ?? '' );

	$panel_id = 'pcm-playbook-' . $playbook_id;
	$ajax_url = admin_url( 'admin-ajax.php' );
	$nonce    = wp_create_nonce( 'pcm_cacheability_scan' );

	ob_start();
	?>
	<div id="<?php echo esc_attr( $panel_id ); ?>" class="pcm-playbook-panel">
		<h3><?php echo esc_html( $title ); ?></h3>
		<p>
			<strong><?php esc_html_e( 'Severity:', 'pressable_cache_management' ); ?></strong>
			<?php echo esc_html( $playbook['meta']['severity'] ?? 'warning' ); ?>
			&nbsp;|&nbsp;
			<strong><?php esc_html_e( 'Version:', 'pressable_cache_management' ); ?></strong>
			<?php echo esc_html( $playbook['meta']['version'] ?? '1.0.0' ); ?>
		</p>
		<div class="pcm-playbook-tabs" style="margin:12px 0;">
			<button type="button" class="pcm-btn-text" onclick="pcmPlaybookSwitchTab('<?php echo esc_js( $panel_id ); ?>','quick')"><?php esc_html_e( 'Quick Fix', 'pressable_cache_management' ); ?></button>
			<button type="button" class="pcm-btn-text" onclick="pcmPlaybookSwitchTab('<?php echo esc_js( $panel_id ); ?>','technical')"><?php esc_html_e( 'Technical', 'pressable_cache_management' ); ?></button>
			<button type="button" class="pcm-btn-text" onclick="pcmPlaybookSwitchTab('<?php echo esc_js( $panel_id ); ?>','verify')"><?php esc_html_e( 'Verify', 'pressable_cache_management' ); ?></button>
		</div>
		<div class="pcm-playbook-content"><?php echo wp_kses_post( $content ); ?></div>
		<hr />
		<div class="pcm-playbook-checklist">
			<label><input type="checkbox" data-pcm-check="1" /> <?php esc_html_e( 'Step 1 complete', 'pressable_cache_management' ); ?></label><br />
			<label><input type="checkbox" data-pcm-check="2" /> <?php esc_html_e( 'Step 2 complete', 'pressable_cache_management' ); ?></label><br />
			<label><input type="checkbox" data-pcm-check="3" /> <?php esc_html_e( 'Verification complete', 'pressable_cache_management' ); ?></label>
		</div>
		<p style="margin-top:10px;">
			<button type="button" class="pcm-btn-secondary" onclick="pcmPlaybookTriggerRescan()"><?php esc_html_e( 'Re-scan now', 'pressable_cache_management' ); ?></button>
		</p>
	</div>
	<script>
		(function(){
			var panelId = <?php echo wp_json_encode( $panel_id ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var key = 'pcm_playbook_checklist_' + panelId;
			var panel = document.getElementById(panelId);
			if (!panel) return;
			var checks = panel.querySelectorAll('input[data-pcm-check]');
			var saved = {};
			try { saved = JSON.parse(localStorage.getItem(key) || '{}'); } catch(e) { saved = {}; }
			checks.forEach(function(el){
				var id = el.getAttribute('data-pcm-check');
				if (saved[id]) el.checked = true;
				el.addEventListener('change', function(){
					saved[id] = !!el.checked;
					localStorage.setItem(key, JSON.stringify(saved));
				});
			});

			window.pcmPlaybookAjax = {
				url: ajaxUrl,
				nonce: nonce
			};
		})();

		function pcmPlaybookSwitchTab(panelId, tab) {
			void panelId; void tab;
		}

		function pcmPlaybookTriggerRescan() {
			var cfg = window.pcmPlaybookAjax || {};
			if (!cfg.url || !cfg.nonce) {
				alert('Unable to start scan: missing AJAX configuration.');
				return;
			}

			var params = new URLSearchParams();
			params.append('action', 'pcm_cacheability_scan_start');
			params.append('nonce', cfg.nonce);

			fetch(cfg.url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: params.toString()
			})
			.then(function(response){ return response.json(); })
			.then(function(payload){
				if (!payload || !payload.success || !payload.data || !payload.data.run_id) {
					throw new Error('Scan start failed.');
				}

				var runId = payload.data.run_id;
				var statusParams = new URLSearchParams();
				statusParams.append('action', 'pcm_cacheability_scan_status');
				statusParams.append('nonce', cfg.nonce);
				statusParams.append('run_id', String(runId));

				return fetch(cfg.url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: statusParams.toString()
				});
			})
			.then(function(response){ return response.json(); })
			.then(function(payload){
				var status = payload && payload.data && payload.data.run && payload.data.run.status ? payload.data.run.status : 'unknown';
				alert('Cacheability scan triggered. Current status: ' + status + '.');
			})
			.catch(function(){
				alert('Unable to trigger cacheability scan. Check permissions and feature flags.');
			});
		}
	</script>
	<?php

	return (string) ob_get_clean();
}


/**
 * Build a serialized playbook payload for UI use.
 *
 * @param array $playbook Playbook.
 *
 * @return array
 */
function pcm_playbook_build_payload( array $playbook ): array {
	$renderer = new PCM_Playbook_Renderer();

	return array(
		'meta'      => isset( $playbook['meta'] ) && is_array( $playbook['meta'] ) ? $playbook['meta'] : array(),
		'body'      => (string) ( $playbook['body'] ?? '' ),
		'html_body' => $renderer->render( $playbook['body'] ?? '' ),
	);
}

/**
 * AJAX: Lookup playbook for a finding rule.
 *
 * @return void
 */
function pcm_ajax_playbook_lookup(): void {
	pcm_playbooks_verify_ajax( 'pcm_view_diagnostics' );

	$rule_id = sanitize_key( pcm_playbooks_request_value( 'rule_id' ) );
	if ( '' === $rule_id ) {
		wp_send_json_error( array( 'message' => 'Missing rule_id.' ), 400 );
	}

	$lookup = new PCM_Playbook_Lookup_Service();
	$result = $lookup->lookup_for_finding( $rule_id );

	if ( empty( $result['available'] ) || empty( $result['playbook'] ) ) {
		wp_send_json_success(
			array(
				'available' => false,
				'reason'    => $result['reason'] ?? 'no_playbook',
			)
		);
	}

	$playbook = $result['playbook'];
	$store    = new PCM_Playbook_Progress_Store();
	$state    = $store->get_state( $playbook['meta']['playbook_id'] ?? '' );

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'playbook_lookup', 'guided_playbooks', array( 'rule_id' => $rule_id ) );
	}

	wp_send_json_success(
		array(
			'available' => true,
			'playbook'  => pcm_playbook_build_payload( $playbook ),
			'progress'  => $state,
		)
	);
}
add_action( 'wp_ajax_pcm_playbook_lookup', 'pcm_ajax_playbook_lookup' );

/**
 * AJAX: Save playbook checklist progress.
 *
 * @return void
 */
function pcm_ajax_playbook_progress_save(): void {
	pcm_playbooks_verify_ajax( 'pcm_view_diagnostics' );

	$playbook_id = sanitize_key( pcm_playbooks_request_value( 'playbook_id' ) );
	if ( '' === $playbook_id ) {
		wp_send_json_error( array( 'message' => 'Missing playbook_id.' ), 400 );
	}

	$checklist_raw = pcm_playbooks_request_value( 'checklist' );
	$checklist     = json_decode( $checklist_raw, true );

	if ( ! is_array( $checklist ) ) {
		$checklist = array();
	}

	$store = new PCM_Playbook_Progress_Store();
	$state = $store->save_checklist( $playbook_id, $checklist );

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log( 'playbook_progress_saved', 'guided_playbooks', array( 'playbook_id' => $playbook_id ) );
	}

	wp_send_json_success(
		array(
			'playbook_id' => $playbook_id,
			'progress'    => $state,
		)
	);
}
add_action( 'wp_ajax_pcm_playbook_progress_save', 'pcm_ajax_playbook_progress_save' );

/**
 * AJAX: Run post-fix verification for a playbook.
 *
 * @return void
 */
function pcm_ajax_playbook_verify(): void {
	pcm_playbooks_verify_ajax( 'pcm_run_scans' );

	if ( ! function_exists( 'pcm_cacheability_advisor_is_enabled' ) || ! pcm_cacheability_advisor_is_enabled() ) {
		wp_send_json_error( array( 'message' => 'Cacheability Advisor is disabled.' ), 400 );
	}

	$playbook_id = sanitize_key( pcm_playbooks_request_value( 'playbook_id' ) );
	$rule_id     = sanitize_key( pcm_playbooks_request_value( 'rule_id' ) );
	if ( '' === $playbook_id || '' === $rule_id ) {
		wp_send_json_error( array( 'message' => 'Missing playbook_id or rule_id.' ), 400 );
	}

	$service = new PCM_Cacheability_Advisor_Run_Service();
	$run_id  = $service->start_scan();

	if ( ! $run_id ) {
		wp_send_json_error( array( 'message' => 'Unable to start verification scan.' ), 500 );
	}

	if ( function_exists( 'pcm_audit_log' ) ) {
		pcm_audit_log(
			'playbook_verification_run',
			'guided_playbooks',
			array(
				'playbook_id' => $playbook_id,
				'run_id'      => (int) $run_id,
			)
		);
	}

	$queue = get_transient( 'pcm_scan_queue_' . $run_id );

	wp_send_json_success(
		array(
			'playbook_id' => $playbook_id,
			'run_id'      => (int) $run_id,
			'status'      => 'queued',
			'remaining'   => is_array( $queue ) ? count( $queue ) : 0,
			'rule_id'     => $rule_id,
		)
	);
}
add_action( 'wp_ajax_pcm_playbook_verify', 'pcm_ajax_playbook_verify' );
