<?php
/**
 * Guided Remediation Playbooks (Pillar 8).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Feature gate for guided remediation playbooks.
 *
 * @return bool
 */
function pcm_guided_playbooks_is_enabled(): bool {
    static $cached = null;
    if ( $cached === null ) {
        $enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
        $cached  = (bool) apply_filters( 'pcm_enable_guided_playbooks', $enabled );
    }

    return $cached;
}

/**
 * Playbook repository backed by bundled markdown files.
 */
class PCM_Playbook_Repository {
    private ?array $playbooks_cache = null;
    private ?array $id_index = null;
    private ?array $rule_index = null;

    /**
     * @return string
     */
    protected function get_playbooks_dir(): string {
        return plugin_dir_path( __FILE__ ) . 'playbooks/';
    }

    /**
     * @return array
     */
    public function list_playbooks(): array {
        if ( null !== $this->playbooks_cache ) {
            return $this->playbooks_cache;
        }

        $paths = glob( $this->get_playbooks_dir() . '*.md' );

        if ( ! is_array( $paths ) ) {
            $this->playbooks_cache = array();
            return $this->playbooks_cache;
        }

        $rows = array();

        foreach ( $paths as $path ) {
            // Validate the file is inside the expected playbooks directory
            $real = realpath( $path );
            $base = realpath( $this->get_playbooks_dir() );
            if ( false === $real || false === $base || ! str_starts_with( $real, $base ) ) {
                continue;
            }

            $playbook = $this->read_playbook_file( $path );
            if ( ! empty( $playbook ) ) {
                $rows[] = $playbook;
            }
        }

        $this->playbooks_cache = $rows;

        return $rows;
    }

    /**
     * @param string $playbook_id Playbook ID.
     *
     * @return array|null
     */
    public function get_by_id( string $playbook_id ): ?array {
        $playbook_id = sanitize_key( $playbook_id );
        $index       = $this->get_id_index();

        return $index[ $playbook_id ] ?? null;
    }

    /**
     * @param string $rule_id Rule ID.
     *
     * @return array|null
     */
    public function get_by_rule_id( string $rule_id ): ?array {
        $rule_id = sanitize_key( $rule_id );
        $index   = $this->get_rule_index();

        return $index[ $rule_id ] ?? null;
    }

    /**
     * Build a keyed index of playbooks by playbook_id for O(1) lookup.
     *
     * @return array<string, array>
     */
    protected function get_id_index(): array {
        if ( null !== $this->id_index ) {
            return $this->id_index;
        }

        $this->id_index = array();
        foreach ( $this->list_playbooks() as $playbook ) {
            if ( isset( $playbook['meta']['playbook_id'] ) ) {
                $this->id_index[ $playbook['meta']['playbook_id'] ] = $playbook;
            }
        }

        return $this->id_index;
    }

    /**
     * Build a keyed index of playbooks by rule_id for O(1) lookup.
     *
     * @return array<string, array>
     */
    protected function get_rule_index(): array {
        if ( null !== $this->rule_index ) {
            return $this->rule_index;
        }

        $this->rule_index = array();
        foreach ( $this->list_playbooks() as $playbook ) {
            $rule_ids = isset( $playbook['meta']['rule_ids'] ) && is_array( $playbook['meta']['rule_ids'] ) ? $playbook['meta']['rule_ids'] : array();
            foreach ( $rule_ids as $rid ) {
                $this->rule_index[ $rid ] = $playbook;
            }
        }

        return $this->rule_index;
    }

    /**
     * @param string $path Path.
     *
     * @return array
     */
    protected function read_playbook_file( string $path ): array {
        $content = file_get_contents( $path );

        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            return array();
        }

        $meta = $this->parse_meta_json( $content );

        if ( empty( $meta['playbook_id'] ) ) {
            return array();
        }

        $body = preg_replace( '/\A\/\*PCM_PLAYBOOK_META\n.*?\nPCM_PLAYBOOK_META\*\/\n/s', '', $content );

        return array(
            'meta' => $meta,
            'body' => is_string( $body ) ? trim( $body ) : '',
        );
    }

    /**
     * @param string $content File content.
     *
     * @return array
     */
    protected function parse_meta_json( string $content ): array {
        if ( ! preg_match( '/\A\/\*PCM_PLAYBOOK_META\n(.*?)\nPCM_PLAYBOOK_META\*\//s', $content, $matches ) ) {
            return array();
        }

        $json = json_decode( trim( $matches[1] ), true );

        if ( ! is_array( $json ) ) {
            return array();
        }

        $json['playbook_id'] = isset( $json['playbook_id'] ) ? sanitize_key( $json['playbook_id'] ) : '';
        $json['version']     = isset( $json['version'] ) ? sanitize_text_field( $json['version'] ) : '1.0.0';
        $json['severity']    = isset( $json['severity'] ) ? sanitize_key( $json['severity'] ) : 'warning';
        $json['title']       = isset( $json['title'] ) ? sanitize_text_field( $json['title'] ) : '';

        $json['rule_ids'] = isset( $json['rule_ids'] ) && is_array( $json['rule_ids'] )
            ? array_values( array_filter( array_map( 'sanitize_key', $json['rule_ids'] ) ) )
            : array();

        $json['audiences'] = isset( $json['audiences'] ) && is_array( $json['audiences'] )
            ? array_values( array_filter( array_map( 'sanitize_key', $json['audiences'] ) ) )
            : array();

        return $json;
    }
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
 * Persisted progress storage for playbook checklist + verification.
 */
class PCM_Playbook_Progress_Store {
    const OPTION_KEY = 'pcm_playbook_progress_v1';

    /**
     * @return array
     */
    protected function all(): array {
        $raw = get_option( self::OPTION_KEY, array() );

        return is_array( $raw ) ? $raw : array();
    }

    /**
     * @param array $rows Rows.
     */
    protected function save_all( array $rows ): void {
        update_option( self::OPTION_KEY, is_array( $rows ) ? $rows : array(), false );
    }

    /**
     * @param string $playbook_id Playbook identifier.
     *
     * @return array
     */
    public function get_state( string $playbook_id ): array {
        $playbook_id = sanitize_key( $playbook_id );
        $rows        = $this->all();

        if ( empty( $rows[ $playbook_id ] ) || ! is_array( $rows[ $playbook_id ] ) ) {
            return array(
                'checklist'    => array(),
                'verification' => array(),
            );
        }

        return $rows[ $playbook_id ];
    }

    /**
     * @param string $playbook_id Playbook identifier.
     * @param array  $checklist   Checklist values.
     *
     * @return array
     */
    public function save_checklist( string $playbook_id, array $checklist ): array {
        $playbook_id = sanitize_key( $playbook_id );
        $rows        = $this->all();
        $state       = isset( $rows[ $playbook_id ] ) && is_array( $rows[ $playbook_id ] ) ? $rows[ $playbook_id ] : array();
        $clean       = array();

        foreach ( $checklist as $step => $complete ) {
            $step          = sanitize_key( $step );
            $clean[ $step ] = (bool) $complete;
        }

        $state['checklist']    = $clean;
        $state['updated_at']   = gmdate( 'c' );
        $rows[ $playbook_id ]  = $state;

        $this->save_all( $rows );

        return $state;
    }

    /**
     * @param string $playbook_id Playbook identifier.
     * @param array  $verification Verification payload.
     *
     * @return array
     */
    public function save_verification( string $playbook_id, array $verification ): array {
        $playbook_id = sanitize_key( $playbook_id );
        $rows        = $this->all();
        $state       = isset( $rows[ $playbook_id ] ) && is_array( $rows[ $playbook_id ] ) ? $rows[ $playbook_id ] : array();

        $state['verification'] = array(
            'status'      => ! empty( $verification['status'] ) ? sanitize_key( $verification['status'] ) : 'unknown',
            'run_id'      => isset( $verification['run_id'] ) ? absint( $verification['run_id'] ) : 0,
            'rule_id'     => isset( $verification['rule_id'] ) ? sanitize_key( $verification['rule_id'] ) : '',
            'checked_at'  => gmdate( 'c' ),
            'message'     => isset( $verification['message'] ) ? sanitize_text_field( $verification['message'] ) : '',
        );
        $state['updated_at']   = gmdate( 'c' );
        $rows[ $playbook_id ]  = $state;

        $this->save_all( $rows );

        return $state;
    }
}

/**
 * Rule-to-playbook lookup service.
 */
class PCM_Playbook_Lookup_Service {
    protected readonly PCM_Playbook_Repository $repository;

    public function __construct(
        ?PCM_Playbook_Repository $repository = null,
    ) {
        $this->repository = $repository ?? new PCM_Playbook_Repository();
    }

    /**
     * @param string $rule_id Rule ID.
     *
     * @return array
     */
    public function lookup_for_finding( string $rule_id ): array {
        if ( ! pcm_guided_playbooks_is_enabled() ) {
            return array(
                'available' => false,
                'reason'    => 'feature_disabled',
            );
        }

        $playbook = $this->repository->get_by_rule_id( $rule_id );

        if ( empty( $playbook ) ) {
            return array(
                'available' => false,
                'reason'    => 'no_playbook',
            );
        }

        return array(
            'available' => true,
            'playbook'  => $playbook,
        );
    }
}

/**
 * Safe markdown renderer for admin panel.
 */
class PCM_Playbook_Renderer {
    /**
     * Render ordered lists with support for indented bullet sub-items.
     *
     * @param string $text Escaped markdown.
     *
     * @return string
     */
    protected function render_ordered_lists( string $text ): string {
        $lines = preg_split( '/\r\n|\r|\n/', $text );

        if ( ! is_array( $lines ) ) {
            return $text;
        }

        $output      = array();
        $in_ordered  = false;
        $ordered_buf = '';
        $count       = count( $lines );

        for ( $i = 0; $i < $count; $i++ ) {
            $line = $lines[ $i ];

            if ( preg_match( '/^\d+\.\s+(.+)$/', trim( $line ), $match ) ) {
                if ( ! $in_ordered ) {
                    $ordered_buf = '<ol>';
                    $in_ordered  = true;
                }

                $item_text = $match[1];
                $subitems  = array();

                for ( $j = $i + 1; $j < $count; $j++ ) {
                    $next = $lines[ $j ];

                    if ( preg_match( '/^\s*-\s+(.+)$/', $next, $sub_match ) ) {
                        $subitems[] = $sub_match[1];
                        continue;
                    }

                    if ( '' === trim( $next ) ) {
                        continue;
                    }

                    break;
                }

                $ordered_buf .= '<li>' . $item_text;

                if ( ! empty( $subitems ) ) {
                    $ordered_buf .= '<ul>';
                    foreach ( $subitems as $subitem ) {
                        $ordered_buf .= '<li>' . $subitem . '</li>';
                    }
                    $ordered_buf .= '</ul>';
                }

                $ordered_buf .= '</li>';

                if ( ! empty( $subitems ) ) {
                    $i = $j - 1;
                }

                continue;
            }

            if ( $in_ordered ) {
                $ordered_buf .= '</ol>';
                $output[]     = $ordered_buf;
                $ordered_buf  = '';
                $in_ordered   = false;
            }

            $output[] = $line;
        }

        if ( $in_ordered ) {
            $ordered_buf .= '</ol>';
            $output[]     = $ordered_buf;
        }

        return implode( "\n", $output );
    }

    /**
     * @param string $markdown Markdown.
     *
     * @return string
     */
    public function render( string $markdown ): string {
        $escaped  = esc_html( $markdown );

        $escaped = preg_replace( '/^###\s+(.+)$/m', '<h4>$1</h4>', $escaped );
        $escaped = preg_replace( '/^##\s+(.+)$/m', '<h3>$1</h3>', $escaped );
        $escaped = preg_replace( '/^#\s+(.+)$/m', '<h2>$1</h2>', $escaped );
        $escaped = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped );
        $escaped = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $escaped );

        $escaped = $this->render_ordered_lists( $escaped );

        $escaped = preg_replace_callback(
            '/(?:^|\n)((?:-\s+[^\n]+(?:\n|$))+)/',
            static function ( array $matches ): string {
                $lines = preg_split( '/\n+/', trim( $matches[1] ) );
                $items = array();

                foreach ( $lines as $line ) {
                    if ( preg_match( '/^-\s+(.+)$/', trim( $line ), $item_match ) ) {
                        $items[] = $item_match[1];
                    }
                }

                if ( empty( $items ) ) {
                    return $matches[0];
                }

                $out = '<ul>';
                foreach ( $items as $item ) {
                    $out .= '<li>' . $item . '</li>';
                }
                $out .= '</ul>';

                return "\n" . $out . "\n";
            },
            $escaped
        );

        $escaped = wpautop( $escaped );

        return wp_kses(
            $escaped,
            array(
                'h2'     => array(),
                'h3'     => array(),
                'h4'     => array(),
                'strong' => array(),
                'code'   => array(),
                'p'      => array(),
                'br'     => array(),
                'ol'     => array(),
                'ul'     => array(),
                'li'     => array(),
            )
        );
    }
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

    $rule_id = isset( $_REQUEST['rule_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['rule_id'] ) ) : '';
    if ( '' === $rule_id ) {
        wp_send_json_error( array( 'message' => 'Missing rule_id.' ), 400 );
    }

    $lookup = new PCM_Playbook_Lookup_Service();
    $result = $lookup->lookup_for_finding( $rule_id );

    if ( empty( $result['available'] ) || empty( $result['playbook'] ) ) {
        wp_send_json_success( array( 'available' => false, 'reason' => $result['reason'] ?? 'no_playbook' ) );
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

    $playbook_id = isset( $_REQUEST['playbook_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['playbook_id'] ) ) : '';
    if ( '' === $playbook_id ) {
        wp_send_json_error( array( 'message' => 'Missing playbook_id.' ), 400 );
    }

    $checklist_raw = isset( $_REQUEST['checklist'] ) ? wp_unslash( $_REQUEST['checklist'] ) : '';
    $checklist     = is_string( $checklist_raw ) ? json_decode( $checklist_raw, true ) : array();

    if ( ! is_array( $checklist ) ) {
        $checklist = array();
    }

    $store = new PCM_Playbook_Progress_Store();
    $state = $store->save_checklist( $playbook_id, $checklist );

    if ( function_exists( 'pcm_audit_log' ) ) {
        pcm_audit_log( 'playbook_progress_saved', 'guided_playbooks', array( 'playbook_id' => $playbook_id ) );
    }

    wp_send_json_success( array( 'playbook_id' => $playbook_id, 'progress' => $state ) );
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

    $playbook_id = isset( $_REQUEST['playbook_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['playbook_id'] ) ) : '';
    $rule_id     = isset( $_REQUEST['rule_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['rule_id'] ) ) : '';
    if ( '' === $playbook_id || '' === $rule_id ) {
        wp_send_json_error( array( 'message' => 'Missing playbook_id or rule_id.' ), 400 );
    }

    $service    = new PCM_Cacheability_Advisor_Run_Service();
    $repository = new PCM_Cacheability_Advisor_Repository();
    $run_id     = $service->start_and_execute_scan();

    if ( ! $run_id ) {
        wp_send_json_error( array( 'message' => 'Unable to execute verification scan.' ), 500 );
    }

    $findings      = $repository->list_findings( $run_id );
    $rule_still_on = false;

    foreach ( $findings as $finding ) {
        if ( isset( $finding['rule_id'] ) && sanitize_key( $finding['rule_id'] ) === $rule_id ) {
            $rule_still_on = true;
            break;
        }
    }

    $status  = $rule_still_on ? 'failing' : 'passed';
    $message = $rule_still_on
        ? 'Rule is still present after verification scan.'
        : 'Rule did not appear in verification scan.';

    $store = new PCM_Playbook_Progress_Store();
    $state = $store->save_verification(
        $playbook_id,
        array(
            'status'  => $status,
            'run_id'  => $run_id,
            'rule_id' => $rule_id,
            'message' => $message,
        )
    );

    if ( function_exists( 'pcm_audit_log' ) ) {
        pcm_audit_log( 'playbook_verification_run', 'guided_playbooks', array( 'playbook_id' => $playbook_id, 'run_id' => (int) $run_id, 'status' => $status ) );
    }

    wp_send_json_success(
        array(
            'playbook_id' => $playbook_id,
            'run_id'      => (int) $run_id,
            'status'      => $status,
            'message'     => $message,
            'progress'    => $state,
        )
    );
}
add_action( 'wp_ajax_pcm_playbook_verify', 'pcm_ajax_playbook_verify' );
