<?php
/**
 * Status transitions, events, rights/IDOR, bulk actions, notes (P4-06, P4-10, P5-15).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Install\Capabilities;
use FormCaptain\Service\EntryService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class EntryServiceTest extends TestCase {

	use EntryFixtures;

	private function service(): EntryService {
		return $this->plugin()->get( EntryService::class );
	}

	public function test_status_change_fires_event_after_update_and_writes_audit(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$id    = $this->make_entry( $form );
		$seen  = array();
		$check = function ( $entry_id, $old, $new, $form_id ) use ( &$seen ): void {
			$seen[] = array( $entry_id, $old, $new, $form_id, $this->entries()->find( $entry_id )['status'] );
		};
		add_action( 'form_captain_entry_status_changed_v1', $check, 10, 4 );

		$this->assertTrue( $this->service()->set_status( $id, 'read' ) );
		$this->assertTrue( $this->service()->set_status( $id, 'archived' ) );
		$this->assertTrue( $this->service()->set_status( $id, 'read' ) );
		$this->assertTrue( $this->service()->set_status( $id, 'spam' ) );
		$this->assertTrue( $this->service()->set_status( $id, 'read' ) );
		remove_action( 'form_captain_entry_status_changed_v1', $check, 10 );

		$this->assertSame( array( $id, 'new', 'read', (int) $form['id'], 'read' ), $seen[0], 'event fires after the row is updated' );
		$this->assertCount( 5, $seen );

		$notes = $this->entries()->notes_for( $id );
		$this->assertCount( 5, $notes );
		foreach ( $notes as $note ) {
			$this->assertSame( 'audit', $note['type'] );
			$this->assertStringNotContainsString( 'Müller', $note['message'] );
			$this->assertStringNotContainsString( 'example.org', $note['message'] );
		}
		$this->assertSame( 'status_new_read', $notes[0]['message'] );
	}

	public function test_invalid_transitions_are_conflicts(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$this->assertSame( 'conflict', $this->service()->set_status( $id, 'new' )->get_error_code() );
		$this->assertTrue( $this->service()->set_status( $id, 'spam' ) );
		$this->assertSame( 'conflict', $this->service()->set_status( $id, 'archived' )->get_error_code() );
		$this->assertTrue( $this->service()->set_status( $id, 'trash' ) );
		$this->assertSame( 'conflict', $this->service()->set_status( $id, 'trash' )->get_error_code() );
		$this->assertSame( 'conflict', $this->service()->set_status( $id, 'read' )->get_error_code() );
	}

	public function test_spam_sets_trashed_gmt_and_trash_remembers_previous_status(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$this->service()->set_status( $id, 'archived' );
		$this->service()->set_status( $id, 'trash' );
		$entry = $this->entries()->find( $id );
		$this->assertSame( 'archived', $entry['previous_status'] );
		$this->assertNotEmpty( $entry['trashed_gmt'] );
		$this->assertTrue( $this->service()->restore( $id ) );
		$this->assertSame( 'archived', $this->entries()->find( $id )['status'] );
		$this->assertNull( $this->entries()->find( $id )['trashed_gmt'] );
	}

	public function test_restore_is_time_limited(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		Clock::freeze( strtotime( '2026-01-01 10:00:00 UTC' ) );
		$this->service()->set_status( $id, 'trash' );
		Clock::freeze( strtotime( '2026-02-05 10:00:00 UTC' ) );
		$this->assertSame( 'conflict', $this->service()->restore( $id )->get_error_code() );
	}

	public function test_editor_author_and_subscriber_are_forbidden_even_for_existing_entries(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			$this->as_role( $role );
			$this->assertSame( 'forbidden', $this->service()->set_status( $id, 'read' )->get_error_code(), $role );
			$this->assertSame( 'forbidden', $this->service()->set_status( $id, 'trash' )->get_error_code(), $role );
			$this->assertSame( 'forbidden', $this->service()->delete( $id )->get_error_code(), $role );
			$this->assertSame( 'forbidden', $this->service()->add_note( $id, 'x' )->get_error_code(), $role );
			$this->assertSame( 'forbidden', $this->service()->notes( $id )->get_error_code(), $role );
			$this->assertFalse( $this->service()->mark_read( $id ), $role );
			// Capability check first: a missing ID yields the same answer (no existence oracle).
			$this->assertSame( 'forbidden', $this->service()->set_status( 999999, 'read' )->get_error_code(), $role );
		}
		$this->assertSame( 'new', $this->entries()->find( $id )['status'] );
		$this->assertNotNull( $this->entries()->find( $id ) );
	}

	public function test_admin_gets_not_found_for_unknown_ids(): void {
		$this->as_admin();
		$this->assertSame( 'entry_not_found', $this->service()->set_status( 999999, 'read' )->get_error_code() );
		$this->assertSame( 'entry_not_found', $this->service()->delete( 999999 )->get_error_code() );
		$this->assertSame( 'entry_not_found', $this->service()->add_note( 999999, 'x' )->get_error_code() );
	}

	public function test_reader_role_auto_reads_but_cannot_edit(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$this->role_with_caps( 'fc_reader', array( Capabilities::READ_ENTRIES ) );
		$this->assertTrue( $this->service()->mark_read( $id ) );
		$this->assertSame( 'read', $this->entries()->find( $id )['status'] );
		$this->assertFalse( $this->service()->mark_read( $id ), 'only new → read' );
		$this->assertSame( 'forbidden', $this->service()->set_status( $id, 'archived' )->get_error_code() );
		$this->assertIsArray( $this->service()->notes( $id ) );
	}

	public function test_edit_without_delete_cannot_trash(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$this->role_with_caps( 'fc_editor_only', array( Capabilities::READ_ENTRIES, Capabilities::EDIT_ENTRIES ) );
		$this->assertTrue( $this->service()->set_status( $id, 'archived' ) );
		$this->assertSame( 'forbidden', $this->service()->set_status( $id, 'trash' )->get_error_code() );
		$this->assertSame( 'forbidden', $this->service()->delete( $id )->get_error_code() );
	}

	public function test_bulk_checks_every_id_individually(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$b    = $this->make_entry( $form );
		$c    = $this->make_entry( $form, array(), array( 'status' => 'spam' ) );
		$out  = $this->service()->bulk( 'archive', array( $a, $b, $c, 999999, -3 ) );
		$this->assertSame( array( $a, $b ), $out['done'] );
		$codes = array_column( $out['skipped'], 'code', 'id' );
		$this->assertSame( 'conflict', $codes[ $c ] );
		$this->assertSame( 'entry_not_found', $codes[999999] );
		$this->assertArrayNotHasKey( -3, $codes );

		$out = $this->service()->bulk( 'trash', array( $a, $b, $c ) );
		$this->assertCount( 3, $out['done'] );
		$out = $this->service()->bulk( 'restore', array( $a, $c ) );
		$this->assertSame( array( $a, $c ), $out['done'] );
		$this->assertSame( 'spam', $this->entries()->find( $c )['status'] );
		$out = $this->service()->bulk( 'delete', array( $b ) );
		$this->assertSame( array( $b ), $out['done'] );
		$this->assertNull( $this->entries()->find( $b ) );

		$this->assertSame( 'invalid_request', $this->service()->bulk( 'explode', array( $a ) )->get_error_code() );
		$this->assertSame( 'invalid_request', $this->service()->bulk( 'read', range( 1, 501 ) )->get_error_code() );
	}

	public function test_bulk_as_editor_skips_everything(): void {
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$this->as_role( 'editor' );
		$out = $this->service()->bulk( 'delete', array( $a ) );
		$this->assertSame( array(), $out['done'] );
		$this->assertSame( 'forbidden', $out['skipped'][0]['code'] );
		$this->assertNotNull( $this->entries()->find( $a ) );
	}

	public function test_notes_are_plaintext_and_do_not_touch_values(): void {
		$user   = $this->as_admin();
		$form   = $this->create_published_form();
		$id     = $this->make_entry( $form );
		$before = $this->entries()->values_for( $id );
		$note   = $this->service()->add_note( $id, "<script>alert(1)</script>Rückruf\nmorgen" );
		$this->assertIsInt( $note );
		$this->assertSame( $before, $this->entries()->values_for( $id ) );
		$notes = $this->entries()->notes_for( $id );
		$this->assertSame( 'note', $notes[0]['type'] );
		$this->assertSame( $user, (int) $notes[0]['user_id'] );
		$this->assertStringNotContainsString( '<script>', $notes[0]['message'] );
		$this->assertStringContainsString( 'Rückruf', $notes[0]['message'] );
		$this->assertSame( 'validation_failed', $this->service()->add_note( $id, '   ' )->get_error_code() );
		$this->assertSame( 'validation_failed', $this->service()->add_note( $id, str_repeat( 'a', 5001 ) )->get_error_code() );
	}

	public function test_retry_job_checks_ownership_and_type_capability(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$b    = $this->make_entry( $form );
		$job  = $this->enqueue_mail( $a, (int) $form['id'] );
		$this->assertSame( 'job_not_retryable', $this->service()->retry_job( $a, $job )->get_error_code(), 'pending job' );
		$this->assertSame( 'job_not_found', $this->service()->retry_job( $b, $job )->get_error_code(), 'job of other entry (IDOR)' );

		$conn  = $this->jobs()->enqueue( 'connector.action', 'form-captain:1:' . $a . ':ref.x:a_0000000001', array( 'action_key' => 'ref.x' ), $a, (int) $form['id'] );
		$token = $this->jobs()->claim( $conn );
		$this->jobs()->fail( $conn, $token, 'remote_down', 'x', true );
		$this->role_with_caps( 'fc_entry_editor', array( Capabilities::READ_ENTRIES, Capabilities::EDIT_ENTRIES ) );
		$this->assertSame( 'forbidden', $this->service()->retry_job( $a, $conn )->get_error_code(), 'connector retry needs manage_integrations' );
		$this->as_admin();
		$this->assertTrue( $this->service()->retry_job( $a, $conn ) );
		$this->assertSame( 'pending', $this->jobs()->find( $conn )['status'] );
	}
}
