<?php
/**
 * Object Cache Intelligence - Stats provider resolver.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Select best available provider.
 */
class PCM_Object_Cache_Stats_Provider_Resolver {
	/**
	 * Cached resolve result: [ provider, metrics ].
	 *
	 * @var array{0: PCM_Object_Cache_Stats_Provider_Interface, 1: array<string, mixed>}|null
	 */
	protected ?array $cached = null;

	/**
	 * Maximum seconds allowed for the entire provider resolution.
	 *
	 * @var float
	 */
	protected float $time_budget;

	/**
	 * @param float $time_budget Maximum seconds for provider resolution.
	 */
	public function __construct( float $time_budget = 5.0 ) {
		$this->time_budget = $time_budget;
	}

	/**
	 * @return PCM_Object_Cache_Stats_Provider_Interface
	 */
	public function resolve(): PCM_Object_Cache_Stats_Provider_Interface {
		$result = $this->resolve_with_metrics();

		return $result[0];
	}

	/**
	 * Resolve the best provider and return both provider and its metrics.
	 *
	 * @return array{0: PCM_Object_Cache_Stats_Provider_Interface, 1: array<string, mixed>}
	 */
	public function resolve_with_metrics(): array {
		if ( null !== $this->cached ) {
			return $this->cached;
		}

		$start_time = microtime( true );
		$providers  = array(
			new PCM_Object_Cache_Dropin_Stats_Provider(),
			new PCM_Object_Cache_Memcached_Extension_Stats_Provider(),
			new PCM_Object_Cache_Memcache_Extension_Stats_Provider(),
		);

		$log = defined( 'WP_DEBUG' ) && WP_DEBUG;

		foreach ( $providers as $provider ) {
			$elapsed = microtime( true ) - $start_time;
			if ( $elapsed >= $this->time_budget ) {
				if ( $log ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[PCM OCI] Time budget exhausted (%.2fs/%.2fs) before trying %s.', $elapsed, $this->time_budget, $provider->get_provider_key() ) );
				}
				break;
			}

			$provider_start = microtime( true );

			if ( $log ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[PCM OCI] Trying provider: %s (elapsed: %.2fs)', $provider->get_provider_key(), $elapsed ) );
			}

			try {
				$metrics = $provider->get_metrics();
			} catch ( \Throwable $e ) {
				if ( $log ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[PCM OCI] Provider %s threw %s: %s (took %.2fs)', $provider->get_provider_key(), get_class( $e ), $e->getMessage(), microtime( true ) - $provider_start ) );
				}
				continue;
			}

			$provider_elapsed = microtime( true ) - $provider_start;

			if ( ! empty( $metrics ) ) {
				if ( $log ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[PCM OCI] Provider %s succeeded in %.2fs. Status: %s, hits: %s', $provider->get_provider_key(), $provider_elapsed, $metrics['status'] ?? '?', $metrics['hits'] ?? 'null' ) );
				}
				$this->cached = array( $provider, $metrics );

				return $this->cached;
			}

			if ( $log ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( '[PCM OCI] Provider %s returned empty in %.2fs.', $provider->get_provider_key(), $provider_elapsed ) );
			}
		}

		$fallback     = new PCM_Object_Cache_Null_Stats_Provider();
		$this->cached = array( $fallback, $fallback->get_metrics() );

		return $this->cached;
	}
}
