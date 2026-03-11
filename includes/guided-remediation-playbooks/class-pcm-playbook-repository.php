<?php
/**
 * Guided Remediation Playbooks - Repository.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Playbook repository backed by bundled markdown files.
 */
class PCM_Playbook_Repository {
	private ?array $playbooks_cache = null;
	private ?array $id_index        = null;
	private ?array $rule_index      = null;

	/**
	 * @return string
	 */
	protected function get_playbooks_dir(): string {
		return plugin_dir_path( __FILE__ ) . 'playbooks/';
	}

	/**
	 * @return array
	 */
	public function list_playbooks(): array {
		if ( null !== $this->playbooks_cache ) {
			return $this->playbooks_cache;
		}

		$paths = glob( $this->get_playbooks_dir() . '*.md' );

		if ( ! is_array( $paths ) ) {
			$this->playbooks_cache = array();
			return $this->playbooks_cache;
		}

		$rows = array();

		foreach ( $paths as $path ) {
			// Validate the file is inside the expected playbooks directory.
			$real = realpath( $path );
			$base = realpath( $this->get_playbooks_dir() );
			if ( false === $real || false === $base || ! str_starts_with( $real, $base ) ) {
				continue;
			}

			$playbook = $this->read_playbook_file( $path );
			if ( ! empty( $playbook ) ) {
				$rows[] = $playbook;
			}
		}

		$this->playbooks_cache = $rows;

		return $rows;
	}

	/**
	 * @param string $playbook_id Playbook ID.
	 *
	 * @return array|null
	 */
	public function get_by_id( string $playbook_id ): ?array {
		$playbook_id = sanitize_key( $playbook_id );
		$index       = $this->get_id_index();

		return $index[ $playbook_id ] ?? null;
	}

	/**
	 * @param string $rule_id Rule ID.
	 *
	 * @return array|null
	 */
	public function get_by_rule_id( string $rule_id ): ?array {
		$rule_id = sanitize_key( $rule_id );
		$index   = $this->get_rule_index();

		return $index[ $rule_id ] ?? null;
	}

	/**
	 * Build a keyed index of playbooks by playbook_id for O(1) lookup.
	 *
	 * @return array<string, array>
	 */
	protected function get_id_index(): array {
		if ( null !== $this->id_index ) {
			return $this->id_index;
		}

		$this->id_index = array();
		foreach ( $this->list_playbooks() as $playbook ) {
			if ( isset( $playbook['meta']['playbook_id'] ) ) {
				$this->id_index[ $playbook['meta']['playbook_id'] ] = $playbook;
			}
		}

		return $this->id_index;
	}

	/**
	 * Build a keyed index of playbooks by rule_id for O(1) lookup.
	 *
	 * @return array<string, array>
	 */
	protected function get_rule_index(): array {
		if ( null !== $this->rule_index ) {
			return $this->rule_index;
		}

		$this->rule_index = array();
		foreach ( $this->list_playbooks() as $playbook ) {
			$rule_ids = isset( $playbook['meta']['rule_ids'] ) && is_array( $playbook['meta']['rule_ids'] ) ? $playbook['meta']['rule_ids'] : array();
			foreach ( $rule_ids as $rid ) {
				$this->rule_index[ $rid ] = $playbook;
			}
		}

		return $this->rule_index;
	}

	/**
	 * @param string $path Path.
	 *
	 * @return array
	 */
	protected function read_playbook_file( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Playbooks are loaded from local plugin files, not remote URLs.
		$content = file_get_contents( $path );

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return array();
		}

		$meta = $this->parse_meta_json( $content );

		if ( empty( $meta['playbook_id'] ) ) {
			return array();
		}

		$body = preg_replace( '/\A\/\*PCM_PLAYBOOK_META\n.*?\nPCM_PLAYBOOK_META\*\/\n/s', '', $content );

		return array(
			'meta' => $meta,
			'body' => is_string( $body ) ? trim( $body ) : '',
		);
	}

	/**
	 * @param string $content File content.
	 *
	 * @return array
	 */
	protected function parse_meta_json( string $content ): array {
		if ( ! preg_match( '/\A\/\*PCM_PLAYBOOK_META\n(.*?)\nPCM_PLAYBOOK_META\*\//s', $content, $matches ) ) {
			return array();
		}

		$json = json_decode( trim( $matches[1] ), true );

		if ( ! is_array( $json ) ) {
			return array();
		}

		$json['playbook_id'] = isset( $json['playbook_id'] ) ? sanitize_key( $json['playbook_id'] ) : '';
		$json['version']     = isset( $json['version'] ) ? sanitize_text_field( $json['version'] ) : '1.0.0';
		$json['severity']    = isset( $json['severity'] ) ? sanitize_key( $json['severity'] ) : 'warning';
		$json['title']       = isset( $json['title'] ) ? sanitize_text_field( $json['title'] ) : '';

		$json['rule_ids'] = isset( $json['rule_ids'] ) && is_array( $json['rule_ids'] )
			? array_values( array_filter( array_map( 'sanitize_key', $json['rule_ids'] ) ) )
			: array();

		$json['audiences'] = isset( $json['audiences'] ) && is_array( $json['audiences'] )
			? array_values( array_filter( array_map( 'sanitize_key', $json['audiences'] ) ) )
			: array();

		return $json;
	}
}
