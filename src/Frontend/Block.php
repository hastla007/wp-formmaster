<?php
/**
 * Dynamic Gutenberg block `form-captain/form` (P3-02). Same renderer as the shortcode.
 *
 * The signed suite context is never a block attribute (editors cannot set it); adapters use the
 * filter `form_captain_render_context_v1` instead.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Frontend;

use FormCaptain\Plugin;
use FormCaptain\Support\Asset;

final class Block {

	public const NAME          = 'form-captain/form';
	public const EDITOR_HANDLE = 'form-captain-block-editor';

	public function __construct( private Plugin $plugin, private Shortcode $shortcode ) {}

	public function register( Plugin $plugin ): void {
		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', array( $this, 'register_block' ) );
		}
	}

	public function register_block(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}
		wp_register_script(
			self::EDITOR_HANDLE,
			Asset::url( 'block-editor.js' ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-block-editor', 'wp-server-side-render' ),
			Asset::version( 'block-editor.js' ),
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'form-captain', FORM_CAPTAIN_DIR . 'languages' );
		register_block_type(
			FORM_CAPTAIN_DIR . 'blocks/form',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function render( array $attributes = array() ): string {
		$id   = isset( $attributes['formId'] ) && is_scalar( $attributes['formId'] ) ? (string) $attributes['formId'] : '';
		$form = '' !== $id ? $this->shortcode->resolve( $id ) : null;
		if ( null === $form ) {
			return $this->plugin->get( Renderer::class )->render( array() );
		}
		return $this->plugin->get( Renderer::class )->render( $form );
	}
}
