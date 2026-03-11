<?php
/**
 * Guided Remediation Playbooks - Progress store.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		update_option( self::OPTION_KEY, $rows, false );
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
			$step           = sanitize_key( $step );
			$clean[ $step ] = (bool) $complete;
		}

		$state['checklist']   = $clean;
		$state['updated_at']  = gmdate( 'c' );
		$rows[ $playbook_id ] = $state;

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
			'status'     => ! empty( $verification['status'] ) ? sanitize_key( $verification['status'] ) : 'unknown',
			'run_id'     => isset( $verification['run_id'] ) ? absint( $verification['run_id'] ) : 0,
			'rule_id'    => isset( $verification['rule_id'] ) ? sanitize_key( $verification['rule_id'] ) : '',
			'checked_at' => gmdate( 'c' ),
			'message'    => isset( $verification['message'] ) ? sanitize_text_field( $verification['message'] ) : '',
		);
		$state['updated_at']   = gmdate( 'c' );
		$rows[ $playbook_id ]  = $state;

		$this->save_all( $rows );

		return $state;
	}
}
