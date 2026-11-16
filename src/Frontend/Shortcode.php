<?php
/**
 * Shortcode `[form_captain id="<public_id or id>"]` (P3-03).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Frontend;

use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;

final class Shortcode {

	public const TAG = 'form_captain';

	public function __construct( private Plugin $plugin, private FormRepository $forms ) {}

	public function register( Plugin $plugin ): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'id'      => '',
				'title'   => '',
				'context' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$form = $this->resolve( (string) $atts['id'] );
		if ( null === $form ) {
			return current_user_can( Capabilities::MANAGE_FORMS )
				? '<div class="fc-notice fc-notice--editor" role="note"><p>' . esc_html__( 'Form Captain: The form in this shortcode does not exist (anymore).', 'form-captain' ) . '</p></div>'
				: '';
		}
		if ( YoastCompat::is_analysis_request() ) {
			return YoastCompat::analysis_markup( $form, $this->plugin );
		}
		$args = array( 'title' => in_array( strtolower( (string) $atts['title'] ), array( '1', 'yes', 'true' ), true ) );
		if ( '' !== $atts['context'] && preg_match( '/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]{43}$/', (string) $atts['context'] ) ) {
			$args['context'] = (string) $atts['context'];
		}
		return $this->plugin->get( Renderer::class )->render( $form, $args );
	}

	/**
	 * Accepts a 16-character public ID or a numeric ID; anything else is rejected without a query.
	 *
	 * @return array<string,mixed>|null
	 */
	public function resolve( string $id ): ?array {
		$id = trim( $id );
		if ( preg_match( '/^[a-z0-9]{16}$/', $id ) ) {
			$form = $this->forms->find_by_public_id( $id );
			if ( $form || ! ctype_digit( $id ) ) {
				return $form;
			}
		}
		if ( preg_match( '/^[1-9][0-9]{0,18}$/', $id ) ) {
			return $this->forms->find( (int) $id );
		}
		return null;
	}
}
