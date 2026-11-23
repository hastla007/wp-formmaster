<?php
/**
 * Describing list of the per-form settings (SET-FC-28).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Service\FormService;
use FormCaptain\Settings\FormSettingsSchema;
use FormCaptain\Settings\Settings;
use FormCaptain\Tests\Integration\TestCase;

final class FormSettingsSchemaTest extends TestCase {

	public function test_every_definition_has_type_label_help_and_a_default(): void {
		foreach ( FormSettingsSchema::definitions() as $path => $def ) {
			$this->assertNotEmpty( $def['type'], $path );
			$this->assertNotSame( '', (string) $def['label'], $path );
			$this->assertNotSame( '', (string) $def['help'], "$path has no help text" );
			$this->assertArrayHasKey( 'default', $def, $path );
		}
	}

	public function test_form_service_defaults_come_from_the_schema_and_copy_global_values(): void {
		Settings::set_group( 'appearance', array( 'mode' => 'base' ) );
		Settings::set_group( 'email', array( 'from_name' => 'Shop', 'from_address' => 'shop@example.org' ) );
		$defaults = $this->plugin()->get( FormService::class )->default_settings();
		$this->assertSame( FormSettingsSchema::defaults(), $defaults );
		$this->assertSame( array( 'notification', 'confirmation_mail', 'confirmation', 'paused_message', 'appearance', 'email', 'retention', 'actions', 'submit_label', 'texts', 'limits', 'spam', 'double_opt_in' ), array_keys( $defaults ) );
		$this->assertSame( 'base', $defaults['appearance']['mode'] );
		$this->assertSame( 'shop@example.org', $defaults['email']['from_address'] );
		$this->assertSame( array( '{admin_email}' ), $defaults['notification']['to'] );
		$this->assertSame( 'global', $defaults['retention']['mode'] );
	}

	public function test_sanitize_resets_invalid_values_and_reports_codes(): void {
		$fields = array(
			'name' => array( 'id' => 'name', 'type' => 'text' ),
			'mail' => array( 'id' => 'mail', 'type' => 'email', 'confirmation_recipient' => false ),
			'note' => array( 'id' => 'note', 'type' => 'html' ),
		);
		$res    = FormSettingsSchema::sanitize(
			array(
				'notification'      => array(
					'to'             => 'A@Example.org, nope, {admin_email}',
					'reply_to_field' => 'name',
					'subject'        => '',
					'fields'         => array( 'note', 'name', 'missing' ),
				),
				'confirmation_mail' => array( 'enabled' => '1' ),
				'confirmation'      => array( 'type' => 'page', 'page_id' => 999999, 'message' => '   ' ),
				'appearance'        => array( 'mode' => 'fancy' ),
				'email'             => array( 'from_address' => 'broken' ),
				'retention'         => array( 'mode' => 'forever' ),
				'unknown'           => 'dropped',
			),
			$fields
		);
		$s      = $res['settings'];
		$e      = $res['errors'];
		$this->assertSame( array( 'a@example.org', '{admin_email}' ), $s['notification']['to'] );
		$this->assertSame( 'invalid_recipient', $e['notification.to'] );
		$this->assertSame( '', $s['notification']['reply_to_field'] );
		$this->assertSame( 'invalid_reply_to_field', $e['notification.reply_to_field'] );
		$this->assertNotSame( '', $s['notification']['subject'], 'Empty subject falls back to the default' );
		$this->assertSame( array( 'name' ), $s['notification']['fields'], 'Only value fields of the form' );
		$this->assertSame( 'confirmation_recipient_missing', $e['confirmation_mail.enabled'] );
		$this->assertSame( 'subject_required', $e['confirmation_mail.subject'] );
		$this->assertSame( 'message', $s['confirmation']['type'] );
		$this->assertSame( 0, $s['confirmation']['page_id'] );
		$this->assertSame( 'invalid_page', $e['confirmation.page_id'] );
		$this->assertNotSame( '', trim( $s['confirmation']['message'] ) );
		$this->assertSame( 'inherit', $s['appearance']['mode'] );
		$this->assertSame( 'invalid_email', $e['email.from_address'] );
		$this->assertSame( 'global', $s['retention']['mode'] );
		$this->assertSame( 'invalid_retention', $e['retention.mode'] );
		$this->assertArrayNotHasKey( 'unknown', $s );
	}

	public function test_disabled_notification_without_recipients_is_valid(): void {
		$res = FormSettingsSchema::sanitize( array( 'notification' => array( 'enabled' => false, 'to' => array() ) ), array() );
		$this->assertArrayNotHasKey( 'notification.to', $res['errors'] );
		$res = FormSettingsSchema::sanitize( array( 'notification' => array( 'to' => array() ) ), array() );
		$this->assertSame( 'recipient_required', $res['errors']['notification.to'] );
	}
}
