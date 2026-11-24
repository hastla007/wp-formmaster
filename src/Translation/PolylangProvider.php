<?php
/**
 * Adapter for Polylang (P9-07).
 *
 * Uses Polylang's documented function API: `pll_register_string`, `pll_translate_string`,
 * `pll_current_language`, `pll_languages_list` and `pll_get_post`. Minimum version: Polylang 3.7.0.
 * Polylang keeps registered strings only for the current request, so Form Captain registers the
 * texts of all published forms again when its string translation screen is opened
 * (Translation\TranslationModule).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Translation;

use FormCaptain\Integrations\TranslationProviderInterface;

final class PolylangProvider implements TranslationProviderInterface {

	public const MIN_VERSION = '3.7.0';

	/** Admin page of Polylang's string translations. */
	public const STRINGS_PAGE = 'mlang_strings';

	/**
	 * @param string|null            $version   detected Polylang version; null reads POLYLANG_VERSION
	 * @param array<string,callable> $functions replacements for the pll_* functions (tests)
	 */
	public function __construct( private ?string $version = null, private array $functions = array() ) {}

	public function id(): string {
		return 'polylang';
	}

	public function version(): string {
		if ( null !== $this->version ) {
			return $this->version;
		}
		return defined( 'POLYLANG_VERSION' ) ? (string) constant( 'POLYLANG_VERSION' ) : '';
	}

	public function is_active(): bool {
		$version = $this->version();
		return '' !== $version && version_compare( $version, self::MIN_VERSION, '>=' ) && $this->has( 'pll_translate_string' );
	}

	public function current_language(): ?string {
		$lang = $this->call( 'pll_current_language', 'slug' );
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	/** @return string[] */
	public function languages(): array {
		$list = $this->call( 'pll_languages_list', array( 'fields' => 'slug' ) );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ), static fn( string $slug ): bool => '' !== $slug ) ) : array();
	}

	public function locale( string $lang ): ?string {
		$slugs   = $this->languages();
		$locales = $this->call( 'pll_languages_list', array( 'fields' => 'locale' ) );
		$index   = array_search( $lang, $slugs, true );
		if ( false === $index || ! is_array( $locales ) ) {
			return null;
		}
		$locales = array_values( $locales );
		return isset( $locales[ $index ] ) && is_string( $locales[ $index ] ) && '' !== $locales[ $index ] ? $locales[ $index ] : null;
	}

	public function register( string $context, string $name, string $value ): void {
		$this->call( 'pll_register_string', $name, $value, $context, str_contains( $value, "\n" ) || strlen( $value ) > 100 );
	}

	public function translate( string $context, string $name, string $value, ?string $lang = null ): string {
		unset( $context, $name ); // Polylang finds a translation by its source text.
		$lang = $lang ?? $this->current_language();
		if ( null === $lang ) {
			return $value;
		}
		$out = $this->call( 'pll_translate_string', $value, $lang );
		return is_string( $out ) && '' !== $out ? $out : $value;
	}

	public function translated_post_id( int $post_id, string $lang ): ?int {
		$id = $this->call( 'pll_get_post', $post_id, $lang );
		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	private function has( string $fn ): bool {
		return isset( $this->functions[ $fn ] ) || function_exists( $fn );
	}

	private function call( string $fn, mixed ...$args ): mixed {
		if ( isset( $this->functions[ $fn ] ) ) {
			return ( $this->functions[ $fn ] )( ...$args );
		}
		return function_exists( $fn ) ? call_user_func_array( $fn, $args ) : null;
	}
}
