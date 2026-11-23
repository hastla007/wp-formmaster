<?php
/**
 * Texts per form (SET-FC-02, SET-FC-11): submit button text, required-fields hint,
 * error summary and an own error message per field.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Domain\SubmissionValidator;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Import\ImportService;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\SubmissionService;

require_once __DIR__ . '/FrontendTestCase.php';

final class FormTextsTest extends FrontendTestCase {

	private function forms(): FormService {
		return $this->plugin()->get( FormService::class );
	}

	/** @return array<int,array<string,mixed>> */
	private function fields(): array {
		return array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true, 'validation' => array( 'message' => 'Bitte deinen Namen angeben.' ) ),
			array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
		);
	}

	/** Saves settings through FormService (editor path) and publishes; returns the fresh form row. */
	private function saved_form( array $settings ): array {
		$this->as_admin();
		$id     = $this->forms()->create_blank( 'Texte' );
		$repo   = $this->plugin()->get( FormRepository::class );
		$schema = array(
			'schema_version' => 1,
			'fields'         => $this->fields(),
		);
		$base   = (array) $repo->find( $id )['settings'];
		$res    = $this->forms()->save( $id, 'Texte', $schema, array_replace( $base, $settings ), (int) $repo->find( $id )['revision'] );
		$this->assertIsArray( $res );
		$this->assertIsArray( $this->forms()->publish( $id ) );
		return (array) $repo->find( $id );
	}

	public function test_submit_label_is_saved_and_rendered(): void {
		$form = $this->saved_form( array( 'submit_label' => '  Anfrage <b>senden</b> ' ) );
		$this->assertSame( 'Anfrage senden', $form['settings']['submit_label'] );
		wp_set_current_user( 0 );
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( '>Anfrage senden</button>', $html );
	}

	public function test_empty_submit_label_uses_translated_default_and_long_label_is_reported(): void {
		$form = $this->saved_form( array( 'submit_label' => '' ) );
		wp_set_current_user( 0 );
		$this->assertStringContainsString( '>Send</button>', $this->plugin()->get( Renderer::class )->render( $form ) );

		$clean = $this->forms()->sanitize_settings( array( 'submit_label' => str_repeat( 'x', 61 ) ), array( 'fields' => array() ) );
		$this->assertSame( 'text_too_long', $clean['errors']['submit_label'] );
		$this->assertSame( 60, mb_strlen( $clean['settings']['submit_label'] ) );
	}

	public function test_submit_label_survives_export_and_import(): void {
		$form = $this->saved_form( array( 'submit_label' => 'Jetzt anmelden' ) );
		$json = (string) wp_json_encode( $this->forms()->export( (int) $form['id'] ) );
		$res  = $this->forms()->import( $json );
		$this->assertIsArray( $res );
		$copy = $this->plugin()->get( FormRepository::class )->find( (int) $res['form_id'] );
		$this->assertSame( 'Jetzt anmelden', $copy['settings']['submit_label'] );
	}

	public function test_shortcode_import_keeps_the_button_text(): void {
		$this->as_admin();
		$res = $this->plugin()->get( ImportService::class )->import( "<label> Dein Name\n    [text* your-name] </label>\n\n[submit \"Abschicken\"]", 'Alt' );
		$this->assertIsArray( $res );
		$form = $this->plugin()->get( FormRepository::class )->find( (int) $res['form_id'] );
		$this->assertSame( 'Abschicken', $form['settings']['submit_label'] );
	}

	public function test_required_hint_can_be_replaced_and_keeps_the_marker(): void {
		$form = $this->saved_form( array( 'texts' => array( 'required_hint' => 'Pflichtfelder sind mit * markiert.' ) ) );
		wp_set_current_user( 0 );
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( '<p class="fc-required-note">Pflichtfelder sind mit <span class="fc-required">*</span> markiert.</p>', $html );

		$plain = $this->saved_form( array() );
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'Fields marked with <span class="fc-required">*</span> are required.', $this->plugin()->get( Renderer::class )->render( $plain ) );
	}

	public function test_error_summary_and_own_field_message_reach_the_visitor(): void {
		$form = $this->saved_form( array( 'texts' => array( 'error_summary' => 'Da fehlt noch etwas.' ) ) );
		$res  = $this->plugin()->get( SubmissionService::class )->submit( $form, $this->payload( $form, array( 'f_name01' => '', 'f_mail01' => 'kaputt' ) ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'Da fehlt noch etwas.', $res['message'] );
		$this->assertSame( 'Bitte deinen Namen angeben.', $res['errors']['f_name01'], 'own message replaces "required"' );
		$this->assertSame( 'Please enter a valid email address.', $res['errors']['f_mail01'], 'other fields keep the default' );

		$plain = $this->saved_form( array() );
		$res   = $this->plugin()->get( SubmissionService::class )->submit( $plain, $this->payload( $plain, array( 'f_name01' => '' ) ) );
		$this->assertSame( 'Please check the highlighted fields.', $res['message'] );
	}

	public function test_own_message_is_plain_text_and_not_used_for_structural_errors(): void {
		$field = array(
			'id'         => 'f_name01',
			'type'       => 'text',
			'validation' => array( 'message' => 'Nur <i>Text</i>' ),
		);
		$this->assertSame( 'Nur Text', SubmissionValidator::field_message( 'required', $field ) );
		$this->assertStringContainsString( 'reload the page', SubmissionValidator::field_message( 'unknown_field', $field ) );

		$schema = ( new \FormCaptain\Domain\Schema\SchemaValidator() )->validate(
			array(
				'schema_version' => 1,
				'fields'         => array(
					array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'validation' => array( 'message' => '<b>Fehler</b>' ) ),
					array( 'id' => 'f_hid001', 'type' => 'hidden', 'key' => 'src', 'validation' => array( 'message' => 'egal' ) ),
				),
			)
		);
		$this->assertSame( 'Fehler', $schema['schema']['fields'][0]['validation']['message'] );
		$this->assertArrayNotHasKey( 'message', $schema['schema']['fields'][1]['validation'], 'hidden fields have no message' );
	}
}
