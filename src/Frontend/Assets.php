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
			// Dependency-free proof-of-work solver (SET-FC-05); only does work when the token route sends a challenge.
			wp_register_script(
				self::HANDLE . '-pow',
				Asset::url( 'proof-of-work.js' ),
				array(),
				Asset::version( 'proof-of-work.js' ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_register_script(
				self::HANDLE,
				Asset::url( 'frontend.js' ),
				array( self::HANDLE . '-pow' ),
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
			wp_add_inline_style( self::HANDLE, self::base_css() );
		}
		if ( empty( self::$modes['custom'] ) ) {
			self::$modes['custom'] = true;
			$custom                = self::custom_css();
			if ( '' !== $custom ) {
				wp_add_inline_style( self::HANDLE, $custom );
			}
		}
		self::$modes[ $mode ] = true;
	}

	/**
	 * Variables of the base styles (SET-FC-32): accent colour and, when changed from the default,
	 * corner radius, space between fields and font size.
	 */
	public static function base_css(): string {
		$accent = sanitize_hex_color( (string) Settings::get( 'appearance.accent_color' ) ) ?: '#2271b1';
		$css    = '--fc-accent:' . $accent . ';';
		$radius = max( 0, min( 24, (int) Settings::get( 'appearance.radius' ) ) );
		if ( 4 !== $radius ) {
			$css .= '--fc-radius:' . $radius . 'px;';
		}
		$spacing = max( 0, min( 48, (int) Settings::get( 'appearance.spacing' ) ) );
		if ( 16 !== $spacing ) {
			$css .= '--fc-gap:' . $spacing . 'px;';
		}
		$size = (string) Settings::get( 'appearance.font_size' );
		if ( in_array( $size, array( '14', '16', '18', '20' ), true ) ) {
			$css .= 'font-size:' . $size . 'px;';
		}
		return '.fc-mode-base{' . $css . '}';
	}

	/**
	 * Custom CSS of the operator (SET-FC-32). Stored only by users with unfiltered_html and checked
	 * on saving; "<" is removed again here so the value can never end the style element.
	 */
	public static function custom_css(): string {
		$css = (string) Settings::get( 'appearance.custom_css' );
		return trim( str_replace( '<', '', $css ) );
	}
}
