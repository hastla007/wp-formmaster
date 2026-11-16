<?php
/**
 * Trash/restore/hard delete incl. job cancellation and file hook (P4-13, P4-18, P5-17).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Database\Tables;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class EntryDeletionTest extends TestCase {

	use EntryFixtures;

	private function deletion(): EntryDeletionService {
		return $this->plugin()->get( EntryDeletionService::class );
	}

	public function test_trash_and_restore(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form, array(), array( 'status' => 'read' ) );
		$this->assertTrue( $this->deletion()->trash( $id ) );
		$this->assertSame( 'trash', $this->entries()->find( $id )['status'] );
		$this->assertSame( 'read', $this->entries()->find( $id )['previous_status'] );
		$this->assertTrue( $this->deletion()->restore( $id ) );
		$this->assertSame( 'read', $this->entries()->find( $id )['status'] );
	}

	public function test_delete_cancels_jobs_before_values_disappear_and_removes_everything(): void {
		global $wpdb;
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );
		$conn = $this->jobs()->enqueue( 'connector.action', 'k-' . $id, array( 'action_key' => 'ref.x' ), $id, (int) $form['id'] );
		$this->entries()->add_note( $id, 1, 'Notiz' );
		$wpdb->insert(
			Tables::name( Tables::FILES ),
			array(
				'entry_id'      => $id,
				'field_id'      => 'f_file01',
				'storage_key'   => bin2hex( random_bytes( 16 ) ),
				'original_name' => 'cv.pdf',
				'mime'          => 'application/pdf',
				'size'          => 10,
				'sha256'        => str_repeat( 'a', 64 ),
				'status'        => 'clean',
				'created_gmt'   => Clock::now(),
			)
		);

		$order = array();
		add_filter(
			'form_captain_delete_entry_files_v1',
			function ( $entry_id ) use ( &$order, $job ) {
				$order[] = array( 'files', $this->jobs()->find( $job )['status'], count( $this->entries()->values_for( (int) $entry_id ) ) );
				return $entry_id;
			}
		);
		add_action(
			'form_captain_entry_deleted_v1',
			function ( $entry_id, $form_id, $reason ) use ( &$order ) {
				$order[] = array( 'event', $entry_id, $form_id, $reason, null === $this->entries()->find( (int) $entry_id ) );
			},
			10,
			3
		);

		$this->assertTrue( $this->deletion()->delete( $id, 'manual' ) );

		$this->assertSame( array( 'files', 'cancelled', 3 ), $order[0], 'jobs cancelled before files/values are removed' );
		$this->assertSame( array( 'event', $id, (int) $form['id'], 'manual', true ), $order[1] );
		$this->assertNull( $this->entries()->find( $id ) );
		$this->assertSame( array(), $this->entries()->values_for( $id ) );
		$this->assertSame( array(), $this->entries()->notes_for( $id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::FILES ) . ' WHERE entry_id = %d', $id ) ) );
		$this->assertSame( 'cancelled', $this->jobs()->find( $job )['status'] );
		$this->assertSame( 'entry_deleted', $this->jobs()->find( $job )['last_error_code'] );
		$this->assertSame( 'cancelled', $this->jobs()->find( $conn )['status'] );
		$this->assertFalse( $this->deletion()->delete( $id ), 'second delete is a no-op' );
	}

	public function test_deleted_entry_is_not_mailed_by_worker(): void {
		$this->reset_mailer();
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->deletion()->delete( $id );
		$this->runner()->run();
		$this->assertSame( array(), $this->sent_mails() );
		$this->assertSame( 'cancelled', $this->jobs()->find( $job )['status'] );
		// Even a manual retry cannot deliver anything for the deleted entry.
		$this->jobs()->retry( $job );
		$this->runner()->run();
		$this->assertSame( array(), $this->sent_mails() );
		$this->assertSame( 'dead', $this->jobs()->find( $job )['status'] );
		$this->assertSame( 'entry_deleted', $this->jobs()->find( $job )['last_error_code'] );
	}

	public function test_running_job_of_deleted_entry_is_cancelled_and_logged(): void {
		global $wpdb;
		$form  = $this->create_published_form();
		$id    = $this->make_entry( $form );
		$job   = $this->enqueue_mail( $id, (int) $form['id'] );
		$token = $this->jobs()->claim( $job );
		$this->assertNotNull( $token );
		$this->deletion()->delete( $id );
		$this->assertSame( 'cancelled', $this->jobs()->find( $job )['status'] );
		$this->assertFalse( $this->jobs()->complete( $job, $token ), 'late completion of a cancelled job is rejected' );
		$codes = $wpdb->get_col( $wpdb->prepare( 'SELECT code FROM ' . Tables::name( Tables::LOG ) . ' WHERE entry_id = %d', $id ) );
		$this->assertContains( 'entry_deleted_jobs_running', $codes );
		$this->assertContains( 'entry_purged', $codes );
	}

	public function test_delete_for_form_removes_all_statuses(): void {
		$form    = $this->create_published_form();
		$other   = $this->create_published_form();
		$ids     = array(
			$this->make_entry( $form ),
			$this->make_entry( $form, array(), array( 'status' => 'spam' ) ),
			$this->make_entry( $form, array(), array( 'status' => 'trash' ) ),
		);
		$keep    = $this->make_entry( $other );
		$reasons = array();
		$cb      = static function ( $entry_id, $form_id, $reason ) use ( &$reasons ): void {
			$reasons[] = $reason;
		};
		add_action( 'form_captain_entry_deleted_v1', $cb, 10, 3 );
		$this->assertSame( 3, $this->deletion()->delete_for_form( (int) $form['id'] ) );
		remove_action( 'form_captain_entry_deleted_v1', $cb, 10 );
		$this->assertSame( array( 'form_deleted', 'form_deleted', 'form_deleted' ), $reasons );
		foreach ( $ids as $id ) {
			$this->assertNull( $this->entries()->find( $id ) );
		}
		$this->assertSame( 0, $this->deletion()->remaining_for_form( (int) $form['id'] ) );
		$this->assertNotNull( $this->entries()->find( $keep ) );
	}
}
