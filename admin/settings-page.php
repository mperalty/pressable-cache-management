<?php
/**
 * Pressable Cache Management - Settings Page (Redesigned v3)
 * Fixes: double notices, visible timestamps, red→green hover button,
 *        correct feature list matching official repo, branded notices.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pcm_settings_current_page(): string {
	$page = filter_input( INPUT_GET, 'page', FILTER_UNSAFE_RAW );

	return is_string( $page ) ? sanitize_key( $page ) : '';
}

function pcm_settings_current_tab(): ?string {
	$tab = filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW );

	if ( ! is_string( $tab ) || '' === $tab ) {
		return null;
	}

	return sanitize_key( $tab );
}

function pcm_settings_post_flag( string $key ): bool {
	$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW );

	return is_string( $value ) && '1' === $value;
}

// ─── Kill WP's default "Settings saved." on our page – one branded notice only ──
// Priority 0 runs before settings_errors (priority 10), so we can remove it first.
add_action( 'admin_notices', 'pcm_kill_default_settings_notice', 0 );
function pcm_kill_default_settings_notice() {
	if ( 'pressable_cache_management' !== pcm_settings_current_page() ) {
		return;
	}
	remove_action( 'admin_notices', 'settings_errors', 10 );
}

add_action( 'admin_notices', 'pcm_branded_settings_saved_notice', 5 );
function pcm_branded_settings_saved_notice() {
	$settings_updated = filter_input( INPUT_GET, 'settings-updated', FILTER_UNSAFE_RAW );

	if ( 'pressable_cache_management' !== pcm_settings_current_page() ) {
		return;
	}
	if ( 'true' !== sanitize_key( (string) $settings_updated ) ) {
		return;
	}

	$id = 'pcm-settings-saved-notice';
	echo '<div id="' . esc_attr( $id ) . '" class="pcm-settings-saved-notice">';
	echo '<p class="pcm-branded-notice-text">'
		. esc_html__( 'Cache settings updated.', 'pressable_cache_management' ) . '</p>';
	echo '<button type="button" aria-label="Dismiss notification" data-pcm-dismiss="' . esc_attr( $id ) . '" class="pcm-settings-saved-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button>';
	echo '</div>';
	echo '<script>document.querySelector(\'[data-pcm-dismiss="' . esc_js( $id ) . '"]\').addEventListener("click",function(){document.getElementById("' . esc_js( $id ) . '").remove();});</script>';
}

// ─── "Extending Batcache" notice — shows ONCE after first enable, then never again ─
// extend_batcache.php sets 'pcm_extend_batcache_notice_pending' only when it copies
// the mu-plugin for the first time (fresh enable). We delete the flag here immediately
// after rendering, so any subsequent page load or refresh will never see it again.
add_action( 'admin_notices', 'pcm_extend_batcache_branded_notice' );
function pcm_extend_batcache_branded_notice() {
	if ( 'pressable_cache_management' !== pcm_settings_current_page() ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only show if freshly enabled
	if ( '1' !== get_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value ) ) {
		return;
	}

	// Delete flag IMMEDIATELY — refresh / navigation will never trigger this again
	delete_option( PCM_Options::EXTEND_BATCACHE_NOTICE_PENDING->value );

	echo '<div class="pcm-batcache-notice-outer">';
	echo '<div id="pcm-extend-batcache-notice" class="pcm-branded-notice pcm-branded-notice-edge">';
	echo '<p class="pcm-branded-notice-text">'
		. esc_html__( 'Extending Batcache for 24 hours — see ', 'pressable_cache_management' )
		. '<a href="https://pressable.com/knowledgebase/modifying-cache-times-batcache/" target="_blank" rel="noopener noreferrer" '
		. 'class="pcm-branded-notice-link">'
		. esc_html__( 'Modifying Batcache Times.', 'pressable_cache_management' ) . '</a>'
		. '</p>';
	echo '<button type="button" aria-label="Dismiss notification" onclick="document.getElementById(\'pcm-extend-batcache-notice\').remove();" '
		. 'class="pcm-branded-notice-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button>';
	echo '</div>';
	echo '</div>';
}

// ─── Helper: pcm_branded_notice (shared, safe) ───────────────────────────────
if ( ! function_exists( 'pcm_branded_notice' ) ) {
	function pcm_branded_notice( $message, $border_color = '#03fcc2', $is_html = false ) {
		$id = 'pcm-notice-' . substr( md5( $message . $border_color . microtime() ), 0, 8 );
		echo '<div id="' . esc_attr( $id ) . '" class="pcm-branded-notice" style="border-left-color:' . esc_attr( $border_color ) . ';"><div class="pcm-branded-notice-body">';
		if ( $is_html ) {
			echo wp_kses_post( $message );
		} else {
			echo '<p class="pcm-branded-notice-text">' . esc_html( $message ) . '</p>';
		}
		echo '</div><button type="button" aria-label="Dismiss notification" onclick="document.getElementById(\'' . esc_js( $id ) . '\').remove();" class="pcm-branded-notice-close"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></button></div>';
	}
}

// ─── Batcache status check ───────────────────────────────────────────────────
// WHY BROWSER-SIDE: wp_remote_get() is a server-side loopback request. Pressable's
// infrastructure routes loopback requests directly to PHP, bypassing the Batcache/CDN
// layer entirely — so x-nananana is never present regardless of real cache state.
// The browser is the only client that sees the actual CDN response headers.
// SOLUTION: JS fetches the homepage with cache:reload (forces a fresh CDN response)
// and reads x-nananana directly, then reports the result back to PHP via AJAX.
// PHP only stores/returns the transient — it never probes the URL itself.

function pcm_get_batcache_status() {
	$cached = get_transient( 'pcm_batcache_status' );
	return false !== $cached ? $cached : 'unknown';
}

// ── AJAX: browser reports the header value it observed ───────────────────────
function pcm_ajax_report_batcache_header() {
	pcm_verify_ajax_request( 'nonce', 'pcm_batcache_nonce' );

	$raw           = filter_input( INPUT_POST, 'x_nananana', FILTER_UNSAFE_RAW );
	$is_cloudflare = filter_input( INPUT_POST, 'is_cloudflare', FILTER_UNSAFE_RAW );
	$val           = strtolower( trim( sanitize_text_field( is_string( $raw ) ? $raw : '' ) ) );

	$status = match ( true ) {
		str_contains( $val, 'batcache' ) => 'active',
		'1' === (string) $is_cloudflare => 'cloudflare',
		default                         => 'broken',
	};

	// Active: 24 hrs — prevents the badge falsely flipping to broken after 5 min.
	// Broken: 2 min — re-probe frequently until resolved.
	$ttl = 'active' === $status ? 86400 : 120;
	set_transient( 'pcm_batcache_status', $status, $ttl );

	$labels = array(
		'active'     => __( 'Batcache Active', 'pressable_cache_management' ),
		'cloudflare' => __( 'Cloudflare Detected', 'pressable_cache_management' ),
		'broken'     => __( 'Batcache Broken', 'pressable_cache_management' ),
	);

	wp_send_json_success(
		array(
			'status' => $status,
			'label'  => $labels[ $status ],
		)
	);
}
add_action( 'wp_ajax_pcm_report_batcache_header', 'pcm_ajax_report_batcache_header' );

// ── AJAX: return current stored status (for badge refresh without re-fetching) ─
function pcm_ajax_get_batcache_status() {
	check_ajax_referer( 'pcm_batcache_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$status = pcm_get_batcache_status();
	$labels = array(
		'active'     => __( 'Batcache Active', 'pressable_cache_management' ),
		'cloudflare' => __( 'Cloudflare Detected', 'pressable_cache_management' ),
		'broken'     => __( 'Batcache Broken', 'pressable_cache_management' ),
		'unknown'    => __( 'Batcache Broken', 'pressable_cache_management' ),
	);

	wp_send_json_success(
		array(
			'status' => $status,
			'label'  => $labels[ $status ] ?? $labels['broken'],
		)
	);
}
add_action( 'wp_ajax_pcm_get_batcache_status', 'pcm_ajax_get_batcache_status' );

// Keep the old action name as an alias so any cached JS still works
add_action( 'wp_ajax_pcm_refresh_batcache_status', 'pcm_ajax_get_batcache_status' );

// ── AJAX: toggle Deep Dive diagnostics without page reload ────────────────────
function pcm_ajax_toggle_caching_suite_features() {
	check_ajax_referer( 'pcm_toggle_caching_suite_features', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'pressable_cache_management' ) ), 403 );
	}

	$enabled = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );
	update_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, $enabled, false );

	wp_send_json_success(
		array(
			'enabled' => $enabled,
			'label'   => $enabled
				? __( 'Deep Dive diagnostics enabled', 'pressable_cache_management' )
				: __( 'Deep Dive diagnostics disabled', 'pressable_cache_management' ),
		)
	);
}
add_action( 'wp_ajax_pcm_toggle_caching_suite_features', 'pcm_ajax_toggle_caching_suite_features' );

/**
 * AJAX: refresh the cacheability nonce for long-lived Deep Dive sessions.
 *
 * @return void
 */
function pcm_ajax_refresh_cacheability_nonce(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized', 'pressable_cache_management' ) ), 403 );
	}

	wp_send_json_success(
		array(
			'nonce' => wp_create_nonce( 'pcm_cacheability_scan' ),
		)
	);
}
add_action( 'wp_ajax_pcm_refresh_cacheability_nonce', 'pcm_ajax_refresh_cacheability_nonce' );

/**
 * Clear the cached status immediately after any cache flush
 * so the badge re-checks on next page load.
 */
function pcm_clear_batcache_status_transient() {
	delete_transient( 'pcm_batcache_status' );
}
add_action( 'pcm_after_object_cache_flush', 'pcm_clear_batcache_status_transient' );
add_action( 'pcm_after_batcache_flush', 'pcm_clear_batcache_status_transient' );
// Also clear when edge cache is fully purged — Batcache is implicitly invalidated too,
// so the next probe will correctly detect the transitional 'broken' state.
add_action( 'pcm_after_edge_cache_purge', 'pcm_clear_batcache_status_transient' );

// ─── Main page ───────────────────────────────────────────────────────────────
function pressable_cache_management_display_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( pcm_verify_request( 'pcm_feature_flags_nonce', 'pcm_save_feature_flags' ) ) {
		$caching_suite_enabled = pcm_settings_post_flag( 'pcm_enable_caching_suite_features' );
		$advanced_scan_enabled = pcm_settings_post_flag( 'pcm_enable_advanced_scan_workflows' );
		$microcache_enabled    = pcm_settings_post_flag( 'pcm_enable_durable_origin_microcache' );

		update_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, $caching_suite_enabled, false );
		update_option( PCM_Options::ENABLE_ADVANCED_SCAN_WORKFLOWS->value, $advanced_scan_enabled, false );
		update_option( PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value, $microcache_enabled, false );
	}

	$caching_suite_enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );
	$advanced_scan_enabled = (bool) get_option( PCM_Options::ENABLE_ADVANCED_SCAN_WORKFLOWS->value, false );
	$tab                   = pcm_settings_current_tab();

	$is_deep_dive_disabled = ( 'deep_dive_tab' === $tab && ! $caching_suite_enabled );

	if ( $is_deep_dive_disabled ) {
		$tab = null;
	}

	$is_object_tab    = ( null === $tab );
	$is_deep_dive_tab = ( 'deep_dive_tab' === $tab );
	$is_settings_tab  = ( 'settings_tab' === $tab );

	$branding_opts              = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
	$show_branding              = ! (
		is_array( $branding_opts )
		&& isset( $branding_opts['branding_on_off_radio_button'] )
		&& 'disable' === $branding_opts['branding_on_off_radio_button']
	);
	$pcm_module_available       = array(
		'cacheability' => function_exists( 'pcm_cacheability_advisor_is_enabled' ),
	);
	$bc_status                  = pcm_get_batcache_status();
	$bc_is_unknown              = ( 'unknown' === $bc_status );

	$css_path         = dirname( __DIR__ ) . '/public/css/style.css';
	$plugin_main_path = dirname( __DIR__ ) . '/pressable-cache-management.php';
	wp_enqueue_style(
		'pressable_cache_management',
		plugins_url( 'public/css/style.css', dirname( __DIR__ ) . '/pressable-cache-management.php' ),
		array(),
		file_exists( $css_path ) ? (string) filemtime( $css_path ) : '3.0.0',
		'screen'
	);
	wp_enqueue_style(
		'pcm-google-fonts',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
		array(),
		file_exists( $plugin_main_path ) ? (string) filemtime( $plugin_main_path ) : '1.0.0'
	);

	$base_js_url  = plugin_dir_url( __FILE__ ) . 'public/js/';
	$base_js_path = plugin_dir_path( __FILE__ ) . 'public/js/';

	wp_enqueue_script(
		'pcm-utils',
		$base_js_url . 'pcm-utils.js',
		array(),
		file_exists( $base_js_path . 'pcm-utils.js' ) ? (string) filemtime( $base_js_path . 'pcm-utils.js' ) : false,
		true
	);

	wp_enqueue_script(
		'pcm-modal-a11y',
		$base_js_url . 'pcm-modal-a11y.js',
		array(),
		file_exists( $base_js_path . 'pcm-modal-a11y.js' ) ? (string) filemtime( $base_js_path . 'pcm-modal-a11y.js' ) : false,
		true
	);

	wp_enqueue_script(
		'pcm-post',
		$base_js_url . 'pcm-post.js',
		array( 'pcm-utils' ),
		file_exists( $base_js_path . 'pcm-post.js' ) ? (string) filemtime( $base_js_path . 'pcm-post.js' ) : false,
		true
	);

	wp_enqueue_script(
		'pcm-settings-page-settings',
		$base_js_url . 'settings.js',
		array( 'jquery', 'pcm-post', 'pcm-modal-a11y', 'pcm-utils' ),
		file_exists( $base_js_path . 'settings.js' ) ? (string) filemtime( $base_js_path . 'settings.js' ) : false,
		true
	);

	// Only load tab-specific JS when the corresponding tab is active.
	// This avoids loading ~2,300 lines of JS on tabs that don't need them.
	if ( $is_object_tab ) {
		wp_enqueue_script(
			'pcm-settings-page-object-cache',
			$base_js_url . 'object-cache-tab.js',
			array( 'pcm-post' ),
			file_exists( $base_js_path . 'object-cache-tab.js' ) ? (string) filemtime( $base_js_path . 'object-cache-tab.js' ) : false,
			true
		);
	}

	if ( $is_deep_dive_tab ) {
		wp_enqueue_script(
			'pcm-settings-page-deep-dive',
			$base_js_url . 'deep-dive.js',
			array( 'pcm-post' ),
			file_exists( $base_js_path . 'deep-dive.js' ) ? (string) filemtime( $base_js_path . 'deep-dive.js' ) : false,
			true
		);
	}

	if ( $is_deep_dive_tab ) {
		wp_enqueue_script(
			'pcm-layered-probe',
			$base_js_url . 'layered-probe.js',
			array( 'pcm-post', 'pcm-utils', 'pcm-settings-page-deep-dive' ),
			file_exists( $base_js_path . 'layered-probe.js' ) ? (string) filemtime( $base_js_path . 'layered-probe.js' ) : false,
			true
		);
	}

	wp_localize_script(
		'pcm-settings-page-settings',
		'pcmSettingsData',
		array(
			'nonces'          => array(
				'cacheabilityScan'  => wp_create_nonce( 'pcm_cacheability_scan' ),
				'edgeCacheToggle'   => wp_create_nonce( 'pcm_edge_cache_toggle' ),
			),
			'strings'         => array(
				'failedRetrieveStatus' => __( 'Failed to retrieve status.', 'pressable_cache_management' ),
				'couldNotConnect'      => __( 'Could not connect to server.', 'pressable_cache_management' ),
				'flushFailed'          => __( 'Object cache flush failed. Please try again.', 'pressable_cache_management' ),
				'flushUnauthorized'    => __( 'Unauthorized request.', 'pressable_cache_management' ),
			),
		)
	);

	// Read Batcache max_age from the global if available (set by advanced-cache / mu-plugin).
	$pcm_batcache_max_age = null;
	global $batcache;
	if ( is_object( $batcache ) && isset( $batcache->max_age ) ) {
		$pcm_batcache_max_age = (int) $batcache->max_age;
	} elseif ( is_array( $batcache ) && isset( $batcache['max_age'] ) ) {
		$pcm_batcache_max_age = (int) $batcache['max_age'];
	} elseif ( file_exists( WP_CONTENT_DIR . '/mu-plugins/pressable-cache-management/pcm_extend_batcache.php' ) ) {
		// Extend-batcache mu-plugin is active — max_age is 86400.
		$pcm_batcache_max_age = 86400;
	}

	if ( $is_object_tab ) {
		wp_localize_script(
			'pcm-settings-page-object-cache',
			'pcmObjectCacheData',
			array(
				'nonces'         => array(
					'batcache' => wp_create_nonce( 'pcm_batcache_nonce' ),
				),
				'siteUrl'        => trailingslashit( get_site_url() ),
				'isUnknown'      => $bc_is_unknown,
				'batcacheMaxAge' => $pcm_batcache_max_age,
				'strings'        => array(
					'alreadyChecking' => __( 'Already checking…', 'pressable_cache_management' ),
					'checking'        => __( 'Checking…', 'pressable_cache_management' ),
					'checkFailed'     => __( 'Check Failed', 'pressable_cache_management' ),
				),
			)
		);
	}

	if ( $is_deep_dive_tab ) {
		wp_localize_script(
			'pcm-settings-page-deep-dive',
			'pcmDeepDiveData',
			array(
				'nonces'         => array(
					'cacheabilityScan' => wp_create_nonce( 'pcm_cacheability_scan' ),
					'batcache'         => wp_create_nonce( 'pcm_batcache_nonce' ),
				),
				'siteUrl'        => trailingslashit( get_site_url() ),
				'batcacheMaxAge' => $pcm_batcache_max_age,
			)
		);
	}
	?>
	<div class="wrap pcm-wrap pcm-page-wrap">
	<div class="pcm-page-inner">
	<h1 class="pcm-sr-only"><?php echo esc_html( get_admin_page_title() ); ?></h1>


	<!-- ── Tabs ── -->
	<nav class="nav-tab-wrapper pcm-tab-nav" id="pcm-main-tab-nav">
		<a href="admin.php?page=pressable_cache_management"
			class="nav-tab <?php echo $is_object_tab ? 'nav-tab-active' : ''; ?>">Object Cache</a>
		<a href="admin.php?page=pressable_cache_management&tab=edge_cache_settings_tab"
			class="nav-tab <?php echo 'edge_cache_settings_tab' === $tab ? 'nav-tab-active' : ''; ?>">Edge Cache</a>
		<?php if ( $caching_suite_enabled ) : ?>
		<a href="admin.php?page=pressable_cache_management&tab=deep_dive_tab"
			class="nav-tab <?php echo $is_deep_dive_tab ? 'nav-tab-active' : ''; ?>" id="pcm-deep-dive-tab">Deep Dive</a>
		<?php endif; ?>
		<a href="admin.php?page=pressable_cache_management&tab=settings_tab"
			class="nav-tab <?php echo $is_settings_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
		<a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab"
			class="nav-tab nav-tab-hidden <?php echo 'remove_pressable_branding_tab' === $tab ? 'nav-tab-active' : ''; ?>">Branding</a>
	</nav>

	<?php if ( $is_deep_dive_disabled ) : ?>
	<div class="pcm-card pcm-deep-dive-disabled-notice">
		<span class="pcm-deep-dive-disabled-icon"><span class="dashicons dashicons-lock" aria-hidden="true"></span></span>
		<h3 class="pcm-deep-dive-disabled-title">
			<?php esc_html_e( 'Deep Dive is locked', 'pressable_cache_management' ); ?>
		</h3>
		<p class="pcm-deep-dive-disabled-text">
			<?php esc_html_e( 'Enable Deep Dive diagnostics in Settings to use Deep Dive.', 'pressable_cache_management' ); ?>
		</p>
		<a href="admin.php?page=pressable_cache_management&tab=settings_tab"
			class="pcm-btn-primary pcm-btn-inline-flex">
			<?php esc_html_e( 'Go to Settings', 'pressable_cache_management' ); ?>
		</a>
	</div>
	<?php endif; ?>

	<?php
	if ( $is_object_tab || $is_deep_dive_tab || $is_settings_tab ) :
		$options = pcm_get_options();

		// Batcache status badge
		$bc_status     = pcm_get_batcache_status();
		$bc_is_unknown = ( 'unknown' === $bc_status );
		$bc_label      = $bc_is_unknown
			? __( 'Checking…', 'pressable_cache_management' )
			: ( 'active' === $bc_status
				? __( 'Batcache Active', 'pressable_cache_management' )
				: ( 'cloudflare' === $bc_status
					? __( 'Cloudflare Detected', 'pressable_cache_management' )
					: __( 'Batcache Broken', 'pressable_cache_management' ) ) );
		$bc_class      = $bc_is_unknown ? 'checking' : ( 'active' === $bc_status ? 'active' : 'broken' );
		?>

		<?php if ( $is_settings_tab ) : ?>
			<?php $microcache_enabled = (bool) get_option( PCM_Options::ENABLE_DURABLE_ORIGIN_MICROCACHE->value, false ); ?>
	<div class="pcm-card pcm-card-mb">
		<h3 class="pcm-card-title"><span class="dashicons dashicons-flag pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Deep Dive Options', 'pressable_cache_management' ); ?></h3>
		<p class="pcm-text-muted-intro"><?php echo esc_html__( 'Toggle the focused Deep Dive tools exposed by this plugin. The active scope is Cacheability Advisor, Route Diagnosis, Layered Probe Runner, and Durable Origin Microcache.', 'pressable_cache_management' ); ?></p>
		<form method="post">
			<input type="hidden" name="pcm_feature_flags_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pcm_save_feature_flags' ) ); ?>" />
			<input type="hidden" id="pcm-caching-suite-toggle-nonce" value="<?php echo esc_attr( wp_create_nonce( 'pcm_toggle_caching_suite_features' ) ); ?>" />

			<div class="pcm-toggle-row">
				<label class="switch pcm-switch-label">
					<input type="checkbox" name="pcm_enable_caching_suite_features" id="pcm-caching-suite-toggle" value="1" <?php checked( $caching_suite_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Deep Dive Diagnostics', 'pressable_cache_management' ); ?>" />
					<span class="slider round"></span>
				</label>
				<div>
					<div class="pcm-toggle-title"><?php echo esc_html__( 'Deep Dive Diagnostics', 'pressable_cache_management' ); ?>
						<span class="pcm-status-badge <?php echo $caching_suite_enabled ? 'is-active' : 'is-inactive'; ?>" id="pcm-caching-suite-status-badge"><?php echo $caching_suite_enabled ? esc_html__( 'Active', 'pressable_cache_management' ) : esc_html__( 'Inactive', 'pressable_cache_management' ); ?></span>
					</div>
					<div class="pcm-toggle-desc"><?php echo esc_html__( 'Turns on Cacheability Advisor, Route Diagnosis, and Layered Probe Runner inside the Deep Dive tab.', 'pressable_cache_management' ); ?></div>
					<div class="pcm-feature-chips">
						<span class="pcm-chip-tooltip-wrap" tabindex="0" role="button" title="Analyzes headers and cache directives to highlight uncached opportunities." aria-describedby="pcm-chip-desc-1"><?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?> <span class="dashicons dashicons-info-outline pcm-chip-info-icon" aria-hidden="true"></span><span class="pcm-chip-tooltip" id="pcm-chip-desc-1" role="tooltip"><?php echo esc_html__( 'Analyzes headers and cache directives to highlight uncached opportunities.', 'pressable_cache_management' ); ?></span></span>
						<span class="pcm-chip-tooltip-wrap" tabindex="0" role="button" title="Opens a sampled route so you can inspect bypass reasons, headers, and timing." aria-describedby="pcm-chip-desc-2"><?php echo esc_html__( 'Route Diagnosis', 'pressable_cache_management' ); ?> <span class="dashicons dashicons-info-outline pcm-chip-info-icon" aria-hidden="true"></span><span class="pcm-chip-tooltip" id="pcm-chip-desc-2" role="tooltip"><?php echo esc_html__( 'Opens a sampled route so you can inspect bypass reasons, headers, and timing.', 'pressable_cache_management' ); ?></span></span>
						<span class="pcm-chip-tooltip-wrap" tabindex="0" role="button" title="Runs a side-by-side edge, origin, and object-cache probe for a single URL." aria-describedby="pcm-chip-desc-3"><?php echo esc_html__( 'Layered Probe Runner', 'pressable_cache_management' ); ?> <span class="dashicons dashicons-info-outline pcm-chip-info-icon" aria-hidden="true"></span><span class="pcm-chip-tooltip" id="pcm-chip-desc-3" role="tooltip"><?php echo esc_html__( 'Runs a side-by-side edge, origin, and object-cache probe for a single URL.', 'pressable_cache_management' ); ?></span></span>
					</div>
					<span class="pcm-ts-inline" id="pcm-caching-suite-inline-status"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $caching_suite_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
				</div>
			</div>

			<div class="pcm-toggle-row">
				<label class="switch pcm-switch-label">
					<input type="checkbox" name="pcm_enable_advanced_scan_workflows" value="1" <?php checked( $advanced_scan_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Cacheability Rescans', 'pressable_cache_management' ); ?>" />
					<span class="slider round"></span>
				</label>
				<div>
					<div class="pcm-toggle-title"><?php echo esc_html__( 'Cacheability Rescans', 'pressable_cache_management' ); ?></div>
					<div class="pcm-toggle-desc"><?php echo esc_html__( 'Allows Cacheability Advisor to queue and process sampled URL rescans.', 'pressable_cache_management' ); ?></div>
					<span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $advanced_scan_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
				</div>
			</div>

			<div class="pcm-toggle-row pcm-toggle-row-no-border">
				<label class="switch pcm-switch-label">
					<input type="checkbox" name="pcm_enable_durable_origin_microcache" value="1" <?php checked( $microcache_enabled ); ?> aria-label="<?php echo esc_attr__( 'Enable Durable Origin Microcache', 'pressable_cache_management' ); ?>" />
					<span class="slider round"></span>
				</label>
				<div>
					<div class="pcm-toggle-title"><?php echo esc_html__( 'Durable Origin Microcache', 'pressable_cache_management' ); ?></div>
					<div class="pcm-toggle-desc"><?php echo esc_html__( 'Caches anonymous-safe JSON/HTML artifacts at origin with tag-based invalidation and fast regeneration.', 'pressable_cache_management' ); ?></div>
					<span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Status:', 'pressable_cache_management' ); ?></strong> <?php echo $microcache_enabled ? esc_html__( 'Enabled', 'pressable_cache_management' ) : esc_html__( 'Disabled', 'pressable_cache_management' ); ?></span>
				</div>
			</div>

			<div class="pcm-feature-flags-save-row">
				<button type="submit" class="pcm-btn-primary"><?php echo esc_html__( 'Save Deep Dive Options', 'pressable_cache_management' ); ?></button>
				<span class="pcm-feature-flags-note"><?php echo esc_html__( 'Changes take effect immediately after save.', 'pressable_cache_management' ); ?></span>
			</div>
		</form>
	</div>
	<?php endif; ?>

		<?php if ( $is_object_tab ) : ?>
	<!-- Header: logo + status badge -->
	<div class="pcm-object-tab-header">
		<div>
			<?php if ( $show_branding ) : ?>
			<p class="pcm-branding-subtitle"><?php echo esc_html__( 'Cache Management by', 'pressable_cache_management' ); ?></p>
			<img class="pressablecmlogo"
				src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/img/pressable-logo-primary.svg' ); ?>"
				alt="Pressable">
			<?php endif; ?>
		</div>
		<div class="pcm-batcache-header-group">
			<span class="pcm-batcache-status <?php echo esc_attr( $bc_class ); ?>" id="pcm-bc-badge">
				<span class="pcm-dot" id="pcm-bc-dot"></span>
				<span id="pcm-bc-label"><?php echo esc_html( $bc_label ); ?></span>
				<button id="pcm-bc-refresh" title="<?php esc_attr_e( 'Re-check Batcache status', 'pressable_cache_management' ); ?>"
						class="pcm-bc-refresh-btn"
						onclick="pcmRefreshBatcacheStatus()"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
			</span>
			<span class="screen-reader-text" aria-live="polite" id="pcm-bc-status-announce"></span>
			<span class="pcm-bc-tooltip-wrap">
				<span class="pcm-bc-tooltip-trigger" tabindex="0" role="button" aria-label="Batcache info"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></span>
				<span class="pcm-bc-tooltip">
					<?php echo esc_html__( 'Use the refresh button to manually check your cache status. If the cache status remains broken for more than 4 minutes after two visits are recorded on your site, it is likely that caching is failing due to cookie interference from your plugin, theme or custom code.', 'pressable_cache_management' ); ?>
					<span class="pcm-bc-tooltip-arrow"></span>
				</span>
			</span>
		</div>
	</div>


	<?php endif; ?>


		<?php if ( $is_deep_dive_tab ) : ?>
	<nav class="pcm-anchor-nav" id="pcm-deep-dive-nav" aria-label="Deep Dive sections">
		<a href="#pcm-feature-cacheability-advisor">Cacheability</a>
		<a href="#pcm-feature-layered-probe">Layered Probe</a>
		<a href="#pcm-feature-route-diagnosis">Route Diagnosis</a>
		<?php if ( function_exists( 'pcm_microcache_render_deep_dive_card' ) ) : ?>
		<a href="#pcm-feature-durable-origin-microcache">Durable Microcache</a>
		<?php endif; ?>
	</nav>

			<?php if ( $is_deep_dive_tab && $pcm_module_available['cacheability'] && pcm_cacheability_advisor_is_enabled() ) : ?>
	<div class="pcm-card pcm-card-hover pcm-card-mb-scroll pcm-lazy-section" id="pcm-feature-cacheability-advisor" data-section="cacheability">
		<h3 class="pcm-card-title"><span class="dashicons dashicons-performance pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?></h3>
		<p class="pcm-text-muted-intro"><?php echo esc_html__( 'Run a cacheability scan and review per-template scores, sampled URLs, and latest findings.', 'pressable_cache_management' ); ?></p>
		<div class="pcm-lazy-skeleton pcm-skeleton-panel" aria-hidden="true"></div>
		<template class="pcm-lazy-template">
			<p>
				<button type="button" class="pcm-btn-primary" id="pcm-advisor-run-btn" <?php disabled( ! $advanced_scan_enabled ); ?>><?php echo esc_html__( 'Rescan now', 'pressable_cache_management' ); ?></button>
				<span id="pcm-advisor-run-status" class="pcm-inline-status" aria-live="polite" role="status"></span>
			</p>
					<?php if ( ! $advanced_scan_enabled ) : ?>
					<p class="pcm-text-muted-intro"><?php echo esc_html__( 'Rescans are disabled until Cacheability Rescans is enabled in Settings > Deep Dive Options.', 'pressable_cache_management' ); ?></p>
				<?php endif; ?>
			<div class="pcm-advisor-grid pcm-responsive-two-col pcm-grid-2col">
				<div>
					<h4 class="pcm-section-subhead"><?php echo esc_html__( 'Template Scores', 'pressable_cache_management' ); ?></h4>
					<div id="pcm-advisor-template-scores" class="pcm-panel-text"></div>
				</div>
				<div>
					<h4 class="pcm-section-subhead"><?php echo esc_html__( 'Latest Findings', 'pressable_cache_management' ); ?></h4>
					<div id="pcm-advisor-findings" class="pcm-panel-text"></div>
				</div>
			</div>
		</template>
	</div>
	<?php elseif ( $is_deep_dive_tab ) : ?>
	<div class="pcm-card pcm-card-mb-scroll" id="pcm-feature-cacheability-advisor">
		<h3 class="pcm-card-title"><span class="dashicons dashicons-performance pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Cacheability Advisor', 'pressable_cache_management' ); ?></h3>
		<p class="pcm-text-muted-intro"><?php echo esc_html__( 'This module is not available. It may be disabled or failed to load.', 'pressable_cache_management' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pressable_cache_management&tab=settings_tab' ) ); ?>"><?php echo esc_html__( 'Check Deep Dive Options in Settings', 'pressable_cache_management' ); ?></a></p>
	</div>
	<?php endif; ?>

		<?php if ( $is_deep_dive_tab ) : ?>
	<div class="pcm-card pcm-card-hover pcm-card-mb-scroll pcm-lazy-section" id="pcm-feature-layered-probe" data-section="layered-probe">
		<h3 class="pcm-card-title"><span class="dashicons dashicons-admin-site-alt3 pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Layered Probe Runner', 'pressable_cache_management' ); ?></h3>
		<p class="pcm-text-muted-intro"><?php echo esc_html__( 'Probe a single URL through Edge, Origin, and Object Cache layers side by side to isolate WPCloud-specific cache issues.', 'pressable_cache_management' ); ?></p>
		<div class="pcm-lazy-skeleton pcm-skeleton-panel" aria-hidden="true"></div>
		<template class="pcm-lazy-template">
		<div class="pcm-probe-input-row">
			<input type="url" id="pcm-probe-url" class="pcm-probe-url-input" value="<?php echo esc_attr( trailingslashit( get_site_url() ) ); ?>" placeholder="<?php echo esc_attr__( 'https://yoursite.com/page/', 'pressable_cache_management' ); ?>" />
			<button type="button" class="pcm-btn-primary" id="pcm-probe-run-btn"><?php echo esc_html__( 'Run Probe', 'pressable_cache_management' ); ?></button>
		</div>
		<div id="pcm-probe-status" class="pcm-inline-status" aria-live="polite" role="status"></div>
		<div id="pcm-probe-results" class="pcm-probe-results-grid pcm-hidden">
			<!-- Edge Probe -->
			<div class="pcm-probe-column" id="pcm-probe-edge">
				<h4 class="pcm-probe-col-title"><span class="dashicons dashicons-cloud pcm-probe-col-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Edge / CDN', 'pressable_cache_management' ); ?></h4>
				<p class="pcm-probe-col-desc"><?php echo esc_html__( 'What the browser sees through CDN & Batcache.', 'pressable_cache_management' ); ?></p>
				<div class="pcm-probe-col-body" id="pcm-probe-edge-body"></div>
			</div>
			<!-- Origin Probe -->
			<div class="pcm-probe-column" id="pcm-probe-origin">
				<h4 class="pcm-probe-col-title"><span class="dashicons dashicons-admin-generic pcm-probe-col-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Origin / Server', 'pressable_cache_management' ); ?></h4>
				<p class="pcm-probe-col-desc"><?php echo esc_html__( 'Direct PHP response bypassing cache layers.', 'pressable_cache_management' ); ?></p>
				<div class="pcm-probe-col-body" id="pcm-probe-origin-body"></div>
			</div>
			<!-- Object Cache Snapshot -->
			<div class="pcm-probe-column" id="pcm-probe-objcache">
				<h4 class="pcm-probe-col-title"><span class="dashicons dashicons-database pcm-probe-col-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Object Cache', 'pressable_cache_management' ); ?></h4>
				<p class="pcm-probe-col-desc"><?php echo esc_html__( 'Memcached / Redis state at probe time.', 'pressable_cache_management' ); ?></p>
				<div class="pcm-probe-col-body" id="pcm-probe-objcache-body"></div>
			</div>
		</div>
		<div id="pcm-probe-raw-toggle-wrap" class="pcm-hidden pcm-mt-14">
			<button type="button" class="pcm-btn-text pcm-toggle-advanced-btn" id="pcm-probe-raw-toggle"><?php echo esc_html__( 'Show Raw Headers', 'pressable_cache_management' ); ?></button>
			<div id="pcm-probe-raw-headers" class="pcm-probe-raw-headers pcm-hidden"></div>
		</div>
		</template>
	</div>
	<?php endif; ?>

			<?php if ( $is_deep_dive_tab ) : ?>
	<div class="pcm-card pcm-card-hover pcm-card-mb-scroll" id="pcm-feature-route-diagnosis" data-section="route-diagnosis">
		<h3 class="pcm-card-title"><span class="dashicons dashicons-search pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Route Diagnosis', 'pressable_cache_management' ); ?></h3>
		<p class="pcm-text-muted-intro"><?php echo esc_html__( 'Open a sampled route from Cacheability Advisor to inspect cache bypass reasons, response headers, and timing in one place.', 'pressable_cache_management' ); ?></p>
				<?php if ( $pcm_module_available['cacheability'] && pcm_cacheability_advisor_is_enabled() ) : ?>
				<div id="pcm-advisor-diagnosis" class="pcm-advisor-diagnosis pcm-advisor-diagnosis-box"><em><?php echo esc_html__( 'Select a route from Cacheability Advisor to view diagnosis details.', 'pressable_cache_management' ); ?></em></div>
				<?php else : ?>
				<div class="pcm-panel-text"><em><?php echo esc_html__( 'Route Diagnosis is unavailable until Deep Dive diagnostics are enabled.', 'pressable_cache_management' ); ?></em></div>
				<?php endif; ?>
	</div>
	<?php endif; ?>


			<?php if ( $is_deep_dive_tab && function_exists( 'pcm_microcache_render_deep_dive_card' ) ) : ?>
				<?php pcm_microcache_render_deep_dive_card(); ?>
	<?php endif; ?>

	<?php endif; ?>

		<?php if ( $is_object_tab ) : ?>

	<!-- ── 2-column grid ── -->
	<div class="pcm-object-cache-grid">

		<!-- LEFT -->
		<div class="pcm-col-stack">

			<!-- Global Controls -->
			<div class="pcm-card">
				<h3 class="pcm-card-title"><span class="dashicons dashicons-update pcm-title-icon" aria-hidden="true"></span> <?php echo esc_html__( 'Global Controls', 'pressable_cache_management' ); ?></h3>
				<p class="pcm-section-title"><?php echo esc_html__( 'Flush Object Cache', 'pressable_cache_management' ); ?></p>
				<form method="post" id="pcm-flush-form">
					<input type="hidden" name="flush_object_cache_nonce" value="<?php echo esc_attr( wp_create_nonce( 'flush_object_cache_nonce' ) ); ?>">
					<input type="submit" value="<?php esc_attr_e( 'Flush Cache for all Pages', 'pressable_cache_management' ); ?>"
							class="pcm-btn-primary pcm-btn-block" id="pcm-flush-btn">
				</form>
				<div id="pcm-flush-feedback" class="pcm-flush-feedback" aria-live="polite"></div>
				<?php $ts = get_option( PCM_Options::FLUSH_OBJ_CACHE_TIMESTAMP->value ); ?>
				<div class="pcm-ts-block">
					<span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span><br>
					<span class="pcm-ts-value" id="pcm-last-flushed-value"><?php echo $ts ? esc_html( $ts ) : '—'; ?></span>
				</div>

				<!-- Current Cache TTL -->
				<?php $pcm_ttl = get_option( PCM_Options::LAST_TTL->value, array() ); ?>
				<div class="pcm-ts-divider">
					<span class="pcm-ts-label"><?php esc_html_e( 'CURRENT CACHE MAX-AGE', 'pressable_cache_management' ); ?></span><br>
					<span id="pcm-ttl-value" class="pcm-ts-value">—</span>
				</div>
			</div>

			<!-- Automated Rules -->
			<div class="pcm-card">
				<form action="options.php" method="post" id="pcm-main-settings-form">
				<?php settings_fields( PCM_Options::MAIN_OPTIONS->value ); ?>
				<h3 class="pcm-card-title"><?php echo esc_html__( 'Automated Rules', 'pressable_cache_management' ); ?></h3>

				<?php
				$rules = array(
					'flush_cache_theme_plugin_checkbox' => array(
						'title' => '<span class="dashicons dashicons-admin-plugins pcm-rule-icon" aria-hidden="true"></span> ' . __( 'Flush Cache on Plugin/Theme Update', 'pressable_cache_management' ),
						'desc'  => __( 'Flush cache automatically on plugin & theme update.', 'pressable_cache_management' ),
						'ts'    => get_option( PCM_Options::FLUSH_CACHE_THEME_PLUGIN_TIMESTAMP->value ),
					),
					'flush_cache_page_edit_checkbox'    => array(
						'title' => '<span class="dashicons dashicons-edit pcm-rule-icon" aria-hidden="true"></span> ' . __( 'Flush Cache on Post/Page Edit', 'pressable_cache_management' ),
						'desc'  => __( 'Flush cache automatically when page/post/post_types are updated.', 'pressable_cache_management' ),
						'ts'    => get_option( PCM_Options::FLUSH_CACHE_PAGE_EDIT_TIMESTAMP->value ),
					),
					'flush_cache_on_comment_delete_checkbox' => array(
						'title' => '<span class="dashicons dashicons-admin-comments pcm-rule-icon" aria-hidden="true"></span> ' . __( 'Flush Cache on Comment Delete', 'pressable_cache_management' ),
						'desc'  => __( 'Flush cache automatically when comments are deleted.', 'pressable_cache_management' ),
						'ts'    => get_option( PCM_Options::FLUSH_CACHE_COMMENT_DELETE_TIMESTAMP->value ),
					),
				);
				foreach ( $rules as $id => $rule ) :
					?>
				<div class="pcm-toggle-row">
					<label class="switch pcm-switch-label">
						<input type="checkbox"
								name="pressable_cache_management_options[<?php echo esc_attr( $id ); ?>]"
								value="1" <?php checked( isset( $options[ $id ] ) ? $options[ $id ] : '', 1 ); ?>
								aria-label="<?php echo esc_attr( wp_strip_all_tags( html_entity_decode( $rule['title'] ) ) ); ?>">
						<span class="slider round"></span>
					</label>
					<div>
						<div class="pcm-toggle-title"><?php echo wp_kses_post( $rule['title'] ); ?></div>
						<div class="pcm-toggle-desc"><?php echo wp_kses_post( $rule['desc'] ); ?></div>
						<span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Last flushed at:', 'pressable_cache_management' ); ?></strong> <?php echo $rule['ts'] ? esc_html( $rule['ts'] ) : '&#8212;'; ?></span>
					</div>
				</div>
				<?php endforeach; ?>
				</form>
			</div>

		</div><!-- /LEFT -->

		<!-- RIGHT -->
		<div class="pcm-col-stack">

			<!-- Batcache & Page Rules -->
			<div class="pcm-card">
				<h3 class="pcm-card-title"><?php echo esc_html__( 'Batcache & Page Rules', 'pressable_cache_management' ); ?></h3>

				<?php
				// Extend Batcache
				?>
				<div class="pcm-toggle-row">
					<label class="switch pcm-switch-label">
						<input type="checkbox" form="pcm-main-settings-form"
								name="pressable_cache_management_options[extend_batcache_checkbox]"
								value="1" <?php checked( isset( $options['extend_batcache_checkbox'] ) ? $options['extend_batcache_checkbox'] : '', 1 ); ?>
								aria-label="<?php echo esc_attr__( 'Extend Batcache TTL', 'pressable_cache_management' ); ?>">
						<span class="slider round"></span>
					</label>
					<div>
						<div class="pcm-toggle-title"><?php echo esc_html__( 'Extend Batcache (by 24 hrs)', 'pressable_cache_management' ); ?></div>
						<div class="pcm-toggle-desc"><?php echo esc_html__( 'Extend Batcache storage time by 24 hours.', 'pressable_cache_management' ); ?></div>
					</div>
				</div>

				<?php
				// Flush Batcache for Individual Pages
				$sp_ts  = get_option( PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value );
				$sp_url = get_option( PCM_Options::SINGLE_PAGE_URL_FLUSHED->value );
				?>
				<div class="pcm-toggle-row">
					<label class="switch pcm-switch-label">
						<input type="checkbox" form="pcm-main-settings-form"
								name="pressable_cache_management_options[flush_object_cache_for_single_page]"
								value="1" <?php checked( isset( $options['flush_object_cache_for_single_page'] ) ? $options['flush_object_cache_for_single_page'] : '', 1 ); ?>
								aria-label="<?php echo esc_attr__( 'Enable Individual Page Rules', 'pressable_cache_management' ); ?>">
						<span class="slider round"></span>
					</label>
					<div>
						<div class="pcm-toggle-title"><?php echo esc_html__( 'Flush Batcache for Individual Pages', 'pressable_cache_management' ); ?></div>
						<div class="pcm-toggle-desc"><?php echo esc_html__( 'Flush Batcache for individual pages from page preview toolbar.', 'pressable_cache_management' ); ?></div>
						<span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Last flushed at:', 'pressable_cache_management' ); ?></strong> <?php echo $sp_ts ? esc_html( $sp_ts ) : '&#8212;'; ?></span>
						<span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Page URL:', 'pressable_cache_management' ); ?></strong> <?php echo $sp_url ? esc_html( $sp_url ) : '&#8212;'; ?></span>
					</div>
				</div>

				<?php
				// Flush cache automatically when published pages/posts are deleted
				$del_ts = get_option( PCM_Options::FLUSH_CACHE_PAGE_POST_DELETE_TIMESTAMP->value );
				?>
				<div class="pcm-toggle-row">
					<label class="switch pcm-switch-label">
						<input type="checkbox" form="pcm-main-settings-form"
								name="pressable_cache_management_options[flush_cache_on_page_post_delete_checkbox]"
								value="1" <?php checked( isset( $options['flush_cache_on_page_post_delete_checkbox'] ) ? $options['flush_cache_on_page_post_delete_checkbox'] : '', 1 ); ?>
								aria-label="<?php echo esc_attr__( 'Flush on Page/Post Delete', 'pressable_cache_management' ); ?>">
						<span class="slider round"></span>
					</label>
					<div>
						<div class="pcm-toggle-title"><?php echo esc_html__( 'Flush cache automatically when published pages/posts are deleted.', 'pressable_cache_management' ); ?></div>
						<div class="pcm-toggle-desc"><?php echo esc_html__( 'Flushes Batcache for the specific page when it is deleted.', 'pressable_cache_management' ); ?></div>
						<span class="pcm-ts-inline"><strong><?php echo esc_html__( 'Last flushed at:', 'pressable_cache_management' ); ?></strong> <?php echo $del_ts ? esc_html( $del_ts ) : '&#8212;'; ?></span>
					</div>
				</div>

				<?php
				// Flush Batcache for WooCommerce product pages
				?>
				<div class="pcm-toggle-row pcm-toggle-row-no-border">
					<label class="switch pcm-switch-label">
						<input type="checkbox" form="pcm-main-settings-form"
								name="pressable_cache_management_options[flush_batcache_for_woo_product_individual_page_checkbox]"
								value="1" <?php checked( isset( $options['flush_batcache_for_woo_product_individual_page_checkbox'] ) ? $options['flush_batcache_for_woo_product_individual_page_checkbox'] : '', 1 ); ?>
								aria-label="<?php echo esc_attr__( 'Enable WooCommerce Cache Rules', 'pressable_cache_management' ); ?>">
						<span class="slider round"></span>
					</label>
					<div>
						<div class="pcm-toggle-title"><?php echo esc_html__( 'Flush Batcache for WooCommerce product pages', 'pressable_cache_management' ); ?></div>
						<div class="pcm-toggle-desc"><?php echo esc_html__( 'Flush Batcache for WooCommerce product pages.', 'pressable_cache_management' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Exclude Pages -->
			<div class="pcm-card">
				<h3 class="pcm-card-title"><?php echo esc_html__( 'Exclude Pages', 'pressable_cache_management' ); ?></h3>
				<p class="pcm-section-title"><?php echo esc_html__( 'Cache Exclusions', 'pressable_cache_management' ); ?></p>

				<?php
				$exempt_val = isset( $options['exempt_from_batcache'] ) ? sanitize_text_field( $options['exempt_from_batcache'] ) : '';
				$pages      = $exempt_val ? array_values( array_filter( array_map( 'trim', explode( ',', $exempt_val ) ) ) ) : array();
				?>

				<!-- Chips -->
				<div id="pcm-chips-wrap" class="pcm-chips-wrap">
					<?php foreach ( $pages as $page ) : ?>
					<span class="pcm-chip" data-value="<?php echo esc_attr( $page ); ?>">
						<?php echo esc_html( $page ); ?>
						<button type="button" class="pcm-chip-remove" title="Remove"><span class="dashicons dashicons-no" aria-hidden="true"></span></button>
					</span>
					<?php endforeach; ?>
				</div>
				<a href="#" id="pcm-chips-clear-all" class="pcm-chips-clear-all" style="display:<?php echo count( $pages ) >= 2 ? 'inline' : 'none'; ?>;"><?php echo esc_html__( 'Clear all', 'pressable_cache_management' ); ?></a>

				<input type="hidden" id="pcm-exempt-hidden"
						name="pressable_cache_management_options[exempt_from_batcache]"
						form="pcm-main-settings-form"
						value="<?php echo esc_attr( $exempt_val ); ?>">

				<input type="text" id="pcm-exempt-input" autocomplete="off"
						placeholder="<?php echo esc_attr__( 'Enter single URL (e.g., /pagename/).', 'pressable_cache_management' ); ?>"
						class="pcm-exempt-input">
				<span id="pcm-exempt-dupe-msg" class="pcm-exempt-dupe-msg" style="display:none;" aria-live="polite"></span>
				<p class="pcm-exclusion-hint">
					<?php echo esc_html__( 'To exclude a single page use', 'pressable_cache_management' ); ?> <code>/page/</code> — <?php echo esc_html__( 'for multiple pages separate with comma, e.g.', 'pressable_cache_management' ); ?> <code>/your-site.com/, /about-us/, /info/</code>
				</p>
			</div>

		</div><!-- /RIGHT -->
	</div><!-- /grid -->

	<!-- MU-Plugin Health Check -->
			<?php
			$missing_mu = pcm_check_mu_plugin_health();
			if ( ! empty( $missing_mu ) ) :
				?>
	<div class="pcm-card pcm-card-mb pcm-notice-error-inline">
		<h3 class="pcm-card-title"><span class="dashicons dashicons-warning pcm-title-icon" aria-hidden="true" style="color:#dd3a03;"></span> <?php echo esc_html__( 'MU-Plugin Health', 'pressable_cache_management' ); ?></h3>
		<p><?php echo esc_html__( 'The following MU-plugin files are expected but missing. Try toggling the related feature off and on again, or check file permissions.', 'pressable_cache_management' ); ?></p>
		<ul>
					<?php foreach ( $missing_mu as $file ) : ?>
				<li><code><?php echo esc_html( $file ); ?></code></li>
			<?php endforeach; ?>
		</ul>
	</div>
			<?php endif; ?>

	<!-- Save button -->
	<div class="pcm-save-row">
		<button type="submit" name="submit" form="pcm-main-settings-form" class="pcm-btn-primary"><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php echo esc_html__( 'Save Settings', 'pressable_cache_management' ); ?></button>
	</div>

	<!-- Chip JS -->

	<?php endif; ?>

	<?php elseif ( 'edge_cache_settings_tab' === $tab ) : ?>



	<!-- Page heading -->
	<div class="pcm-edge-heading-wrap">
		<h2 class="pcm-edge-heading">
			<?php echo esc_html__( 'Manage Edge Cache Settings', 'pressable_cache_management' ); ?>
		</h2>
	</div>

	<!-- Card -->
	<div class="pcm-edge-cache-card-wrap">
	<div class="pcm-card pcm-card-no-pad">

		<!-- Row 1: Turn On/Off -->
		<div class="pcm-ec-row">
			<div>
				<p class="pcm-ec-row-title">
					<?php echo esc_html__( 'Turn On/Off Edge Cache', 'pressable_cache_management' ); ?>
				</p>
				<p class="pcm-ec-row-desc">
					<?php echo esc_html__( 'Enable or disable the edge cache for this site.', 'pressable_cache_management' ); ?>
				</p>
			</div>
			<div id="edge-cache-control-wrapper" class="pcm-ec-control-wrap">
				<div class="edge-cache-loader"></div>
			</div>
		</div>

		<!-- Row 2: Purge + description + timestamps -->
		<div class="pcm-ec-purge-section">

			<!-- Purge title + button -->
			<div class="pcm-ec-purge-header">
				<p class="pcm-ec-row-title pcm-mb-0">
					<?php echo esc_html__( 'Purge Edge Cache', 'pressable_cache_management' ); ?>
				</p>
				<form method="post" id="purge_edge_cache_nonce_form_static" class="pcm-ec-purge-form">
					<?php settings_fields( 'edge_cache_settings_tab_options' ); ?>
					<input type="hidden" name="purge_edge_cache_nonce" value="<?php echo esc_attr( wp_create_nonce( 'purge_edge_cache_nonce' ) ); ?>">
					<input id="purge-edge-cache-button-input"
							name="edge_cache_settings_tab_options[purge_edge_cache_button]"
							type="submit"
							value="<?php echo esc_attr__( 'Purge Edge Cache', 'pressable_cache_management' ); ?>"
							disabled
							class="pcm-btn-danger disabled-button-style"
							>
				</form>
			</div>

			<!-- Description -->
			<p class="pcm-ec-purge-warning">
				<?php echo esc_html__( 'Purging cache will temporarily slow down your site for all visitors while the cache rebuilds.', 'pressable_cache_management' ); ?>
			</p>

			<!-- Timestamps -->
			<div class="pcm-ec-timestamps">

				<div>
					<span class="pcm-ts-label"><?php echo esc_html__( 'LAST FLUSHED', 'pressable_cache_management' ); ?></span>
					<span class="pcm-ts-value pcm-ts-value-block">
					<?php
						$v = get_option( PCM_Options::EDGE_CACHE_PURGE_TIMESTAMP->value );
						echo $v ? esc_html( $v ) : '&mdash;';
					?>
					</span>
				</div>

				<div>
					<span class="pcm-ts-label"><?php echo esc_html__( 'SINGLE PAGE LAST FLUSHED', 'pressable_cache_management' ); ?></span>
					<span class="pcm-ts-value pcm-ts-value-block">
					<?php
						$v = get_option( PCM_Options::SINGLE_PAGE_EDGE_CACHE_PURGE_TIMESTAMP->value );
						echo $v ? esc_html( $v ) : '&mdash;';
					?>
					</span>
				</div>

				<div>
					<span class="pcm-ts-label"><?php echo esc_html__( 'SINGLE PAGE URL', 'pressable_cache_management' ); ?></span>
					<span class="pcm-ts-value pcm-ts-value-block-break">
					<?php
						$v = get_option( PCM_Options::EDGE_CACHE_SINGLE_PAGE_URL_PURGED->value );
						echo $v ? esc_html( $v ) : '&mdash;';
					?>
					</span>
				</div>

			</div>
		</div>

	</div><!-- /card -->
	</div><!-- /max-width -->

	<?php elseif ( 'remove_pressable_branding_tab' === $tab ) : ?>

	<form action="options.php" method="post">
		<?php
		settings_fields( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
		do_settings_sections( 'remove_pressable_branding_tab' );
		echo '<button type="submit" class="pcm-btn-primary">' . esc_html__( 'Save Settings', 'pressable_cache_management' ) . '</button>';
		?>
	</form>

	<?php endif; ?>

	</div><!-- /inner-center -->
	</div><!-- /wrap -->

	<?php
}

// ─── Footer ──────────────────────────────────────────────────────────────────
function pcm_footer_msg() {
	if ( 'not-exists' === get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value, 'not-exists' ) ) {
		add_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value, '' );
		update_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value, array( 'branding_on_off_radio_button' => 'enable' ) );
	}
	add_filter( 'admin_footer_text', 'pcm_replace_default_footer' );
}

function pcm_replace_default_footer( $footer_text ) {
	if ( is_admin() && 'pressable_cache_management' === pcm_settings_current_page() ) {
		$opts              = get_option( PCM_Options::REMOVE_BRANDING_OPTIONS->value );
		$branding_disabled = is_array( $opts )
			&& isset( $opts['branding_on_off_radio_button'] )
			&& 'disable' === $opts['branding_on_off_radio_button'];

		if ( $branding_disabled ) {
			// Branding hidden: "Built with ♥" — heart links to branding settings page
			return 'Built with <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab" title="Show or Hide Plugin Branding" class="pcm-footer-heart"><span class="dashicons dashicons-heart pcm-footer-heart-icon-minimal" onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'"></span></a>';
		} else {
			// Branding shown: full credit — heart links to branding settings page
			return 'Built with <a href="admin.php?page=pressable_cache_management&tab=remove_pressable_branding_tab" title="Show or Hide Plugin Branding" class="pcm-footer-heart"><span class="dashicons dashicons-heart pcm-footer-heart-icon-branded" onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'"></span></a> by The Pressable CS Team.';
		}
	}
	return $footer_text;
}
add_action( 'admin_init', 'pcm_footer_msg' );
