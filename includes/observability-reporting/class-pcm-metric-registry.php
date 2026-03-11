<?php
/**
 * Observability Reporting - Metric registry.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical metrics registry.
 */
class PCM_Metric_Registry {
	/**
	 * @return array<string, array<string, string>>
	 */
	public function get_catalog(): array {
		return array(
			'cacheability_score'     => array(
				'unit'   => 'score',
				'source' => 'cacheability_advisor',
			),
			'cache_buster_incidence' => array(
				'unit'   => 'count',
				'source' => 'cache_busters',
			),
			'batcache_hits'          => array(
				'unit'   => 'count',
				'source' => 'runtime_headers',
			),
		);
	}

	/**
	 * @param string $metric_key Metric key.
	 *
	 * @return bool
	 */
	public function has_metric( string $metric_key ): bool {
		$catalog = $this->get_catalog();

		return isset( $catalog[ $metric_key ] );
	}
}
