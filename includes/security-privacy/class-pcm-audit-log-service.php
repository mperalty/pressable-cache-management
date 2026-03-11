<?php
/**
 * Security & Privacy - Audit log service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit writer with tamper-evident sequence/hash chain.
 */
class PCM_Audit_Log_Service {
	protected string $key = 'pcm_audit_log_v1';

	protected int $max_rows = 2000;

	/**
	 * @param string $action Action.
	 * @param string $target Target.
	 * @param array  $context Context.
	 *
	 * @return array
	 */
	public function log( string $action, string $target = '', array $context = array() ): array {
		$rows = $this->all();
		$last = end( $rows );

		$sequence  = isset( $last['sequence_id'] ) ? absint( $last['sequence_id'] ) + 1 : 1;
		$prev_hash = (string) ( $last['entry_hash'] ?? '' );

		$entry = array(
			'sequence_id'  => $sequence,
			'actor_id'     => get_current_user_id(),
			'action'       => sanitize_key( $action ),
			'target'       => sanitize_text_field( $target ),
			'context_json' => pcm_privacy_redact_value( $context ),
			'created_at'   => current_time( 'mysql', true ),
			'prev_hash'    => $prev_hash,
		);

		$entry['entry_hash'] = hash( 'sha256', wp_json_encode( $entry ) );

		$rows[] = $entry;
		update_option( $this->key, array_slice( $rows, -1 * $this->max_rows ), false );

		return $entry;
	}

	/**
	 * @return array
	 */
	public function all(): array {
		$rows = get_option( $this->key, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return bool
	 */
	public function verify_chain(): bool {
		$rows      = $this->all();
		$prev_hash = '';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}

			$entry_hash = (string) ( $row['entry_hash'] ?? '' );
			$computed   = $row;
			unset( $computed['entry_hash'] );

			if ( isset( $row['prev_hash'] ) && (string) $row['prev_hash'] !== $prev_hash ) {
				return false;
			}

			if ( '' === $entry_hash || ! hash_equals( $entry_hash, hash( 'sha256', wp_json_encode( $computed ) ) ) ) {
				return false;
			}

			$prev_hash = $entry_hash;
		}

		return true;
	}
}
