<?php
/**
 * JSON import/export (P4-12, T-JSON-01 … T-JSON-06, AC-108 … AC-110).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Tests\Integration\TestCase;

final class ImportExportTest extends TestCase {

	private FormService $service;
	private FormRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->as_admin();
		$this->service = $this->plugin()->get( FormService::class );
		$this->repo    = $this->plugin()->get( FormRepository::class );
	}

	public function test_roundtrip_keeps_field_ids_but_creates_new_draft_without_entries(): void {
		$id   = $this->service->create_from_template( 'support' );
		$form = $this->repo->find( $id );
		$this->repo->update( $id, array( 'settings' => array_merge( $form['settings'], array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => 'ref.ticket', 'enabled' => true, 'settings' => array( 'queue' => 'support', 'api_token' => 'SECRET123' ), 'mapping' => array( 'email' => 'f_spmail01' ) ) ) ) ) ), $form['revision'] );
		$this->service->publish( $id );
		$form = $this->repo->find( $id );
		$this->plugin()->get( EntryRepository::class )->insert(
			array(
				'form_id'         => $id,
				'form_version_id' => $form['published_version_id'],
				'request_key'     => hash( 'sha256', 'rt' ),
			)
		);

		$export = $this->service->export( $id );
		$json   = (string) wp_json_encode( $export );
		$this->assertSame( FormService::EXPORT_FORMAT, $export['format'] );
		$this->assertStringNotContainsString( $form['public_id'], $json );
		$this->assertStringNotContainsString( 'SECRET123', $json );
		$this->assertArrayNotHasKey( 'id', $export );
		$this->assertArrayNotHasKey( 'created_by', $export );
		$this->assertArrayNotHasKey( 'entries', $export );
		$this->assertStringNotContainsString( '"revision"', $json );

		$result = $this->service->import( $json );
		$this->assertIsArray( $result );
		$new = $this->repo->find( $result['form_id'] );
		$this->assertSame( 'draft', $new['status'] );
		$this->assertNotSame( $form['public_id'], $new['public_id'] );
		$this->assertSame( array_column( $form['schema']['fields'], 'id' ), array_column( $new['schema']['fields'], 'id' ) );
		$this->assertSame( $form['schema'], $new['schema'] );
		$this->assertFalse( $new['settings']['actions'][0]['enabled'] );
		$this->assertSame( 0, $this->service->entry_total( $result['form_id'] ) );
		$this->assertSame( array( '{admin_email}' ), $result['report']['recipients'] );
	}

	public function test_too_large_file_is_rejected(): void {
		$res = $this->service->import( str_repeat( ' ', FormService::IMPORT_MAX_BYTES + 1 ) );
		$this->assertSame( 'payload_too_large', $res->get_error_code() );
	}

	public function test_invalid_json_deep_nesting_and_serialized_php_are_rejected(): void {
		$this->assertSame( 'schema_invalid', $this->service->import( '{not json' )->get_error_code() );
		$deep = str_repeat( '[', 40 ) . str_repeat( ']', 40 );
		$this->assertSame( 'schema_invalid', $this->service->import( '{"schema":' . $deep . '}' )->get_error_code() );
		$this->assertSame( 'schema_invalid', $this->service->import( 'O:8:"stdClass":0:{}' )->get_error_code() );
		$this->assertSame( 'schema_invalid', $this->service->import( '{"title":"x","schema":{"schema_version":1,"fields":[{"id":"bad","type":"text","key":"a","label":"A"}]}}' )->get_error_code() );
	}

	public function test_newer_schema_version_is_unsupported(): void {
		$res = $this->service->import( '{"title":"x","schema":{"schema_version":99,"fields":[]}}' );
		$this->assertSame( 'schema_unsupported', $res->get_error_code() );
	}

	public function test_precheched_consent_is_rejected(): void {
		$json = wp_json_encode(
			array(
				'title'  => 'X',
				'schema' => array(
					'schema_version' => 1,
					'fields'         => array(
						array( 'id' => 'f_consent1', 'type' => 'consent', 'key' => 'c', 'label' => 'C', 'consent_text' => 'Yes', 'default' => '1' ),
					),
				),
			)
		);
		$res  = $this->service->import( (string) $json );
		$this->assertSame( 'schema_invalid', $res->get_error_code() );
		$this->assertContains( 'consent_prechecked', $res->get_error_data()['errors'] );
	}

	public function test_scripts_are_stripped_and_reported_consent_revision_reset_and_redirect_removed(): void {
		$json = wp_json_encode(
			array(
				'title'    => '<b>Evil</b> form',
				'schema'   => array(
					'schema_version' => 1,
					'fields'         => array(
						array( 'id' => 'f_name0001', 'type' => 'text', 'key' => 'name', 'label' => '<script>alert(1)</script>Name' ),
						array( 'id' => 'f_html0001', 'type' => 'html', 'key' => 'note', 'content' => '<p onclick="x()">Hi</p><iframe src="https://evil.test"></iframe><a href="javascript:alert(1)">x</a>' ),
						array( 'id' => 'f_cons0001', 'type' => 'consent', 'key' => 'consent', 'label' => 'C', 'consent_text' => 'OK', 'consent_revision' => 7 ),
					),
				),
				'settings' => array(
					'notification' => array( 'enabled' => true, 'to' => array( 'boss@example.org', 'not valid' ) ),
					'confirmation' => array( 'type' => 'page', 'page_id' => 999999, 'url' => 'https://evil.test' ),
				),
			)
		);
		$res  = $this->service->import( (string) $json );
		$this->assertIsArray( $res );
		$form = $this->repo->find( $res['form_id'] );
		$this->assertSame( 'Evil form', $form['title'] );
		$this->assertSame( 'Name', $form['schema']['fields'][0]['label'] );
		$html = $form['schema']['fields'][1]['content'];
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'iframe', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertSame( 1, $form['schema']['fields'][2]['consent_revision'] );
		$this->assertSame( 'message', $form['settings']['confirmation']['type'] );
		$this->assertArrayNotHasKey( 'url', $form['settings']['confirmation'] );
		$this->assertSame( array( 'boss@example.org' ), $res['report']['recipients'] );
		$this->assertNotEmpty( $res['report']['sanitized'] );
		$this->assertNotEmpty( $res['report']['removed'] );
	}
}
