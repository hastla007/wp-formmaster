<?php
/**
 * Data protection across all copies (P5-05, P5-14, P5-17) and DB integrity (P1-16).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Database\Tables;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Service\RetentionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class DataLifecycleTest extends TestCase {

	use EntryFixtures;

	private const MARKER_NAME  = 'Zyxw Privatperson';
	private const MARKER_EMAIL = 'zyxw.privat@example.org';

	public function test_retention_deletion_leaves_no_copy_in_tables_queue_logs_export_or_list(): void {
		global $wpdb;
		$this->reset_mailer();
		$this->as_admin();
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form, array( 'f_name01' => self::MARKER_NAME, 'f_mail01' => self::MARKER_EMAIL, 'f_msg001' => 'x' ), array( 'expires_gmt' => '2026-05-01 00:00:00' ) );
		$mail = $this->enqueue_mail( $id, (int) $form['id'] );
		$conn = $this->jobs()->enqueue( 'connector.action', 'form-captain:1:' . $id . ':ref.x:a_0123456789', array( 'action_key' => 'ref.x', 'instance_id' => 'a_0123456789' ), $id, (int) $form['id'] );
		$this->entries()->add_note( $id, 1, 'Notiz zu ' . self::MARKER_NAME );
		$wpdb->insert( Tables::name( Tables::FILES ), array( 'entry_id' => $id, 'field_id' => 'f_x', 'storage_key' => 'k' . $id, 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat( '0', 64 ), 'status' => 'clean', 'created_gmt' => Clock::now() ) );

		$this->plugin()->get( RetentionService::class )->run();

		$this->assertNull( $this->entries()->find( $id ) );
		foreach ( array( Tables::ENTRY_VALUES, Tables::ENTRY_NOTES, Tables::FILES ) as $table ) {
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( $table ) . ' WHERE entry_id = %d', $id ) ), $table );
		}
		$this->assertSame( 'cancelled', $this->jobs()->find( $mail )['status'] );
		$this->assertSame( 'cancelled', $this->jobs()->find( $conn )['status'] );

		// Queue: no field values in payloads; worker delivers nothing.
		$payloads = implode( ' ', $wpdb->get_col( 'SELECT CONCAT(payload_json, IFNULL(last_error_message, \'\'), IFNULL(result_json, \'\')) FROM ' . Tables::name( Tables::JOBS ) ) );
		$this->assertStringNotContainsString( self::MARKER_NAME, $payloads );
		$this->assertStringNotContainsString( self::MARKER_EMAIL, $payloads );
		$this->runner()->run();
		$this->assertSame( array(), $this->sent_mails() );

		// Logs: IDs and codes only.
		$logs = implode( ' ', $wpdb->get_col( 'SELECT CONCAT(code, \' \', message) FROM ' . Tables::name( Tables::LOG ) ) );
		$this->assertStringContainsString( 'entry_purged', $logs );
		$this->assertStringNotContainsString( 'Zyxw', $logs );
		$this->assertStringNotContainsString( self::MARKER_EMAIL, $logs );

		// Options: no shadow register with values.
		$options = implode( ' ', $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'form_captain%'" ) );
		$this->assertStringNotContainsString( 'Zyxw', $options );

		// Export and list.
		$stream = fopen( 'php://memory', 'w+b' );
		$this->plugin()->get( CsvExporter::class )->export( array( 'form_id' => (int) $form['id'], 'status' => array( 'new', 'read', 'archived', 'spam', 'trash' ) ), $stream );
		rewind( $stream );
		$this->assertStringNotContainsString( 'Zyxw', (string) stream_get_contents( $stream ) );
		$list = $this->entries()->list( array( 'form_id' => (int) $form['id'], 'any_status' => true ) );
		$this->assertSame( 0, $list['total'] );
	}

	public function test_anonymized_entry_is_not_mailed_and_shown_as_anonymized(): void {
		$this->reset_mailer();
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form, array( 'f_name01' => '[deleted]', 'f_mail01' => '[deleted]' ) );
		$this->entries()->update( $id, array( 'anonymized_gmt' => Clock::now() ) );
		$job = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$this->assertSame( 'entry_anonymized', $this->jobs()->find( $job )['last_error_code'] );
		$this->assertSame( array(), $this->sent_mails() );
		$this->assertSame( 'Anonymized', CsvExporter::status_label( $this->entries()->find( $id ) ) );
	}

	public function test_restored_backup_rows_are_deleted_again(): void {
		// DS-B-02: cleanup is purely time based – re-inserted expired rows are removed on the next run.
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-04-01 00:00:00' ) );
		$this->plugin()->get( RetentionService::class )->run();
		$this->assertNull( $this->entries()->find( $id ) );
		$again = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-04-01 00:00:00' ) );
		$this->plugin()->get( RetentionService::class )->run();
		$this->assertNull( $this->entries()->find( $again ) );
	}

	public function test_unique_keys_and_indexes(): void {
		global $wpdb;
		$form = $this->create_published_form();
		$key  = hash( 'sha256', 'dup' );
		$id   = $this->make_entry( $form, array(), array( 'request_key' => $key ) );
		$wpdb->suppress_errors( true );
		$this->assertSame( 0, $this->entries()->insert( array( 'form_id' => $form['id'], 'form_version_id' => $form['published_version_id'], 'request_key' => $key ) ), 'request_key unique' );
		$this->assertFalse( $this->entries()->insert_values( $id, array( 'f_name01' => 'zweiter Wert' ) ), 'entry_field unique' );
		$wpdb->suppress_errors( false );
		$a = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->assertSame( $a, $this->enqueue_mail( $id, (int) $form['id'] ), 'idempotency_key unique' );

		$expect = array(
			Tables::ENTRIES       => array( 'request_key', 'form_status_created', 'status_created', 'expires', 'trashed' ),
			Tables::ENTRY_VALUES  => array( 'entry_field', 'field_value' ),
			Tables::ENTRY_NOTES   => array( 'entry' ),
			Tables::JOBS          => array( 'idempotency_key', 'runnable', 'entry' ),
			Tables::FILES         => array( 'entry' ),
			Tables::FORMS         => array( 'public_id' ),
			Tables::FORM_VERSIONS => array( 'form_version' ),
		);
		foreach ( $expect as $table => $indexes ) {
			$names = array_unique( $wpdb->get_col( 'SHOW INDEX FROM ' . Tables::name( $table ), 2 ) );
			foreach ( $indexes as $index ) {
				$this->assertContains( $index, $names, $table . '.' . $index );
			}
		}
	}

	public function test_partial_delete_without_transaction_is_completed_on_retry(): void {
		global $wpdb;
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		// Simulate a crash after values were removed but before the entry row was deleted.
		$wpdb->delete( Tables::name( Tables::ENTRY_VALUES ), array( 'entry_id' => $id ) );
		$this->assertNotNull( $this->entries()->find( $id ) );
		$this->assertTrue( $this->plugin()->get( EntryDeletionService::class )->delete( $id, 'retention' ) );
		$this->assertNull( $this->entries()->find( $id ) );
	}
}
