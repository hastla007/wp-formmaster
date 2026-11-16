<?php
/**
 * Mail jobs: content, allowlist, header injection, failures, dead letter (P4-07, P4-08, P4-17, F-12).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Database\Tables;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class MailJobHandlerTest extends TestCase {

	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		update_option( 'admin_email', 'site-admin@example.org' );
		$this->reset_mailer();
	}

	/** @return array<int,array<string,mixed>> */
	private function fields(): array {
		return array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
			array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'confirmation_recipient' => true ),
			array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht' ),
			array( 'id' => 'f_iban01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
			array( 'id' => 'f_html01', 'type' => 'html', 'content' => '<p>Hinweis intern</p>' ),
			array( 'id' => 'f_topic1', 'type' => 'select', 'key' => 'topic', 'label' => 'Thema', 'options' => array( array( 'value' => 'offer', 'label' => 'Angebot' ) ) ),
			array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Datenschutz', 'consent_text' => 'Ich stimme der Verarbeitung zu.' ),
		);
	}

	/** @param array<string,mixed> $notification */
	private function form( array $notification = array(), array $extra = array() ): array {
		return $this->create_published_form(
			$this->fields(),
			array_merge(
				array(
					'notification' => array_merge(
						array(
							'enabled'        => true,
							'to'             => array( '{admin_email}', 'team@example.org, sales@example.org', 'not-an-email' ),
							'reply_to_field' => 'f_mail01',
							'subject'        => 'Neu: {form_title} von {field:name} {field:iban}',
							'fields'         => 'all_non_sensitive',
						),
						$notification
					),
					'email'        => array( 'from_name' => 'Kontakt Team', 'from_address' => 'noreply@example.org' ),
				),
				$extra
			)
		);
	}

	/** @param array<string,string> $override */
	private function values( array $override = array() ): array {
		return array_merge(
			array(
				'f_name01' => 'Jürgen <b>Groß</b>',
				'f_mail01' => 'juergen@example.net',
				'f_msg001' => "Zeile 1\nZeile 2 <script>x</script>",
				'f_iban01' => 'DE89370400440532013000',
				'f_topic1' => 'offer',
				'f_cons01' => '1',
			),
			$override
		);
	}

	public function test_admin_mail_contents(): void {
		$form = $this->form();
		$id   = $this->make_entry( $form, $this->values() );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$this->assertSame( 'succeeded', $this->jobs()->find( $job )['status'] );

		$mails = $this->sent_mails();
		$this->assertCount( 1, $mails );
		$mail = $mails[0];
		$to   = array_map( static fn( array $r ): string => $r[0], $mail->to );
		$this->assertSame( array( 'site-admin@example.org', 'team@example.org', 'sales@example.org' ), $to );
		$this->assertSame( 'Neu: Kontakt von Jürgen Groß', $mail->subject, 'sensitive placeholder is empty, tags stripped' );
		$this->assertStringContainsString( 'Reply-To: juergen@example.net', $mail->header );
		$this->assertStringContainsString( 'From: Kontakt Team <noreply@example.org>', $mail->header );
		$this->assertStringContainsString( 'multipart/alternative', $mail->header );

		$body = $mail->body;
		$this->assertStringContainsString( 'Jürgen &lt;b&gt;Groß&lt;/b&gt;', $body, 'HTML part is escaped' );
		$html = substr( $body, (int) strpos( $body, 'Content-Type: text/html' ) );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'Angebot', $body, 'option label instead of value' );
		$this->assertStringContainsString( 'Yes', $body, 'consent shown as Yes/No' );
		$this->assertStringNotContainsString( 'Ich stimme', $body, 'consent text not included' );
		$this->assertStringNotContainsString( 'DE89370400440532013000', $body, 'sensitive field excluded' );
		$this->assertStringNotContainsString( 'Hinweis intern', $body, 'html field excluded' );
		$this->assertStringContainsString( 'Content-Type: text/plain', $body, 'text alternative present' );
		$this->assertStringContainsString( 'Nachricht: ', $body );
		$this->assertStringContainsString( 'page=form-captain-entries', $body );
		$this->assertStringContainsString( 'view=' . $id, $body );
	}

	public function test_explicit_field_selection_includes_only_chosen_fields(): void {
		$form = $this->form( array( 'fields' => array( 'f_mail01', 'iban' ) ) );
		$id   = $this->make_entry( $form, $this->values() );
		$this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$body = $this->sent_mails()[0]->body;
		$this->assertStringContainsString( 'DE89370400440532013000', $body, 'explicitly selected sensitive field' );
		$this->assertStringNotContainsString( 'Zeile 1', $body );
		$this->assertStringNotContainsString( 'Angebot', $body );
	}

	public function test_header_injection_in_reply_to_and_subject_is_rejected(): void {
		global $wpdb;
		$form = $this->form( array( 'subject' => 'Anfrage {field:message}' ) );
		$id   = $this->make_entry(
			$form,
			$this->values(
				array(
					'f_mail01' => "juergen@example.net\r\nBcc: victim@example.com",
					'f_msg001' => "Hallo\r\nBcc: victim2@example.com",
				)
			)
		);
		$this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$mail = $this->sent_mails()[0];
		$this->assertStringNotContainsString( 'Reply-To', $mail->header );
		$this->assertDoesNotMatchRegularExpression( '/^(Bcc|Cc):/mi', $mail->header );
		$this->assertStringNotContainsString( 'victim@example.com', $mail->header );
		$this->assertSame( array(), $mail->bcc );
		$this->assertStringNotContainsString( "\n", $mail->subject );
		$this->assertStringNotContainsString( "\r", $mail->subject );
		$this->assertStringStartsWith( 'Anfrage Hallo', $mail->subject );
		$log = $wpdb->get_row( $wpdb->prepare( 'SELECT code, message FROM ' . Tables::name( Tables::LOG ) . " WHERE entry_id = %d AND code = 'mail_header_rejected'", $id ), ARRAY_A );
		$this->assertNotEmpty( $log );
		$this->assertStringContainsString( 'reply_to', $log['message'] );
		$this->assertStringNotContainsString( 'victim', $log['message'] );
	}

	public function test_text_format_has_no_html(): void {
		update_option( 'form_captain_settings', array_replace_recursive( (array) get_option( 'form_captain_settings', array() ), array( 'email' => array( 'format' => 'text' ) ) ) );
		$form = $this->form();
		$id   = $this->make_entry( $form, $this->values() );
		$this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$mail = $this->sent_mails()[0];
		$this->assertStringContainsString( 'text/plain', $mail->header );
		$this->assertStringNotContainsString( '<table', $mail->body );
	}

	public function test_wp_mail_failure_retries_then_dead_letter_with_event(): void {
		global $wpdb;
		$form  = $this->form();
		$id    = $this->make_entry( $form, $this->values() );
		$job   = $this->jobs()->enqueue( 'mail.admin', 'form-captain:1:' . $id . ':mail.admin', array( 'notification' => 'admin' ), $id, (int) $form['id'], 0, 2 );
		$break = static function ( $mailer ): void {
			$mailer->clearAllRecipients();
		};
		add_action( 'phpmailer_init', $break );
		$failed = array();
		$cb     = static function ( $entry_id, $form_id, $type, $code ) use ( &$failed ): void {
			$failed[] = array( $entry_id, $form_id, $type, $code );
		};
		add_action( 'form_captain_notification_failed_v1', $cb, 10, 4 );

		$now = time();
		Clock::freeze( $now );
		$this->runner()->run();
		$row = $this->jobs()->find( $job );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'mail_failed', $row['last_error_code'] );
		$this->assertGreaterThan( Clock::now(), $row['run_after_gmt'], 'backoff scheduled' );
		$this->assertStringNotContainsString( '@', (string) $row['last_error_message'] );
		$this->assertSame( array(), $failed );

		Clock::freeze( $now + 7200 );
		$this->runner()->run();
		$this->assertSame( 'dead', $this->jobs()->find( $job )['status'] );
		$this->assertSame( array( array( $id, (int) $form['id'], 'mail.admin', 'mail_failed' ) ), $failed );
		remove_action( 'phpmailer_init', $break );
		remove_action( 'form_captain_notification_failed_v1', $cb, 10 );

		// Entry is untouched by delivery failures.
		$this->assertSame( 'new', $this->entries()->find( $id )['status'] );
		$this->assertSame( array(), $this->sent_mails() );

		// Manual retry from dead delivers once.
		$this->assertTrue( $this->jobs()->retry( $job ) );
		$this->assertSame( 0, $this->jobs()->find( $job )['attempts'] );
		$this->runner()->run();
		$this->assertSame( 'succeeded', $this->jobs()->find( $job )['status'] );
		$this->assertCount( 1, $this->sent_mails() );
		$logs = implode( ' ', $wpdb->get_col( 'SELECT message FROM ' . Tables::name( Tables::LOG ) ) );
		$this->assertStringNotContainsString( 'juergen@example.net', $logs );
		$this->assertStringNotContainsString( 'Jürgen', $logs );
	}

	public function test_spam_and_trashed_entries_get_no_mail(): void {
		$form = $this->form();
		$spam = $this->make_entry( $form, $this->values(), array( 'status' => 'spam' ) );
		$tr   = $this->make_entry( $form, $this->values(), array( 'status' => 'trash' ) );
		$a    = $this->enqueue_mail( $spam, (int) $form['id'] );
		$b    = $this->enqueue_mail( $tr, (int) $form['id'] );
		$this->runner()->run();
		$this->assertSame( array(), $this->sent_mails() );
		$this->assertSame( 'entry_spam', $this->jobs()->find( $a )['last_error_code'] );
		$this->assertSame( 'entry_trashed', $this->jobs()->find( $b )['last_error_code'] );
		$this->assertSame( 'dead', $this->jobs()->find( $a )['status'] );
	}

	public function test_confirmation_goes_only_to_validated_field_without_values(): void {
		$form = $this->form( array(), array( 'confirmation_mail' => array( 'enabled' => true, 'subject' => 'Danke, {field:name}', 'body' => "Hallo {field:name},\ndanke für Ihre Nachricht zu {form_title}. {field:iban}" ) ) );
		$id   = $this->make_entry( $form, $this->values() );
		$job  = $this->enqueue_mail( $id, (int) $form['id'], 'mail.confirmation' );
		$this->runner()->run();
		$this->assertSame( 'succeeded', $this->jobs()->find( $job )['status'] );
		$mail = $this->sent_mails()[0];
		$this->assertSame( 'juergen@example.net', $mail->to[0][0] );
		$this->assertCount( 1, $mail->to );
		$this->assertSame( 'Danke, Jürgen Groß', $mail->subject );
		$body = $mail->body;
		$this->assertStringContainsString( 'danke für Ihre Nachricht zu Kontakt', $body );
		$this->assertStringNotContainsString( 'Zeile 1', $body, 'no field values unless opted in' );
		$this->assertStringNotContainsString( 'DE8937', $body, 'sensitive placeholder empty' );
		$this->assertStringNotContainsString( 'page=form-captain-entries', $body, 'no admin link for submitters' );
	}

	public function test_confirmation_with_invalid_recipient_fails_permanently(): void {
		$form = $this->form( array(), array( 'confirmation_mail' => array( 'enabled' => true ) ) );
		$id   = $this->make_entry( $form, $this->values( array( 'f_mail01' => 'kaputt' ) ) );
		$job  = $this->enqueue_mail( $id, (int) $form['id'], 'mail.confirmation' );
		$this->runner()->run();
		$this->assertSame( 'dead', $this->jobs()->find( $job )['status'] );
		$this->assertSame( 'invalid_recipient', $this->jobs()->find( $job )['last_error_code'] );
		$this->assertSame( array(), $this->sent_mails() );
	}

	public function test_confirmation_rate_limit_per_recipient(): void {
		$form = $this->form( array(), array( 'confirmation_mail' => array( 'enabled' => true ) ) );
		$jobs = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$id     = $this->make_entry( $form, $this->values() );
			$jobs[] = $this->enqueue_mail( $id, (int) $form['id'], 'mail.confirmation' );
		}
		$this->runner()->run();
		$this->assertCount( 3, $this->sent_mails() );
		$this->assertSame( 'receipt_rate_limited', $this->jobs()->find( $jobs[3] )['last_error_code'] );
	}

	public function test_core_always_sends_its_own_mails_without_handoff(): void {
		// Owner decision F-12 / D10: the former `form_captain_mail_handoff` filter is removed;
		// a callback on that hook name must not suppress native delivery.
		$form    = $this->form();
		$a       = $this->make_entry( $form, $this->values() );
		$ja      = $this->enqueue_mail( $a, (int) $form['id'] );
		$handoff = static fn() => 'handled';
		add_filter( 'form_captain_mail_handoff', $handoff );
		$this->runner()->run();
		remove_filter( 'form_captain_mail_handoff', $handoff );
		$this->assertSame( 'succeeded', $this->jobs()->find( $ja )['status'] );
		$this->assertArrayNotHasKey( 'handoff', (array) $this->jobs()->find( $ja )['result'] );
		$this->assertCount( 1, $this->sent_mails() );
		// Same idempotency key cannot create a second job → no duplicate mail.
		$this->assertSame( $ja, $this->enqueue_mail( $a, (int) $form['id'] ) );
		$this->runner()->run();
		$this->assertCount( 1, $this->sent_mails() );
	}

	public function test_disabled_notification_is_skipped(): void {
		$form = $this->form( array( 'enabled' => false ) );
		$id   = $this->make_entry( $form, $this->values() );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$this->assertSame( 'succeeded', $this->jobs()->find( $job )['status'] );
		$this->assertSame( array(), $this->sent_mails() );
	}
}
