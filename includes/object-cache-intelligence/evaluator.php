<?php
/**
 * Object Cache Intelligence — Health Evaluator & Service Facade.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Evaluate health heuristics and generate recommendations.
 */
class PCM_Object_Cache_Health_Evaluator {
    /**
     * @param array<string, mixed> $metrics Metrics payload.
     *
     * @return array{health: string, recommendations: array<int, array<string, mixed>>}
     */
    public function evaluate( array $metrics ): array {
        $health = 'connected';
        $recommendations = array();

        if ( empty( $metrics ) || 'offline' === $metrics['status'] ) {
            return array(
                'health' => 'offline',
                'recommendations' => array(
                    array(
                        'rule_id'  => 'memcache_unavailable',
                        'severity' => 'critical',
                        'message'  => 'Object cache statistics are unavailable. Verify object-cache drop-in and Memcached connectivity.',
                        'checklist'=> array(
                            'Confirm object-cache.php drop-in exists and loads without warnings.',
                            'Confirm Memcached service endpoint is reachable from WP runtime.',
                            'Re-check stats after infrastructure validation.',
                        ),
                    ),
                ),
            );
        }

        $hit_ratio = isset( $metrics['hit_ratio'] ) ? $metrics['hit_ratio'] : null;
        $evictions = isset( $metrics['evictions'] ) ? absint( $metrics['evictions'] ) : 0;

        if ( null !== $hit_ratio && $hit_ratio < 70 ) {
            $health = 'degraded';
            $recommendations[] = array(
                'rule_id'  => 'low_hit_ratio',
                'severity' => 'warning',
                'message'  => sprintf( 'Hit ratio is %s%% (below 70%% warning threshold). Investigate short TTLs and high key churn.', $hit_ratio ),
                'checklist'=> array(
                    'Review frequent cache flush triggers in plugin/theme workflow.',
                    'Verify cache key normalization for anonymous requests.',
                    'Measure hit ratio again after configuration changes.',
                ),
            );
        }

        if ( $evictions > 0 ) {
            $memory_pressure = pcm_calculate_memory_pressure( $metrics );

            if ( $evictions >= 100 || $memory_pressure >= 90 ) {
                $health = 'degraded';
                $recommendations[] = array(
                    'rule_id'  => 'high_evictions_or_pressure',
                    'severity' => 'critical',
                    'message'  => sprintf( 'Evictions (%d) and/or memory pressure (%s%%) indicate cache churn and likely reduced effectiveness.', $evictions, $memory_pressure ),
                    'checklist'=> array(
                        'Check object cache memory allocation and slab usage.',
                        'Reduce oversized or low-value cache entries where possible.',
                        'Verify lower eviction trend after adjustments.',
                    ),
                );
            }
        }

        return array(
            'health'          => $health,
            'recommendations' => $recommendations,
        );
    }
}

/**
 * Facade service used by admin UI/scheduler in future slices.
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
        $derived = $this->evaluator->evaluate( $metrics );

        return array(
            'taken_at'         => current_time( 'mysql', true ),
            'provider'         => $provider->get_provider_key(),
            'status'           => $metrics['status'] ?? 'offline',
            'health'           => $derived['health'],
            'hits'             => $metrics['hits'] ?? null,
            'misses'           => $metrics['misses'] ?? null,
            'hit_ratio'        => $metrics['hit_ratio'] ?? null,
            'evictions'        => $metrics['evictions'] ?? null,
            'bytes_used'       => $metrics['bytes_used'] ?? null,
            'bytes_limit'      => $metrics['bytes_limit'] ?? null,
            'memory_pressure'  => pcm_calculate_memory_pressure( $metrics ),
            'recommendations'  => $derived['recommendations'],
            'meta'             => $metrics['meta'] ?? array(),
        );
    }
}
