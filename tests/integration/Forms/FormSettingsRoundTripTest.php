<?php
/**
 * Every per-form setting survives save, export and import unchanged (SET-FC-P1).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Settings\FormSettingsSchema;
use FormCaptain\Tests\Integration\TestCase;

final class FormSettingsRoundTripTest extends TestCase {

	public function test_every_form_setting_survives_save_export_and_import(): void {
		$this->as_admin();
		$service = $this->plugin()->get( FormService::class );
		$repo    = $this->plugin()->get( FormRepository::class );
		$id      = (int) $service->create_from_template( 'contact' );
		$form    = $repo->find( $id );
		$schema  = $form['schema'];
		foreach ( $schema['fields'] as $i => $field ) {
			if ( 'email' === $field['type'] ) {
				$schema['fields'][ $i ]['confirmation_recipient'] = true;
			}
		}
		$page     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$settings = array(
			'notification'      => array(
				'enabled'        => true,
				'to'             => array( 'team@example.org', '{admin_email}' ),
				'reply_to_field' => 'f_ctmail01',
				'subject'        => 'Neu: {form_title}',
				'fields'         => array( 'f_ctname01', 'f_ctmail01' ),
			),
			'confirmation_mail' => array(
				'enabled' => true,
				'subject' => 'Danke',
				'body'    => '<p>Hallo <strong>du</strong></p>',
				'fields'  => array( 'f_ctname01' ),
			),
			'confirmation'      => array(
				'type'    => 'page',
				'message' => 'Danke!',
				'page_id' => $page,
			),
			'paused_message'    => 'Gerade Pause.',
			'appearance'        => array( 'mode' => 'base' ),
			'email'             => array(
				'from_name'    => 'Shop',
				'from_address' => 'shop@example.org',
				'format'       => 'text',
			),
			'retention'         => array( 'mode' => '18m' ),
			'submit_label'      => 'Absenden',
			'texts'             => array(
				'required_hint' => 'Pflichtfelder',
				'error_summary' => 'Bitte prüfen',
			),
			'limits'            => array(
				'opens_at'       => '2026-12-01T10:00',
				'closes_at'      => '2027-01-31T18:30',
				'max_entries'    => 50,
				'closed_message' => 'Geschlossen.',
			),
			'spam'              => array( 'mode' => 'strict' ),
			'double_opt_in'     => array(
				'enabled'           => true,
				'email_field'       => 'f_ctmail01',
				'subject'           => 'Bitte bestätigen',
				'body'              => '<p>Bitte klicke: {confirm_link}</p>',
				'pending_message'   => 'Fast geschafft.',
				'link_hours'        => 24,
				'delete_after_days' => 3,
			),
		);
		$res      = $service->save( $id, 'Kontakt', $schema, $settings, (int) $form['revision'] );
		$this->assertIsArray( $res );
		$saved = $repo->find( $id )['settings'];

		// Every described value that differs from its default was stored as given.
		$defaults = FormSettingsSchema::defaults();
		foreach ( FormSettingsSchema::definitions() as $path => $def ) {
			if ( ! empty( $def['internal'] ) ) {
				continue;
			}
			$given  = '' === $def['section'] ? $settings[ $def['key'] ] : $settings[ $def['section'] ][ $def['key'] ];
			$stored = '' === $def['section'] ? $saved[ $def['key'] ] : $saved[ $def['section'] ][ $def['key'] ];
			$this->assertSame( $given, $stored, $path . ' stored' );
			$default = '' === $def['section'] ? $defaults[ $def['key'] ] : $defaults[ $def['section'] ][ $def['key'] ];
			if ( 'notification.enabled' !== $path ) { // on by default; the other switches are turned on here
				$this->assertNotSame( $default, $stored, $path . ' is not the default (test covers it)' );
			}
		}

		// Saving again changes nothing.
		$form  = $repo->find( $id );
		$again = $service->save( $id, 'Kontakt', $form['schema'], $form['settings'], (int) $form['revision'] );
		$this->assertSame( $saved, $again['form']['settings'] );

		// Export → import gives the same settings (the redirect page exists on this site).
		$import = $service->import( (string) wp_json_encode( $service->export( $id ) ) );
		$this->assertIsArray( $import );
		$this->assertSame( array(), $import['report']['removed'] );
		$copy = $repo->find( $import['form_id'] )['settings'];
		unset( $saved['template'], $saved['template_version'] );
		$this->assertSame( $saved, $copy );
	}
}
