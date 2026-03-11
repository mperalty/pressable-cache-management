<?php
/**
 * Cacheability Advisor - Run service.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service wrapper for run status lifecycle.
 */
class PCM_Cacheability_Advisor_Run_Service {
	/**
	 * @param PCM_Cacheability_Advisor_Repository $repository Repository dependency.
	 * @param PCM_Cacheability_Probe_Client       $probe_client Probe client.
	 * @param PCM_Cacheability_Rule_Engine        $rule_engine Rule engine.
	 * @param PCM_Cacheability_URL_Sampler        $sampler Sampler.
	 * @param PCM_Cacheability_Decision_Tracer    $decision_tracer Decision tracer.
	 */
	public function __construct(
		protected PCM_Cacheability_Advisor_Repository $repository = new PCM_Cacheability_Advisor_Repository(),
		protected PCM_Cacheability_Probe_Client $probe_client = new PCM_Cacheability_Probe_Client(),
		protected PCM_Cacheability_Rule_Engine $rule_engine = new PCM_Cacheability_Rule_Engine(),
		protected PCM_Cacheability_URL_Sampler $sampler = new PCM_Cacheability_URL_Sampler(),
		protected PCM_Cacheability_Decision_Tracer $decision_tracer = new PCM_Cacheability_Decision_Tracer(),
	) {
	}

	/**
	 * Start a new scan run and store the URL queue for batch processing.
	 *
	 * @return int|false Run ID on success, false on failure.
	 */
	public function start_scan(): int|false {
		if ( ! $this->repository->ensure_tables_exist() ) {
			pcm_cacheability_advisor_log_message( 'PCM Cacheability Advisor: required database tables could not be created.' );
			return false;
		}

		$samples = $this->sampler->sample();

		if ( empty( $samples ) ) {
			pcm_cacheability_advisor_log_message( 'PCM Cacheability Advisor: URL sampler returned no URLs.' );
			return false;
		}

		$run_id = $this->repository->create_run( get_current_user_id(), count( $samples ) );

		if ( ! $run_id ) {
			return false;
		}

		set_transient( 'pcm_scan_queue_' . $run_id, $samples, HOUR_IN_SECONDS );

		return $run_id;
	}

	/**
	 * Process the next queued URL for a run.
	 *
	 * @param int $run_id Run ID.
	 *
	 * @return array{done: bool, processed: int, remaining: int, stored: bool}
	 */
	public function process_next( int $run_id ): array {
		$queue = get_transient( 'pcm_scan_queue_' . $run_id );

		if ( ! is_array( $queue ) || empty( $queue ) ) {
			$this->finalize_run( $run_id );

			return array(
				'done'      => true,
				'processed' => 0,
				'remaining' => 0,
				'stored'    => false,
			);
		}

		$sample        = array_shift( $queue );
		$url           = isset( $sample['url'] ) ? $sample['url'] : '';
		$template_type = isset( $sample['template_type'] ) ? $sample['template_type'] : 'unknown';

		$probe      = $this->probe_client->probe( $url );
		$evaluation = $this->rule_engine->evaluate( $probe );
		$trace      = $this->decision_tracer->trace( $url, $probe, $evaluation );
		$score      = absint( $evaluation['score'] );

		$url_result_id = $this->repository->add_url_result(
			$run_id,
			$url,
			$template_type,
			isset( $probe['status_code'] ) ? absint( $probe['status_code'] ) : 0,
			$score,
			array(
				'decision_trace' => $trace,
				'probe'          => array(
					'effective_url'    => isset( $probe['effective_url'] ) ? $probe['effective_url'] : '',
					'redirect_chain'   => isset( $probe['redirect_chain'] ) ? $probe['redirect_chain'] : array(),
					'timing'           => isset( $probe['timing'] ) ? $probe['timing'] : array(),
					'response_size'    => isset( $probe['response_size'] ) ? absint( $probe['response_size'] ) : 0,
					'platform_headers' => isset( $probe['platform_headers'] ) ? $probe['platform_headers'] : array(),
					'headers'          => isset( $probe['headers'] ) ? $probe['headers'] : array(),
				),
			)
		);

		if ( false === $url_result_id ) {
			global $wpdb;
			pcm_cacheability_advisor_log_message( 'PCM Cacheability Advisor: add_url_result failed for run ' . $run_id . ' url=' . $url . ' db_error=' . $wpdb->last_error );
		}

		foreach ( (array) $evaluation['findings'] as $finding ) {
			$finding_id = $this->repository->add_finding(
				$run_id,
				$url,
				isset( $finding['rule_id'] ) ? $finding['rule_id'] : 'unknown_rule',
				isset( $finding['severity'] ) ? $finding['severity'] : 'warning',
				array(
					'headers'       => isset( $probe['headers'] ) ? $probe['headers'] : array(),
					'probe_context' => isset( $finding['evidence'] ) ? $finding['evidence'] : array(),
				),
				isset( $finding['recommendation_id'] ) ? $finding['recommendation_id'] : ''
			);

			if ( false === $finding_id ) {
				global $wpdb;
				pcm_cacheability_advisor_log_message( 'PCM Cacheability Advisor: add_finding failed for run ' . $run_id . ' url=' . $url . ' db_error=' . $wpdb->last_error );
			}
		}

		if ( empty( $queue ) ) {
			delete_transient( 'pcm_scan_queue_' . $run_id );
			$this->finalize_run( $run_id );

			return array(
				'done'      => true,
				'processed' => 1,
				'remaining' => 0,
				'stored'    => false !== $url_result_id,
			);
		}

		set_transient( 'pcm_scan_queue_' . $run_id, $queue, HOUR_IN_SECONDS );

		return array(
			'done'      => false,
			'processed' => 1,
			'remaining' => count( $queue ),
			'stored'    => false !== $url_result_id,
		);
	}

	/**
	 * Compute template aggregates and mark the run as completed.
	 *
	 * @param int $run_id Run ID.
	 */
	protected function finalize_run( int $run_id ): void {
		$results             = $this->repository->list_url_results( $run_id );
		$template_aggregates = array();

		foreach ( $results as $result ) {
			$template_type = isset( $result['template_type'] ) ? $result['template_type'] : 'unknown';
			$score         = isset( $result['score'] ) ? absint( $result['score'] ) : 0;

			if ( ! isset( $template_aggregates[ $template_type ] ) ) {
				$template_aggregates[ $template_type ] = array(
					'score_total' => 0,
					'count'       => 0,
				);
			}

			$template_aggregates[ $template_type ]['score_total'] += $score;
			++$template_aggregates[ $template_type ]['count'];
		}

		foreach ( $template_aggregates as $template_type => $aggregate ) {
			$count = max( 1, absint( $aggregate['count'] ) );
			$avg   = (int) round( absint( $aggregate['score_total'] ) / $count );
			$this->repository->add_template_score( $run_id, $template_type, $avg, $count );
		}

		$this->repository->complete_run( $run_id, 'completed' );
	}

	/**
	 * Mark run as failed.
	 *
	 * @param int $run_id Run ID.
	 *
	 * @return bool
	 */
	public function mark_failed( int $run_id ): bool {
		return $this->repository->complete_run( $run_id, 'failed' );
	}
}
