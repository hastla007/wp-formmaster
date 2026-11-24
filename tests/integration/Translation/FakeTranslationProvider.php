<?php
/**
 * Translation provider for tests (SET-FC-36): keeps registered texts and translations in memory.
 *
 * By default translations are found by name, like WPML (an outdated translation is still returned
 * after the source text changed); with $by_source by the source text, like Polylang.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Translation;

use FormCaptain\Integrations\TranslationProviderInterface;

final class FakeTranslationProvider implements TranslationProviderInterface {

	public ?string $current = 'de';

	/** @var array<string,string> language code => locale */
	public array $langs = array(
		'de' => 'de_DE',
		'en' => 'en_US',
		'fr' => 'fr_FR',
	);

	/** @var array<string,string> "context|name" => value */
	public array $registered = array();

	/** @var array<string,array<string,string>> language => name (or source text) => translation */
	public array $translations = array();

	/** @var array<string,int> "post_id|lang" => translated post ID */
	public array $posts = array();

	public function __construct( private string $key = 'fake', private bool $active = true, public bool $by_source = false ) {}

	public function id(): string {
		return $this->key;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function current_language(): ?string {
		return $this->current;
	}

	public function languages(): array {
		return array_keys( $this->langs );
	}

	public function locale( string $lang ): ?string {
		return $this->langs[ $lang ] ?? null;
	}

	public function register( string $context, string $name, string $value ): void {
		$this->registered[ $context . '|' . $name ] = $value;
	}

	public function translate( string $context, string $name, string $value, ?string $lang = null ): string {
		$lang = $lang ?? $this->current;
		$key  = $this->by_source ? $value : $name;
		return (string) ( $this->translations[ (string) $lang ][ $key ] ?? $value );
	}

	public function translated_post_id( int $post_id, string $lang ): ?int {
		return $this->posts[ $post_id . '|' . $lang ] ?? null;
	}
}
