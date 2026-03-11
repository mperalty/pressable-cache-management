<?php
/**
 * Object Cache Intelligence - Service facade.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facade service used by admin UI and scheduler flows.
 */
class PCM_Object_Cache_Intelligence_Service {
	protected PCM_Object_Cache_Stats_Provider_Resolver $resolver;

	protected PCM_Object_Cache_Health_Evaluator $evaluator;

	/**
	 * @param PCM_Object_Cache_Stats_Provider_Resolver|null $resolver  Resolver dependency.
	 * @param PCM_Object_Cache_Health_Evaluator|null        $evaluator Evaluator dependency.
	 */
	public function __construct(
		?PCM_Object_Cache_Stats_Provider_Resolver $resolver = null,
		?PCM_Object_Cache_Health_Evaluator $evaluator = null,
	) {
		$this->resolver  = $resolver ?? new PCM_Object_Cache_Stats_Provider_Resolver();
		$this->evaluator = $evaluator ?? new PCM_Object_Cache_Health_Evaluator();
	}

	/**
	 * Collect one normalized intelligence payload.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_snapshot(): array {
		if ( ! pcm_object_cache_intelligence_is_enabled() ) {
			return array();
		}

		list( $provider, $metrics ) = $this->resolver->resolve_with_metrics();
		$derived                    = $this->evaluator->evaluate( $metrics );

		return array(
			'taken_at'        => current_time( 'mysql', true ),
			'provider'        => $provider->get_provider_key(),
			'status'          => $metrics['status'] ?? 'offline',
			'health'          => $derived['health'],
			'hits'            => $metrics['hits'] ?? null,
			'misses'          => $metrics['misses'] ?? null,
			'hit_ratio'       => $metrics['hit_ratio'] ?? null,
			'evictions'       => $metrics['evictions'] ?? null,
			'bytes_used'      => $metrics['bytes_used'] ?? null,
			'bytes_limit'     => $metrics['bytes_limit'] ?? null,
			'memory_pressure' => pcm_calculate_memory_pressure( $metrics ),
			'recommendations' => $derived['recommendations'],
			'meta'            => $metrics['meta'] ?? array(),
		);
	}
}
