<?php
/**
 * Smart Purge Strategy (Pillar 6).
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Feature gate for smart purge strategy.
 *
 * @return bool
 */
function pcm_smart_purge_is_enabled() {
    $enabled = (bool) get_option( 'pcm_enable_caching_suite_features', false );

    return (bool) apply_filters( 'pcm_enable_smart_purge_strategy', $enabled );
}

/**
 * Active mode gate.
 *
 * @return bool
 */
function pcm_smart_purge_is_active_mode() {
    $active = (bool) get_option( 'pcm_smart_purge_active_mode', false );

    return (bool) apply_filters( 'pcm_enable_smart_purge_active_mode', $active );
}

/**
 * Cooldown window in seconds for dedupe.
 *
 * @return int
 */
function pcm_smart_purge_cooldown_window() {
    $seconds = (int) get_option( 'pcm_smart_purge_cooldown_seconds', 120 );

    return max( 15, min( 3600, $seconds ) );
}

/**
 * Deferred execution window in seconds.
 *
 * @return int
 */
function pcm_smart_purge_defer_window() {
    $seconds = (int) get_option( 'pcm_smart_purge_defer_seconds', 60 );

    return max( 0, min( 3600, $seconds ) );
}

/**
 * Whether URL prewarm is enabled.
 *
 * @return bool
 */
function pcm_smart_purge_is_prewarm_enabled() {
    return (bool) get_option( 'pcm_smart_purge_enable_prewarm', false );
}

/**
 * Max prewarm URLs per job.
 *
 * @return int
 */
function pcm_smart_purge_prewarm_url_cap() {
    $cap = (int) get_option( 'pcm_smart_purge_prewarm_url_cap', 10 );

    return max( 1, min( 100, $cap ) );
}

/**
 * Batch size for prewarm requests.
 *
 * @return int
 */
function pcm_smart_purge_prewarm_batch_size() {
    $size = (int) get_option( 'pcm_smart_purge_prewarm_batch_size', 3 );

    return max( 1, min( 20, $size ) );
}

/**
 * Repeat hits for priority URLs.
 *
 * @return int
 */
function pcm_smart_purge_prewarm_repeat_hits() {
    $hits = (int) get_option( 'pcm_smart_purge_prewarm_repeat_hits', 2 );

    return max( 1, min( 5, $hits ) );
}

/**
 * Important URLs configured by operators.
 *
 * @return array
 */
function pcm_smart_purge_important_urls() {
    $raw = (string) get_option( 'pcm_smart_purge_important_urls', '' );
    if ( '' === trim( $raw ) ) {
        return array();
    }

    $pieces = preg_split( '/[\r\n,]+/', $raw );
    if ( ! is_array( $pieces ) ) {
        return array();
    }

    return array_values(
        array_filter(
            array_unique(
                array_map( 'esc_url_raw', array_map( 'trim', $pieces ) )
            )
        )
    );
}

/**
 * Event + queue storage abstraction.
 */
class PCM_Smart_Purge_Storage {
    /** @var string */
    protected $events_key = 'pcm_smart_purge_events_v1';

    /** @var string */
    protected $jobs_key = 'pcm_smart_purge_jobs_v1';

    /** @var string */
    protected $outcomes_key = 'pcm_smart_purge_outcomes_v1';

    /** @var int */
    protected $max_rows = 500;

    public function add_event( $event ) {
        $rows   = $this->get_events();
        $rows[] = $event;
        update_option( $this->events_key, array_slice( $rows, -1 * $this->max_rows ), false );
    }

    public function get_events() {
        $rows = get_option( $this->events_key, array() );

        return is_array( $rows ) ? $rows : array();
    }

    public function save_jobs( $jobs ) {
        update_option( $this->jobs_key, array_values( (array) $jobs ), false );
    }

    public function get_jobs() {
        $rows = get_option( $this->jobs_key, array() );

        return is_array( $rows ) ? $rows : array();
    }

    public function add_outcome( $outcome ) {
        $rows   = get_option( $this->outcomes_key, array() );
        $rows[] = $outcome;
        update_option( $this->outcomes_key, array_slice( $rows, -1 * $this->max_rows ), false );
    }

    public function get_outcomes() {
        $rows = get_option( $this->outcomes_key, array() );

        return is_array( $rows ) ? $rows : array();
    }
}

/**
 * Event normalizer for existing invalidation triggers.
 */
class PCM_Smart_Purge_Event_Normalizer {
    /** @var PCM_Smart_Purge_Storage */
    protected $storage;

    public function __construct( $storage = null ) {
        $this->storage = $storage ? $storage : new PCM_Smart_Purge_Storage();
    }

    public function record_event( $source_action, $object_type, $object_id, $context = array() ) {
        $event = array(
            'event_id'      => 'evt_' . wp_generate_uuid4(),
            'source_action' => sanitize_key( $source_action ),
            'object_type'   => sanitize_key( $object_type ),
            'object_id'     => absint( $object_id ),
            'actor'         => get_current_user_id(),
            'timestamp'     => current_time( 'mysql', true ),
            'context'       => is_array( $context ) ? $context : array(),
        );

        $this->storage->add_event( $event );

        return $event;
    }
}

/**
 * Scope recommendation engine.
 */
class PCM_Smart_Purge_Recommendation_Engine {
    public function recommend( $event ) {
        $object_type = isset( $event['object_type'] ) ? $event['object_type'] : 'unknown';
        $object_id   = isset( $event['object_id'] ) ? absint( $event['object_id'] ) : 0;

        $recommendation = array(
            'scope'            => 'global',
            'targets'          => array( home_url( '/' ) ),
            'reason'           => 'Fallback to global scope for unknown event type.',
            'estimated_impact' => 'high',
        );

        if ( 'post' === $object_type && $object_id > 0 ) {
            $related_urls = $this->collect_related_urls_for_post( $object_id, $event );

            $recommendation = array(
                'scope'            => 'related_urls',
                'targets'          => $related_urls,
                'prewarm_targets'  => $related_urls,
                'reason'           => 'Post update detected. Purge post URL and homepage before escalating to global.',
                'estimated_impact' => 'medium',
            );
        }

        if ( 'comment' === $object_type && $object_id > 0 ) {
            $comment = get_comment( $object_id );
            $post_id = $comment ? (int) $comment->comment_post_ID : 0;
            $target  = $post_id ? get_permalink( $post_id ) : home_url( '/' );

            $recommendation = array(
                'scope'            => 'single_url',
                'targets'          => array_filter( array( $target ) ),
                'prewarm_targets'  => array_filter( array( $target, home_url( '/' ) ) ),
                'reason'           => 'Comment mutation detected. Purge the affected post URL only.',
                'estimated_impact' => 'low',
            );
        }

        if ( empty( $recommendation['prewarm_targets'] ) ) {
            $recommendation['prewarm_targets'] = isset( $recommendation['targets'] ) ? (array) $recommendation['targets'] : array();
        }

        return $recommendation;
    }

    /**
     * @param int   $post_id Post ID.
     * @param array $event Event payload.
     *
     * @return array
     */
    protected function collect_related_urls_for_post( $post_id, $event ) {
        $urls = array();

        $canonical = get_permalink( $post_id );
        if ( $canonical ) {
            $urls[] = $canonical;
        }

        $urls[] = home_url( '/' );

        $post = get_post( $post_id );
        if ( $post && ! is_wp_error( $post ) ) {
            $archive = get_post_type_archive_link( $post->post_type );
            if ( $archive ) {
                $urls[] = $archive;
            }

            $taxonomies = get_object_taxonomies( $post->post_type, 'names' );
            if ( is_array( $taxonomies ) ) {
                foreach ( $taxonomies as $taxonomy ) {
                    if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
                        continue;
                    }

                    $terms = wp_get_post_terms( $post_id, $taxonomy, array( 'number' => 3 ) );
                    if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        continue;
                    }

                    foreach ( $terms as $term ) {
                        $term_url = get_term_link( $term );
                        if ( ! is_wp_error( $term_url ) ) {
                            $urls[] = $term_url;
                        }
                    }
                }
            }
        }

        $urls = array_merge( $urls, pcm_smart_purge_important_urls() );

        $urls = apply_filters( 'pcm_smart_purge_collect_related_urls', $urls, $post_id, $event );

        return array_values( array_filter( array_unique( array_map( 'esc_url_raw', (array) $urls ) ) ) );
    }
}

/**
 * Queue + dedupe manager.
 */
class PCM_Smart_Purge_Job_Queue {
    /** @var PCM_Smart_Purge_Storage */
    protected $storage;

    public function __construct( $storage = null ) {
        $this->storage = $storage ? $storage : new PCM_Smart_Purge_Storage();
    }

    public function enqueue( $event, $recommendation ) {
        $jobs      = $this->storage->get_jobs();
        $targets   = isset( $recommendation['targets'] ) ? (array) $recommendation['targets'] : array();
        $normalized_targets = array_values( array_unique( array_map( 'esc_url_raw', $targets ) ) );
        $prewarm_targets = isset( $recommendation['prewarm_targets'] ) ? (array) $recommendation['prewarm_targets'] : $targets;
        $prewarm_targets = array_values( array_unique( array_map( 'esc_url_raw', $prewarm_targets ) ) );
        sort( $normalized_targets );
        sort( $prewarm_targets );

        $dedupe_key = hash( 'sha256', wp_json_encode( array(
            'scope'   => isset( $recommendation['scope'] ) ? $recommendation['scope'] : 'global',
            'targets' => $normalized_targets,
        ) ) );

        $now      = time();
        $cooldown = pcm_smart_purge_cooldown_window();

        foreach ( $jobs as $index => $job ) {
            $scheduled = isset( $job['scheduled_ts'] ) ? absint( $job['scheduled_ts'] ) : 0;
            $status    = isset( $job['status'] ) ? $job['status'] : 'queued';

            if ( 'queued' !== $status ) {
                continue;
            }

            if ( isset( $job['dedupe_key'] ) && hash_equals( $job['dedupe_key'], $dedupe_key ) && ( $now - $scheduled ) <= $cooldown ) {
                $jobs[ $index ]['batched_events'][] = isset( $event['event_id'] ) ? $event['event_id'] : '';
                $this->storage->save_jobs( $jobs );

                return $jobs[ $index ];
            }
        }

        $scheduled_ts = $now + pcm_smart_purge_defer_window();

        $job = array(
            'job_id'           => 'job_' . wp_generate_uuid4(),
            'scope'            => isset( $recommendation['scope'] ) ? sanitize_key( $recommendation['scope'] ) : 'global',
            'targets_json'     => $normalized_targets,
            'prewarm_targets'  => $prewarm_targets,
            'prewarm_attempts_per_url' => array(),
            'prewarm_status'   => array(
                'state'                => 'pending',
                'warmed_url_count'     => 0,
                'failure_count'        => 0,
                'average_latency_ms'   => 0,
                'target_outcomes'      => array(),
                'logs'                 => array(),
                'last_run_at'          => null,
            ),
            'throttle_profile' => array(
                'max_urls_per_run' => pcm_smart_purge_prewarm_url_cap(),
                'batch_size'       => pcm_smart_purge_prewarm_batch_size(),
                'delay_ms'         => 150,
                'jitter_ms'        => 75,
            ),
            'reason'           => isset( $recommendation['reason'] ) ? sanitize_text_field( $recommendation['reason'] ) : '',
            'estimated_impact' => isset( $recommendation['estimated_impact'] ) ? sanitize_key( $recommendation['estimated_impact'] ) : 'unknown',
            'status'           => 'queued',
            'scheduled_at'     => gmdate( 'Y-m-d H:i:s', $scheduled_ts ),
            'scheduled_ts'     => $scheduled_ts,
            'executed_at'      => null,
            'dedupe_key'       => $dedupe_key,
            'batched_events'   => array( isset( $event['event_id'] ) ? $event['event_id'] : '' ),
        );

        $jobs[] = $job;
        $this->storage->save_jobs( $jobs );

        return $job;
    }
}

/**
 * Queue runner.
 */
class PCM_Smart_Purge_Queue_Runner {
    /** @var PCM_Smart_Purge_Storage */
    protected $storage;

    public function __construct( $storage = null ) {
        $this->storage = $storage ? $storage : new PCM_Smart_Purge_Storage();
    }

    public function run() {
        $jobs = $this->storage->get_jobs();
        $now  = time();

        foreach ( $jobs as $index => $job ) {
            if ( ! isset( $job['status'] ) || 'queued' !== $job['status'] ) {
                continue;
            }

            $scheduled_ts = isset( $job['scheduled_ts'] ) ? absint( $job['scheduled_ts'] ) : 0;
            if ( $scheduled_ts > $now ) {
                continue;
            }

            $pre_impact = $this->capture_impact_baseline();

            if ( pcm_smart_purge_is_active_mode() ) {
                $this->execute_job( $job );
                $prewarm_results = $this->execute_prewarm_stage( $job );
                $jobs[ $index ]['prewarm_status'] = isset( $prewarm_results['summary'] ) ? $prewarm_results['summary'] : array();
                $jobs[ $index ]['prewarm_attempts_per_url'] = isset( $prewarm_results['attempts_per_url'] ) ? $prewarm_results['attempts_per_url'] : array();
                $jobs[ $index ]['status'] = 'executed';
            } else {
                $jobs[ $index ]['status'] = 'shadowed';
                if ( empty( $jobs[ $index ]['prewarm_status'] ) || ! is_array( $jobs[ $index ]['prewarm_status'] ) ) {
                    $jobs[ $index ]['prewarm_status'] = array();
                }
                $jobs[ $index ]['prewarm_status']['state'] = 'shadowed';
                $jobs[ $index ]['prewarm_status']['last_run_at'] = current_time( 'mysql', true );
            }

            $jobs[ $index ]['executed_at'] = current_time( 'mysql', true );

            $post_impact = $this->capture_impact_baseline();

            $this->storage->add_outcome(
                array(
                    'job_id'            => isset( $job['job_id'] ) ? $job['job_id'] : '',
                    'estimated_impact'  => isset( $job['estimated_impact'] ) ? $job['estimated_impact'] : 'unknown',
                    'observed_impact'   => $this->calculate_observed_impact( $pre_impact, $post_impact ),
                    'impact_baseline'   => $pre_impact,
                    'impact_after'      => $post_impact,
                    'notes'             => pcm_smart_purge_is_active_mode()
                        ? 'Active mode executed scoped purge hooks.'
                        : 'Shadow mode only; job recorded without changing current purge behavior.',
                    'prewarm'           => isset( $jobs[ $index ]['prewarm_status'] ) ? $jobs[ $index ]['prewarm_status'] : array(),
                    'timestamp'         => current_time( 'mysql', true ),
                )
            );
        }

        $this->storage->save_jobs( $jobs );
    }

    /**
     * @param array $job Job payload.
     *
     * @return array
     */
    protected function execute_prewarm_stage( $job ) {
        $default_summary = array(
            'state'              => 'disabled',
            'warmed_url_count'   => 0,
            'failure_count'      => 0,
            'average_latency_ms' => 0,
            'target_outcomes'    => array(),
            'logs'               => array(),
            'last_run_at'        => current_time( 'mysql', true ),
        );

        if ( ! pcm_smart_purge_is_prewarm_enabled() ) {
            return array(
                'summary'          => $default_summary,
                'attempts_per_url' => array(),
            );
        }

        $targets = isset( $job['prewarm_targets'] ) && is_array( $job['prewarm_targets'] ) ? $job['prewarm_targets'] : array();
        $targets = array_values( array_filter( array_unique( array_map( 'esc_url_raw', $targets ) ) ) );

        $throttle = isset( $job['throttle_profile'] ) && is_array( $job['throttle_profile'] ) ? $job['throttle_profile'] : array();
        $max_urls = isset( $throttle['max_urls_per_run'] ) ? absint( $throttle['max_urls_per_run'] ) : pcm_smart_purge_prewarm_url_cap();
        $batch_size = isset( $throttle['batch_size'] ) ? absint( $throttle['batch_size'] ) : pcm_smart_purge_prewarm_batch_size();
        $delay_ms = isset( $throttle['delay_ms'] ) ? absint( $throttle['delay_ms'] ) : 150;
        $jitter_ms = isset( $throttle['jitter_ms'] ) ? absint( $throttle['jitter_ms'] ) : 75;
        $repeat_hits = pcm_smart_purge_prewarm_repeat_hits();

        $targets = array_slice( $targets, 0, max( 1, $max_urls ) );
        $priority_targets = array_slice( $targets, 0, min( 2, count( $targets ) ) );
        $logs = array();
        $attempts = array();
        $latency_total = 0;
        $latency_samples = 0;
        $target_outcomes = array();

        $chunks = array_chunk( $targets, max( 1, $batch_size ) );

        foreach ( $chunks as $chunk ) {
            foreach ( $chunk as $url ) {
                $hits = in_array( $url, $priority_targets, true ) ? $repeat_hits : 1;
                for ( $i = 1; $i <= $hits; $i++ ) {
                    $request_args = apply_filters(
                        'pcm_smart_purge_prewarm_request_args',
                        array(
                            'timeout'             => 5,
                            'redirection'         => 2,
                            'user-agent'          => 'PCM-Smart-Purge-Prewarm/1.0',
                            'headers'             => array( 'Accept' => 'text/html,*/*;q=0.8' ),
                            'blocking'            => true,
                        ),
                        $url,
                        $job
                    );

                    $start = microtime( true );
                    $response = wp_remote_get( $url, $request_args );
                    $latency = (int) round( ( microtime( true ) - $start ) * 1000 );

                    $status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
                    $success = ! is_wp_error( $response ) && $status_code >= 200 && $status_code < 400;

                    if ( ! isset( $attempts[ $url ] ) ) {
                        $attempts[ $url ] = 0;
                    }
                    $attempts[ $url ]++;

                    if ( ! isset( $target_outcomes[ $url ] ) ) {
                        $target_outcomes[ $url ] = array(
                            'attempts'         => 0,
                            'success'          => false,
                            'failure_count'    => 0,
                            'status_code'      => 0,
                            'average_latency_ms' => 0,
                            'error'            => '',
                        );
                    }

                    $target_outcomes[ $url ]['attempts']++;
                    if ( $success ) {
                        $target_outcomes[ $url ]['success'] = true;
                        $target_outcomes[ $url ]['error']   = '';
                    } else {
                        $target_outcomes[ $url ]['failure_count']++;
                        $target_outcomes[ $url ]['error'] = is_wp_error( $response ) ? $response->get_error_message() : '';
                    }

                    $target_outcomes[ $url ]['status_code'] = $status_code;
                    $prev_avg = (float) $target_outcomes[ $url ]['average_latency_ms'];
                    $prev_attempts = max( 1, (int) $target_outcomes[ $url ]['attempts'] );
                    $target_outcomes[ $url ]['average_latency_ms'] = round( ( ( $prev_avg * ( $prev_attempts - 1 ) ) + $latency ) / $prev_attempts, 2 );

                    $latency_total += $latency;
                    $latency_samples++;

                    $logs[] = array(
                        'url'         => $url,
                        'attempt'     => $i,
                        'success'     => $success,
                        'status_code' => $status_code,
                        'latency_ms'  => $latency,
                        'error'       => is_wp_error( $response ) ? $response->get_error_message() : '',
                        'timestamp'   => current_time( 'mysql', true ),
                    );
                }
            }

            $sleep_us = ( $delay_ms + wp_rand( 0, $jitter_ms ) ) * 1000;
            if ( $sleep_us > 0 ) {
                usleep( $sleep_us );
            }
        }

        $warmed_url_count = 0;
        $failure_count = 0;
        foreach ( $target_outcomes as $target_outcome ) {
            if ( ! empty( $target_outcome['success'] ) ) {
                $warmed_url_count++;
            } else {
                $failure_count++;
            }
        }

        return array(
            'summary' => array(
                'state'              => 'completed',
                'warmed_url_count'   => $warmed_url_count,
                'failure_count'      => $failure_count,
                'average_latency_ms' => $latency_samples > 0 ? round( $latency_total / $latency_samples, 2 ) : 0,
                'target_outcomes'    => $target_outcomes,
                'logs'               => array_slice( $logs, -20 ),
                'last_run_at'        => current_time( 'mysql', true ),
            ),
            'attempts_per_url' => $attempts,
        );
    }

    /**
     * @param array $job Job payload.
     *
     * @return void
     */
    protected function execute_job( $job ) {
        $scope   = isset( $job['scope'] ) ? sanitize_key( $job['scope'] ) : 'global';
        $targets = isset( $job['targets_json'] ) && is_array( $job['targets_json'] ) ? $job['targets_json'] : array();

        do_action( 'pcm_smart_purge_before_execute_job', $job );

        if ( 'global' === $scope ) {
            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
            }

            do_action( 'pcm_after_object_cache_flush' );
            do_action( 'pcm_after_edge_cache_purge' );
        } else {
            foreach ( $targets as $target_url ) {
                $target_url = esc_url_raw( $target_url );
                if ( '' === $target_url ) {
                    continue;
                }

                do_action( 'pcm_smart_purge_single_url', $target_url, $job );
            }
        }

        do_action( 'pcm_smart_purge_after_execute_job', $job );
    }

    /**
     * @return array
     */
    protected function capture_impact_baseline() {
        $hit_ratio = (float) get_option( 'pcm_latest_object_cache_hit_ratio', 0 );
        $evictions = (float) get_option( 'pcm_latest_object_cache_evictions', 0 );

        if ( function_exists( 'pcm_object_cache_collect_and_store_snapshot' ) ) {
            $snapshot = pcm_object_cache_collect_and_store_snapshot();
            if ( is_array( $snapshot ) && ! empty( $snapshot ) ) {
                $hit_ratio = isset( $snapshot['hit_ratio'] ) ? (float) $snapshot['hit_ratio'] : $hit_ratio;
                $evictions = isset( $snapshot['evictions'] ) ? (float) $snapshot['evictions'] : $evictions;
            }
        }

        return array(
            'object_cache_hit_ratio' => $hit_ratio,
            'object_cache_evictions' => $evictions,
            'captured_at'            => current_time( 'mysql', true ),
        );
    }

    /**
     * @param array $pre Pre metrics.
     * @param array $post Post metrics.
     *
     * @return array
     */
    protected function calculate_observed_impact( $pre, $post ) {
        $pre_ratio  = isset( $pre['object_cache_hit_ratio'] ) ? (float) $pre['object_cache_hit_ratio'] : 0;
        $post_ratio = isset( $post['object_cache_hit_ratio'] ) ? (float) $post['object_cache_hit_ratio'] : 0;

        $pre_evict  = isset( $pre['object_cache_evictions'] ) ? (float) $pre['object_cache_evictions'] : 0;
        $post_evict = isset( $post['object_cache_evictions'] ) ? (float) $post['object_cache_evictions'] : 0;

        return array(
            'hit_ratio_delta' => round( $post_ratio - $pre_ratio, 4 ),
            'evictions_delta' => round( $post_evict - $pre_evict, 4 ),
        );
    }
}

/**
 * Helper: record and enqueue smart purge event.
 *
 * @param string $source_action Action source.
 * @param string $object_type Object type.
 * @param int    $object_id Object id.
 * @param array  $context Context.
 *
 * @return void
 */
function pcm_smart_purge_record_and_enqueue( $source_action, $object_type, $object_id = 0, $context = array() ) {
    if ( ! pcm_smart_purge_is_enabled() ) {
        return;
    }

    $normalizer = new PCM_Smart_Purge_Event_Normalizer();
    $engine     = new PCM_Smart_Purge_Recommendation_Engine();
    $queue      = new PCM_Smart_Purge_Job_Queue();

    $event          = $normalizer->record_event( $source_action, $object_type, $object_id, $context );
    $recommendation = $engine->recommend( $event );
    $queue->enqueue( $event, $recommendation );
}

/**
 * Capture post/comment events and enqueue smart-purge jobs.
 */
function pcm_smart_purge_capture_post_event( $post_id ) {
    if ( ! pcm_smart_purge_is_enabled() || wp_is_post_revision( $post_id ) ) {
        return;
    }

    pcm_smart_purge_record_and_enqueue( 'save_post', 'post', $post_id );
}
add_action( 'save_post', 'pcm_smart_purge_capture_post_event', 20, 1 );

/**
 * @param int|string $comment_id Comment id.
 */
function pcm_smart_purge_capture_comment_event( $comment_id ) {
    if ( ! pcm_smart_purge_is_enabled() ) {
        return;
    }

    pcm_smart_purge_record_and_enqueue( 'comment_post', 'comment', absint( $comment_id ) );
}
add_action( 'comment_post', 'pcm_smart_purge_capture_comment_event', 20, 1 );

/**
 * Extra source events for bulk/import/update/programmatic triggers (A6.2).
 */
function pcm_smart_purge_capture_post_delete_event( $post_id ) {
    if ( ! pcm_smart_purge_is_enabled() ) {
        return;
    }

    pcm_smart_purge_record_and_enqueue( 'delete_post', 'post', absint( $post_id ), array( 'lifecycle' => 'delete' ) );
}
add_action( 'deleted_post', 'pcm_smart_purge_capture_post_delete_event', 20, 1 );

function pcm_smart_purge_capture_upgrader_event( $upgrader, $options ) {
    unset( $upgrader );

    if ( ! pcm_smart_purge_is_enabled() || ! is_array( $options ) ) {
        return;
    }

    $type   = isset( $options['type'] ) ? sanitize_key( $options['type'] ) : 'unknown';
    $action = isset( $options['action'] ) ? sanitize_key( $options['action'] ) : 'unknown';

    if ( ! in_array( $type, array( 'plugin', 'theme', 'translation', 'core' ), true ) ) {
        return;
    }

    pcm_smart_purge_record_and_enqueue( 'upgrader_process_complete', 'update', 0, array( 'type' => $type, 'action' => $action ) );
}
add_action( 'upgrader_process_complete', 'pcm_smart_purge_capture_upgrader_event', 20, 2 );

function pcm_smart_purge_capture_manual_flush_event() {
    if ( ! pcm_smart_purge_is_enabled() ) {
        return;
    }

    pcm_smart_purge_record_and_enqueue( 'manual_flush', 'flush', 0, array( 'source' => current_filter() ) );
}
add_action( 'pcm_after_object_cache_flush', 'pcm_smart_purge_capture_manual_flush_event' );
add_action( 'pcm_after_edge_cache_purge', 'pcm_smart_purge_capture_manual_flush_event' );

/**
 * Register cron cadence and schedule runner.
 *
 * @param array $schedules Schedules.
 *
 * @return array
 */
function pcm_smart_purge_register_schedule( $schedules ) {
    if ( ! isset( $schedules['pcm_every_2_minutes'] ) ) {
        $schedules['pcm_every_2_minutes'] = array(
            'interval' => 120,
            'display'  => __( 'Every 2 Minutes (PCM Smart Purge)', 'pressable_cache_management' ),
        );
    }

    return $schedules;
}
add_filter( 'cron_schedules', 'pcm_smart_purge_register_schedule' );

/**
 * Ensure cron is scheduled.
 */
function pcm_smart_purge_maybe_schedule_runner() {
    if ( ! pcm_smart_purge_is_enabled() ) {
        return;
    }

    if ( ! wp_next_scheduled( 'pcm_smart_purge_run_queue' ) ) {
        wp_schedule_event( time() + 60, 'pcm_every_2_minutes', 'pcm_smart_purge_run_queue' );
    }
}
add_action( 'init', 'pcm_smart_purge_maybe_schedule_runner' );

/**
 * Save smart purge settings from admin form (A6.4).
 *
 * @return void
 */
function pcm_smart_purge_handle_settings_post() {
    if ( ! is_admin() || empty( $_POST['pcm_smart_purge_settings_submit'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'pcm_smart_purge_settings_action', 'pcm_smart_purge_settings_nonce' );

    $active   = ! empty( $_POST['pcm_smart_purge_active_mode'] );
    $enable_prewarm = ! empty( $_POST['pcm_smart_purge_enable_prewarm'] );
    $cooldown = isset( $_POST['pcm_smart_purge_cooldown_seconds'] ) ? absint( wp_unslash( $_POST['pcm_smart_purge_cooldown_seconds'] ) ) : 120;
    $defer    = isset( $_POST['pcm_smart_purge_defer_seconds'] ) ? absint( wp_unslash( $_POST['pcm_smart_purge_defer_seconds'] ) ) : 60;
    $prewarm_cap = isset( $_POST['pcm_smart_purge_prewarm_url_cap'] ) ? absint( wp_unslash( $_POST['pcm_smart_purge_prewarm_url_cap'] ) ) : 10;
    $prewarm_batch_size = isset( $_POST['pcm_smart_purge_prewarm_batch_size'] ) ? absint( wp_unslash( $_POST['pcm_smart_purge_prewarm_batch_size'] ) ) : 3;
    $prewarm_repeat_hits = isset( $_POST['pcm_smart_purge_prewarm_repeat_hits'] ) ? absint( wp_unslash( $_POST['pcm_smart_purge_prewarm_repeat_hits'] ) ) : 2;
    $important_urls = isset( $_POST['pcm_smart_purge_important_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pcm_smart_purge_important_urls'] ) ) : '';

    update_option( 'pcm_smart_purge_active_mode', $active ? 1 : 0, false );
    update_option( 'pcm_smart_purge_enable_prewarm', $enable_prewarm ? 1 : 0, false );
    update_option( 'pcm_smart_purge_cooldown_seconds', max( 15, min( 3600, $cooldown ) ), false );
    update_option( 'pcm_smart_purge_defer_seconds', max( 0, min( 3600, $defer ) ), false );
    update_option( 'pcm_smart_purge_prewarm_url_cap', max( 1, min( 100, $prewarm_cap ) ), false );
    update_option( 'pcm_smart_purge_prewarm_batch_size', max( 1, min( 20, $prewarm_batch_size ) ), false );
    update_option( 'pcm_smart_purge_prewarm_repeat_hits', max( 1, min( 5, $prewarm_repeat_hits ) ), false );
    update_option( 'pcm_smart_purge_important_urls', $important_urls, false );
}
add_action( 'admin_init', 'pcm_smart_purge_handle_settings_post' );

/**
 * Run queued jobs.
 */
function pcm_smart_purge_run_queue() {
    if ( ! pcm_smart_purge_is_enabled() ) {
        return;
    }

    $runner = new PCM_Smart_Purge_Queue_Runner();
    $runner->run();
}
add_action( 'pcm_smart_purge_run_queue', 'pcm_smart_purge_run_queue' );
