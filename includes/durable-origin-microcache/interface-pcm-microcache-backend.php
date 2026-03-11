<?php
/**
 * Durable Origin Microcache - Backend interface.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PCM_Microcache_Backend {
	public function get( string $key ): ?array;
	public function set( string $key, mixed $payload, int $ttl, array $tags, string $builder_name ): bool;
	public function invalidate_by_tags( array $tags ): int;
	public function invalidate_key( string $key ): bool;
	public function flush_all(): int;
	public function list_recent_events( int $limit ): array;
}
