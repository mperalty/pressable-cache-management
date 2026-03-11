<?php
/**
 * Cache Busters - Event value object.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized cache-buster event value object.
 */
class PCM_Cache_Buster_Event {
	/** @var string */
	public string $category = '';

	/** @var string */
	public string $signature = '';

	/** @var string */
	public string $confidence = 'low';

	/** @var int */
	public int $count = 0;

	/** @var string */
	public string $likely_source = 'unknown';

	/** @var array<int, string> */
	public array $affected_urls = array();

	/** @var array<int, array<string, mixed>> */
	public array $evidence_samples = array();

	/**
	 * @param array<string, mixed> $args Event args.
	 */
	public function __construct( array $args = array() ) {
		$this->category         = isset( $args['category'] ) ? sanitize_key( $args['category'] ) : '';
		$this->signature        = isset( $args['signature'] ) ? sanitize_text_field( $args['signature'] ) : '';
		$this->confidence       = isset( $args['confidence'] ) ? sanitize_key( $args['confidence'] ) : 'low';
		$this->count            = isset( $args['count'] ) ? absint( $args['count'] ) : 0;
		$this->likely_source    = isset( $args['likely_source'] ) ? sanitize_text_field( $args['likely_source'] ) : 'unknown';
		$this->affected_urls    = isset( $args['affected_urls'] ) && is_array( $args['affected_urls'] ) ? $args['affected_urls'] : array();
		$this->evidence_samples = isset( $args['evidence_samples'] ) && is_array( $args['evidence_samples'] ) ? $args['evidence_samples'] : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'category'         => $this->category,
			'signature'        => $this->signature,
			'confidence'       => $this->confidence,
			'count'            => $this->count,
			'likely_source'    => $this->likely_source,
			'affected_urls'    => array_values( array_unique( array_map( 'esc_url_raw', $this->affected_urls ) ) ),
			'evidence_samples' => $this->evidence_samples,
		);
	}
}
