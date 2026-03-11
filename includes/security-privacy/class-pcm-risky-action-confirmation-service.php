<?php
/**
 * Security & Privacy - Risky action confirmation service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit confirmation token helper for risky actions.
 */
class PCM_Risky_Action_Confirmation_Service {
	/**
	 * @param string $action Action key.
	 *
	 * @return string
	 */
	public function issue_token( string $action ): string {
		$action = sanitize_key( $action );
		$token  = wp_generate_password( 32, false, false );
		set_transient( 'pcm_confirm_' . $action . '_' . get_current_user_id(), $token, MINUTE_IN_SECONDS * 10 );

		return $token;
	}

	/**
	 * @param string $action Action key.
	 * @param string $token  Token.
	 *
	 * @return bool
	 */
	public function verify_token( string $action, string $token ): bool {
		$action = sanitize_key( $action );
		$token  = sanitize_text_field( $token );

		$key      = 'pcm_confirm_' . $action . '_' . get_current_user_id();
		$expected = get_transient( $key );

		if ( ! is_string( $expected ) || '' === $expected ) {
			return false;
		}

		$valid = hash_equals( $expected, $token );

		if ( $valid ) {
			delete_transient( $key );
		}

		return $valid;
	}
}
