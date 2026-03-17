<?php
/**
 * Durable Origin Microcache - Table index backend.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCM_Microcache_TableIndex_Backend extends PCM_Microcache_Filesystem_Backend {
	protected readonly string $table_name;

	public function __construct() {
		parent::__construct();
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'pcm_microcache_index';
	}

	public function get( string $key ): ?array {
		$row = parent::get( $key );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $row;
	}

	public function set( string $key, mixed $payload, int $ttl, array $tags, string $builder_name ): bool {
		$saved = parent::set( $key, $payload, $ttl, $tags, $builder_name );
		if ( ! $saved ) {
			return false;
		}

		$index = get_option( pcm_microcache_index_option_key(), array() );
		if ( ! is_array( $index ) || empty( $index[ $key ] ) ) {
			return true;
		}

		$meta = $index[ $key ];

		global $wpdb;
		$result = $wpdb->replace(
			$this->table_name,
			array(
				'cache_key'     => $key,
				'version'       => $meta['version'] ?? '',
				'expires_at'    => isset( $meta['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $meta['expires_at'] ) ) : gmdate( 'Y-m-d H:i:s' ),
				'artifact_path' => $meta['artifact_path'] ?? '',
				'tags'          => wp_json_encode( isset( $meta['tags'] ) ? (array) $meta['tags'] : array() ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			pcm_microcache_log_message( 'PCM Microcache: DB replace failed for key ' . $key . ': ' . $wpdb->last_error );
		}

		return true;
	}

	public function invalidate_by_tags( array $tags ): int {
		$removed = parent::invalidate_by_tags( $tags );

		$tags = pcm_microcache_normalize_tags( $tags );
		if ( empty( $tags ) ) {
			return $removed;
		}

		global $wpdb;
		foreach ( $tags as $tag ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE tags LIKE %s", '%' . $wpdb->esc_like( '"' . $tag . '"' ) . '%' ) );
		}

		return $removed;
	}

	public function invalidate_key( string $key ): bool {
		$removed = parent::invalidate_key( $key );

		global $wpdb;
		$wpdb->delete( $this->table_name, array( 'cache_key' => $key ), array( '%s' ) );

		return $removed;
	}

	public function flush_all(): int {
		$removed = parent::flush_all();

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table name with dynamic prefix.
		$result = $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
		if ( false === $result ) {
			pcm_microcache_log_message( 'PCM Microcache: TRUNCATE TABLE failed: ' . $wpdb->last_error );
		}

		return $removed;
	}
}
