<?php
/**
 * Confirmation mail with simple HTML and "send test to me" in the editor (SET-FC-19),
 * e-mail format per form (SET-FC-17).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Mail\MailBuilder;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Tests\Integration\TestCase;

final class TestMailTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		parent::tear_down();
	}

	/** @param array<string,mixed> $body */
	private function call( int $form_id, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/form-captain/v1/admin/forms/' . $form_id . '/test-mail' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private function form(): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'confirmation_recipient' => true ),
				array( 'id' => 'f_topic1', 'type' => 'select', 'key' => 'topic', 'label' => 'Thema', 'options' => array( array( 'value' => 'offer', 'label' => 'Angebot' ) ) ),
			),
			array( 'notification' => array( 'enabled' => true, 'to' => array( 'team@example.org' ), 'subject' => 'Neu' ) )
		);
	}

	public function test_body_keeps_allowlisted_html_and_plain_text_stays_plain(): void {
		$service = $this->plugin()->get( FormService::class );
		$schema  = array( 'fields' => array() );
		$html    = $service->sanitize_settings( array( 'confirmation_mail' => array( 'body' => '<p>Hallo <strong>{field:name}</strong><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a></p>' ) ), $schema );
		$this->assertSame( '<p>Hallo <strong>{field:name}</strong><a href="alert(1)">x</a></p>', $html['settings']['confirmation_mail']['body'] );
		$plain = $service->sanitize_settings( array( 'confirmation_mail' => array( 'body' => "Tom & Jerry\nZeile 2" ) ), $schema );
		$this->assertSame( "Tom & Jerry\nZeile 2", $plain['settings']['confirmation_mail']['body'], 'plain text is not turned into entities' );

		$this->assertSame( "Hallo Erika\n\n- eins\n- zwei\n\nSeite (https://example.org/x?a=1&b=2)", MailBuilder::html_to_text( '<p>Hallo <strong>Erika</strong></p><ul><li>eins</li><li>zwei</li></ul><p><a href="https://example.org/x?a=1&amp;b=2">Seite</a></p>' ) );
	}

	public function test_format_and_spam_mode_are_stored_per_form(): void {
		$service = $this->plugin()->get( FormService::class );
		$this->assertSame( 'global', $service->default_settings()['email']['format'] );
		$this->assertSame( 'global', $service->default_settings()['spam']['mode'] );
		$res = $service->sanitize_settings(
			array(
				'email' => array( 'format' => 'text' ),
				'spam'  => array( 'mode' => 'strict' ),
			),
			array( 'fields' => array() )
		);
		$this->assertSame( 'text', $res['settings']['email']['format'] );
		$this->assertSame( 'strict', $res['settings']['spam']['mode'] );
		$res = $service->sanitize_settings(
			array(
				'email' => array( 'format' => 'pdf' ),
				'spam'  => array( 'mode' => 'none' ),
			),
			array( 'fields' => array() )
		);
		$this->assertSame( 'global', $res['settings']['email']['format'] );
		$this->assertSame( 'invalid_spam_mode', $res['errors']['spam.mode'] );
	}

	public function test_send_test_to_me_uses_unsaved_settings_and_only_the_own_address(): void {
		wp_set_current_user(
			self::factory()->user->create(
				array(
					'role'       => 'administrator',
					'user_email' => 'tester@example.org',
				)
			)
		);
		$form = $this->form();
		$res  = $this->call(
			(int) $form['id'],
			array(
				'kind'     => 'confirmation',
				'settings' => array(
					'notification'      => $form['settings']['notification'],
					'confirmation_mail' => array(
						'enabled' => true,
						'subject' => 'Danke {field:name}',
						'body'    => '<p>Du hast <em>{field:topic}</em> gewählt.</p>',
					),
					'email'             => array( 'format' => 'html' ),
				),
			)
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'tester@example.org', $mail->to[0][0] );
		$this->assertSame( '[Test] Danke Sample text', $mail->subject );
		$this->assertStringContainsString( '<p>Du hast <em>Angebot</em> gewählt.</p>', $mail->body );
		$this->assertStringContainsString( 'Du hast Angebot gewählt.', $mail->body, 'text alternative' );
		$this->assertArrayNotHasKey( 'confirmation_mail', $this->plugin()->get( FormRepository::class )->find( (int) $form['id'] )['settings'], 'nothing saved' );

		reset_phpmailer_instance();
		$res = $this->call( (int) $form['id'], array( 'kind' => 'admin' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( array( 'tester@example.org', '' ) ), tests_retrieve_phpmailer_instance()->get_sent()->to, 'never the configured recipients' );

		$this->call( (int) $form['id'], array( 'kind' => 'admin' ) );
		$res = $this->call( (int) $form['id'], array( 'kind' => 'admin' ) );
		$this->assertSame( 429, $res->get_status(), 'three per ten minutes' );
	}

	public function test_text_format_per_form_wins_over_global_html(): void {
		$this->as_admin();
		$form = $this->form();
		$res  = $this->call(
			(int) $form['id'],
			array(
				'kind'     => 'admin',
				'settings' => array(
					'notification' => $form['settings']['notification'],
					'email'        => array( 'format' => 'text' ),
				),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'text/plain', $mail->header );
		$this->assertStringNotContainsString( '<table', $mail->body );
	}

	public function test_requires_the_form_capability(): void {
		$this->as_role( 'subscriber' );
		$form = $this->form();
		$this->assertSame( 403, $this->call( (int) $form['id'], array( 'kind' => 'admin' ) )->get_status() );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
