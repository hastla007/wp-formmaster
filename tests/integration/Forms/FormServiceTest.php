<?php
/**
 * FormService: templates, save/revision, publish, status, duplicate, delete (P2-04, P2-12, P2-13, P2-16, P2-20).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormSchemaDiff;
use FormCaptain\Service\FormService;
use FormCaptain\Service\Templates;
use FormCaptain\Settings\Settings;
use FormCaptain\Tests\Integration\TestCase;

final class FormServiceTest extends TestCase {

	private FormService $service;
	private FormRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->as_admin();
		$this->service = $this->plugin()->get( FormService::class );
		$this->repo    = $this->plugin()->get( FormRepository::class );
	}

	public function test_templates_are_valid_versioned_and_publishable(): void {
		$templates = $this->plugin()->get( Templates::class );
		$this->assertSame( array( 'contact', 'quote', 'support' ), array_keys( $templates->all() ) );
		foreach ( Templates::KEYS as $key ) {
			$tpl = $templates->get( $key );
			$this->assertNotNull( $tpl, $key );
			$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $tpl['template_version'] );
			$result = ( new SchemaValidator() )->validate( $tpl['schema'] );
			$this->assertSame( array(), $result['errors'], $key );

			$id = $this->service->create_from_template( $key );
			$this->assertIsInt( $id );
			$form = $this->repo->find( $id );
			$this->assertSame( 'draft', $form['status'] );
			$this->assertSame( $key, $form['settings']['template'] );
			$this->assertSame( array(), $this->service->errors_for( $form ), $key );
			$this->assertIsArray( $this->service->publish( $id ) );
			$this->assertSame( 'published', $this->repo->find( $id )['status'] );
		}
		// Stable IDs: same template twice gives the same field IDs.
		$a = $this->repo->find( $this->service->create_from_template( 'contact' ) );
		$b = $this->repo->find( $this->service->create_from_template( 'contact' ) );
		$this->assertSame( array_column( $a['schema']['fields'], 'id' ), array_column( $b['schema']['fields'], 'id' ) );
		$this->assertNotSame( $a['public_id'], $b['public_id'] );
	}

	public function test_unknown_template_is_rejected(): void {
		$res = $this->service->create_from_template( '../../etc/passwd' );
		$this->assertWPError( $res );
	}

	public function test_new_forms_copy_global_sender_and_appearance(): void {
		Settings::set_group( 'email', array( 'from_name' => 'Shop', 'from_address' => 'shop@example.org' ) );
		Settings::set_group( 'appearance', array( 'mode' => 'base' ) );
		$id   = $this->service->create_blank();
		$form = $this->repo->find( $id );
		$this->assertSame( 'shop@example.org', $form['settings']['email']['from_address'] );
		$this->assertSame( 'base', $form['settings']['appearance']['mode'] );
		// Changing global defaults later does not alter the form.
		Settings::set_group( 'email', array( 'from_address' => 'other@example.org' ) );
		$this->assertSame( 'shop@example.org', $this->repo->find( $id )['settings']['email']['from_address'] );
	}

	public function test_save_with_wrong_revision_conflicts(): void {
		$id   = $this->service->create_from_template( 'contact' );
		$form = $this->repo->find( $id );
		$ok   = $this->service->save( $id, 'Kontakt', $form['schema'], $form['settings'], $form['revision'] );
		$this->assertIsArray( $ok );
		$this->assertSame( $form['revision'] + 1, $ok['revision'] );
		$stale = $this->service->save( $id, 'Kontakt 2', $form['schema'], $form['settings'], $form['revision'] );
		$this->assertWPError( $stale );
		$this->assertSame( 'revision_conflict', $stale->get_error_code() );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
		$this->assertSame( 'Kontakt', $this->repo->find( $id )['title'] );
	}

	public function test_draft_with_errors_is_saved_but_not_publishable(): void {
		$id     = $this->service->create_blank( 'Test' );
		$form   = $this->repo->find( $id );
		$schema = array(
			'schema_version' => 1,
			'fields'         => array(
				array( 'id' => 'f_aaaaaa', 'type' => 'text', 'key' => 'Bad Key', 'label' => '' ),
			),
		);
		$res    = $this->service->save( $id, 'Test', $schema, $form['settings'], $form['revision'] );
		$this->assertIsArray( $res );
		$this->assertArrayHasKey( 'fields.0.key', $res['errors'] );
		$this->assertArrayHasKey( 'fields.0.label', $res['errors'] );
		$pub = $this->service->publish( $id );
		$this->assertWPError( $pub );
		$this->assertSame( 'schema_invalid', $pub->get_error_code() );
		$this->assertSame( 'draft', $this->repo->find( $id )['status'] );
	}

	public function test_publish_requires_an_input_field(): void {
		$id  = $this->service->create_blank();
		$res = $this->service->publish( $id );
		$this->assertWPError( $res );
		$this->assertSame( 'no_input_fields', $res->get_error_data()['errors']['fields'] );
	}

	public function test_invalid_settings_block_publishing(): void {
		$id                      = $this->service->create_from_template( 'contact' );
		$form                    = $this->repo->find( $id );
		$s                       = $form['settings'];
		$s['notification']['to'] = array( 'not-an-address' );
		$res                     = $this->service->save( $id, 'Kontakt', $form['schema'], $s, $form['revision'] );
		$this->assertSame( 'invalid_recipient', $res['errors']['notification.to'] );
		$this->assertWPError( $this->service->publish( $id ) );
	}

	public function test_confirmation_page_must_be_published_page(): void {
		$id                = $this->service->create_from_template( 'contact' );
		$form              = $this->repo->find( $id );
		$draft             = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$publish           = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$s                 = $form['settings'];
		$s['confirmation'] = array( 'type' => 'page', 'page_id' => $draft );
		$res               = $this->service->save( $id, 'K', $form['schema'], $s, $form['revision'] );
		$this->assertSame( 'invalid_page', $res['errors']['confirmation.page_id'] );
		$this->assertSame( 'message', $res['form']['settings']['confirmation']['type'] );
		$s['confirmation'] = array( 'type' => 'page', 'page_id' => $publish );
		$res               = $this->service->save( $id, 'K', $form['schema'], $s, $res['revision'] );
		$this->assertSame( $publish, $res['form']['settings']['confirmation']['page_id'] );
	}

	public function test_save_keeps_actions_and_tracks_retired_ids_and_consent_revision(): void {
		$id   = $this->service->create_from_template( 'contact' );
		$form = $this->repo->find( $id );
		$this->repo->update( $id, array( 'settings' => array_merge( $form['settings'], array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => 'x.y', 'enabled' => true ) ) ) ) ), $form['revision'] );
		$form   = $this->repo->find( $id );
		$schema = $form['schema'];
		// Remove "subject" field, change consent text.
		$schema['fields'] = array_values( array_filter( $schema['fields'], static fn( $f ) => 'subject' !== $f['key'] ) );
		foreach ( $schema['fields'] as $i => $f ) {
			if ( 'consent' === $f['type'] ) {
				$schema['fields'][ $i ]['consent_text'] = 'New consent wording.';
			}
		}
		$settings            = $form['settings'];
		$settings['actions'] = array();
		$res                 = $this->service->save( $id, 'K', $schema, $settings, $form['revision'] );
		$saved               = $res['form'];
		$this->assertContains( 'f_ctsubj01', $saved['schema']['retired_ids'] );
		$this->assertCount( 1, $saved['settings']['actions'], 'PUT must not change actions' );
		$consent = array_values( array_filter( $saved['schema']['fields'], static fn( $f ) => 'consent' === $f['type'] ) )[0];
		$this->assertSame( 2, $consent['consent_revision'] );
		// Unchanged text keeps revision.
		$res2    = $this->service->save( $id, 'K', $saved['schema'], $saved['settings'], $res['revision'] );
		$consent = array_values( array_filter( $res2['form']['schema']['fields'], static fn( $f ) => 'consent' === $f['type'] ) )[0];
		$this->assertSame( 2, $consent['consent_revision'] );
		// Retired IDs cannot be reused.
		$schema             = $res2['form']['schema'];
		$schema['fields'][] = array( 'id' => 'f_ctsubj01', 'type' => 'text', 'key' => 'again', 'label' => 'Again' );
		$res3               = $this->service->save( $id, 'K', $schema, $saved['settings'], $res2['revision'] );
		$this->assertContains( 'duplicate_id', $res3['errors'] );
	}

	public function test_status_transitions(): void {
		$id = $this->service->create_from_template( 'contact' );
		$this->assertWPError( $this->service->set_status( $id, 'paused' ), 'draft → paused not allowed' );
		$this->assertSame( array( 'status' => 'published' ), $this->service->set_status( $id, 'published' ) );
		$this->assertNotNull( $this->repo->find( $id )['published_version_id'] );
		$this->assertSame( array( 'status' => 'paused' ), $this->service->set_status( $id, 'paused' ) );
		$this->assertSame( array( 'status' => 'published' ), $this->service->set_status( $id, 'published' ) );
		$this->assertSame( array( 'status' => 'draft' ), $this->service->set_status( $id, 'draft' ) );
		$this->assertSame( array( 'status' => 'archived' ), $this->service->set_status( $id, 'archived' ) );
		$err = $this->service->set_status( $id, 'published' );
		$this->assertWPError( $err );
		$this->assertSame( 'conflict', $err->get_error_code() );
		$this->assertWPError( $this->service->publish( $id ) );
		$this->assertSame( array( 'status' => 'draft' ), $this->service->set_status( $id, 'draft' ) );
		$this->assertWPError( $this->service->set_status( $id, 'bogus' ) );
	}

	public function test_events_fire_after_saving(): void {
		$created = array();
		$updated = array();
		add_action(
			'form_captain_form_created_v1',
			function ( $dto ) use ( &$created ) {
				$created[] = $dto;
			}
		);
		add_action(
			'form_captain_form_updated_v1',
			function ( $dto, $change ) use ( &$updated ) {
				$updated[] = array( $dto['status'], $change );
			},
			10,
			2
		);
		$id = $this->service->create_from_template( 'contact' );
		$this->service->publish( $id );
		$this->service->set_status( $id, 'paused' );
		$this->assertSame( $id, $created[0]['id'] );
		$this->assertArrayNotHasKey( 'settings', $created[0] );
		$this->assertSame( array( array( 'published', 'published' ), array( 'paused', 'paused' ) ), $updated );
	}

	public function test_duplicate_gets_new_public_id_and_disabled_actions(): void {
		$id   = $this->service->create_from_template( 'quote' );
		$form = $this->repo->find( $id );
		$this->repo->update( $id, array( 'settings' => array_merge( $form['settings'], array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => 'x.y', 'enabled' => true ) ) ) ) ), $form['revision'] );
		$this->service->publish( $id );
		$copy_id = $this->service->duplicate( $id );
		$copy    = $this->repo->find( $copy_id );
		$this->assertNotSame( $form['public_id'], $copy['public_id'] );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertSame( array_column( $form['schema']['fields'], 'id' ), array_column( $copy['schema']['fields'], 'id' ) );
		$this->assertFalse( $copy['settings']['actions'][0]['enabled'] );
		$this->assertSame( 0, $this->service->entry_total( $copy_id ) );
	}

	public function test_delete_with_entries_requires_flag_and_capability(): void {
		$form    = $this->create_published_form();
		$entries = $this->plugin()->get( EntryRepository::class );
		$entries->insert(
			array(
				'form_id'         => $form['id'],
				'form_version_id' => $form['published_version_id'],
				'request_key'     => hash( 'sha256', 'x' . $form['id'] ),
			)
		);
		$res = $this->service->delete( $form['id'] );
		$this->assertSame( 'conflict', $res->get_error_code() );

		// Admin without delete_entries.
		$user = wp_get_current_user();
		$user->add_cap( Capabilities::DELETE_ENTRIES, false );
		$res = $this->service->delete( $form['id'], true );
		$this->assertSame( 'forbidden', $res->get_error_code() );
		$this->assertNotNull( $this->repo->find( $form['id'] ) );

		$user->remove_cap( Capabilities::DELETE_ENTRIES );
		$this->assertTrue( current_user_can( Capabilities::DELETE_ENTRIES ) );
		if ( ! class_exists( \FormCaptain\Service\EntryDeletionService::class ) ) {
			$this->assertWPError( $this->service->delete( $form['id'], true ) );
			return;
		}
		$this->assertTrue( $this->service->delete( $form['id'], true ) );
		$this->assertNull( $this->repo->find( $form['id'] ) );
		$this->assertSame( 0, $this->service->entry_total( $form['id'] ) );
	}

	public function test_delete_without_entries(): void {
		$id = $this->service->create_blank();
		$this->assertTrue( $this->service->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
	}

	public function test_schema_diff_reports_breaking_changes(): void {
		$old  = array(
			'fields' => array(
				array( 'id' => 'f_aaaaaa', 'type' => 'text', 'label' => 'A', 'required' => false ),
				array( 'id' => 'f_bbbbbb', 'type' => 'select', 'label' => 'B', 'options' => array( array( 'value' => 'x' ), array( 'value' => 'y' ) ) ),
				array( 'id' => 'f_cccccc', 'type' => 'text', 'label' => 'C' ),
			),
		);
		$new  = array(
			'fields' => array(
				array( 'id' => 'f_aaaaaa', 'type' => 'text', 'label' => 'A', 'required' => true ),
				array( 'id' => 'f_bbbbbb', 'type' => 'select', 'label' => 'B', 'options' => array( array( 'value' => 'x' ) ) ),
				array( 'id' => 'f_dddddd', 'type' => 'email', 'label' => 'D', 'required' => true ),
			),
		);
		$diff = FormSchemaDiff::compare( $old, $new );
		$this->assertTrue( $diff['has_changes'] );
		$this->assertSame( array( 'f_cccccc' ), array_column( $diff['removed'], 'id' ) );
		$this->assertSame( array( 'f_dddddd' ), array_column( $diff['added'], 'id' ) );
		$this->assertSame( array( 'f_aaaaaa', 'f_dddddd' ), array_column( $diff['newly_required'], 'id' ) );
		$this->assertSame( array( 'y' ), $diff['options_removed'][0]['values'] );
	}

	public function test_old_versions_stay_untouched_after_republish(): void {
		$id = $this->service->create_from_template( 'contact' );
		$this->service->publish( $id );
		$form   = $this->repo->find( $id );
		$v1     = $form['published_version_id'];
		$schema = $form['schema'];
		array_pop( $schema['fields'] );
		$this->service->save( $id, 'K', $schema, $form['settings'], $form['revision'] );
		$this->assertTrue( $this->service->pending_changes( $this->repo->find( $id ) )['unpublished'] );
		$this->service->publish( $id );
		$v2 = $this->repo->find( $id )['published_version_id'];
		$this->assertNotSame( $v1, $v2 );
		$this->assertCount( 5, $this->repo->find_version( $v1 )['schema']['fields'] );
		$this->assertCount( 4, $this->repo->find_version( $v2 )['schema']['fields'] );
	}
}
