<?php
/**
 * Redirect Assistant - Repository.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect rules repository.
 */
class PCM_Redirect_Assistant_Repository {
	protected string $option_key = 'pcm_redirect_assistant_rules_v1';

	/**
	 * List all rules ordered by priority ASC, id ASC.
	 *
	 * @return array
	 */
	public function list_rules(): array {
		$rules = get_option( $this->option_key, array() );

		if ( ! is_array( $rules ) ) {
			return array();
		}

		usort(
			$rules,
			static function ( array $a, array $b ): int {
				$priority_a = isset( $a['priority'] ) ? absint( $a['priority'] ) : 999;
				$priority_b = isset( $b['priority'] ) ? absint( $b['priority'] ) : 999;

				if ( $priority_a === $priority_b ) {
					return strcmp( (string) $a['id'], (string) $b['id'] );
				}

				return ( $priority_a < $priority_b ) ? -1 : 1;
			}
		);

		return $rules;
	}

	/**
	 * Upsert one rule.
	 *
	 * @param array $rule Rule payload.
	 *
	 * @return string Rule ID.
	 */
	public function upsert_rule( array $rule ): string {
		$sanitized = pcm_redirect_assistant_sanitize_rule( $rule );
		$rules     = $this->list_rules();

		$found = false;
		foreach ( $rules as $index => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $sanitized['id'] ) {
				$rules[ $index ] = $sanitized;
				$found           = true;
				break;
			}
		}

		if ( ! $found ) {
			$rules[] = $sanitized;
		}

		update_option( $this->option_key, array_values( $rules ), false );

		return $sanitized['id'];
	}

	/**
	 * Delete rule by ID.
	 *
	 * @param string $rule_id Rule ID.
	 *
	 * @return bool
	 */
	public function delete_rule( string $rule_id ): bool {
		$rule_id = sanitize_key( $rule_id );
		$rules   = $this->list_rules();

		$filtered = array_values(
			array_filter(
				$rules,
				static function ( array $rule ) use ( $rule_id ): bool {
					return ! isset( $rule['id'] ) || $rule['id'] !== $rule_id;
				}
			)
		);

		update_option( $this->option_key, $filtered, false );

		return count( $filtered ) < count( $rules );
	}
}
