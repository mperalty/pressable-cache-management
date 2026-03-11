<?php
/**
 * Guided Remediation Playbooks - Lookup service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
