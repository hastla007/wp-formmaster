<?php
/**
 * Adapters for WPML and Polylang (SET-FC-36) against stubbed hooks and functions of both plugins;
 * no real WPML/Polylang is loaded.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Translation;

use FormCaptain\Frontend\Renderer;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Tests\Integration\TestCase;
use FormCaptain\Translation\PolylangProvider;
use FormCaptain\Translation\Translations;
use FormCaptain\Translation\WpmlProvider;

require_once __DIR__ . '/FakeTranslationProvider.php';

final class TranslationAdaptersTest extends TestCase {

	/** @var array<int,array<int,mixed>> */
	private array $calls = array();

	/** @var array<string,array{0:string,1:callable,2:int,3:int}> */
	private array $hooks = array();

	public function set_up(): void {
		parent::set_up();
		Translations::reset();
		$this->calls = array();
	}

	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->hooks = array();
		Translations::reset();
		parent::tear_down();
	}

	private function hook( string $name, callable $callback, int $args = 1 ): void {
		add_filter( $name, $callback, 10, $args );
		$this->hooks[] = array( $name, $callback, 10, $args );
	}

	/** WPML's documented hook API, stubbed. */
	private function stub_wpml( string $current = 'en' ): void {
		$this->hook( 'wpml_current_language', static fn() => $current );
		$this->hook(
			'wpml_active_languages',
			static fn() => array(
				'de' => array( 'code' => 'de', 'default_locale' => 'de_DE' ),
				'en' => array( 'code' => 'en', 'default_locale' => 'en_US' ),
			),
			2
		);
		$this->hook(
			'wpml_register_single_string',
			function ( $context, $name, $value ): void {
				$this->calls[] = array( 'register', $context, $name, $value );
			},
			3
		);
		$this->hook(
			'wpml_translate_single_string',
			function ( $value, $context, $name, $lang = null ) use ( $current ) {
				$this->calls[] = array( 'translate', $context, $name, $lang );
				$table         = array( 'en' => array( 'f_name01.label' => 'Full name' ) );
				return $table[ $lang ?? $current ][ $name ] ?? $value;
			},
			4
		);
		$this->hook(
			'wpml_object_id',
			function ( $id, $type, $original, $lang ) {
				$this->calls[] = array( 'object_id', $id, $type, $original, $lang );
				return 'en' === $lang ? 4242 : null;
			},
			4
		);
	}

	public function test_wpml_adapter_uses_the_documented_hooks(): void {
		$this->stub_wpml();
		$wpml = new WpmlProvider( '4.7.0' );
		$this->assertSame( 'wpml', $wpml->id() );
		$this->assertTrue( $wpml->is_active() );
		$this->assertFalse( ( new WpmlProvider( '4.6.9' ) )->is_active(), 'below the minimum version' );
		$this->assertFalse( ( new WpmlProvider() )->is_active(), 'not loaded' );
		$this->assertSame( 'en', $wpml->current_language() );
		$this->assertSame( array( 'de', 'en' ), $wpml->languages() );
		$this->assertSame( 'en_US', $wpml->locale( 'en' ) );
		$this->assertNull( $wpml->locale( 'fr' ) );

		$wpml->register( 'form-captain/abc', 'f_name01.label', 'Name' );
		$this->assertSame( array( 'register', 'form-captain/abc', 'f_name01.label', 'Name' ), $this->calls[0] );
		$this->assertSame( 'Full name', $wpml->translate( 'form-captain/abc', 'f_name01.label', 'Name', 'en' ) );
		$this->assertSame( 'Name', $wpml->translate( 'form-captain/abc', 'f_name01.label', 'Name', 'de' ) );
		$this->assertSame( 'Full name', $wpml->translate( 'form-captain/abc', 'f_name01.label', 'Name' ), 'current language' );
		$this->assertSame( 'Thema', $wpml->translate( 'form-captain/abc', 'f_topic1.label', 'Thema', 'en' ), 'missing → source' );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 4242, $wpml->translated_post_id( $page, 'en' ) );
		$this->assertNull( $wpml->translated_post_id( $page, 'de' ) );
		$this->assertSame( array( 'object_id', $page, 'page', false, 'en' ), $this->calls[ count( $this->calls ) - 2 ] );
	}

	public function test_wpml_ignores_the_all_languages_mode(): void {
		$this->hook( 'wpml_current_language', static fn() => 'all' );
		$this->assertNull( ( new WpmlProvider( '4.7.0' ) )->current_language() );
	}

	/** @return array<string,callable> Polylang's function API, stubbed. */
	private function polylang_functions(): array {
		return array(
			'pll_current_language' => static fn( $field = 'slug' ) => 'slug' === $field ? 'en' : 'en_US',
			'pll_languages_list'   => static fn( $args = array() ) => 'locale' === ( $args['fields'] ?? '' ) ? array( 'de_DE', 'en_US' ) : array( 'de', 'en' ),
			'pll_register_string'  => function ( $name, $string, $context, $multiline ): void {
				$this->calls[] = array( 'register', $name, $string, $context, $multiline );
			},
			'pll_translate_string' => static fn( $string, $lang ) => array( 'en' => array( 'Name' => 'Full name' ) )[ $lang ][ $string ] ?? $string,
			'pll_get_post'         => static fn( $id, $lang ) => 'en' === $lang ? 77 : false,
		);
	}

	public function test_polylang_adapter_uses_the_documented_functions(): void {
		$pll = new PolylangProvider( '3.7.0', $this->polylang_functions() );
		$this->assertSame( 'polylang', $pll->id() );
		$this->assertTrue( $pll->is_active() );
		$this->assertFalse( ( new PolylangProvider( '3.6.4', $this->polylang_functions() ) )->is_active(), 'below the minimum version' );
		$this->assertFalse( ( new PolylangProvider( '3.7.0' ) )->is_active(), 'functions missing' );
		$this->assertFalse( ( new PolylangProvider() )->is_active(), 'not loaded' );
		$this->assertSame( 'en', $pll->current_language() );
		$this->assertSame( array( 'de', 'en' ), $pll->languages() );
		$this->assertSame( 'de_DE', $pll->locale( 'de' ) );
		$this->assertNull( $pll->locale( 'fr' ) );

		$pll->register( 'form-captain/abc', 'f_name01.label', 'Name' );
		$pll->register( 'form-captain/abc', 'settings.confirmation_mail.body', "Zeile 1\nZeile 2" );
		$this->assertSame( array( 'register', 'f_name01.label', 'Name', 'form-captain/abc', false ), $this->calls[0] );
		$this->assertTrue( $this->calls[1][4], 'multi-line texts get a text area in Polylang' );
		$this->assertSame( 'Full name', $pll->translate( 'form-captain/abc', 'f_name01.label', 'Name', 'en' ) );
		$this->assertSame( 'Full name', $pll->translate( 'form-captain/abc', 'f_name01.label', 'Name' ), 'current language' );
		$this->assertSame( 'Name', $pll->translate( 'form-captain/abc', 'f_name01.label', 'Name', 'de' ) );
		$this->assertSame( 77, $pll->translated_post_id( 5, 'en' ) );
		$this->assertNull( $pll->translated_post_id( 5, 'de' ), 'false from pll_get_post → none' );
	}

	/** Default providers: without WPML/Polylang loaded nothing is active and nothing is reported. */
	public function test_default_providers_are_inactive_without_the_plugins(): void {
		$status = Translations::status();
		$this->assertSame( '', $status['provider'] );
		$this->assertSame( array(), $status['active'] );
		$this->assertFalse( $status['conflict'] );
		$this->assertNull( Translations::language() );
	}

	/** Invalid filter results are ignored. */
	public function test_invalid_providers_from_the_filter_are_ignored(): void {
		$filter = static fn(): array => array( 'not a provider', new \stdClass(), new FakeTranslationProvider( 'Bad Id!' ) );
		add_filter( 'form_captain_translation_providers_v1', $filter );
		Translations::reset();
		$this->assertNull( Translations::provider() );
		$this->assertSame( array(), Translations::status()['active'] );
		remove_filter( 'form_captain_translation_providers_v1', $filter );
	}

	/** End to end with the WPML adapter: publish registers, the page shows the translation. */
	public function test_wpml_adapter_end_to_end(): void {
		$this->stub_wpml();
		$filter = static fn(): array => array( new WpmlProvider( '4.7.0' ), new PolylangProvider() );
		add_filter( 'form_captain_translation_providers_v1', $filter );
		Translations::reset();
		$service = $this->plugin()->get( FormService::class );
		$id      = $service->create(
			'Kontakt',
			array(
				'schema_version' => 1,
				'fields'         => array( array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ) ),
			),
			array()
		);
		$this->assertIsInt( $id );
		$this->assertIsArray( $service->publish( $id ) );
		$form       = (array) $this->plugin()->get( FormRepository::class )->find( $id );
		$registered = array_values( array_filter( $this->calls, static fn( array $c ): bool => 'register' === $c[0] ) );
		$this->assertContains( array( 'register', 'form-captain/' . $form['public_id'], 'f_name01.label', 'Name' ), $registered );
		Renderer::reset_counters();
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( 'Full name', $html );
		$this->assertStringContainsString( 'name="_fc_lang" value="en"', $html );
		remove_filter( 'form_captain_translation_providers_v1', $filter );
	}

	/** Polylang keeps strings per request: its string screen registers all published forms again. */
	public function test_polylang_string_screen_registers_published_forms(): void {
		$filter = fn(): array => array( new PolylangProvider( '3.7.0', $this->polylang_functions() ) );
		add_filter( 'form_captain_translation_providers_v1', $filter );
		Translations::reset();
		$form        = $this->create_published_form();
		$this->calls = array();
		$_GET        = array( 'page' => PolylangProvider::STRINGS_PAGE );
		$this->plugin()->get( \FormCaptain\Translation\TranslationModule::class )->register_for_string_screen();
		$_GET  = array();
		$names = array_column( $this->calls, 1 );
		$this->assertContains( 'f_name01.label', $names );
		$this->assertContains( 'title', $names );
		$this->assertContains( 'form-captain/' . $form['public_id'], array_column( $this->calls, 3 ) );
		remove_filter( 'form_captain_translation_providers_v1', $filter );
	}
}
