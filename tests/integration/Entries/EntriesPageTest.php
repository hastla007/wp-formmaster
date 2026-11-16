<?php
/**
 * Admin entries screen: list, detail, rights, IDOR, bulk/entry actions (P4-04, P4-05, P4-09, P5-15).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Install\Capabilities;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class EntriesPageTest extends TestCase {

	use EntryFixtures;

	private function page(): EntriesPage {
		return $this->plugin()->get( EntriesPage::class );
	}

	/** @param array<string,string|int> $get */
	private function render( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			$this->page()->render();
		} finally {
			$out  = (string) ob_get_clean();
			$_GET = array();
		}
		return $out;
	}

	public function test_list_shows_contact_fields_escaped_and_status_tabs(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$this->make_entry( $form, array( 'f_name01' => '<img src=x onerror=alert(1)>Max', 'f_mail01' => 'max@example.org', 'f_msg001' => 'Hi' ) );
		$this->make_entry( $form, array( 'f_name01' => 'Spam Bot', 'f_mail01' => 'bot@example.org' ), array( 'status' => 'spam', 'spam_reason' => 'honeypot' ) );
		$gone = $this->make_entry( $form, array( 'f_name01' => 'Geloescht', 'f_mail01' => 'g@example.org' ) );
		$this->plugin()->get( EntryDeletionService::class )->delete( $gone );

		$html = $this->render();
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;Max', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'max@example.org', $html );
		$this->assertStringContainsString( 'Kontakt', $html );
		$this->assertStringNotContainsString( 'Spam Bot', $html, 'spam hidden from the default view' );
		$this->assertStringNotContainsString( 'Geloescht', $html );
		$this->assertMatchesRegularExpression( '/Spam <span class="count">\(1\)/', $html );
		$this->assertStringContainsString( 'name="fc_bulk_action"', $html );
		$this->assertStringContainsString( EntriesPage::ACTION_BULK, $html );

		$spam = $this->render( array( 'status' => 'spam' ) );
		$this->assertStringContainsString( 'Spam Bot', $spam );
		$this->assertStringContainsString( 'Honeypot field filled', $spam );
	}

	public function test_sensitive_contact_field_is_not_shown_in_list(): void {
		$this->as_admin();
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'sensitive' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
			)
		);
		$this->make_entry( $form, array( 'f_name01' => 'Diagnose X', 'f_mail01' => 'p@example.org' ) );
		$html = $this->render();
		$this->assertStringNotContainsString( 'Diagnose X', $html );
		$this->assertStringContainsString( 'p@example.org', $html );
	}

	public function test_search_and_form_filter(): void {
		$this->as_admin();
		$a = $this->create_published_form();
		$b = $this->create_published_form();
		$this->make_entry( $a, array( 'f_name01' => 'Alpha Person', 'f_mail01' => 'a@example.org' ) );
		$this->make_entry( $b, array( 'f_name01' => 'Beta Person', 'f_mail01' => 'b@example.org' ) );
		$html = $this->render( array( 's' => 'Alpha' ) );
		$this->assertStringContainsString( 'Alpha Person', $html );
		$this->assertStringNotContainsString( 'Beta Person', $html );
		$html = $this->render( array( 'form_id' => (int) $b['id'] ) );
		$this->assertStringContainsString( 'Beta Person', $html );
		$this->assertStringNotContainsString( 'Alpha Person', $html );
	}

	public function test_editor_and_author_cannot_open_list_or_detail(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		foreach ( array( 'editor', 'author' ) as $role ) {
			$this->as_role( $role );
			try {
				$this->render( array( 'view' => $id ) );
				$this->fail( 'wp_die expected for ' . $role );
			} catch ( \WPDieException $e ) {
				$this->assertStringContainsString( 'not allowed', $e->getMessage() );
			}
		}
		$this->assertSame( 'new', $this->entries()->find( $id )['status'], 'no auto-read without rights' );
	}

	public function test_detail_view_labels_removed_fields_auto_read_and_no_ip(): void {
		$admin = $this->as_admin();
		$form  = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Ihr Name' ),
				array( 'id' => 'f_old001', 'type' => 'text', 'key' => 'old', 'label' => 'Altes Feld' ),
				array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Einwilligung', 'consent_text' => 'Ich stimme <strong>zu</strong>.<script>x</script>', 'consent_revision' => 2 ),
			)
		);
		$id    = $this->make_entry(
			$form,
			array( 'f_name01' => "<b>Max</b>\nZeile", 'f_old001' => 'Wert alt', 'f_cons01' => '1' ),
			array(
				'status'     => 'new',
				'source_url' => 'https://example.org/kontakt/',
				'consent'    => array( 'f_cons01' => array( 'given' => true, 'revision' => 2, 'given_at' => '2026-11-16T09:41:07Z' ) ),
			)
		);
		// New version without the old field.
		$schema           = $form['schema'];
		$schema['fields'] = array( $schema['fields'][0], $schema['fields'][2] );
		$this->forms()->update( (int) $form['id'], array( 'schema' => $schema ), (int) $form['revision'] );
		$this->forms()->publish_version( (int) $form['id'], $schema );
		$this->entries()->add_note( $id, $admin, 'Rückruf vereinbart' );
		$job   = $this->enqueue_mail( $id, (int) $form['id'] );
		$token = $this->jobs()->claim( $job );
		$this->jobs()->fail( $job, $token, 'mail_failed', 'boom', true );

		$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
		$_SERVER['HTTP_USER_AGENT'] = 'SecretBrowser/1.0';
		$html                       = $this->render( array( 'view' => $id ) );

		$this->assertStringContainsString( 'Ihr Name', $html );
		$this->assertStringContainsString( '&lt;b&gt;Max&lt;/b&gt;<br />', $html );
		$this->assertStringContainsString( 'Altes Feld', $html );
		$this->assertStringContainsString( 'Field removed', $html );
		$this->assertStringContainsString( 'Wert alt', $html );
		$this->assertStringContainsString( 'text revision 2', $html );
		$this->assertStringContainsString( '<strong>zu</strong>', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( 'https://example.org/kontakt/', $html );
		$this->assertStringContainsString( 'Rückruf vereinbart', $html );
		$this->assertStringContainsString( 'Admin notification', $html );
		$this->assertStringContainsString( 'Failed permanently', $html );
		$this->assertStringContainsString( 'mail_failed', $html );
		$this->assertStringContainsString( 'Send again', $html );
		$this->assertStringNotContainsString( '203.0.113.9', $html );
		$this->assertStringNotContainsString( 'SecretBrowser', $html );
		$this->assertSame( 'read', $this->entries()->find( $id )['status'], 'auto-read on open' );
	}

	public function test_reader_sees_notes_but_no_write_controls(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$this->entries()->add_note( $id, 1, 'Interne Notiz' );
		$this->role_with_caps( 'fc_reader', array( Capabilities::READ_ENTRIES ) );
		$html = $this->render( array( 'view' => $id ) );
		$this->assertStringContainsString( 'Interne Notiz', $html );
		$this->assertStringNotContainsString( 'name="note"', $html );
		$this->assertStringNotContainsString( 'Move to trash', $html );
		$this->assertStringNotContainsString( 'Delete permanently', $html );
	}

	public function test_bulk_requires_nonce_and_capability(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$b    = $this->make_entry( $form );
		$this->assertSame( 'invalid_nonce', $this->page()->process_bulk( array( 'fc_bulk_action' => 'archive', 'ids' => array( $a ) ) )->get_error_code() );
		$out = $this->page()->process_bulk(
			array(
				'_fc_nonce'       => wp_create_nonce( EntriesPage::NONCE_BULK ),
				'fc_bulk_action'  => '-1',
				'fc_bulk_action2' => 'archive',
				'ids'             => array( $a, $b ),
			)
		);
		$this->assertSame( array( $a, $b ), $out['done'] );
		$this->assertSame( 'archived', $this->entries()->find( $a )['status'] );

		$this->as_role( 'editor' );
		$this->assertSame( 'forbidden', $this->page()->process_bulk( array( '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_BULK ), 'fc_bulk_action' => 'delete', 'ids' => array( $a ) ) )->get_error_code() );
		$this->assertNotNull( $this->entries()->find( $a ) );
	}

	public function test_entry_action_nonce_is_bound_to_the_entry(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$b    = $this->make_entry( $form );
		$res  = $this->page()->process_entry_action( array( 'entry_id' => $b, 'do' => 'delete', '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_ENTRY . $a ) ) );
		$this->assertSame( 'invalid_nonce', $res->get_error_code() );
		$this->assertNotNull( $this->entries()->find( $b ) );

		$nonce = wp_create_nonce( EntriesPage::NONCE_ENTRY . $b );
		$this->assertSame( 'status_changed', $this->page()->process_entry_action( array( 'entry_id' => $b, 'do' => 'status', 'status' => 'spam', '_fc_nonce' => $nonce ) ) );
		$this->assertSame( 'note_added', $this->page()->process_entry_action( array( 'entry_id' => $b, 'do' => 'note', 'note' => 'Hallo', '_fc_nonce' => $nonce ) ) );
		$this->assertSame( 'trashed', $this->page()->process_entry_action( array( 'entry_id' => $b, 'do' => 'trash', '_fc_nonce' => $nonce ) ) );
		$this->assertSame( 'restored', $this->page()->process_entry_action( array( 'entry_id' => $b, 'do' => 'restore', '_fc_nonce' => $nonce ) ) );
		$this->assertSame( 'spam', $this->entries()->find( $b )['status'] );
		$this->assertSame( 'deleted', $this->page()->process_entry_action( array( 'entry_id' => $b, 'do' => 'delete', '_fc_nonce' => $nonce ) ) );
		$this->assertNull( $this->entries()->find( $b ) );
		$this->assertSame( 'invalid_request', $this->page()->process_entry_action( array( 'entry_id' => $a, 'do' => 'hack', '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_ENTRY . $a ) ) )->get_error_code() );
	}

	public function test_resend_notification_queues_once(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$id    = $this->make_entry( $form );
		$nonce = wp_create_nonce( EntriesPage::NONCE_ENTRY . $id );
		$this->assertSame( 'queued', $this->page()->process_entry_action( array( 'entry_id' => $id, 'do' => 'resend', '_fc_nonce' => $nonce ) ) );
		$this->assertSame( 'queued', $this->page()->process_entry_action( array( 'entry_id' => $id, 'do' => 'resend', '_fc_nonce' => $nonce ) ) );
		$this->assertCount( 1, $this->jobs()->for_entry( $id ), 'pending job is not duplicated' );
	}

	public function test_export_screen_marks_sensitive_columns_unchecked(): void {
		$this->as_admin();
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
				array( 'id' => 'f_sec001', 'type' => 'text', 'key' => 'secret', 'label' => 'Geheim', 'sensitive' => true ),
			)
		);
		$html = $this->render( array( 'export' => 1, 'form_id' => (int) $form['id'] ) );
		$this->assertMatchesRegularExpression( '/value="field:f_name01" checked=\'checked\'/', $html );
		$this->assertMatchesRegularExpression( '/value="field:f_sec001" \/>/', $html );
		$this->assertStringContainsString( 'form_captain_export_entries', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}
}
