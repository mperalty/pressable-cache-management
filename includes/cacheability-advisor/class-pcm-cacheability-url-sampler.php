<?php
/**
 * Cacheability Advisor - URL sampler.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL sampler.
 */
class PCM_Cacheability_URL_Sampler {
	protected const MAX_URLS = 20;

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

		$unique = $this->unique_samples( $samples );

		return array_slice( $unique, 0, self::MAX_URLS );
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
			$output[]     = array(
				'url'           => $url,
				'template_type' => isset( $sample['template_type'] ) ? sanitize_key( $sample['template_type'] ) : 'unknown',
			);
		}

		return $output;
	}
}
