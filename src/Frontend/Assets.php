<?php
/**
 * Frontend assets: loaded only on pages that actually contain a Form Captain form (P3-22, AC-061).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Frontend;

use FormCaptain\Plugin;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Asset;

final class Assets {

	public const HANDLE = 'form-captain-frontend';

	/** @var bool set when the renderer produced a form in this request */
	private static bool $rendered = false;

	/** @var array<string,bool> appearance modes used on the page */
	private static array $modes = array();

	public function register( Plugin $plugin ): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/** Early enqueue (in <head>) for singular content with the block or shortcode. */
	public function maybe_enqueue(): void {
		$post = get_post();
		if ( ! is_singular() || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( has_block( Block::NAME, $post ) || has_shortcode( (string) $post->post_content, Shortcode::TAG ) ) {
			self::enqueue( (string) Settings::get( 'appearance.mode' ) );
		}
	}

	/** Called by the renderer for every rendered form (late enqueue works for footer scripts). */
	public static function mark_rendered( string $mode ): void {
		self::$rendered = true;
		self::enqueue( $mode );
	}

	public static function was_rendered(): bool {
		return self::$rendered;
	}

	/** Test helper. */
	public static function reset(): void {
		self::$rendered = false;
		self::$modes    = array();
	}

	public static function enqueue( string $mode ): void {
		$mode = 'base' === $mode ? 'base' : 'inherit';
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_register_style( self::HANDLE, Asset::url( 'frontend.css' ), array(), Asset::version( 'frontend.css' ) );
			wp_register_script(
				self::HANDLE,
				Asset::url( 'frontend.js' ),
				array(),
				Asset::version( 'frontend.js' ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			// Configuration only – no nonces, no user data: safe for full-page caches.
			wp_add_inline_script(
				self::HANDLE,
				'window.formCaptain=' . wp_json_encode(
					array(
						'restRoot' => esc_url_raw( rest_url( 'form-captain/v1/' ) ),
						'i18n'     => array(
							'sending'      => __( 'Sending…', 'form-captain' ),
							'summary'      => __( 'Please check the following fields:', 'form-captain' ),
							'network'      => __( 'The form could not be sent. Please check your connection and try again.', 'form-captain' ),
							'preview'      => __( 'Preview: nothing was sent.', 'form-captain' ),
							'required'     => __( 'This field is required.', 'form-captain' ),
							'invalidEmail' => __( 'Please enter a valid email address.', 'form-captain' ),
							/* translators: %s: field label */
							'fieldShown'   => __( 'Additional field shown: %s', 'form-captain' ),
							/* translators: %s: field label */
							'fieldHidden'  => __( 'Field hidden: %s', 'form-captain' ),
						),
					)
				) . ';',
				'before'
			);
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		if ( 'base' === $mode && empty( self::$modes['base'] ) ) {
			$accent = sanitize_hex_color( (string) Settings::get( 'appearance.accent_color' ) ) ?: '#2271b1';
			wp_add_inline_style( self::HANDLE, '.fc-mode-base{--fc-accent:' . $accent . ';}' );
		}
		self::$modes[ $mode ] = true;
	}
}
