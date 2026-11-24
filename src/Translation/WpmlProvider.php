<?php
/**
 * Adapter for WPML (Multilingual CMS + String Translation), P9-07.
 *
 * Uses only WPML's documented hook API: `wpml_register_single_string`,
 * `wpml_translate_single_string`, `wpml_current_language`, `wpml_active_languages` and
 * `wpml_object_id`. Minimum version: WPML 4.7.0 (String Translation for the texts; without it
 * only the confirmation page follows the language and texts stay in the source language).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// WPML's own hook names (documented WPML API), not hooks of this plugin.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

namespace FormCaptain\Translation;

use FormCaptain\Integrations\TranslationProviderInterface;

final class WpmlProvider implements TranslationProviderInterface {

	public const MIN_VERSION = '4.7.0';

	/** @var array<string,array<string,mixed>>|null */
	private ?array $languages = null;

	/**
	 * @param string|null $version detected WPML version; null reads ICL_SITEPRESS_VERSION (tests pass one)
	 */
	public function __construct( private ?string $version = null ) {}

	public function id(): string {
		return 'wpml';
	}

	public function version(): string {
		if ( null !== $this->version ) {
			return $this->version;
		}
		return defined( 'ICL_SITEPRESS_VERSION' ) && class_exists( 'SitePress', false ) ? (string) constant( 'ICL_SITEPRESS_VERSION' ) : '';
	}

	public function is_active(): bool {
		$version = $this->version();
		return '' !== $version && version_compare( $version, self::MIN_VERSION, '>=' );
	}

	public function current_language(): ?string {
		$lang = apply_filters( 'wpml_current_language', null );
		return is_string( $lang ) && '' !== $lang && 'all' !== $lang ? $lang : null;
	}

	/** @return string[] */
	public function languages(): array {
		return array_map( 'strval', array_keys( $this->active_languages() ) );
	}

	public function locale( string $lang ): ?string {
		$locale = $this->active_languages()[ $lang ]['default_locale'] ?? null;
		return is_string( $locale ) && '' !== $locale ? $locale : null;
	}

	public function register( string $context, string $name, string $value ): void {
		do_action( 'wpml_register_single_string', $context, $name, $value );
	}

	public function translate( string $context, string $name, string $value, ?string $lang = null ): string {
		$out = null === $lang
			? apply_filters( 'wpml_translate_single_string', $value, $context, $name )
			: apply_filters( 'wpml_translate_single_string', $value, $context, $name, $lang );
		return is_string( $out ) && '' !== $out ? $out : $value;
	}

	public function translated_post_id( int $post_id, string $lang ): ?int {
		$type = (string) get_post_type( $post_id );
		$id   = apply_filters( 'wpml_object_id', $post_id, '' !== $type ? $type : 'page', false, $lang );
		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	/** @return array<string,array<string,mixed>> */
	private function active_languages(): array {
		if ( null === $this->languages ) {
			$list            = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			$this->languages = array();
			foreach ( is_array( $list ) ? $list : array() as $code => $lang ) {
				if ( is_array( $lang ) ) {
					$this->languages[ (string) ( $lang['code'] ?? $code ) ] = $lang;
				}
			}
		}
		return $this->languages;
	}
}
