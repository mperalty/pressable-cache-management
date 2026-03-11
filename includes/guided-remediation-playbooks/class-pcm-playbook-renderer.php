<?php
/**
 * Guided Remediation Playbooks - Renderer.
 *
 * @package PressableCacheManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe markdown renderer for admin panel.
 */
class PCM_Playbook_Renderer {
	/**
	 * Render ordered lists with support for indented bullet sub-items.
	 *
	 * @param string $text Escaped markdown.
	 *
	 * @return string
	 */
	protected function render_ordered_lists( string $text ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $text );

		if ( ! is_array( $lines ) ) {
			return $text;
		}

		$output      = array();
		$in_ordered  = false;
		$ordered_buf = '';
		$count       = count( $lines );

		for ( $i = 0; $i < $count; $i++ ) {
			$line = $lines[ $i ];

			if ( preg_match( '/^\d+\.\s+(.+)$/', trim( $line ), $match ) ) {
				if ( ! $in_ordered ) {
					$ordered_buf = '<ol>';
					$in_ordered  = true;
				}

				$item_text = $match[1];
				$subitems  = array();

				for ( $j = $i + 1; $j < $count; $j++ ) {
					$next = $lines[ $j ];

					if ( preg_match( '/^\s*-\s+(.+)$/', $next, $sub_match ) ) {
						$subitems[] = $sub_match[1];
						continue;
					}

					if ( '' === trim( $next ) ) {
						continue;
					}

					break;
				}

				$ordered_buf .= '<li>' . $item_text;

				if ( ! empty( $subitems ) ) {
					$ordered_buf .= '<ul>';
					foreach ( $subitems as $subitem ) {
						$ordered_buf .= '<li>' . $subitem . '</li>';
					}
					$ordered_buf .= '</ul>';
				}

				$ordered_buf .= '</li>';

				if ( ! empty( $subitems ) ) {
					$i = $j - 1;
				}

				continue;
			}

			if ( $in_ordered ) {
				$ordered_buf .= '</ol>';
				$output[]     = $ordered_buf;
				$ordered_buf  = '';
				$in_ordered   = false;
			}

			$output[] = $line;
		}

		if ( $in_ordered ) {
			$ordered_buf .= '</ol>';
			$output[]     = $ordered_buf;
		}

		return implode( "\n", $output );
	}

	/**
	 * @param string $markdown Markdown.
	 *
	 * @return string
	 */
	public function render( string $markdown ): string {
		$escaped = esc_html( $markdown );

		$escaped = preg_replace( '/^###\s+(.+)$/m', '<h4>$1</h4>', $escaped );
		$escaped = preg_replace( '/^##\s+(.+)$/m', '<h3>$1</h3>', $escaped );
		$escaped = preg_replace( '/^#\s+(.+)$/m', '<h2>$1</h2>', $escaped );
		$escaped = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped );
		$escaped = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $escaped );

		$escaped = $this->render_ordered_lists( $escaped );

		$escaped = preg_replace_callback(
			'/(?:^|\n)((?:-\s+[^\n]+(?:\n|$))+)/',
			static function ( array $matches ): string {
				$lines = preg_split( '/\n+/', trim( $matches[1] ) );
				$items = array();

				foreach ( $lines as $line ) {
					if ( preg_match( '/^-\s+(.+)$/', trim( $line ), $item_match ) ) {
						$items[] = $item_match[1];
					}
				}

				if ( empty( $items ) ) {
					return $matches[0];
				}

				$out = '<ul>';
				foreach ( $items as $item ) {
					$out .= '<li>' . $item . '</li>';
				}
				$out .= '</ul>';

				return "\n" . $out . "\n";
			},
			$escaped
		);

		$escaped = wpautop( $escaped );

		return wp_kses(
			$escaped,
			array(
				'h2'     => array(),
				'h3'     => array(),
				'h4'     => array(),
				'strong' => array(),
				'code'   => array(),
				'p'      => array(),
				'br'     => array(),
				'ol'     => array(),
				'ul'     => array(),
				'li'     => array(),
			)
		);
	}
}
