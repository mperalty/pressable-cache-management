<?php
/**
 * Durable Origin Microcache - Filesystem backend.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCM_Microcache_Filesystem_Backend implements PCM_Microcache_Backend {
	protected readonly string $index_key;

	private ?array $index_cache = null;

	public function __construct() {
		$this->index_key = pcm_microcache_index_option_key();
	}

	public function get( string $key ): ?array {
		$index = $this->get_index();
		if ( empty( $index[ $key ] ) || ! is_array( $index[ $key ] ) ) {
			return null;
		}

		$row = $index[ $key ];
		if ( empty( $row['artifact_path'] ) || ! file_exists( $row['artifact_path'] ) ) {
			unset( $index[ $key ] );
			$this->save_index( $index );
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a plugin-managed local artifact file.
		$raw = file_get_contents( $row['artifact_path'] );
		if ( false === $raw ) {
			return null;
		}

		$row['payload'] = $this->decode_payload( $raw, $row['content_type'] ?? 'json' );

		return $row;
	}

	public function set( string $key, mixed $payload, int $ttl, array $tags, string $builder_name ): bool {
		$dir = pcm_microcache_artifact_dir();
		if ( '' === $dir ) {
			return false;
		}

		$now        = time();
		$content    = is_string( $payload ) ? 'html' : 'json';
		$version    = gmdate( 'YmdHis', $now ) . '-' . wp_generate_password( 6, false, false );
		$artifact   = trailingslashit( $dir ) . sanitize_file_name( $key . '-' . $version . '.' . $content );
		$serialized = 'html' === $content ? (string) $payload : wp_json_encode( $payload );

		if ( false === $serialized ) {
			pcm_microcache_log_message( 'PCM Microcache: Failed to serialize payload for key ' . $key );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a plugin-managed local artifact file.
		if ( false === file_put_contents( $artifact, $serialized ) ) {
			pcm_microcache_log_message( 'PCM Microcache: Failed to write artifact file ' . $artifact );
			return false;
		}

		$index = $this->get_index();
		$old   = isset( $index[ $key ]['artifact_path'] ) ? (string) $index[ $key ]['artifact_path'] : '';

		$index[ $key ] = array(
			'key'           => $key,
			'version'       => $version,
			'expires_at'    => $now + max( 5, absint( $ttl ) ),
			'artifact_path' => $artifact,
			'tags'          => pcm_microcache_normalize_tags( $tags ),
			'content_type'  => $content,
			'builder'       => sanitize_text_field( $builder_name ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		$this->save_index( $index );

		if ( $old && $old !== $artifact ) {
			pcm_microcache_delete_artifact( $old );
		}

		return true;
	}

	public function invalidate_by_tags( array $tags ): int {
		$tags = pcm_microcache_normalize_tags( $tags );
		if ( empty( $tags ) ) {
			return 0;
		}

		$index   = $this->get_index();
		$removed = 0;

		foreach ( $index as $key => $row ) {
			$row_tags = isset( $row['tags'] ) ? (array) $row['tags'] : array();
			if ( empty( array_intersect( $tags, $row_tags ) ) ) {
				continue;
			}

			if ( ! empty( $row['artifact_path'] ) ) {
				pcm_microcache_delete_artifact( (string) $row['artifact_path'] );
			}
			unset( $index[ $key ] );
			++$removed;
		}

		$this->save_index( $index );

		return $removed;
	}

	public function invalidate_key( string $key ): bool {
		$index = $this->get_index();
		if ( empty( $index[ $key ] ) ) {
			return false;
		}

		if ( ! empty( $index[ $key ]['artifact_path'] ) ) {
			pcm_microcache_delete_artifact( (string) $index[ $key ]['artifact_path'] );
		}

		unset( $index[ $key ] );
		$this->save_index( $index );

		return true;
	}

	public function flush_all(): int {
		$index = $this->get_index();
		foreach ( $index as $row ) {
			if ( ! empty( $row['artifact_path'] ) ) {
				pcm_microcache_delete_artifact( (string) $row['artifact_path'] );
			}
		}

		$this->save_index( array() );

		return count( $index );
	}

	public function list_recent_events( int $limit ): array {
		$rows = get_option( pcm_microcache_events_option_key(), array() );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_slice( array_reverse( $rows ), 0, max( 1, absint( $limit ) ) );
	}

	protected function get_index(): array {
		if ( null !== $this->index_cache ) {
			return $this->index_cache;
		}

		$index             = get_option( $this->index_key, array() );
		$this->index_cache = is_array( $index ) ? $index : array();

		return $this->index_cache;
	}

	protected function save_index( array $index ): void {
		update_option( $this->index_key, $index, false );
		$this->index_cache = $index;
	}

	protected function decode_payload( string $raw, string $content_type ): string|array {
		if ( 'html' === $content_type ) {
			return $raw;
		}

		$decoded = json_decode( $raw, true );

		return ( null === $decoded ) ? $raw : $decoded;
	}
}
