<?php
/**
 * HTML allowlist for html notes and consent texts (P2-09).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain;

final class HtmlSanitizer {

	/** @return array<string,array<string,bool>> */
	public static function allowed(): array {
		return array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'h3'     => array(),
			'h4'     => array(),
			'span'   => array( 'class' => true ),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
		);
	}

	public static function clean( string $html ): string {
		$html = wp_kses( $html, self::allowed(), array( 'http', 'https', 'mailto' ) );
		// Enforce rel="noopener noreferrer" on target links.
		$html = (string) preg_replace_callback(
			'/<a\s([^>]*)>/i',
			static function ( array $m ): string {
				$attrs = $m[1];
				if ( preg_match( '/\btarget=/i', $attrs ) ) {
					$attrs = (string) preg_replace( '/\srel=("[^"]*"|\'[^\']*\')/i', '', ' ' . $attrs );
					$attrs = trim( $attrs ) . ' rel="noopener noreferrer"';
				}
				return '<a ' . trim( $attrs ) . '>';
			},
			$html
		);
		return mb_substr( $html, 0, 20000 );
	}
}
