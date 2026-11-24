<?php
/**
 * Form contents per language (SET-FC-36, P9-07, decision 23): test matrix ML-01 … ML-12 of
 * docs/planung/mehrsprachigkeit.md with a fake provider.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions

namespace FormCaptain\Tests\Integration\Translation;

use FormCaptain\Admin\SystemStatus;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Http\AdminPostController;
use FormCaptain\Http\RestController;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormSchemaLoader;
use FormCaptain\Service\FormService;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\Frontend\FrontendTestCase;
use FormCaptain\Translation\TranslationModule;
use FormCaptain\Translation\Translations;
use FormCaptain\Translation\WpmlProvider;

require_once __DIR__ . '/FakeTranslationProvider.php';
require_once dirname( __DIR__ ) . '/Frontend/FrontendTestCase.php';

final class MultilingualTest extends FrontendTestCase {

	private FakeTranslationProvider $fake;

	/** @var array<int,object> */
	private array $providers = array();

	/** @var callable */
	private $filter;

	public function set_up(): void {
		parent::set_up();
		$this->fake      = new FakeTranslationProvider();
		$this->providers = array( $this->fake );
		$this->filter    = fn(): array => $this->providers;
		add_filter( 'form_captain_translation_providers_v1', $this->filter );
		Translations::reset();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_filter( 'form_captain_translation_providers_v1', $this->filter );
		Translations::reset();
		parent::tear_down();
	}

	private function use_providers( object ...$providers ): void {
		$this->providers = $providers;
		Translations::reset();
	}

	/** @return array<int,array<string,mixed>> */
	private function fields( string $name_label = 'Name', string $consent = 'Ich stimme der <strong>Verarbeitung</strong> zu.' ): array {
		return array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => $name_label, 'help' => 'Vor- und Nachname', 'placeholder' => 'Erika Muster', 'required' => true ),
			array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'confirmation_recipient' => true ),
			array(
				'id'      => 'f_topic1',
				'type'    => 'select',
				'key'     => 'topic',
				'label'   => 'Thema',
				'options' => array(
					array( 'value' => 'offer', 'label' => 'Angebot' ),
					array( 'value' => 'support', 'label' => 'Hilfe' ),
				),
			),
			array( 'id' => 'f_note01', 'type' => 'html', 'key' => 'note', 'content' => '<p>Hinweis <strong>wichtig</strong></p>' ),
			array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Datenschutz', 'required' => true, 'consent_text' => $consent ),
			array( 'id' => 'f_hide01', 'type' => 'hidden', 'key' => 'campaign', 'default' => 'kampagne-7' ),
		);
	}

	/** @return array<string,mixed> */
	private function settings( array $extra = array() ): array {
		return array_replace_recursive(
			array(
				'notification'      => array( 'enabled' => true, 'to' => array( 'admin@example.org' ), 'subject' => 'Neue Anfrage', 'fields' => 'all_non_sensitive' ),
				'confirmation_mail' => array( 'enabled' => true, 'subject' => 'Danke, {field:name}', 'body' => 'Wir melden uns.' ),
				'confirmation'      => array( 'type' => 'message', 'message' => 'Danke!' ),
				'paused_message'    => 'Pausiert',
			),
			$extra
		);
	}

	private function service(): FormService {
		return $this->plugin()->get( FormService::class );
	}

	private function forms(): FormRepository {
		return $this->plugin()->get( FormRepository::class );
	}

	/** @return array<string,mixed> published form row (published through FormService) */
	private function publish_form( ?array $fields = null, array $settings = array() ): array {
		$id = $this->service()->create( 'Kontakt', array( 'schema_version' => 1, 'fields' => $fields ?? $this->fields() ), $this->settings( $settings ) );
		$this->assertIsInt( $id );
		$pub = $this->service()->publish( $id );
		$this->assertIsArray( $pub, is_wp_error( $pub ) ? $pub->get_error_message() . ' ' . wp_json_encode( $pub->get_error_data() ) : '' );
		return (array) $this->forms()->find( $id );
	}

	/** @return array<string,mixed> */
	private function republish( array $form, array $fields, ?array $settings = null ): array {
		$form  = (array) $this->forms()->find( (int) $form['id'] );
		$saved = $this->service()->save( (int) $form['id'], (string) $form['title'], array( 'schema_version' => 1, 'fields' => $fields ), $settings ?? (array) $form['settings'], (int) $form['revision'] );
		$this->assertIsArray( $saved );
		$this->assertIsArray( $this->service()->publish( (int) $form['id'] ) );
		$this->plugin()->get( FormSchemaLoader::class )->flush();
		return (array) $this->forms()->find( (int) $form['id'] );
	}

	private function render( array $form ): string {
		Renderer::reset_counters();
		return $this->plugin()->get( Renderer::class )->render( (array) $this->forms()->find( (int) $form['id'] ) );
	}

	/** @return array<string,mixed> no-JS submission result */
	private function submit( array $form, ?string $lang, array $fields = array() ): array {
		$post = $this->payload(
			$form,
			$fields + array(
				'f_name01' => 'Erika Muster',
				'f_mail01' => 'erika@example.org',
				'f_topic1' => 'offer',
				'f_cons01' => '1',
			)
		);
		if ( null !== $lang ) {
			$post['_fc_lang'] = $lang;
		}
		return $this->plugin()->get( AdminPostController::class )->process( $post, array() )['result'];
	}

	private function entry( int $id ): array {
		return (array) $this->plugin()->get( EntryRepository::class )->find( $id );
	}

	/** English translations of the form (by name). */
	private function translate_to_english( array $form ): void {
		$this->fake->translations['en'] = array(
			'title'                              => 'Contact',
			'f_name01.label'                     => 'Full name',
			'f_name01.help'                      => 'First and last name',
			'f_topic1.label'                     => 'Topic',
			'f_topic1.option.offer'              => 'Offer',
			'f_note01.content'                   => '<p>Note <strong>important</strong></p>',
			'f_cons01.consent_text'              => 'I agree to the <strong>processing</strong>.',
			'settings.confirmation.message'      => 'Thank you!',
			'settings.paused_message'            => 'Paused',
			'settings.confirmation_mail.subject' => 'Thanks, {field:name}',
			'settings.confirmation_mail.body'    => 'We will get back to you.',
			'settings.notification.subject'      => 'New request (must never be used)',
		);
		unset( $form );
	}

	/** ML-01: publishing registers every text of table 2, never field values. */
	public function test_ml01_publish_registers_all_texts_without_values(): void {
		$form = $this->publish_form();
		$ctx  = 'form-captain/' . $form['public_id'];
		$keys = array();
		foreach ( array_keys( $this->fake->registered ) as $k ) {
			$this->assertStringStartsWith( $ctx . '|', $k );
			$keys[] = substr( $k, strlen( $ctx ) + 1 );
		}
		sort( $keys );
		$this->assertSame(
			array(
				'f_cons01.consent_text',
				'f_cons01.label',
				'f_mail01.label',
				'f_name01.help',
				'f_name01.label',
				'f_name01.placeholder',
				'f_note01.content',
				'f_topic1.label',
				'f_topic1.option.offer',
				'f_topic1.option.support',
				'settings.confirmation.message',
				'settings.confirmation_mail.body',
				'settings.confirmation_mail.subject',
				'settings.paused_message',
				'title',
			),
			array_values( array_filter( $keys, static fn( string $k ): bool => ! str_starts_with( $k, 'settings.texts.' ) && ! str_starts_with( $k, 'settings.double_opt_in.' ) && 'settings.submit_label' !== $k && 'settings.limits.closed_message' !== $k ) )
		);
		$this->assertSame( 'Angebot', $this->fake->registered[ $ctx . '|f_topic1.option.offer' ] );
		$this->assertNotContains( 'kampagne-7', $this->fake->registered, 'no field values (hidden default)' );
		$this->assertNotContains( 'offer', $this->fake->registered, 'option values are not texts' );
		$this->assertArrayNotHasKey( $ctx . '|settings.notification.subject', $this->fake->registered, 'admin mail is not translated' );
		$stored = get_option( Translations::option_name( (int) $form['id'] ) );
		$this->assertSame( Translations::hash( 'Name' ), $stored['strings']['f_name01.label']['h'], 'source hash per text' );
	}

	/** ML-02: page in language B shows B; missing translations fall back to the source text. */
	public function test_ml02_page_in_language_b_shows_translations_and_falls_back(): void {
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$this->fake->current = 'en';
		$html                = $this->render( $form );
		$this->assertStringContainsString( 'Full name', $html );
		$this->assertStringContainsString( 'First and last name', $html );
		$this->assertStringContainsString( '>Offer</option>', $html );
		$this->assertStringContainsString( 'value="offer"', $html, 'option values stay' );
		$this->assertStringContainsString( '>Hilfe</option>', $html, 'missing option translation → source' );
		$this->assertStringContainsString( 'placeholder="Erika Muster"', $html, 'missing translation → source' );
		$this->assertStringContainsString( 'I agree to the <strong>processing</strong>.', $html );
		$this->assertStringContainsString( '<p>Note <strong>important</strong></p>', $html );
		$this->assertStringContainsString( 'name="_fc_lang" value="en"', $html );
		$this->assertStringContainsString( 'data-fc-lang="en"', $html );
		$this->assertStringNotContainsString( '>Name', $html );

		$this->fake->current = 'de';
		$html                = $this->render( $form );
		$this->assertStringContainsString( 'Name', $html );
		$this->assertStringNotContainsString( 'Full name', $html );

		// Paused message in B.
		$this->fake->current = 'en';
		$this->forms()->set_status( (int) $form['id'], 'paused' );
		$this->assertStringContainsString( 'Paused', $this->render( $form ) );
	}

	/** ML-03: changed source → translation outdated, output in the source language, editor hint. */
	public function test_ml03_changed_source_marks_translation_outdated(): void {
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$form = $this->republish( $form, $this->fields( 'Vollständiger Name' ) );

		$this->fake->current = 'en';
		$html                = $this->render( $form );
		$this->assertStringContainsString( 'Vollständiger Name', $html, 'outdated translation → source text' );
		$this->assertStringNotContainsString( 'Full name', $html );
		$this->assertStringContainsString( 'Topic', $html, 'unchanged texts stay translated' );
		$this->assertSame( 1, Translations::outdated( $form ) );

		$this->as_admin();
		$_GET = array(
			'page' => 'form-captain-edit',
			'form' => (string) $form['id'],
		);
		ob_start();
		$this->plugin()->get( TranslationModule::class )->editor_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( '1 translation of this form is outdated', $notice );

		// The translator updates the translation in the multilingual plugin.
		$this->fake->translations['en']['f_name01.label'] = 'Full legal name';
		$this->assertStringContainsString( 'Full legal name', $this->render( $form ) );
		$this->assertSame( 0, Translations::outdated( $form ) );
	}

	/** ML-03 with a provider that finds translations by their source text (Polylang). */
	public function test_ml03_outdated_with_translations_by_source_text(): void {
		$this->fake->by_source          = true;
		$form                           = $this->publish_form();
		$this->fake->translations['en'] = array(
			'Name'  => 'Full name',
			'Thema' => 'Topic',
		);
		$form                           = $this->republish( $form, $this->fields( 'Vollständiger Name' ) );
		$this->fake->current            = 'en';
		$this->assertStringContainsString( 'Vollständiger Name', $this->render( $form ) );
		$this->assertSame( 1, Translations::outdated( $form ) );
		$this->fake->translations['en']['Vollständiger Name'] = 'Full legal name';
		$this->assertStringContainsString( 'Full legal name', $this->render( $form ) );
		$this->assertSame( 0, Translations::outdated( $form ) );
	}

	/** ML-04: submission in B: locale B, option values, consent hash of the B text. */
	public function test_ml04_submission_in_language_b_stores_locale_values_and_consent_hash(): void {
		$this->translate_to_english( array() ); // Translated before publishing: revision 1 in every language.
		$form   = $this->publish_form();
		$result = $this->submit( $form, 'en' );
		$this->assertTrue( $result['success'], (string) ( $result['message'] ?? '' ) );
		$this->assertSame( 'Thank you!', $result['message'], 'confirmation message in B' );
		$entry = $this->entry( (int) $result['entry_id'] );
		$this->assertSame( 'en_US', $entry['locale'] );
		$values = $this->plugin()->get( EntryRepository::class )->values_for( (int) $result['entry_id'] );
		$this->assertSame( 'offer', $values['f_topic1'], 'values are option values' );
		$proof = $entry['consent']['f_cons01'];
		$this->assertSame( Translations::consent_hash( 'I agree to the <strong>processing</strong>.' ), $proof['text_hash'] );
		$this->assertSame( 'en_US', $proof['locale'] );
		$this->assertSame( 1, $proof['revision'] );

		// Source language: hash of the source text.
		$de = $this->submit( $form, 'de', array( 'f_name01' => 'Max Muster' ) );
		$this->assertTrue( $de['success'] );
		$this->assertSame( 'Danke!', $de['message'] );
		$proof = $this->entry( (int) $de['entry_id'] )['consent']['f_cons01'];
		$this->assertSame( Translations::consent_hash( 'Ich stimme der <strong>Verarbeitung</strong> zu.' ), $proof['text_hash'] );
		$this->assertSame( 'de_DE', $proof['locale'] );
	}

	/** A changed translation of the consent text raises the consent revision. */
	public function test_changed_consent_translation_raises_revision(): void {
		$this->set_spam_setting( 'rate_limit', 50 );
		$this->translate_to_english( array() );
		$form  = $this->publish_form();
		$first = $this->submit( $form, 'en' );
		$this->assertSame( 1, $this->entry( (int) $first['entry_id'] )['consent']['f_cons01']['revision'] );

		// Changed in the multilingual plugin, no new publish: detected at the next submission.
		$this->fake->translations['en']['f_cons01.consent_text'] = 'I consent to the processing of my data.';
		$second = $this->submit( $form, 'en', array( 'f_name01' => 'Zweite Person' ) );
		$proof  = $this->entry( (int) $second['entry_id'] )['consent']['f_cons01'];
		$this->assertSame( 2, $proof['revision'] );
		$this->assertSame( Translations::consent_hash( 'I consent to the processing of my data.' ), $proof['text_hash'] );

		// Other languages share the form-wide revision.
		$third = $this->submit( $form, 'de', array( 'f_name01' => 'Dritte Person' ) );
		$this->assertSame( 2, $this->entry( (int) $third['entry_id'] )['consent']['f_cons01']['revision'] );

		// Changed before publishing again: detected on publish.
		$this->fake->translations['en']['f_cons01.consent_text'] = 'I agree.';
		$form   = $this->republish( $form, $this->fields() );
		$fourth = $this->submit( $form, 'de', array( 'f_name01' => 'Vierte Person' ) );
		$this->assertSame( 3, $this->entry( (int) $fourth['entry_id'] )['consent']['f_cons01']['revision'] );

		// Changed source text: the field revision rises (no double count for the outdated translation).
		$form  = $this->republish( $form, $this->fields( 'Name', 'Ich willige ein.' ) );
		$fifth = $this->submit( $form, 'en', array( 'f_name01' => 'Fünfte Person' ) );
		$proof = $this->entry( (int) $fifth['entry_id'] )['consent']['f_cons01'];
		$this->assertSame( 4, $proof['revision'] );
		$this->assertSame( Translations::consent_hash( 'Ich willige ein.' ), $proof['text_hash'], 'outdated translation → source text' );

		// A first translation of the new text changes what visitors in that language see.
		$this->fake->translations['en']['f_cons01.consent_text'] = 'I give my consent.';
		$sixth = $this->submit( $form, 'en', array( 'f_name01' => 'Sechste Person' ) );
		$this->assertTrue( $sixth['success'], (string) $sixth['code'] );
		$this->assertSame( 5, $this->entry( (int) $sixth['entry_id'] )['consent']['f_cons01']['revision'] );
	}

	/** ML-05: confirmation email in the language of the entry, admin email in the site language. */
	public function test_ml05_confirmation_mail_in_entry_language_admin_mail_not_translated(): void {
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$result = $this->submit( $form, 'en' );
		$this->assertTrue( $result['success'] );
		$this->fake->current = 'de'; // The job runs without the visitor's language.
		$this->plugin()->get( JobRunner::class )->run();
		$subjects = array_map( static fn( array $m ): string => (string) $m['subject'], tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertContains( 'Thanks, Erika Muster', $subjects );
		$this->assertContains( 'Neue Anfrage', $subjects, 'admin email untranslated' );
		$this->assertNotContains( 'New request (must never be used)', $subjects );
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			if ( 'Thanks, Erika Muster' === $mail['subject'] ) {
				$this->assertStringContainsString( 'We will get back to you.', (string) $mail['body'] );
			}
		}
	}

	/** ML-06: confirmation page → translation of the page in B, otherwise the original page. */
	public function test_ml06_confirmation_page_follows_the_language(): void {
		$page                               = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Danke' ) );
		$page_en                            = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Thanks' ) );
		$page_fr                            = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Merci' ) );
		$form                               = $this->publish_form( null, array( 'confirmation' => array( 'type' => 'page', 'page_id' => $page ) ) );
		$this->fake->posts[ $page . '|en' ] = $page_en;
		$this->fake->posts[ $page . '|fr' ] = $page_fr;

		$en = $this->submit( $form, 'en' );
		$this->assertSame( get_permalink( $page_en ), $en['redirect_url'] );
		$fr = $this->submit( $form, 'fr', array( 'f_name01' => 'Zweite' ) );
		$this->assertSame( get_permalink( $page ), $fr['redirect_url'], 'unpublished translation → original page' );
		$de = $this->submit( $form, 'de', array( 'f_name01' => 'Dritte' ) );
		$this->assertSame( get_permalink( $page ), $de['redirect_url'], 'no translation → original page' );
	}

	/** ML-07: one CSV file, column locale, the same columns for all languages. */
	public function test_ml07_csv_export_has_a_locale_column(): void {
		$this->as_admin();
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$this->assertTrue( $this->submit( $form, 'en' )['success'] );
		$this->assertTrue( $this->submit( $form, 'de', array( 'f_name01' => 'Max' ) )['success'] );
		$stream = fopen( 'php://memory', 'w+b' );
		$this->assertSame( 2, $this->plugin()->get( CsvExporter::class )->export( array( 'form_id' => (int) $form['id'] ), $stream ) );
		rewind( $stream );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $stream );
		$header = $rows[0];
		$col    = array_search( 'Language', $header, true );
		$this->assertIsInt( $col );
		$this->assertCount( 3, $rows );
		$this->assertEqualsCanonicalizing( array( 'en_US', 'de_DE' ), array( $rows[1][ $col ], $rows[2][ $col ] ) );
		$this->assertCount( count( $header ), $rows[1] );
		$this->assertCount( count( $header ), $rows[2] );
		$this->assertContains( 'Thema', $header, 'columns are named in the source language' );
	}

	/** ML-08: privacy export and eraser find entries of every language. */
	public function test_ml08_privacy_export_and_eraser_find_entries_of_all_languages(): void {
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$this->assertTrue( $this->submit( $form, 'en' )['success'] );
		$this->assertTrue( $this->submit( $form, 'de', array( 'f_name01' => 'Max' ) )['success'] );
		$this->assertTrue( $this->submit( $form, null, array( 'f_name01' => 'Ohne' ) )['success'] );
		$privacy = $this->plugin()->get( PrivacyIntegration::class );
		$this->assertCount( 3, $privacy->export( 'erika@example.org', 1 )['data'] );
		\FormCaptain\Settings\Settings::set_group( 'privacy', array( 'erasure_mode' => 'delete' ) );
		$this->assertTrue( $privacy->erase( 'erika@example.org', 1 )['items_removed'] );
		$this->assertSame( array(), $privacy->export( 'erika@example.org', 1 )['data'] );
	}

	/** ML-09: provider deactivated → forms in the source language, no errors. */
	public function test_ml09_without_provider_forms_use_the_source_language(): void {
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$this->fake->current = 'en';
		$this->use_providers( new FakeTranslationProvider( 'fake', false ) );
		$this->assertNull( Translations::provider() );
		$html = $this->render( $form );
		$this->assertStringContainsString( 'Name', $html );
		$this->assertStringNotContainsString( 'Full name', $html );
		$this->assertStringNotContainsString( '_fc_lang', $html );
		$result = $this->submit( $form, 'en' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Danke!', $result['message'] );
		$this->assertSame( 0, Translations::outdated( $form ) );
		$this->assertArrayNotHasKey( 'translation_providers', $this->plugin()->get( SystemStatus::class )->problems() );
	}

	/** ML-10: two active providers → warning, no adapter, source language. */
	public function test_ml10_two_active_providers_warn_and_use_none(): void {
		$other = new FakeTranslationProvider( 'other' );
		$this->use_providers( $this->fake, $other, new FakeTranslationProvider( 'off', false ) );
		$form = $this->publish_form();
		$this->assertSame( array(), $this->fake->registered, 'nothing registered without an adapter' );
		$this->assertNull( Translations::provider() );
		$this->assertTrue( Translations::status()['conflict'] );
		$problems = $this->plugin()->get( SystemStatus::class )->problems();
		$this->assertArrayHasKey( 'translation_providers', $problems );
		$this->assertStringContainsString( 'fake, other', $problems['translation_providers'] );
		$report = $this->plugin()->get( SystemStatus::class )->report_text();
		$this->assertStringContainsString( '## translations', $report );
		$this->assertStringContainsString( 'conflict: yes', $report );
		$this->fake->translations['en'] = array( 'f_name01.label' => 'Full name' );
		$this->fake->current            = 'en';
		$this->assertStringNotContainsString( 'Full name', $this->render( $form ) );
	}

	/** An installed but too old WPML is reported and not used. */
	public function test_unsupported_wpml_version_is_reported(): void {
		$this->use_providers( new WpmlProvider( '4.6.0' ) );
		$this->assertNull( Translations::provider() );
		$problems = $this->plugin()->get( SystemStatus::class )->problems();
		$this->assertArrayHasKey( 'translation_provider_wpml', $problems );
		$this->assertStringContainsString( '4.7.0', $problems['translation_provider_wpml'] );
	}

	/** ML-11: cached pages of both languages, tokens stay valid; `lang` only for reported languages. */
	public function test_ml11_cached_pages_and_language_switch_keep_tokens_valid(): void {
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$this->fake->current = 'en';
		$en_page             = $this->render( $form );
		$this->fake->current = 'de';
		$de_page             = $this->render( $form );
		$this->assertStringContainsString( 'Full name', $en_page );
		$this->assertStringContainsString( 'Name', $de_page );
		preg_match( '/name="_fc_token" value="([^"]+)"/', $en_page, $m );
		$this->assertNotEmpty( $m[1] );

		// Token request with lang: same token rules in every language; unknown languages are ignored.
		foreach ( array( 'en', 'xx' ) as $lang ) {
			$request = new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/token' );
			$request->set_url_params( array( 'public_id' => $form['public_id'] ) );
			$request->set_param( 'lang', $lang );
			$this->assertSame( 200, $this->plugin()->get( RestController::class )->token( $request )->get_status() );
		}
		$this->assertSame( 'en', Translations::accept( 'en' ) );
		$this->assertNull( Translations::accept( 'xx' ) );
		$this->assertNull( Translations::accept( '../en' ) );

		// The token from the English cached page is accepted with the German language and vice versa.
		\FormCaptain\Support\Clock::freeze( time() + 30 );
		$post = $this->payload( $form, array( 'f_name01' => 'A', 'f_mail01' => 'a@example.org', 'f_cons01' => '1' ), $m[1] ) + array( '_fc_lang' => 'de' );
		$res  = $this->plugin()->get( AdminPostController::class )->process( $post, array() )['result'];
		\FormCaptain\Support\Clock::freeze( null );
		$this->assertTrue( $res['success'], (string) ( $res['message'] ?? '' ) );
		$this->assertSame( 'de_DE', $this->entry( (int) $res['entry_id'] )['locale'] );

		// An unknown lang is ignored: the language of the page (_fc_locale) remains.
		$xx = $this->submit( $form, 'xx', array( 'f_name01' => 'B' ) );
		$this->assertTrue( $xx['success'] );
		$this->assertSame( 'Danke!', $xx['message'] );
	}

	/** ML-12: translated html and consent texts with disallowed HTML are cleaned by the allowlist. */
	public function test_ml12_translated_html_is_cleaned_by_the_allowlist(): void {
		$form                           = $this->publish_form();
		$this->fake->translations['en'] = array(
			'f_note01.content'      => '<p onclick="steal()">Note <script>alert(1)</script><strong>bold</strong><iframe src="https://evil.example"></iframe></p>',
			'f_cons01.consent_text' => 'I agree <img src=x onerror=alert(2)><a href="javascript:alert(3)">here</a> <em>ok</em>',
			'f_name01.label'        => 'Name <b>bold</b>',
		);
		$this->fake->current            = 'en';
		$html                           = $this->render( $form );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<em>ok</em>', $html );
		$this->assertStringContainsString( 'Name &lt;b&gt;bold&lt;/b&gt;', $html, 'labels are escaped text' );
	}

	/** Decision 23: the JSON export of a form holds the source language only. */
	public function test_json_export_holds_the_source_language_only(): void {
		$this->as_admin();
		$form = $this->publish_form();
		$this->translate_to_english( $form );
		$this->fake->current = 'en';
		$export              = $this->service()->export( (int) $form['id'] );
		$this->assertIsArray( $export );
		$json = (string) wp_json_encode( $export );
		$this->assertStringContainsString( 'Vor- und Nachname', $json );
		$this->assertStringNotContainsString( 'Full name', $json );
		$this->assertStringNotContainsString( 'translations', $json );
	}

	/** Decision 23 replaced E3: translations are a Free feature without Pro hint row or key. */
	public function test_translations_have_no_pro_hint_row(): void {
		foreach ( SettingsSchema::pro_hints() as $hint ) {
			$this->assertStringNotContainsString( 'translat', strtolower( (string) $hint['id'] . ' ' . (string) $hint['title'] . ' ' . (string) $hint['text'] ) );
		}
	}

	/** Deleting a form removes its source hashes. */
	public function test_deleting_a_form_removes_its_translation_record(): void {
		$this->as_admin();
		$form = $this->publish_form();
		$this->assertNotFalse( get_option( Translations::option_name( (int) $form['id'] ) ) );
		$this->assertTrue( $this->service()->delete( (int) $form['id'] ) );
		$this->assertFalse( get_option( Translations::option_name( (int) $form['id'] ) ) );
	}

	/** Entry locales of the provider are accepted (SET-FC-21), others are not. */
	public function test_provider_locales_are_accepted_as_entry_locale(): void {
		$this->assertSame( 'fr_FR', \FormCaptain\Service\SubmissionService::locale( 'fr_FR' ) );
		$this->assertNull( \FormCaptain\Service\SubmissionService::locale( 'it_IT' ) );
	}
}
