<?php

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writeable,WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fputs,WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.unlink_unlink -- This legacy helper performs direct atomic config-file edits where WP_Filesystem is not a drop-in replacement.

function pcm_cache_extend( string $message = '' ): void {
}

/**
 * Log config-file write failures.
 *
 * @param string $message Message to log.
 *
 * @return void
 */
function pcm_config_file_log( string $message ): void {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Config file write failures are logged intentionally for troubleshooting.
	error_log( $message );
}

/**
 * Trigger a config-file warning.
 *
 * @param string $message Warning message.
 *
 * @return void
 */
function pcm_config_file_trigger_error( string $message ): void {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Legacy callers expect a PHP warning when config-file writes fail.
	trigger_error( esc_html( $message ) );
}

// http://www.php.net/is_writable
function pcm_is_writeable_wp_config( string $path ): bool {

	if ( ( defined( 'PHP_OS_FAMILY' ) && 'Windows' !== constant( 'PHP_OS_FAMILY' ) ) || stristr( PHP_OS, 'DAR' ) || ! stristr( PHP_OS, 'WIN' ) ) {
		return is_writeable( $path );
	}

	// PHP's is_writable does not work with Win32 NTFS
	if ( '/' === $path[ strlen( $path ) - 1 ] ) { // recursively return a temporary file path
		return pcm_is_writeable_wp_config( $path . uniqid( (string) wp_rand(), true ) . '.tmp' );
	} elseif ( is_dir( $path ) ) {
		return pcm_is_writeable_wp_config( $path . '/' . uniqid( (string) wp_rand(), true ) . '.tmp' );
	}

	// check tmp file for read/write capabilities
	$rm = file_exists( $path );
	$f  = fopen( $path, 'a' );
	if ( false === $f ) {
		pcm_config_file_log( 'PCM: Unable to open file for write test: ' . $path );
		return false;
	}
	fclose( $f );
	if ( ! $rm ) {
		unlink( $path );
	}

	return true;
}

// Legacy `wp_cache_setting()` logic was intentionally removed and is not used here.

// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.newFound -- Legacy helper signature is kept for compatibility with existing callers.
function pcm_config_file_replace_line( string $old, string $new, string $my_file ): bool {
	if ( false === is_file( $my_file ) ) {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'wpsc_config_error', 'config_file_missing', 10 );
		}
		return false;
	}
	if ( ! pcm_is_writeable_wp_config( $my_file ) ) {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'wpsc_config_error', 'config_file_ro', 10 );
		}
		pcm_config_file_trigger_error( sprintf( 'Error: file %s is not writable.', sanitize_text_field( $my_file ) ) );
		return false;
	}

	$found  = false;
	$loaded = false;
	$c      = 0;
	$lines  = array();
	while ( ! $loaded ) {
		$lines = file( $my_file );
		if ( ! empty( $lines ) ) {
			$loaded = true;
		} else {
			++$c;
			if ( $c > 100 ) {
				if ( function_exists( 'set_transient' ) ) {
					set_transient( 'wpsc_config_error', 'config_file_not_loaded', 10 );
				}
				pcm_config_file_trigger_error( sprintf( 'pcm_config_file_replace_line: Error - file %s could not be loaded.', sanitize_text_field( $my_file ) ) );
				return false;
			}
		}
	}
	foreach ( (array) $lines as $line ) {
		if ( '' !== trim( $new ) && trim( $new ) === trim( $line ) ) {
			pcm_cache_extend( "pcm_config_file_replace_line: setting not changed - $new" );
			return true;
		} elseif ( preg_match( "/$old/", $line ) ) {
			pcm_cache_extend( 'pcm_config_file_replace_line: changing line ' . trim( $line ) . " to *$new*" );
			$found = true;
		}
	}

	global $cache_path;
	$tmp_config_filename = tempnam( $GLOBALS['cache_path'], 'wpsc' );
	rename( $tmp_config_filename, $tmp_config_filename . '.php' );
	$tmp_config_filename .= '.php';
	pcm_cache_extend( 'pcm_config_file_replace_line: writing to ' . $tmp_config_filename );
	$fd = fopen( $tmp_config_filename, 'w' );
	if ( ! $fd ) {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'wpsc_config_error', 'config_file_ro', 10 );
		}
		pcm_config_file_trigger_error( sprintf( 'pcm_config_file_replace_line: Error - could not write to %s', sanitize_text_field( $my_file ) ) );
		return false;
	}
	if ( $found ) {
		foreach ( (array) $lines as $line ) {
			if ( ! preg_match( "/$old/", $line ) ) {
				fputs( $fd, $line );
			} elseif ( '' !== $new ) {
				fputs( $fd, "$new\n" );
			}
		}
	} else {
		$done = false;
		foreach ( (array) $lines as $line ) {
			if ( $done || ! preg_match( '/\b(require_once)\b/', $line ) ) {
				fputs( $fd, $line );
			} else {
				// Write the new line immediately after the first require_once.
				fputs( $fd, $line );
				fputs( $fd, "$new\n" );
				$done = true;
			}
		}
	}
	fclose( $fd );
	rename( $tmp_config_filename, $my_file );
	pcm_cache_extend( 'pcm_config_file_replace_line: moved ' . $tmp_config_filename . ' to ' . $my_file );

	if ( function_exists( 'wp_opcache_invalidate' ) ) {
		wp_opcache_invalidate( $my_file );
	}

	return true;
}

// phpcs:enable
