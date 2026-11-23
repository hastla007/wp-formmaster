<?php
/**
 * E-mail footer, fixed reply-to address and logo (SET-FC-03).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';

final class MailFrameTest extends TestCase {

	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		$this->reset_mailer();
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_mail_footer_v1' );
		parent::tear_down();
	}

	private function logo(): int {
		return (int) self::factory()->attachment->create_object( 'logo.png', 0, array( 'post_mime_type' => 'image/png' ) );
	}

	public function test_defaults_are_empty(): void {
		$this->assertSame( '', Settings::get( 'email.footer_text' ) );
		$this->assertSame( '', Settings::get( 'email.reply_to' ) );
		$this->assertSame( 0, Settings::get( 'email.logo_id' ) );
		$frame = MailBuilder::frame( 'admin' );
		$this->assertSame( '', $frame['footer'] );
		$this->assertNull( $frame['logo'] );
		$this->assertNull( MailBuilder::visitor_reply_to() );
	}

	public function test_sanitizing_reports_errors_and_keeps_footer_lines(): void {
		$logo                    = $this->logo();
		$page                    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		list( $values, $errors ) = SettingsSchema::sanitize_group(
			'email',
			array(
				'reply_to'    => '',
				'footer_text' => "Muster GmbH\r\nMusterweg 1\n\n\n\nHRB 1234\nMusterweg 1 <b>x</b>",
				'logo_id'     => (string) $logo,
			)
		);
		$this->assertSame( array(), $errors );
		$this->assertSame( '', $values['reply_to'], 'empty reply-to is allowed' );
		$this->assertSame( "Muster GmbH\nMusterweg 1\n\nHRB 1234\nMusterweg 1 x", $values['footer_text'], 'order and duplicates kept, tags removed' );
		$this->assertSame( $logo, $values['logo_id'] );

		list( $values, $errors ) = SettingsSchema::sanitize_group(
			'email',
			array(
				'reply_to'    => 'not an address',
				'footer_text' => str_repeat( 'x', 2001 ),
				'logo_id'     => (string) $page,
			)
		);
		$this->assertArrayHasKey( 'email.reply_to', $errors );
		$this->assertArrayHasKey( 'email.footer_text', $errors );
		$this->assertArrayHasKey( 'email.logo_id', $errors, 'a page is not an image' );
		$this->assertSame( 0, $values['logo_id'], 'stored value is kept' );

		list( $values, $errors ) = SettingsSchema::sanitize_group( 'email', array( 'logo_id' => '0', 'reply_to' => 'Office@Example.org' ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( 0, $values['logo_id'] );
		$this->assertSame( 'office@example.org', $values['reply_to'] );
	}

	public function test_confirmation_mail_carries_footer_logo_and_reply_to(): void {
		$logo = $this->logo();
		Settings::set_group(
			'email',
			array(
				'footer_text' => "Muster GmbH\nGeschäftsführer: A. Muster",
				'reply_to'    => 'office@example.org',
				'logo_id'     => $logo,
			)
		);
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'confirmation_recipient' => true ),
			),
			array(
				'confirmation_mail' => array(
					'enabled' => true,
					'subject' => 'Danke',
					'body'    => 'Wir melden uns.',
				),
			)
		);
		$id   = $this->make_entry( $form, array( 'f_mail01' => 'visitor@example.net' ) );
		$this->enqueue_mail( $id, (int) $form['id'], 'mail.confirmation' );
		$this->enqueue_mail( $id, (int) $form['id'], 'mail.admin' );
		$this->runner()->run();
		$mails = $this->sent_mails();
		$this->assertCount( 2, $mails );
		foreach ( $mails as $mail ) {
			$this->assertStringContainsString( 'Geschäftsführer: A. Muster', $mail->body );
			$this->assertStringContainsString( wp_get_attachment_url( $logo ), $mail->body );
			$this->assertStringContainsString( "-- \nMuster GmbH", str_replace( "\r\n", "\n", $mail->body ), 'text part uses a signature delimiter' );
		}
		$this->assertStringContainsString( '<html lang="en-US">', $mails[0]->body, 'screen readers know the language of the mail' );
		$confirmation = array_values( array_filter( $mails, static fn( object $m ): bool => 'visitor@example.net' === $m->to[0][0] ) )[0];
		$this->assertStringContainsString( 'Reply-To: office@example.org', $confirmation->header );
	}

	public function test_filter_wins_over_setting(): void {
		Settings::set_group( 'email', array( 'footer_text' => 'Setting' ) );
		add_filter( 'form_captain_mail_footer_v1', static fn( string $footer, string $kind ): string => 'admin' === $kind ? 'Filter <script>x</script>' : $footer, 10, 2 );
		$this->assertSame( 'Filter', MailBuilder::frame( 'admin' )['footer'], 'tags and script content removed' );
		$this->assertSame( 'Setting', MailBuilder::frame( 'confirmation' )['footer'] );
	}

	public function test_settings_test_mail_shows_the_footer(): void {
		$this->as_admin();
		Settings::set_group( 'email', array( 'footer_text' => 'Impressum: Muster GmbH' ) );
		$this->assertSame( 'success', $this->plugin()->get( SettingsPage::class )->send_test_mail()['type'] );
		$this->assertStringContainsString( 'Impressum: Muster GmbH', $this->sent_mails()[0]->body );
	}

	public function test_footer_html_is_escaped(): void {
		$html = MailBuilder::wrap_html(
			'<html><body><p>x</p></body></html>',
			array(
				'footer' => '<img src=x onerror=alert(1)> $1 \\1',
				'logo'   => null,
			)
		);
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt; $1 \\1', $html );
		$this->assertStringEndsWith( '</body></html>', $html );
	}
}
