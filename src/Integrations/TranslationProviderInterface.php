<?php
/**
 * Public contract for multilingual plugins (P9-07, SET-FC-36, contract 1.5.0).
 *
 * A provider connects Form Captain to a multilingual plugin. Form Captain registers the texts of a
 * published form with it and asks it for the text in the current language. The core ships adapters
 * for WPML and Polylang; further providers are added through the filter
 * `form_captain_translation_providers_v1` (docs/spec/api-v1.md). Exactly one provider may be active.
 *
 * This interface is part of the public API. Changes within v1 are additive only.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Integrations;

interface TranslationProviderInterface {

	/** Stable key, ^[a-z0-9_]{2,40}$ (e.g. "wpml", "polylang"). */
	public function id(): string;

	/** True when the multilingual plugin is loaded in a supported version. */
	public function is_active(): bool;

	/** Code of the current language (as the plugin names it, e.g. "en") or null. */
	public function current_language(): ?string;

	/**
	 * Codes of the active languages.
	 *
	 * @return string[]
	 */
	public function languages(): array;

	/** WordPress locale of a language code (e.g. "en" → "en_US") or null when unknown. */
	public function locale( string $lang ): ?string;

	/** Registers a source text (idempotent; the same context and name replace the value). */
	public function register( string $context, string $name, string $value ): void;

	/**
	 * Text in the language $lang (null = current language). Returns $value when there is no
	 * translation; never an empty string for a non-empty $value.
	 */
	public function translate( string $context, string $name, string $value, ?string $lang = null ): string;

	/** ID of the translation of a post or page in $lang, or null when there is none. */
	public function translated_post_id( int $post_id, string $lang ): ?int;
}
