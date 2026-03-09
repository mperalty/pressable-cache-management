<?php
/**
 * Cacheability Advisor storage + services.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION' ) ) {
    define( 'PCM_CACHEABILITY_ADVISOR_DB_VERSION', '1.2.0' );
}

/**
 * Feature flag for cacheability advisor.
 *
 * Default is disabled to keep rollout safe in WPCloud production sites.
 * Enable through:
 * add_filter( 'pcm_enable_cacheability_advisor', '__return_true' );
 *
 * @return bool
 */
function pcm_cacheability_advisor_is_enabled(): bool {
    $enabled = (bool) get_option( PCM_Options::ENABLE_CACHING_SUITE_FEATURES->value, false );

    return (bool) apply_filters( 'pcm_enable_cacheability_advisor', $enabled );
}

/**
 * Ensure schema is up to date when feature is enabled.
 *
 * @return void
 */
function pcm_cacheability_advisor_maybe_migrate(): void {
    if ( ! pcm_cacheability_advisor_is_enabled() ) {
        return;
    }

    if ( ! is_admin() ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $current_version = get_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, '' );

    if ( PCM_CACHEABILITY_ADVISOR_DB_VERSION === $current_version ) {
        return;
    }

    pcm_cacheability_advisor_install_tables();
    update_option( PCM_Options::CACHEABILITY_ADVISOR_DB_VERSION->value, PCM_CACHEABILITY_ADVISOR_DB_VERSION, false );
}
add_action( 'admin_init', 'pcm_cacheability_advisor_maybe_migrate' );

/**
 * Install or update plugin tables.
 *
 * @return void
 */
function pcm_cacheability_advisor_install_tables(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $runs_table            = $wpdb->prefix . 'pcm_scan_runs';
    $urls_table            = $wpdb->prefix . 'pcm_scan_urls';
    $findings_table        = $wpdb->prefix . 'pcm_findings';
    $template_scores_table = $wpdb->prefix . 'pcm_template_scores';

    $sql_runs = "CREATE TABLE {$runs_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        started_at datetime DEFAULT NULL,
        finished_at datetime DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        sample_count int(11) unsigned NOT NULL DEFAULT 0,
        initiated_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY started_at (started_at)
    ) {$charset_collate};";

    $sql_urls = "CREATE TABLE {$urls_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        url text NOT NULL,
        template_type varchar(50) NOT NULL,
        status_code smallint(5) unsigned DEFAULT NULL,
        score int(3) unsigned DEFAULT NULL,
        diagnosis_json longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY run_id (run_id),
        KEY template_type (template_type),
        KEY score (score)
    ) {$charset_collate};";

    $sql_findings = "CREATE TABLE {$findings_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        url text NOT NULL,
        rule_id varchar(100) NOT NULL,
        severity varchar(20) NOT NULL,
        evidence_json longtext DEFAULT NULL,
        recommendation_id varchar(100) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY run_id (run_id),
        KEY rule_id (rule_id),
        KEY severity (severity)
    ) {$charset_collate};";

    $sql_template_scores = "CREATE TABLE {$template_scores_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        template_type varchar(50) NOT NULL,
        score int(3) unsigned NOT NULL DEFAULT 0,
        url_count int(11) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY run_id (run_id),
        KEY template_type (template_type),
        KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta( $sql_runs );
    dbDelta( $sql_urls );
    dbDelta( $sql_findings );
    dbDelta( $sql_template_scores );
}

/**
 * Repository for Cacheability Advisor run lifecycle and findings.
 */
class PCM_Cacheability_Advisor_Repository {
    /**
     * Create a scan run.
     *
     * @param int $initiated_by User ID who started the run.
     * @param int $sample_count Number of URLs sampled.
     *
     * @return int|false Run ID or false on failure.
     */
    public function create_run( int $initiated_by = 0, int $sample_count = 0 ): int|false {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_runs';
        $now   = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            array(
                'started_at'   => $now,
                'status'       => 'running',
                'sample_count' => absint( $sample_count ),
                'initiated_by' => absint( $initiated_by ),
                'created_at'   => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%s' )
        );

        if ( false === $inserted ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update run completion state.
     *
     * @param int    $run_id Run ID.
     * @param string $status pending|running|completed|failed
     *
     * @return bool
     */
    public function complete_run( int $run_id, string $status = 'completed' ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_runs';
        $now   = current_time( 'mysql', true );

        $updated = $wpdb->update(
            $table,
            array(
                'status'      => sanitize_key( $status ),
                'finished_at' => $now,
            ),
            array( 'id' => absint( $run_id ) ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return false !== $updated;
    }

    /**
     * Add URL score row.
     *
     * @param int    $run_id Run ID.
     * @param string $url URL probed.
     * @param string $template_type Template grouping.
     * @param int    $status_code HTTP status code.
     * @param int    $score URL score.
     *
     * @return int|false
     */
    public function add_url_result( int $run_id, string $url, string $template_type, int $status_code = 0, int $score = 0, array $diagnosis = array() ): int|false {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_urls';
        $now   = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            array(
                'run_id'        => absint( $run_id ),
                'url'           => esc_url_raw( $url ),
                'template_type' => sanitize_key( $template_type ),
                'status_code'   => absint( $status_code ),
                'score'         => max( 0, min( 100, absint( $score ) ) ),
                'diagnosis_json'=> wp_json_encode( is_array( $diagnosis ) ? $diagnosis : array() ),
                'created_at'    => $now,
            ),
            array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( false === $inserted ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Add finding row.
     *
     * @param int    $run_id Run ID.
     * @param string $url URL where finding was detected.
     * @param string $rule_id Rule identifier.
     * @param string $severity critical|warning|opportunity.
     * @param array  $evidence Associative evidence payload.
     * @param string $recommendation_id Recommendation key.
     *
     * @return int|false
     */
    public function add_finding( int $run_id, string $url, string $rule_id, string $severity, array $evidence = array(), string $recommendation_id = '' ): int|false {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_findings';
        $now   = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            array(
                'run_id'            => absint( $run_id ),
                'url'               => esc_url_raw( $url ),
                'rule_id'           => sanitize_key( $rule_id ),
                'severity'          => sanitize_key( $severity ),
                'evidence_json'     => wp_json_encode( $evidence ),
                'recommendation_id' => sanitize_key( $recommendation_id ),
                'created_at'        => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Persist template score for trend history.
     *
     * @param int    $run_id Run ID.
     * @param string $template_type Template type.
     * @param int    $score Averaged score.
     * @param int    $url_count URL count used.
     *
     * @return int|false
     */
    public function add_template_score( int $run_id, string $template_type, int $score, int $url_count ): int|false {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_template_scores';
        $now   = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            array(
                'run_id'         => absint( $run_id ),
                'template_type'  => sanitize_key( $template_type ),
                'score'          => max( 0, min( 100, absint( $score ) ) ),
                'url_count'      => max( 0, absint( $url_count ) ),
                'created_at'     => $now,
            ),
            array( '%d', '%s', '%d', '%d', '%s' )
        );

        if ( false === $inserted ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Fetch a run by ID.
     *
     * @param int $run_id Run ID.
     *
     * @return array|null
     */
    public function get_run( int $run_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_runs';

        $query = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $run_id ) );

        return $wpdb->get_row( $query, ARRAY_A );
    }

    /**
     * List recent runs.
     *
     * @param int $limit Result size.
     *
     * @return array
     */
    public function list_runs( int $limit = 10 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_runs';
        $limit = max( 1, min( 100, absint( $limit ) ) );

        $query = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * List URL results for a run.
     *
     * @param int $run_id Run ID.
     *
     * @return array
     */
    public function list_url_results( int $run_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_urls';

        $query = $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", absint( $run_id ) );
        $rows  = $wpdb->get_results( $query, ARRAY_A );

        foreach ( $rows as $index => $row ) {
            $decoded = ! empty( $row['diagnosis_json'] ) ? json_decode( $row['diagnosis_json'], true ) : array();
            $rows[ $index ]['diagnosis'] = is_array( $decoded ) ? $decoded : array();
            unset( $rows[ $index ]['diagnosis_json'] );
        }

        return $rows;
    }

    /**
     * List findings for a run.
     *
     * @param int $run_id Run ID.
     *
     * @return array
     */
    public function list_findings( int $run_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_findings';

        $query = $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", absint( $run_id ) );
        $rows  = $wpdb->get_results( $query, ARRAY_A );

        foreach ( $rows as $index => $row ) {
            $decoded = ! empty( $row['evidence_json'] ) ? json_decode( $row['evidence_json'], true ) : array();
            $rows[ $index ]['evidence'] = is_array( $decoded ) ? $decoded : array();
            unset( $rows[ $index ]['evidence_json'] );
        }

        return $rows;
    }

    /**
     * List template trends.
     *
     * @param string $range 24h|7d|30d.
     *
     * @return array
     */
    public function list_template_trends( string $range = '7d' ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_template_scores';

        $days_by_range = array(
            '24h' => 1,
            '7d'  => 7,
            '30d' => 30,
        );

        $days = isset( $days_by_range[ $range ] ) ? $days_by_range[ $range ] : 7;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );

        $query = $wpdb->prepare(
            "SELECT run_id, template_type, score, url_count, created_at
             FROM {$table}
             WHERE created_at >= %s
             ORDER BY created_at ASC, id ASC",
            $cutoff
        );

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Fetch URL diagnosis detail for a run + URL.
     *
     * @param int    $run_id Run ID.
     * @param string $url URL.
     *
     * @return array|null
     */
    public function get_url_diagnosis( int $run_id, string $url ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'pcm_scan_urls';

        $query = $wpdb->prepare(
            "SELECT id, run_id, url, template_type, status_code, score, diagnosis_json, created_at
             FROM {$table}
             WHERE run_id = %d AND url = %s
             ORDER BY id DESC
             LIMIT 1",
            absint( $run_id ),
            esc_url_raw( $url )
        );

        $row = $wpdb->get_row( $query, ARRAY_A );
        if ( ! $row ) {
            return null;
        }

        $decoded            = ! empty( $row['diagnosis_json'] ) ? json_decode( $row['diagnosis_json'], true ) : array();
        $row['diagnosis']   = is_array( $decoded ) ? $decoded : array();
        unset( $row['diagnosis_json'] );

        return $row;
    }
}

/**
 * Probe client (A1.2).
 */
class PCM_Cacheability_Probe_Client {
    /**
     * Probe URL and return normalized metadata.
     *
     * @param string $url URL.
     *
     * @return array
     */
    public function probe( string $url ): array {
        $url = esc_url_raw( $url );

        $args = array(
            'timeout'     => 8,
            'redirection' => 3,
            'headers'     => array(
                'Cache-Control' => 'no-cache',
                'Pragma'        => 'no-cache',
                'User-Agent'    => 'Pressable-Cache-Advisor/1.0',
            ),
            'cookies'     => array(),
            'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
        );

        if ( function_exists( 'curl_init' ) ) {
            $curl_probe = $this->probe_with_curl( $url, $args );
            if ( is_array( $curl_probe ) && empty( $curl_probe['is_error'] ) ) {
                return $curl_probe;
            }
        }

        $start_time = microtime( true );
        $attempts   = 2;
        $response = null;
        for ( $i = 0; $i < $attempts; $i++ ) {
            $response = wp_remote_get( $url, $args );
            if ( ! is_wp_error( $response ) ) {
                break;
            }
        }

        if ( is_wp_error( $response ) ) {
            return array(
                'url'              => $url,
                'effective_url'    => $url,
                'redirect_chain'   => array(),
                'status_code'      => 0,
                'headers'          => array(),
                'timing'           => array(
                    'total_time'       => $this->format_duration( microtime( true ) - $start_time ),
                    'namelookup_time'  => null,
                    'connect_time'     => null,
                    'starttransfer_time'=> null,
                ),
                'response_size'    => 0,
                'platform_headers' => array(),
                'error_code'       => $response->get_error_code(),
                'error_message'    => $response->get_error_message(),
                'is_error'         => true,
            );
        }

        $headers = wp_remote_retrieve_headers( $response );
        $normalized_headers = $this->normalize_headers( $headers );

        $history = $this->extract_redirect_chain( $response );
        $body    = wp_remote_retrieve_body( $response );

        return array(
            'url'              => $url,
            'effective_url'    => ! empty( $history ) ? end( $history ) : $url,
            'redirect_chain'   => $history,
            'status_code'      => absint( wp_remote_retrieve_response_code( $response ) ),
            'headers'          => $normalized_headers,
            'timing'           => array(
                'total_time'        => $this->format_duration( microtime( true ) - $start_time ),
                'namelookup_time'   => null,
                'connect_time'      => null,
                'starttransfer_time'=> null,
            ),
            'response_size'    => $this->resolve_response_size( $body, $normalized_headers ),
            'platform_headers' => $this->collect_platform_headers( $normalized_headers ),
            'error_code'       => '',
            'error_message'    => '',
            'is_error'         => false,
        );
    }

    /**
     * Probe URL using cURL to capture richer timing metadata.
     *
     * @param string $url URL.
     * @param array  $args Request arguments.
     *
     * @return array|null
     */
    protected function probe_with_curl( string $url, array $args ): ?array {
        $timeout = isset( $args['timeout'] ) ? max( 1, absint( $args['timeout'] ) ) : 8;

        $ch = curl_init();
        if ( ! $ch ) {
            return null;
        }

        $header_blocks   = array();
        $current_headers = array();
        $status_line     = '';

        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_MAXREDIRS, isset( $args['redirection'] ) ? absint( $args['redirection'] ) : 3 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $line ) use ( &$header_blocks, &$current_headers, &$status_line ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed ) {
                if ( ! empty( $status_line ) || ! empty( $current_headers ) ) {
                    $header_blocks[] = array(
                        'status'  => $status_line,
                        'headers' => $current_headers,
                    );
                }
                $current_headers = array();
                $status_line     = '';
                return strlen( $line );
            }

            if ( str_starts_with( strtoupper( $trimmed ), 'HTTP/' ) ) {
                $status_line = sanitize_text_field( $trimmed );
                return strlen( $line );
            }

            $parts = explode( ':', $trimmed, 2 );
            if ( 2 !== count( $parts ) ) {
                return strlen( $line );
            }

            $name  = strtolower( sanitize_text_field( $parts[0] ) );
            $value = sanitize_text_field( trim( $parts[1] ) );

            if ( isset( $current_headers[ $name ] ) ) {
                if ( ! is_array( $current_headers[ $name ] ) ) {
                    $current_headers[ $name ] = array( $current_headers[ $name ] );
                }
                $current_headers[ $name ][] = $value;
            } else {
                $current_headers[ $name ] = $value;
            }

            return strlen( $line );
        } );

        if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
            $curl_headers = array();
            foreach ( $args['headers'] as $name => $value ) {
                $curl_headers[] = sanitize_text_field( (string) $name ) . ': ' . sanitize_text_field( (string) $value );
            }
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
        }

        $body = curl_exec( $ch );
        $info = curl_getinfo( $ch );

        if ( false === $body ) {
            $error_code = curl_errno( $ch );
            $error_msg  = curl_error( $ch );
            curl_close( $ch );

            return array(
                'url'              => $url,
                'effective_url'    => $url,
                'redirect_chain'   => array( $url ),
                'status_code'      => 0,
                'headers'          => array(),
                'timing'           => array(
                    'total_time'        => null,
                    'namelookup_time'   => null,
                    'connect_time'      => null,
                    'starttransfer_time'=> null,
                ),
                'response_size'    => 0,
                'platform_headers' => array(),
                'error_code'       => 'curl_' . absint( $error_code ),
                'error_message'    => sanitize_text_field( $error_msg ),
                'is_error'         => true,
            );
        }

        curl_close( $ch );

        $final_headers = array();
        if ( ! empty( $header_blocks ) ) {
            $last         = end( $header_blocks );
            $final_headers = isset( $last['headers'] ) && is_array( $last['headers'] ) ? $last['headers'] : array();
        }

        $redirect_chain = array( $url );
        foreach ( $header_blocks as $block ) {
            $location = isset( $block['headers']['location'] ) ? $block['headers']['location'] : '';
            if ( is_array( $location ) ) {
                $location = end( $location );
            }
            $location = esc_url_raw( (string) $location );
            if ( '' !== $location ) {
                $redirect_chain[] = $location;
            }
        }

        $effective_url = isset( $info['url'] ) ? esc_url_raw( $info['url'] ) : $url;
        if ( '' !== $effective_url && end( $redirect_chain ) !== $effective_url ) {
            $redirect_chain[] = $effective_url;
        }

        $normalized_headers = $this->normalize_headers( $final_headers );

        return array(
            'url'              => $url,
            'effective_url'    => '' !== $effective_url ? $effective_url : $url,
            'redirect_chain'   => array_values( array_unique( array_filter( $redirect_chain ) ) ),
            'status_code'      => isset( $info['http_code'] ) ? absint( $info['http_code'] ) : 0,
            'headers'          => $normalized_headers,
            'timing'           => array(
                'total_time'        => isset( $info['total_time'] ) ? $this->format_duration( $info['total_time'] ) : null,
                'namelookup_time'   => isset( $info['namelookup_time'] ) ? $this->format_duration( $info['namelookup_time'] ) : null,
                'connect_time'      => isset( $info['connect_time'] ) ? $this->format_duration( $info['connect_time'] ) : null,
                'starttransfer_time'=> isset( $info['starttransfer_time'] ) ? $this->format_duration( $info['starttransfer_time'] ) : null,
            ),
            'response_size'    => $this->resolve_response_size( $body, $normalized_headers, $info ),
            'platform_headers' => $this->collect_platform_headers( $normalized_headers ),
            'error_code'       => '',
            'error_message'    => '',
            'is_error'         => false,
        );
    }

    /**
     * Resolve response size from body length with header/cURL fallbacks.
     *
     * @param string $body Response body.
     * @param array  $headers Normalized response headers.
     * @param array  $curl_info Optional cURL info array.
     *
     * @return int
     */
    protected function resolve_response_size( string $body, array $headers, array $curl_info = array() ): int {
        $size = strlen( (string) $body );

        if ( $size > 0 ) {
            return absint( $size );
        }

        if ( isset( $headers['content-length'] ) ) {
            $content_length = $headers['content-length'];
            if ( is_array( $content_length ) ) {
                $content_length = end( $content_length );
            }

            if ( is_numeric( $content_length ) && (float) $content_length > 0 ) {
                return absint( $content_length );
            }
        }

        if ( isset( $curl_info['size_download'] ) && (float) $curl_info['size_download'] > 0 ) {
            return absint( round( (float) $curl_info['size_download'] ) );
        }

        return 0;
    }

    /**
     * @param array $headers Header map.
     *
     * @return array
     */
    protected function collect_platform_headers( array $headers ): array {
        $interesting = array(
            'x-cache',
            'x-cache-hits',
            'x-cache-group',
            'x-batcache',
            'x-batcache-bypass-reason',
            'x-serve-cache',
            'x-cacheable',
            'cf-cache-status',
            'server-timing',
            'age',
            'via',
        );

        $output = array();
        foreach ( $interesting as $name ) {
            if ( isset( $headers[ $name ] ) ) {
                $output[ $name ] = $headers[ $name ];
            }
        }

        return $output;
    }

    /**
     * @param array $response HTTP response.
     *
     * @return array
     */
    protected function extract_redirect_chain( array $response ): array {
        $chain = array();
        $http_response = isset( $response['http_response'] ) ? $response['http_response'] : null;

        if ( is_object( $http_response ) && method_exists( $http_response, 'get_response_object' ) ) {
            $requests_response = $http_response->get_response_object();
            if ( is_object( $requests_response ) && ! empty( $requests_response->history ) && is_array( $requests_response->history ) ) {
                foreach ( $requests_response->history as $history_entry ) {
                    if ( is_object( $history_entry ) && ! empty( $history_entry->url ) ) {
                        $chain[] = esc_url_raw( $history_entry->url );
                    }
                }
            }

            if ( is_object( $requests_response ) && ! empty( $requests_response->url ) ) {
                $chain[] = esc_url_raw( $requests_response->url );
            }
        }

        if ( empty( $chain ) ) {
            $chain[] = isset( $response['url'] ) ? esc_url_raw( $response['url'] ) : '';
        }

        return array_values( array_filter( array_unique( $chain ) ) );
    }

    /**
     * @param float $duration Duration in seconds.
     *
     * @return float
     */
    protected function format_duration( float $duration ): float {
        return round( (float) $duration, 4 );
    }

    /**
     * @param array|object $headers Headers.
     *
     * @return array
     */
    protected function normalize_headers( array|object $headers ): array {
        $output = array();

        if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $headers = $headers->getAll();
        }

        foreach ( (array) $headers as $name => $value ) {
            $key = strtolower( sanitize_text_field( (string) $name ) );
            if ( is_array( $value ) ) {
                $output[ $key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $output[ $key ] = sanitize_text_field( (string) $value );
            }
        }

        return $output;
    }
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
            $score -= 40;
            $findings[] = array(
                'rule_id'           => 'anonymous_set_cookie',
                'severity'          => 'critical',
                'recommendation_id' => 'remove_anonymous_cookie',
                'evidence'          => array( 'headers' => array( 'set-cookie' => $set_cookie ) ),
            );
        }

        $cache_control = isset( $headers['cache-control'] ) ? strtolower( (string) $headers['cache-control'] ) : '';
        if ( '' !== $cache_control && ( str_contains( $cache_control, 'no-store' ) || str_contains( $cache_control, 'private' ) || str_contains( $cache_control, 'max-age=0' ) ) ) {
            $score -= 30;
            $findings[] = array(
                'rule_id'           => 'cache_control_not_public',
                'severity'          => 'warning',
                'recommendation_id' => 'adjust_cache_control',
                'evidence'          => array( 'headers' => array( 'cache-control' => $cache_control ) ),
            );
        }

        $vary = isset( $headers['vary'] ) ? strtolower( (string) $headers['vary'] ) : '';
        if ( '' !== $vary && ( str_contains( $vary, 'cookie' ) || str_contains( $vary, 'user-agent' ) ) ) {
            $score -= 20;
            $findings[] = array(
                'rule_id'           => 'volatile_vary',
                'severity'          => 'warning',
                'recommendation_id' => 'narrow_vary_headers',
                'evidence'          => array( 'headers' => array( 'vary' => $vary ) ),
            );
        }

        if ( ! empty( $response['is_error'] ) ) {
            $score -= 20;
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

/**
 * Builds structured bypass/poisoning/risk diagnostics for each route.
 */
class PCM_Cacheability_Decision_Tracer {
    /**
     * @param string $url Route URL.
     * @param array  $probe Probe response.
     * @param array  $evaluation Rule evaluation payload.
     *
     * @return array
     */
    public function trace( string $url, array $probe, array $evaluation ): array {
        $headers = isset( $probe['headers'] ) && is_array( $probe['headers'] ) ? $probe['headers'] : array();

        $edge_bypass_reasons     = array();
        $batcache_bypass_reasons = array();
        $poisoning_signals       = array();
        $route_risk_labels       = array();

        $cache_control = strtolower( (string) $this->header_to_scalar( $headers, 'cache-control' ) );
        if ( '' !== $cache_control && ( str_contains( $cache_control, 'no-store' ) || str_contains( $cache_control, 'private' ) ) ) {
            $edge_bypass_reasons[] = array(
                'reason'   => 'cache_control_non_public',
                'evidence' => $cache_control,
            );
        }

        $vary = strtolower( (string) $this->header_to_scalar( $headers, 'vary' ) );
        if ( '' !== $vary && str_contains( $vary, 'cookie' ) ) {
            $edge_bypass_reasons[] = array(
                'reason'   => 'vary_cookie',
                'evidence' => $vary,
            );
            $batcache_bypass_reasons[] = array(
                'reason'   => 'vary_cookie',
                'evidence' => $vary,
            );
        }

        $set_cookie = $this->header_values( $headers, 'set-cookie' );
        if ( ! empty( $set_cookie ) ) {
            $batcache_bypass_reasons[] = array(
                'reason'   => 'set_cookie_present',
                'evidence' => array_slice( $set_cookie, 0, 5 ),
            );

            foreach ( $set_cookie as $cookie_line ) {
                $cookie_name = sanitize_key( strtolower( trim( explode( '=', (string) $cookie_line, 2 )[0] ) ) );
                if ( '' !== $cookie_name ) {
                    $poisoning_signals[] = array(
                        'type'     => 'cookie',
                        'key'      => $cookie_name,
                        'evidence' => sanitize_text_field( (string) $cookie_line ),
                    );
                }
            }
        }

        $url_parts = wp_parse_url( $url );
        if ( is_array( $url_parts ) && ! empty( $url_parts['query'] ) ) {
            parse_str( $url_parts['query'], $query_args );
            foreach ( (array) $query_args as $query_key => $query_value ) {
                $poisoning_signals[] = array(
                    'type'     => 'query',
                    'key'      => sanitize_key( (string) $query_key ),
                    'evidence' => sanitize_text_field( is_scalar( $query_value ) ? (string) $query_value : wp_json_encode( $query_value ) ),
                );
            }
        }

        foreach ( array( 'vary', 'cache-control', 'pragma', 'x-forwarded-host', 'x-forwarded-proto' ) as $header_key ) {
            if ( isset( $headers[ $header_key ] ) ) {
                $poisoning_signals[] = array(
                    'type'     => 'header',
                    'key'      => $header_key,
                    'evidence' => $headers[ $header_key ],
                );
            }
        }

        $score = isset( $evaluation['score'] ) ? absint( $evaluation['score'] ) : 0;
        if ( $score < 60 ) {
            $route_risk_labels[] = array(
                'label'    => 'fragile',
                'evidence' => 'Score below 60',
            );
        }
        if ( ! empty( $probe['timing']['total_time'] ) && $probe['timing']['total_time'] > 1.2 ) {
            $route_risk_labels[] = array(
                'label'    => 'expensive',
                'evidence' => 'Total response time ' . $probe['timing']['total_time'] . 's',
            );
        }
        if ( ! empty( $probe['platform_headers']['x-cache'] ) && str_contains( strtolower( (string) $this->header_to_scalar( $probe['platform_headers'], 'x-cache' ) ), 'miss' ) ) {
            $route_risk_labels[] = array(
                'label'    => 'cold',
                'evidence' => 'x-cache indicates miss',
            );
        }

        return array(
            'edge_bypass_reasons'     => $edge_bypass_reasons,
            'batcache_bypass_reasons' => $batcache_bypass_reasons,
            'poisoning_signals'       => $poisoning_signals,
            'route_risk_labels'       => $route_risk_labels,
        );
    }

    /**
     * @param array  $headers Headers map.
     * @param string $key Header key.
     *
     * @return array
     */
    protected function header_values( array $headers, string $key ): array {
        if ( ! isset( $headers[ $key ] ) ) {
            return array();
        }

        return is_array( $headers[ $key ] ) ? $headers[ $key ] : array( $headers[ $key ] );
    }

    /**
     * @param array  $headers Headers map.
     * @param string $key Header key.
     *
     * @return string
     */
    protected function header_to_scalar( array $headers, string $key ): string {
        if ( ! isset( $headers[ $key ] ) ) {
            return '';
        }

        return is_array( $headers[ $key ] ) ? implode( ', ', $headers[ $key ] ) : (string) $headers[ $key ];
    }
}

/**
 * URL sampler.
 */
class PCM_Cacheability_URL_Sampler {
    /**
     * @return array[]
     */
    public function sample(): array {
        $samples = array();

        $samples[] = array(
            'url'           => home_url( '/' ),
            'template_type' => 'homepage',
        );

        $samples[] = array(
            'url'           => home_url( '/?s=cache' ),
            'template_type' => 'search',
        );

        $posts = get_posts(
            array(
                'post_type'      => array( 'post', 'page' ),
                'posts_per_page' => 6,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        foreach ( (array) $posts as $post ) {
            $url = get_permalink( $post );
            if ( ! $url ) {
                continue;
            }

            $samples[] = array(
                'url'           => $url,
                'template_type' => ( 'page' === $post->post_type ) ? 'page' : 'post',
            );
        }

        if ( class_exists( 'WooCommerce' ) ) {
            foreach ( array( '/cart/', '/checkout/', '/my-account/' ) as $path ) {
                $samples[] = array(
                    'url'           => home_url( $path ),
                    'template_type' => 'commerce',
                );
            }
        }

        return $this->unique_samples( $samples );
    }

    /**
     * @param array $samples Samples.
     *
     * @return array
     */
    protected function unique_samples( array $samples ): array {
        $seen   = array();
        $output = array();

        foreach ( (array) $samples as $sample ) {
            $url = isset( $sample['url'] ) ? esc_url_raw( $sample['url'] ) : '';
            if ( '' === $url || isset( $seen[ $url ] ) ) {
                continue;
            }

            $seen[ $url ] = true;
            $output[] = array(
                'url'           => $url,
                'template_type' => isset( $sample['template_type'] ) ? sanitize_key( $sample['template_type'] ) : 'unknown',
            );
        }

        return $output;
    }
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
     * Start a run + execute orchestrator.
     *
     * @return int|false
     */
    public function start_and_execute_scan(): int|false {
        $samples    = $this->sampler->sample();
        $sample_cnt = count( $samples );
        $run_id     = $this->repository->create_run( get_current_user_id(), $sample_cnt );

        if ( ! $run_id ) {
            return false;
        }

        $template_aggregates = array();

        foreach ( $samples as $sample ) {
            $url           = isset( $sample['url'] ) ? $sample['url'] : '';
            $template_type = isset( $sample['template_type'] ) ? $sample['template_type'] : 'unknown';
            $probe         = $this->probe_client->probe( $url );
            $evaluation    = $this->rule_engine->evaluate( $probe );
            $trace         = $this->decision_tracer->trace( $url, $probe, $evaluation );
            $score         = isset( $evaluation['score'] ) ? absint( $evaluation['score'] ) : 0;

            $this->repository->add_url_result(
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

            foreach ( (array) $evaluation['findings'] as $finding ) {
                $this->repository->add_finding(
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
            }

            if ( ! isset( $template_aggregates[ $template_type ] ) ) {
                $template_aggregates[ $template_type ] = array(
                    'score_total' => 0,
                    'count'       => 0,
                );
            }

            $template_aggregates[ $template_type ]['score_total'] += $score;
            $template_aggregates[ $template_type ]['count']++;
        }

        foreach ( $template_aggregates as $template_type => $aggregate ) {
            $count = max( 1, absint( $aggregate['count'] ) );
            $avg   = (int) round( absint( $aggregate['score_total'] ) / $count );
            $this->repository->add_template_score( $run_id, $template_type, $avg, $count );
        }

        $this->repository->complete_run( $run_id, 'completed' );

        return $run_id;
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

/**
 * Shared permission guard for Cacheability Advisor AJAX endpoints.
 *
 * @return bool
 */
function pcm_cacheability_advisor_ajax_can_manage(): bool {
    if ( function_exists( 'pcm_current_user_can' ) ) {
        return pcm_current_user_can( 'pcm_run_scans' );
    }

    return current_user_can( 'manage_options' );
}

/**
 * AJAX: Start an advisor scan run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_start(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_run_scans' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    if ( ! pcm_cacheability_advisor_is_enabled() ) {
        wp_send_json_error( array( 'message' => 'Cacheability Advisor is disabled.' ), 400 );
    }

    if ( function_exists( 'pcm_get_privacy_settings' ) ) {
        $privacy_settings = pcm_get_privacy_settings();
        if ( empty( $privacy_settings['advanced_scan_opt_in'] ) ) {
            wp_send_json_error( array( 'message' => 'Advanced scans require privacy opt-in.' ), 400 );
        }
    }

    $service = new PCM_Cacheability_Advisor_Run_Service();
    $run_id  = $service->start_and_execute_scan();

    if ( ! $run_id ) {
        wp_send_json_error( array( 'message' => 'Unable to create scan run.' ), 500 );
    }

    if ( function_exists( 'pcm_audit_log' ) ) {
        pcm_audit_log( 'cacheability_scan_started', 'cacheability_advisor', array( 'run_id' => (int) $run_id ) );
    }

    wp_send_json_success(
        array(
            'run_id' => (int) $run_id,
            'status' => 'completed',
        )
    );
}
add_action( 'wp_ajax_pcm_cacheability_scan_start', 'pcm_ajax_cacheability_scan_start' );

/**
 * AJAX: Get scan run status.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_status(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;

    $repository = new PCM_Cacheability_Advisor_Repository();
    $run        = $run_id ? $repository->get_run( $run_id ) : null;

    if ( ! $run ) {
        $runs = $repository->list_runs( 1 );
        $run  = ! empty( $runs ) ? $runs[0] : null;
    }

    if ( ! $run ) {
        wp_send_json_success( array( 'run' => null ) );
    }

    wp_send_json_success( array( 'run' => $run ) );
}
add_action( 'wp_ajax_pcm_cacheability_scan_status', 'pcm_ajax_cacheability_scan_status' );

/**
 * AJAX: Get findings for a run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_findings(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
    if ( $run_id <= 0 ) {
        wp_send_json_error( array( 'message' => 'Missing run_id.' ), 400 );
    }

    $repository = new PCM_Cacheability_Advisor_Repository();
    $findings   = $repository->list_findings( $run_id );

    if ( class_exists( 'PCM_Playbook_Lookup_Service' ) ) {
        $lookup = new PCM_Playbook_Lookup_Service();

        foreach ( $findings as $index => $finding ) {
            $rule_id = isset( $finding['rule_id'] ) ? sanitize_key( $finding['rule_id'] ) : '';
            if ( '' === $rule_id ) {
                continue;
            }

            $playbook_lookup = $lookup->lookup_for_finding( $rule_id );
            if ( ! empty( $playbook_lookup['available'] ) && ! empty( $playbook_lookup['playbook']['meta'] ) ) {
                $meta = $playbook_lookup['playbook']['meta'];
                $findings[ $index ]['playbook_lookup'] = array(
                    'available'   => true,
                    'playbook_id' => isset( $meta['playbook_id'] ) ? $meta['playbook_id'] : '',
                    'title'       => isset( $meta['title'] ) ? $meta['title'] : '',
                    'severity'    => isset( $meta['severity'] ) ? $meta['severity'] : '',
                );
            } else {
                $findings[ $index ]['playbook_lookup'] = array(
                    'available' => false,
                    'reason'    => isset( $playbook_lookup['reason'] ) ? $playbook_lookup['reason'] : 'no_playbook',
                );
            }
        }
    }

    wp_send_json_success(
        array(
            'run_id'   => $run_id,
            'findings' => $findings,
        )
    );
}
add_action( 'wp_ajax_pcm_cacheability_scan_findings', 'pcm_ajax_cacheability_scan_findings' );

/**
 * AJAX: Get URL results for a run.
 *
 * @return void
 */
function pcm_ajax_cacheability_scan_results(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
    if ( $run_id <= 0 ) {
        wp_send_json_error( array( 'message' => 'Missing run_id.' ), 400 );
    }

    $repository = new PCM_Cacheability_Advisor_Repository();
    $results    = $repository->list_url_results( $run_id );

    wp_send_json_success(
        array(
            'run_id'  => $run_id,
            'results' => $results,
        )
    );
}
add_action( 'wp_ajax_pcm_cacheability_scan_results', 'pcm_ajax_cacheability_scan_results' );

/**
 * AJAX: Get template trends.
 *
 * @return void
 */
function pcm_ajax_cacheability_template_trends(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $range      = isset( $_REQUEST['range'] ) ? sanitize_key( wp_unslash( $_REQUEST['range'] ) ) : '7d';
    $repository = new PCM_Cacheability_Advisor_Repository();

    wp_send_json_success(
        array(
            'range'  => $range,
            'trends' => $repository->list_template_trends( $range ),
        )
    );
}
add_action( 'wp_ajax_pcm_cacheability_template_trends', 'pcm_ajax_cacheability_template_trends' );

/**
 * AJAX: Get route diagnosis for a run + URL.
 *
 * @return void
 */
function pcm_ajax_cacheability_route_diagnosis(): void {
    if ( function_exists( 'pcm_ajax_enforce_permissions' ) ) {
        pcm_ajax_enforce_permissions( 'pcm_cacheability_scan', 'pcm_view_diagnostics' );
    } else {
        check_ajax_referer( 'pcm_cacheability_scan', 'nonce' );

        if ( ! pcm_cacheability_advisor_ajax_can_manage() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }
    }

    $run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
    $url    = isset( $_REQUEST['url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['url'] ) ) : '';

    if ( $run_id <= 0 || '' === $url ) {
        wp_send_json_error( array( 'message' => 'Missing run_id or url.' ), 400 );
    }

    $repository = new PCM_Cacheability_Advisor_Repository();
    $diagnosis  = $repository->get_url_diagnosis( $run_id, $url );

    if ( ! $diagnosis ) {
        wp_send_json_error( array( 'message' => 'No diagnosis found for URL.' ), 404 );
    }

    $findings_for_url = array_values(
        array_filter(
            (array) $repository->list_findings( $run_id ),
            function( $finding ) use ( $url ) {
                return isset( $finding['url'] ) && esc_url_raw( $finding['url'] ) === esc_url_raw( $url );
            }
        )
    );

    $diagnosis = pcm_cacheability_enrich_diagnosis_from_findings( $diagnosis, $findings_for_url );

    wp_send_json_success(
        array(
            'run_id'     => $run_id,
            'url'        => $url,
            'diagnosis'  => $diagnosis,
        )
    );
}
add_action( 'wp_ajax_pcm_cacheability_route_diagnosis', 'pcm_ajax_cacheability_route_diagnosis' );

/**
 * Backfill route diagnosis hints from findings when diagnosis trace is unavailable.
 *
 * @param array $diagnosis Route diagnosis payload.
 * @param array $findings Findings for the same run + URL.
 *
 * @return array
 */
function pcm_cacheability_enrich_diagnosis_from_findings( array $diagnosis, array $findings ): array {
    $diagnosis = is_array( $diagnosis ) ? $diagnosis : array();
    $decoded   = isset( $diagnosis['diagnosis'] ) && is_array( $diagnosis['diagnosis'] ) ? $diagnosis['diagnosis'] : array();
    $trace     = isset( $decoded['decision_trace'] ) && is_array( $decoded['decision_trace'] ) ? $decoded['decision_trace'] : array();

    $trace = wp_parse_args(
        $trace,
        array(
            'edge_bypass_reasons'     => array(),
            'batcache_bypass_reasons' => array(),
            'poisoning_signals'       => array(),
            'route_risk_labels'       => array(),
        )
    );

    foreach ( (array) $findings as $finding ) {
        $rule_id   = isset( $finding['rule_id'] ) ? sanitize_key( $finding['rule_id'] ) : '';
        $evidence  = isset( $finding['evidence'] ) && is_array( $finding['evidence'] ) ? $finding['evidence'] : array();
        $headers   = isset( $evidence['headers'] ) && is_array( $evidence['headers'] ) ? $evidence['headers'] : array();

        if ( 'anonymous_set_cookie' === $rule_id && isset( $headers['set-cookie'] ) ) {
            $set_cookie_lines = is_array( $headers['set-cookie'] ) ? $headers['set-cookie'] : array( $headers['set-cookie'] );

            if ( ! pcm_cacheability_trace_has_reason( $trace['batcache_bypass_reasons'], 'set_cookie_present' ) ) {
                $trace['batcache_bypass_reasons'][] = array(
                    'reason'   => 'set_cookie_present',
                    'evidence' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $set_cookie_lines ) ) ), 0, 5 ),
                );
            }

            foreach ( $set_cookie_lines as $line ) {
                $line  = sanitize_text_field( (string) $line );
                $parts = explode( '=', $line, 2 );
                $name  = sanitize_key( strtolower( trim( $parts[0] ) ) );

                if ( '' === $name ) {
                    continue;
                }

                if ( ! pcm_cacheability_trace_has_poison_signal( $trace['poisoning_signals'], 'cookie', $name ) ) {
                    $trace['poisoning_signals'][] = array(
                        'type'     => 'cookie',
                        'key'      => $name,
                        'evidence' => $line,
                    );
                }
            }
        }

        if ( 'cache_control_not_public' === $rule_id && isset( $headers['cache-control'] ) ) {
            $value = is_array( $headers['cache-control'] ) ? implode( ', ', $headers['cache-control'] ) : (string) $headers['cache-control'];
            if ( ! pcm_cacheability_trace_has_reason( $trace['edge_bypass_reasons'], 'cache_control_non_public' ) ) {
                $trace['edge_bypass_reasons'][] = array(
                    'reason'   => 'cache_control_non_public',
                    'evidence' => sanitize_text_field( $value ),
                );
            }
        }
    }

    if ( ! empty( $trace['batcache_bypass_reasons'] ) && ! pcm_cacheability_trace_has_risk_label( $trace['route_risk_labels'], 'fragile' ) ) {
        $trace['route_risk_labels'][] = array(
            'label'    => 'fragile',
            'evidence' => 'Bypass indicators detected in route findings',
        );
    }

    $decoded['decision_trace'] = $trace;
    $diagnosis['diagnosis']    = $decoded;

    return $diagnosis;
}

/**
 * @param array  $reasons Reason list.
 * @param string $reason  Reason key.
 *
 * @return bool
 */
function pcm_cacheability_trace_has_reason( array $reasons, string $reason ): bool {
    foreach ( (array) $reasons as $row ) {
        if ( isset( $row['reason'] ) && sanitize_key( (string) $row['reason'] ) === sanitize_key( $reason ) ) {
            return true;
        }
    }

    return false;
}

/**
 * @param array  $signals Signal list.
 * @param string $type    Signal type.
 * @param string $key     Signal key.
 *
 * @return bool
 */
function pcm_cacheability_trace_has_poison_signal( array $signals, string $type, string $key ): bool {
    foreach ( (array) $signals as $signal ) {
        if ( isset( $signal['type'], $signal['key'] )
            && sanitize_key( (string) $signal['type'] ) === sanitize_key( $type )
            && sanitize_key( (string) $signal['key'] ) === sanitize_key( $key ) ) {
            return true;
        }
    }

    return false;
}

/**
 * @param array  $labels Label list.
 * @param string $label  Label key.
 *
 * @return bool
 */
function pcm_cacheability_trace_has_risk_label( array $labels, string $label ): bool {
    foreach ( (array) $labels as $item ) {
        if ( isset( $item['label'] ) && sanitize_key( (string) $item['label'] ) === sanitize_key( $label ) ) {
            return true;
        }
    }

    return false;
}
