<?php
/**
 * Form contents per language (P9-07, SET-FC-36, decision 23, variant A).
 *
 * One form, one field structure, the same public ID in every language. The texts of a published
 * form are registered with the active multilingual plugin (exactly one provider, see
 * `form_captain_translation_providers_v1`) and resolved in the current language when the form is
 * shown, submitted or mailed. Field values and option values are never translated.
 *
 * Each registered text carries the hash of its source text (option `form_captain_i18n_{form_id}`;
 * no translations are stored by Form Captain). A translation made for an earlier source text is
 * "outdated": the source text is shown until the translation is updated. A missing translation
 * also falls back to the source text.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Translation;

use FormCaptain\Domain\HtmlSanitizer;
use FormCaptain\Integrations\TranslationProviderInterface;
use FormCaptain\Plugin;
use FormCaptain\Service\FormSchemaLoader;
use FormCaptain\Service\SubmissionService;

final class Translations {

	public const FILTER        = 'form_captain_translation_providers_v1';
	public const OPTION_PREFIX = 'form_captain_i18n_';

	/** Visitor-facing form settings that are translated (`settings.<path>`). */
	public const SETTINGS = array(
		'confirmation.message',
		'paused_message',
		'confirmation_mail.subject',
		'confirmation_mail.body',
		'submit_label',
		'texts.required_hint',
		'texts.error_summary',
		'limits.closed_message',
		'double_opt_in.subject',
		'double_opt_in.body',
		'double_opt_in.pending_message',
	);

	/** Field properties that are translated as `{field_id}.<property>`. */
	private const FIELD_TEXTS = array( 'label', 'help', 'placeholder' );

	/** @var array{provider:?TranslationProviderInterface,active:string[],unsupported:array<string,array{version:string,minimum:string}>}|null */
	private static ?array $resolved = null;

	/**
	 * Language of the current request taken from an accepted `lang` parameter.
	 *
	 * @var string|null
	 */
	private static ?string $override = null;

	/** @var array<int,array<string,mixed>> per-form records, see data() */
	private static array $data = array();

	/** For tests and after plugins were (de)activated in the same request. */
	public static function reset(): void {
		self::$resolved = null;
		self::$override = null;
		self::$data     = array();
	}

	// Provider.

	/** @return array{provider:?TranslationProviderInterface,active:string[],unsupported:array<string,array{version:string,minimum:string}>} */
	private static function resolve(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}
		$list        = apply_filters( 'form_captain_translation_providers_v1', array( new WpmlProvider(), new PolylangProvider() ) );
		$active      = array();
		$unsupported = array();
		$seen        = array();
		foreach ( is_array( $list ) ? $list : array() as $provider ) {
			if ( ! $provider instanceof TranslationProviderInterface ) {
				continue;
			}
			try {
				$id = $provider->id();
				if ( ! preg_match( '/^[a-z0-9_]{2,40}$/', $id ) || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				if ( $provider->is_active() ) {
					$active[ $id ] = $provider;
				} elseif ( ( $provider instanceof WpmlProvider || $provider instanceof PolylangProvider ) && '' !== $provider->version() ) {
					$unsupported[ $id ] = array(
						'version' => $provider->version(),
						'minimum' => $provider::MIN_VERSION,
					);
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		$resolved = array(
			'provider'    => 1 === count( $active ) ? reset( $active ) : null,
			'active'      => array_keys( $active ),
			'unsupported' => $unsupported,
		);
		if ( did_action( 'plugins_loaded' ) ) {
			// Multilingual plugins are loaded by now; earlier calls are not cached.
			self::$resolved = $resolved;
		}
		return $resolved;
	}

	/** The one active provider, or null (none, or several active: then none is used). */
	public static function provider(): ?TranslationProviderInterface {
		return self::resolve()['provider'];
	}

	/**
	 * For the system status: active providers, the one in use and unsupported versions.
	 *
	 * @return array{provider:string,active:string[],conflict:bool,unsupported:array<string,array{version:string,minimum:string}>}
	 */
	public static function status(): array {
		$r = self::resolve();
		return array(
			'provider'    => null !== $r['provider'] ? $r['provider']->id() : '',
			'active'      => $r['active'],
			'conflict'    => count( $r['active'] ) > 1,
			'unsupported' => $r['unsupported'],
		);
	}

	// Language.

	/** Current language code of the provider (or of an accepted `lang` parameter), null without provider. */
	public static function language(): ?string {
		$provider = self::provider();
		if ( null === $provider ) {
			return null;
		}
		if ( null !== self::$override ) {
			return self::$override;
		}
		try {
			$lang = $provider->current_language();
			return null !== $lang && in_array( $lang, $provider->languages(), true ) ? $lang : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** A language code from a request, accepted only when the provider reports it as active. */
	public static function accept( mixed $lang ): ?string {
		$provider = self::provider();
		if ( null === $provider || ! is_string( $lang ) || ! preg_match( '/^[A-Za-z0-9_-]{2,20}$/', $lang ) ) {
			return null;
		}
		return in_array( $lang, $provider->languages(), true ) ? $lang : null;
	}

	/**
	 * Runs $callback in the language of a request parameter: texts of the form and messages of
	 * Form Captain (switch_to_locale) follow it. Unknown languages are ignored.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function with_language( mixed $lang, callable $callback ): mixed {
		$accepted = self::accept( $lang );
		if ( null === $accepted ) {
			return $callback();
		}
		$previous       = self::$override;
		self::$override = $accepted;
		$locale         = self::locale_for( $accepted );
		$switched       = null !== $locale && function_exists( 'switch_to_locale' ) && $locale !== determine_locale() && switch_to_locale( $locale );
		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			self::$override = $previous;
		}
	}

	/** WordPress locale of the language of an accepted `lang` parameter, or null. */
	public static function request_locale(): ?string {
		return null !== self::$override ? self::locale_for( self::$override ) : null;
	}

	public static function locale_for( ?string $lang ): ?string {
		$provider = self::provider();
		if ( null === $provider || null === $lang || '' === $lang ) {
			return null;
		}
		$locale = $provider->locale( $lang );
		return null !== $locale && preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]{1,12})?$/', $locale ) ? $locale : null;
	}

	/** Language code of a stored entry locale (e.g. for the confirmation email), or null. */
	public static function lang_for_locale( string $locale ): ?string {
		$provider = self::provider();
		if ( null === $provider || '' === $locale ) {
			return null;
		}
		foreach ( $provider->languages() as $lang ) {
			if ( $provider->locale( $lang ) === $locale ) {
				return $lang;
			}
		}
		return null;
	}

	/**
	 * Locales of the provider's languages (accepted as entry locale, SET-FC-21).
	 *
	 * @return string[]
	 */
	public static function locales(): array {
		$provider = self::provider();
		$out      = array();
		foreach ( null !== $provider ? $provider->languages() : array() as $lang ) {
			$locale = self::locale_for( $lang );
			if ( null !== $locale ) {
				$out[] = $locale;
			}
		}
		return $out;
	}

	// Texts.

	/**
	 * Context under which the texts of a form are registered.
	 *
	 * @param array<string,mixed> $form
	 */
	public static function context( array $form ): string {
		return 'form-captain/' . (string) ( $form['public_id'] ?? '' );
	}

	public static function hash( string $text ): string {
		return substr( hash( 'sha256', $text ), 0, 20 );
	}

	/** Name of an option label (`{field_id}.option.{value}`; long values by hash). */
	public static function option_key( string $field_id, string $value ): string {
		return $field_id . '.option.' . ( strlen( $value ) > 100 ? 'h' . substr( md5( $value ), 0, 12 ) : $value );
	}

	/**
	 * Translatable texts of a form: name => source text (docs/planung/mehrsprachigkeit.md, table 2).
	 *
	 * @param array<string,mixed>            $form
	 * @param array<int,array<string,mixed>> $fields fields of the published version
	 * @return array<string,string>
	 */
	public static function strings( array $form, array $fields ): array {
		$out   = array();
		$title = trim( (string) ( $form['title'] ?? '' ) );
		if ( '' !== $title ) {
			$out['title'] = $title;
		}
		foreach ( $fields as $field ) {
			$id = (string) ( $field['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			foreach ( self::FIELD_TEXTS as $prop ) {
				if ( self::filled( $field[ $prop ] ?? null ) ) {
					$out[ $id . '.' . $prop ] = (string) $field[ $prop ];
				}
			}
			foreach ( is_array( $field['options'] ?? null ) ? $field['options'] : array() as $option ) {
				if ( is_array( $option ) && isset( $option['value'] ) && self::filled( $option['label'] ?? null ) ) {
					$out[ self::option_key( $id, (string) $option['value'] ) ] = (string) $option['label'];
				}
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( 'html' === $type && self::filled( $field['content'] ?? null ) ) {
				$out[ $id . '.content' ] = (string) $field['content'];
			}
			if ( 'consent' === $type && self::filled( $field['consent_text'] ?? null ) ) {
				$out[ $id . '.consent_text' ] = (string) $field['consent_text'];
			}
		}
		$settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();
		foreach ( self::SETTINGS as $path ) {
			$value = self::get_path( $settings, $path );
			if ( self::filled( $value ) ) {
				$out[ 'settings.' . $path ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * One text in $lang (null = current language): the translation when it was made for the
	 * current source text, otherwise the source text.
	 *
	 * @param array<string,mixed> $form
	 */
	public static function text( array $form, string $name, string $value, ?string $lang = null ): string {
		$provider = self::provider();
		$lang     = $lang ?? self::language();
		if ( null === $provider || null === $lang || '' === trim( $value ) || empty( $form['id'] ) ) {
			return $value;
		}
		$data = self::data( (int) $form['id'] );
		$rec  = $data['strings'][ $name ] ?? null;
		if ( ! is_array( $rec ) || ( $rec['h'] ?? '' ) !== self::hash( $value ) ) {
			// Not registered for this source text (changed since publishing): source language.
			return $value;
		}
		try {
			$out = $provider->translate( self::context( $form ), $name, $value, $lang );
		} catch ( \Throwable $e ) {
			return $value;
		}
		if ( '' === trim( $out ) || $out === $value ) {
			return $value;
		}
		if ( ( $data['stale'][ $lang ][ $name ] ?? null ) === self::hash( $out ) ) {
			return $value; // Made for an earlier source text: outdated.
		}
		return $out;
	}

	/**
	 * Fields with their texts in $lang. Translated HTML (html content, consent text) passes the
	 * allowlist again.
	 *
	 * @param array<string,mixed>            $form
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<int,array<string,mixed>>
	 */
	public static function localize_fields( array $form, array $fields, ?string $lang = null ): array {
		$lang = $lang ?? self::language();
		if ( null === $lang || null === self::provider() ) {
			return $fields;
		}
		foreach ( $fields as $i => $field ) {
			$id = (string) ( $field['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			foreach ( self::FIELD_TEXTS as $prop ) {
				if ( self::filled( $field[ $prop ] ?? null ) ) {
					$fields[ $i ][ $prop ] = self::text( $form, $id . '.' . $prop, (string) $field[ $prop ], $lang );
				}
			}
			if ( is_array( $field['options'] ?? null ) ) {
				foreach ( $field['options'] as $j => $option ) {
					if ( is_array( $option ) && isset( $option['value'] ) && self::filled( $option['label'] ?? null ) ) {
						$fields[ $i ]['options'][ $j ]['label'] = self::text( $form, self::option_key( $id, (string) $option['value'] ), (string) $option['label'], $lang );
					}
				}
			}
			foreach ( array( 'content', 'consent_text' ) as $prop ) {
				if ( self::filled( $field[ $prop ] ?? null ) ) {
					$text = self::text( $form, $id . '.' . $prop, (string) $field[ $prop ], $lang );
					if ( $text !== $field[ $prop ] ) {
						$fields[ $i ][ $prop ] = HtmlSanitizer::clean( $text );
					}
				}
			}
		}
		return $fields;
	}

	/**
	 * Form row with its title and visitor-facing settings in $lang.
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	public static function localize_form( array $form, ?string $lang = null ): array {
		$lang = $lang ?? self::language();
		if ( null === $lang || null === self::provider() ) {
			return $form;
		}
		if ( self::filled( $form['title'] ?? null ) ) {
			$form['title'] = self::text( $form, 'title', trim( (string) $form['title'] ), $lang );
		}
		$settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();
		foreach ( self::SETTINGS as $path ) {
			$value = self::get_path( $settings, $path );
			if ( self::filled( $value ) ) {
				$settings = self::set_path( $settings, $path, self::text( $form, 'settings.' . $path, (string) $value, $lang ) );
			}
		}
		$form['settings'] = $settings;
		return $form;
	}

	/** ID of the page in the current language (confirmation page), the original without translation. */
	public static function post_id( int $post_id, ?string $lang = null ): int {
		$provider = self::provider();
		$lang     = $lang ?? self::language();
		if ( $post_id <= 0 || null === $provider || null === $lang ) {
			return $post_id;
		}
		try {
			return $provider->translated_post_id( $post_id, $lang ) ?? $post_id;
		} catch ( \Throwable $e ) {
			return $post_id;
		}
	}

	// Registration on publish.

	/**
	 * Registers the texts of the published version (and the live settings) with the provider and
	 * marks translations of changed source texts as outdated.
	 *
	 * @param array<string,mixed> $form form row
	 */
	public static function sync( array $form, bool $register_only = false ): void {
		$provider = self::provider();
		if ( null === $provider || empty( $form['id'] ) || empty( $form['published_version_id'] ) ) {
			return;
		}
		$version = Plugin::instance()->get( FormSchemaLoader::class )->published( $form );
		if ( null === $version ) {
			return;
		}
		$context = self::context( $form );
		$strings = self::strings( $form, $version['fields'] );
		if ( $register_only ) {
			foreach ( $strings as $name => $value ) {
				$provider->register( $context, $name, $value );
			}
			return;
		}
		$form_id = (int) $form['id'];
		$data    = self::data( $form_id );
		$old     = $data['strings'];
		$langs   = $provider->languages();
		$changed = array();
		foreach ( $strings as $name => $value ) {
			$hash = self::hash( $value );
			if ( isset( $old[ $name ]['h'], $old[ $name ]['v'] ) && $old[ $name ]['h'] !== $hash ) {
				$changed[ $name ] = true;
				foreach ( $langs as $lang ) {
					// Translation of the previous source text: shown again only after it was updated.
					$previous = $provider->translate( $context, $name, (string) $old[ $name ]['v'], $lang );
					if ( '' !== trim( $previous ) && $previous !== $old[ $name ]['v'] ) {
						$data['stale'][ $lang ][ $name ] = self::hash( $previous );
					}
				}
			}
			$provider->register( $context, $name, $value );
			$data['strings'][ $name ] = array(
				'h' => $hash,
				'v' => $value,
			);
		}
		foreach ( array_keys( $data['strings'] ) as $name ) {
			if ( ! isset( $strings[ $name ] ) ) {
				unset( $data['strings'][ $name ] );
			}
		}
		foreach ( $data['stale'] as $lang => $names ) {
			$data['stale'][ $lang ] = array_intersect_key( (array) $names, $strings );
			if ( ! $data['stale'][ $lang ] ) {
				unset( $data['stale'][ $lang ] );
			}
		}
		self::$data[ $form_id ] = $data;

		// Consent texts: a changed translation raises the consent revision (datenschutz.md 5.2).
		foreach ( $version['fields'] as $field ) {
			if ( 'consent' !== ( $field['type'] ?? '' ) || ! isset( $strings[ $field['id'] . '.consent_text' ] ) ) {
				continue;
			}
			$fid  = (string) $field['id'];
			$name = $fid . '.consent_text';
			$bump = false;
			foreach ( $langs as $lang ) {
				$shown = self::consent_hash( self::text( $form, $name, $strings[ $name ], $lang ) );
				$known = $data['consent'][ $fid ][ $lang ] ?? null;
				if ( null !== $known && $known !== $shown && ! isset( $changed[ $name ] ) && isset( $old[ $name ] ) ) {
					$bump = true;
				}
				$data['consent'][ $fid ][ $lang ] = $shown;
			}
			if ( $bump ) {
				$data['consent_rev'][ $fid ] = (int) ( $data['consent_rev'][ $fid ] ?? 0 ) + 1;
			}
		}
		$data['provider'] = $provider->id();
		self::save( $form_id, $data );
	}

	/** Hash of a displayed consent text, as stored in the consent proof. */
	public static function consent_hash( string $text ): string {
		return 'sha256:' . hash( 'sha256', SubmissionService::normalize_consent_text( $text ) );
	}

	/**
	 * Consent revision for a submission: the revision of the field plus the number of detected
	 * changes of a translation of its text. A changed translation seen for the first time at a
	 * submission raises the revision there.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $field source field of the published version
	 */
	public static function consent_revision( array $form, array $field, string $shown_hash, ?string $lang ): int {
		$base = max( 1, (int) ( $field['consent_revision'] ?? 1 ) );
		if ( null === self::provider() || null === $lang || empty( $form['id'] ) ) {
			return $base;
		}
		$form_id = (int) $form['id'];
		$fid     = (string) ( $field['id'] ?? '' );
		$data    = self::data( $form_id );
		$extra   = (int) ( $data['consent_rev'][ $fid ] ?? 0 );
		$rec     = $data['strings'][ $fid . '.consent_text' ]['h'] ?? null;
		if ( $rec !== self::hash( (string) ( $field['consent_text'] ?? '' ) ) ) {
			return $base + $extra; // Older version (signed context): not compared.
		}
		$known = $data['consent'][ $fid ][ $lang ] ?? null;
		if ( $known !== $shown_hash ) {
			if ( null !== $known ) {
				++$extra;
				$data['consent_rev'][ $fid ] = $extra;
			}
			$data['consent'][ $fid ][ $lang ] = $shown_hash;
			self::save( $form_id, $data );
		}
		return $base + $extra;
	}

	/**
	 * Number of outdated translations of a form (texts × languages) for the editor hint.
	 *
	 * @param array<string,mixed> $form
	 */
	public static function outdated( array $form ): int {
		$provider = self::provider();
		if ( null === $provider || empty( $form['id'] ) ) {
			return 0;
		}
		$data  = self::data( (int) $form['id'] );
		$count = 0;
		foreach ( $data['stale'] as $lang => $names ) {
			foreach ( (array) $names as $name => $hash ) {
				$source = $data['strings'][ $name ]['v'] ?? null;
				if ( ! is_string( $source ) ) {
					continue;
				}
				$now = $provider->translate( self::context( $form ), (string) $name, $source, (string) $lang );
				if ( $now === $source || self::hash( $now ) === $hash ) {
					++$count;
				}
			}
		}
		return $count;
	}

	public static function option_name( int $form_id ): string {
		return self::OPTION_PREFIX . $form_id;
	}

	public static function forget( int $form_id ): void {
		unset( self::$data[ $form_id ] );
		delete_option( self::option_name( $form_id ) );
	}

	// Helpers.

	/** @return array{strings:array<string,array{h:string,v:string}>,stale:array<string,array<string,string>>,consent:array<string,array<string,string>>,consent_rev:array<string,int>} */
	private static function data( int $form_id ): array {
		if ( ! isset( self::$data[ $form_id ] ) ) {
			$stored                 = get_option( self::option_name( $form_id ), array() );
			self::$data[ $form_id ] = is_array( $stored ) ? $stored : array();
		}
		$data = self::$data[ $form_id ];
		foreach ( array( 'strings', 'stale', 'consent', 'consent_rev' ) as $key ) {
			$data[ $key ] = is_array( $data[ $key ] ?? null ) ? $data[ $key ] : array();
		}
		return $data;
	}

	/** @param array<string,mixed> $data */
	private static function save( int $form_id, array $data ): void {
		self::$data[ $form_id ] = $data;
		update_option( self::option_name( $form_id ), $data, false );
	}

	private static function filled( mixed $value ): bool {
		return is_string( $value ) && '' !== trim( $value );
	}

	/** @param array<string,mixed> $settings */
	private static function get_path( array $settings, string $path ): mixed {
		$value = $settings;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function set_path( array $settings, string $path, string $value ): array {
		$parts = explode( '.', $path );
		if ( 1 === count( $parts ) ) {
			$settings[ $parts[0] ] = $value;
			return $settings;
		}
		$head              = array_shift( $parts );
		$child             = is_array( $settings[ $head ] ?? null ) ? $settings[ $head ] : array();
		$settings[ $head ] = self::set_path( $child, implode( '.', $parts ), $value );
		return $settings;
	}
}
