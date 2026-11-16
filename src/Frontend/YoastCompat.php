<?php
/**
 * Optional Yoast SEO compatibility without runtime dependency (P3-18 … P3-21).
 *
 * - Registers the shortcode for Yoast's content analysis via the JS filter `yoast.analysis.shortcodes`,
 *   only in the editor and only when Yoast is active.
 * - For the analysis request the shortcode returns only public labels and help texts.
 * - Deliberately registers NO SEO filters (title, description, canonical, robots, Open Graph, X, schema,
 *   sitemap). Forms stay embedded page content.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Frontend;

use FormCaptain\Plugin;
use FormCaptain\Service\FormSchemaLoader;

final class YoastCompat {

	public const HANDLE = 'form-captain-yoast';

	public function register( Plugin $plugin ): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_script' ) );
	}

	public static function yoast_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options', false );
	}

	public function enqueue_editor_script( string $hook = '' ): void {
		if ( ! self::yoast_active() || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! wp_script_is( 'wp-hooks', 'registered' ) ) {
			return;
		}
		wp_register_script( self::HANDLE, false, array( 'wp-hooks' ), FORM_CAPTAIN_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, self::inline_script() );
	}

	public static function inline_script(): string {
		return "(function(h){if(!h||!h.addFilter){return;}h.addFilter('yoast.analysis.shortcodes','form-captain/shortcodes',function(list){list=Array.isArray(list)?list:[];if(list.indexOf('" . Shortcode::TAG . "')===-1){list.push('" . Shortcode::TAG . "');}return list;});})(window.wp&&window.wp.hooks);";
	}

	/** True while Yoast renders shortcodes for its analysis (admin AJAX). */
	public static function is_analysis_request(): bool {
		if ( ! self::yoast_active() || ! wp_doing_ajax() ) {
			return false;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'wpseo_filter_shortcodes' === $action;
	}

	/**
	 * Only visible, public texts: title-less list of labels and help texts. Never values or private data.
	 *
	 * @param array<string,mixed> $form
	 */
	public static function analysis_markup( array $form, Plugin $plugin ): string {
		if ( 'published' !== ( $form['status'] ?? '' ) ) {
			return '';
		}
		$version = $plugin->get( FormSchemaLoader::class )->published( $form );
		$out     = '';
		foreach ( $version['fields'] ?? array() as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, array( 'hidden', 'html' ), true ) ) {
				continue;
			}
			$label = (string) ( $field['label'] ?? '' );
			$help  = (string) ( $field['help'] ?? '' );
			if ( '' !== $label ) {
				$out .= '<p>' . esc_html( $label ) . ( '' !== $help ? ' – ' . esc_html( $help ) : '' ) . '</p>';
			}
		}
		return '' === $out ? '' : '<div class="fc-form-analysis">' . $out . '</div>';
	}
}
