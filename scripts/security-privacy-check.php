<?php
/**
 * Lightweight static checks for A9 permission + redaction coverage.
 */

$pcm_root    = dirname( __DIR__ );
$pcm_targets = array(
	'includes/cacheability-advisor/storage.php',
	'includes/cache-busters/detector-framework.php',
	'includes/object-cache-intelligence/intelligence.php',
	'includes/php-opcache-awareness/opcache-awareness.php',
	'includes/redirect-assistant/assistant.php',
	'includes/guided-remediation-playbooks/playbooks.php',
	'includes/observability-reporting/reporting.php',
	'includes/security-privacy/security-privacy.php',
);

$pcm_errors = array();

foreach ( $pcm_targets as $relative_path ) {
	$absolute_path = $pcm_root . '/' . $relative_path;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This CLI script reads local repository files for static checks.
	$file_contents = file_get_contents( $absolute_path );
	if ( ! is_string( $file_contents ) || '' === $file_contents ) {
		$pcm_errors[] = "Unable to read {$relative_path}";
		continue;
	}

	if ( str_contains( $file_contents, "add_action( 'wp_ajax_" ) && ! str_contains( $file_contents, 'pcm_ajax_enforce_permissions' ) ) {
		$pcm_errors[] = "Expected centralized AJAX guard in {$relative_path}";
	}
}

$reporting_path = $pcm_root . '/includes/observability-reporting/reporting.php';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This CLI script reads local repository files for static checks.
$reporting = file_get_contents( $reporting_path );
if ( ! str_contains( (string) $reporting, 'pcm_privacy_redact_value' ) ) {
	$pcm_errors[] = 'Reporting export path is missing privacy redaction usage.';
}

if ( ! empty( $pcm_errors ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI failure output is written directly to STDERR.
	fwrite( STDERR, "Security/privacy checks failed:\n- " . implode( "\n- ", $pcm_errors ) . "\n" );
	exit( 1 );
}

echo "Security/privacy checks passed.\n";
