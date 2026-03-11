<?php
/**
 * Cacheability Advisor - Rule engine.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule evaluator + score calculator.
 */
class PCM_Cacheability_Rule_Engine {
	/**
	 * Evaluate probe response and return score/findings.
	 *
	 * @param array $response Response context.
	 *
	 * @return array{score:int,findings:array}
	 */
	public function evaluate( array $response ): array {
		$score    = 100;
		$findings = array();

		$headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();

		$set_cookie = isset( $headers['set-cookie'] ) ? $headers['set-cookie'] : '';
		if ( ! empty( $set_cookie ) ) {
			$score     -= 40;
			$findings[] = array(
				'rule_id'           => 'anonymous_set_cookie',
				'severity'          => 'critical',
				'recommendation_id' => 'remove_anonymous_cookie',
				'evidence'          => array( 'headers' => array( 'set-cookie' => $set_cookie ) ),
			);
		}

		$cache_control_raw = isset( $headers['cache-control'] ) ? $headers['cache-control'] : '';
		$cache_control     = strtolower( is_array( $cache_control_raw ) ? implode( ', ', $cache_control_raw ) : (string) $cache_control_raw );
		if ( '' !== $cache_control && ( str_contains( $cache_control, 'no-store' ) || str_contains( $cache_control, 'private' ) || str_contains( $cache_control, 'max-age=0' ) ) ) {
			$score     -= 30;
			$findings[] = array(
				'rule_id'           => 'cache_control_not_public',
				'severity'          => 'warning',
				'recommendation_id' => 'adjust_cache_control',
				'evidence'          => array( 'headers' => array( 'cache-control' => $cache_control ) ),
			);
		}

		$vary_raw = isset( $headers['vary'] ) ? $headers['vary'] : '';
		$vary     = strtolower( is_array( $vary_raw ) ? implode( ', ', $vary_raw ) : (string) $vary_raw );
		if ( '' !== $vary && ( str_contains( $vary, 'cookie' ) || str_contains( $vary, 'user-agent' ) ) ) {
			$score     -= 20;
			$findings[] = array(
				'rule_id'           => 'volatile_vary',
				'severity'          => 'warning',
				'recommendation_id' => 'narrow_vary_headers',
				'evidence'          => array( 'headers' => array( 'vary' => $vary ) ),
			);
		}

		if ( ! empty( $response['is_error'] ) ) {
			$score     -= 20;
			$findings[] = array(
				'rule_id'           => 'probe_error',
				'severity'          => 'warning',
				'recommendation_id' => 'retry_probe_and_check_origin',
				'evidence'          => array(
					'error_code'    => isset( $response['error_code'] ) ? $response['error_code'] : '',
					'error_message' => isset( $response['error_message'] ) ? $response['error_message'] : '',
				),
			);
		}

		return array(
			'score'    => max( 0, min( 100, (int) $score ) ),
			'findings' => $findings,
		);
	}
}
