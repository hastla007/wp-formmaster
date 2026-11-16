<?php
/**
 * Public contract functions v1: DTOs, scopes, rights, submit and signed-context trust boundaries
 * (P8-01, P8-14, P8-15, P8-22).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Install\Capabilities;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/ConnectorContractAssertions.php';

final class ContractApiTest extends TestCase {

	use ConnectorContractAssertions;

	/** @return array<string,mixed> */
	private function sensitive_form( array $settings = array() ): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
				array( 'id' => 'f_iban01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
				array( 'id' => 'f_hid001', 'type' => 'hidden', 'key' => 'campaign', 'label' => 'Kampagne', 'default' => 'secret-default' ),
				array(
					'id'      => 'f_topic1',
					'type'    => 'select',
					'key'     => 'topic',
					'label'   => 'Thema',
					'options' => array(
						array( 'value' => 'a', 'label' => 'A' ),
						array( 'value' => 'b', 'label' => 'B' ),
					),
				),
			),
			$settings
		);
	}

	public function test_contract_version(): void {
		$this->assertSame( '1.0.0', form_captain_contract_version() );
		$this->assertSame( FORM_CAPTAIN_CONTRACT_VERSION, form_captain_contract_version() );
	}

	public function test_get_form_dto_and_visibility(): void {
		$form = $this->sensitive_form();
		wp_set_current_user( 0 );
		$dto = form_captain_get_form_v1( $form['public_id'] );
		$this->assertIsArray( $dto );
		$this->assertSame( array( 'id', 'public_id', 'title', 'status', 'version', 'fields' ), array_keys( $dto ) );
		$this->assertSame( 1, $dto['version'] );
		$this->assertSame( array( 'id', 'key', 'type', 'label', 'required', 'sensitive', 'options' ), array_keys( $dto['fields'][0] ) );
		$this->assertTrue( $dto['fields'][2]['sensitive'] );
		$this->assertStringNotContainsString( 'secret-default', (string) wp_json_encode( $dto ), 'Hidden defaults are never exposed' );
		$this->assertStringNotContainsString( 'admin@example.org', (string) wp_json_encode( $dto ), 'No recipients in DTO' );

		$repo  = $this->plugin()->get( \FormCaptain\Repository\FormRepository::class );
		$draft = $repo->create( 'Draft', array( 'schema_version' => 1, 'fields' => array() ), array() );
		$this->assertSame( 'form_not_found', form_captain_get_form_v1( $draft )->get_error_code() );
		$this->as_admin();
		$this->assertSame( 'draft', form_captain_get_form_v1( $draft )['status'] );
		$this->assertNull( form_captain_get_form_v1( $draft )['version'] );
		$this->assertSame( 'form_not_found', form_captain_get_form_v1( 999999 )->get_error_code() );
	}

	public function test_get_entry_scopes_and_rights(): void {
		$form  = $this->sensitive_form();
		$entry = $this->make_entry( $form, array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org', 'f_iban01' => 'DE001234', 'f_topic1' => 'a' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 'forbidden', form_captain_get_entry_v1( $entry )->get_error_code() );
		$this->assertSame( 'forbidden', form_captain_get_entry_v1( 999999 )->get_error_code(), 'No existence oracle' );
		$this->as_role( 'editor' );
		$this->assertSame( 'forbidden', form_captain_get_entry_v1( $entry )->get_error_code() );

		$this->as_admin();
		$meta = form_captain_get_entry_v1( $entry );
		$this->assertArrayNotHasKey( 'values', $meta );
		foreach ( array( 'entry_id', 'form_id', 'form_version', 'status', 'created_gmt', 'context', 'anonymized' ) as $k ) {
			$this->assertArrayHasKey( $k, $meta );
		}
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $meta['created_gmt'] );
		$values = form_captain_get_entry_v1( $entry, array( 'meta', 'values' ) )['values'];
		$this->assertSame( array( 'name' => 'Ada', 'email' => 'ada@example.org', 'topic' => 'a' ), $values );
		$all = form_captain_get_entry_v1( $entry, array( 'sensitive' ) )['values'];
		$this->assertSame( 'DE001234', $all['iban'] );
		$this->assertSame( 'invalid_scope', form_captain_get_entry_v1( $entry, array( 'notes' ) )->get_error_code() );
		$this->assertSame( 'entry_not_found', form_captain_get_entry_v1( 999999 )->get_error_code() );

		// Reader without export capability: values yes, sensitive no.
		add_role( 'fc_reader', 'Reader', array( 'read' => true, Capabilities::READ_ENTRIES => true ) );
		$this->as_role( 'fc_reader' );
		$this->assertArrayHasKey( 'values', form_captain_get_entry_v1( $entry, array( 'values' ) ) );
		$this->assertSame( 'forbidden', form_captain_get_entry_v1( $entry, array( 'sensitive' ) )->get_error_code() );
		remove_role( 'fc_reader' );
	}

	public function test_submit_requires_source_and_idempotency_key(): void {
		$form = $this->sensitive_form();
		$res  = form_captain_submit_v1( $form['id'], array( 'name' => 'Ada' ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'invalid_request', $res['code'] );
		$this->assertArrayHasKey( 'request_id', $res );
		$res = form_captain_submit_v1( 999999, array(), array( 'source' => 'test', 'idempotency_key' => 'k' ) );
		$this->assertSame( 'form_not_found', $res['code'] );
	}

	public function test_submit_is_idempotent_and_validates(): void {
		if ( ! class_exists( 'FormCaptain\\Service\\SubmissionService' ) ) {
			$this->markTestSkipped( 'SubmissionService not available.' );
		}
		$form  = $this->sensitive_form();
		$ctx   = array( 'source' => 'contracttest', 'idempotency_key' => 'order-1' );
		$first = form_captain_submit_v1( $form['public_id'], array( 'name' => 'Ada', 'email' => 'ada@example.org' ), $ctx );
		$this->assertTrue( $first['success'], (string) wp_json_encode( $first ) );
		$this->assertIsInt( $first['entry_id'] );
		$again = form_captain_submit_v1( $form['public_id'], array( 'name' => 'Ada', 'email' => 'ada@example.org' ), $ctx );
		$this->assertSame( $first['entry_id'], $again['entry_id'], 'Same idempotency key → same entry' );
		$invalid = form_captain_submit_v1( $form['id'], array( 'name' => '' ), array( 'source' => 'contracttest', 'idempotency_key' => 'order-2' ) );
		$this->assertFalse( $invalid['success'] );
		$this->assertSame( 'validation_failed', $invalid['code'] );
		$this->assertNotEmpty( $invalid['errors'] );
	}

	public function test_render_returns_html_for_published_forms_only(): void {
		if ( ! class_exists( 'FormCaptain\\Frontend\\Renderer' ) ) {
			$this->markTestSkipped( 'Renderer not available.' );
		}
		$form = $this->sensitive_form();
		$this->assertStringContainsString( '<form', form_captain_get_render_v1( $form['public_id'] ) );
		$this->assertSame( '', form_captain_get_render_v1( 'zzzzzzzzzzzzzzzz' ) );
	}

	/* --- signed context trust boundaries (P8-14) --------------------------- */

	private function purpose( bool $single_use = false ): string {
		if ( ! class_exists( 'FormCaptain\\Security\\SignedContext' ) || ! class_exists( 'FormCaptain\\Service\\SubmissionService' ) ) {
			$this->markTestSkipped( 'SignedContext/SubmissionService not available.' );
		}
		$purpose = 'trust.case_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$res     = form_captain_register_context_purpose_v1(
			$purpose,
			array(
				'owner'       => 'trust',
				'contract'    => '^1.0',
				'label'       => 'Trust test',
				'ref_types'   => array( 'event' ),
				'default_ttl' => 3600,
				'max_ttl'     => 7200,
				'single_use'  => $single_use,
				'issuance'    => array( 'render' ),
			)
		);
		$this->assertTrue( $res );
		return $purpose;
	}

	private static function submit_ctx( array $form, string $ctx, string $key ): array {
		return form_captain_submit_v1(
			$form['id'],
			array( 'name' => 'Ada', 'email' => 'ada@example.org' ),
			array( 'source' => 'trusttest', 'idempotency_key' => $key, 'signed_context' => $ctx )
		);
	}

	public function test_signed_context_happy_path_and_tampering(): void {
		$purpose = $this->purpose();
		$form    = $this->sensitive_form( array( 'accepted_context_purposes' => array( $purpose ) ) );
		$signed  = form_captain_sign_context_v1( $purpose, $form['id'], 'event', '42' );
		$this->assertTrue( $signed['success'], (string) wp_json_encode( $signed ) );

		$ok = self::submit_ctx( $form, $signed['context'], 'ctx-1' );
		$this->assertTrue( $ok['success'], (string) wp_json_encode( $ok ) );
		$meta = ( function () use ( $ok ) {
			$this->as_admin();
			return form_captain_get_entry_v1( $ok['entry_id'] );
		} )();
		$this->assertSame( '42', $meta['context']['ref_id'] );

		$tampered = substr( $signed['context'], 0, 10 ) . ( 'A' === $signed['context'][10] ? 'B' : 'A' ) . substr( $signed['context'], 11 );
		$bad      = self::submit_ctx( $form, $tampered, 'ctx-2' );
		$this->assertFalse( $bad['success'] );
		$this->assertSame( 'invalid_context', $bad['code'] );

		$other = $this->sensitive_form( array( 'accepted_context_purposes' => array( $purpose ) ) );
		$this->assertSame( 'invalid_context', self::submit_ctx( $other, $signed['context'], 'ctx-3' )['code'], 'Context bound to another form' );

		$this->assertSame( 'context_purpose_not_allowed', form_captain_sign_context_v1( $purpose, $this->sensitive_form()['id'], 'event', '42' )['code'] );
		$this->assertSame( 'context_purpose_unknown', form_captain_sign_context_v1( 'nobody.unknown', $form['id'], 'event', '42' )['code'] );
	}

	public function test_signed_context_expiry_and_replay(): void {
		$purpose = $this->purpose( true );
		$form    = $this->sensitive_form( array( 'accepted_context_purposes' => array( $purpose ) ) );
		$signed  = form_captain_sign_context_v1( $purpose, $form['id'], 'event', '7' );
		$this->assertTrue( self::submit_ctx( $form, $signed['context'], 'once-1' )['success'] );
		$this->assertSame( 'context_replayed', self::submit_ctx( $form, $signed['context'], 'once-2' )['code'] );

		$fresh = form_captain_sign_context_v1( $purpose, $form['id'], 'event', '8', 120 );
		Clock::freeze( time() + 3600 );
		$this->assertSame( 'context_expired', self::submit_ctx( $form, $fresh['context'], 'once-3' )['code'] );
	}
}
