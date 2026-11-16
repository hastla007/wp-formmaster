<?php
/**
 * Settings schema, sanitizing, reset, migration, uninstall confirmation and role matrix
 * (P2-19, P2-20, P2-24, P5-06, P5-18…P5-21).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Install\Capabilities;
use FormCaptain\Settings\AccessControl;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Support\Secret;
use FormCaptain\Tests\Integration\TestCase;

final class SettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	public function test_every_default_has_a_definition_and_vice_versa(): void {
		$defs = SettingsSchema::definitions();
		foreach ( Settings::defaults() as $group => $values ) {
			foreach ( $values as $key => $default ) {
				$this->assertArrayHasKey( $group . '.' . $key, $defs );
				$this->assertSame( $default, $defs[ $group . '.' . $key ]['default'] );
				$this->assertSame( Capabilities::MANAGE_SETTINGS, $defs[ $group . '.' . $key ]['cap'] );
			}
		}
		$this->assertSame( '12m', Settings::get( 'privacy.retention' ), 'Safe default: 12 months' );
		$this->assertTrue( Settings::get( 'spam.honeypot' ) );
		$this->assertSame( 'none', Settings::get( 'spam.captcha_provider' ) );
		$this->assertFalse( Settings::get( 'privacy.delete_data_on_uninstall' ) );
	}

	public function test_sanitize_rejects_invalid_values_and_drops_unknown_keys(): void {
		list( $values, $errors ) = SettingsSchema::sanitize_group(
			'spam',
			array(
				'min_seconds' => '99',
				'rate_limit'  => '10',
				'blocklist'   => "viagra\n\n  casino  \nviagra\n<script>",
				'captcha_provider' => 'evilcaptcha',
				'unknown'     => 'x',
			)
		);
		$this->assertArrayHasKey( 'spam.min_seconds', $errors );
		$this->assertArrayHasKey( 'spam.captcha_provider', $errors );
		$this->assertSame( 3, $values['min_seconds'], 'Invalid value keeps the stored one' );
		$this->assertSame( 10, $values['rate_limit'] );
		$this->assertSame( "viagra\ncasino", $values['blocklist'] );
		$this->assertFalse( $values['honeypot'], 'Missing checkbox = off' );
		$this->assertArrayNotHasKey( 'unknown', $values );

		list( , $errors ) = SettingsSchema::sanitize_group( 'appearance', array( 'mode' => 'base', 'accent_color' => 'red' ) );
		$this->assertArrayHasKey( 'appearance.accent_color', $errors );
		list( $values, $errors ) = SettingsSchema::sanitize_group( 'email', array( 'from_name' => str_repeat( 'x', 191 ), 'from_address' => 'Foo@Example.ORG', 'format' => 'text' ) );
		$this->assertArrayHasKey( 'email.from_name', $errors );
		$this->assertSame( 'foo@example.org', $values['from_address'] );
	}

	public function test_save_and_reset_only_affect_the_chosen_section(): void {
		$this->as_admin();
		$res = $this->page()->save_tab( 'spam', array( 'honeypot' => '1', 'min_seconds' => '5', 'rate_limit' => '7', 'blocklist' => 'spam.example', 'captcha_provider' => 'none' ) );
		$this->assertSame( 'success', $res['type'] );
		$this->page()->save_tab( 'appearance', array( 'mode' => 'base', 'accent_color' => '#112233' ) );
		$this->assertSame( 7, Settings::get( 'spam.rate_limit' ) );
		$res = $this->page()->reset_tab( 'spam' );
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( 5, Settings::get( 'spam.rate_limit' ) );
		$this->assertSame( 'base', Settings::get( 'appearance.mode' ), 'Other sections untouched' );
		$this->assertSame( '#112233', Settings::get( 'appearance.accent_color' ) );
	}

	public function test_migration_adds_new_defaults_without_overwriting(): void {
		update_option( Settings::OPTION, array( 'spam' => array( 'rate_limit' => 9, 'obsolete' => 1 ), 'privacy' => array( 'retention' => '90d' ) ) );
		Settings::ensure_defaults();
		$stored = get_option( Settings::OPTION );
		$this->assertSame( 9, $stored['spam']['rate_limit'] );
		$this->assertSame( '90d', $stored['privacy']['retention'] );
		$this->assertSame( 'delete', $stored['privacy']['erasure_mode'], 'New default added' );
		$this->assertArrayNotHasKey( 'obsolete', $stored['spam'] );
	}

	public function test_retention_change_requires_confirmation(): void {
		$this->as_admin();
		$res = $this->page()->save_tab( 'privacy', array( 'retention' => '90d', 'trash_days' => '30', 'erasure_mode' => 'delete' ) );
		$this->assertSame( 'error', $res['type'] );
		$this->assertArrayHasKey( 'privacy.retention', $res['errors'] );
		$this->assertSame( '12m', Settings::get( 'privacy.retention' ) );
		$res = $this->page()->save_tab( 'privacy', array( 'retention' => '90d', 'trash_days' => '30', 'erasure_mode' => 'anonymize', 'retention_confirm' => '1' ) );
		$this->assertSame( 'success', $res['type'], implode( ' ', $res['messages'] ) );
		$this->assertSame( '90d', Settings::get( 'privacy.retention' ) );
		$this->assertSame( 'anonymize', Settings::get( 'privacy.erasure_mode' ) );
	}

	public function test_retention_estimate_respects_grace_period(): void {
		$before = $this->page()->retention_estimate( '90d' )['count'];
		$form   = $this->create_published_form();
		$repo = $this->plugin()->get( \FormCaptain\Repository\EntryRepository::class );
		$id   = $repo->insert( array( 'form_id' => $form['id'], 'form_version_id' => $form['published_version_id'], 'request_key' => hash( 'sha256', 'r1' ) ) );
		$repo->update( $id, array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
		$p = $this->page()->retention_estimate( '90d' );
		$this->assertSame( $before + 1, $p['count'] );
		$this->assertGreaterThanOrEqual( time() + 14 * DAY_IN_SECONDS - 5, strtotime( $p['earliest'] . ' UTC' ) );
		$this->assertNull( $this->page()->retention_estimate( 'manual' )['earliest'] );
	}

	public function test_uninstall_deletion_needs_confirmation_word(): void {
		$this->as_admin();
		$base = array( 'retention' => '12m', 'trash_days' => '30', 'erasure_mode' => 'delete', 'delete_data_on_uninstall' => '1' );
		$res  = $this->page()->save_tab( 'privacy', $base + array( 'uninstall_confirm_word' => 'yes' ) );
		$this->assertArrayHasKey( 'privacy.delete_data_on_uninstall', $res['errors'] );
		$this->assertFalse( Settings::get( 'privacy.delete_data_on_uninstall' ) );
		$this->assertSame( '', Settings::get( 'privacy.delete_data_confirmed_gmt' ) );

		$this->page()->save_tab( 'privacy', $base + array( 'uninstall_confirm_word' => 'löschen' ) );
		$this->assertTrue( Settings::get( 'privacy.delete_data_on_uninstall' ) );
		$this->assertNotSame( '', Settings::get( 'privacy.delete_data_confirmed_gmt' ) );

		// The internal confirmation timestamp cannot be injected or kept after unticking.
		$this->page()->save_tab( 'privacy', array( 'retention' => '12m', 'trash_days' => '30', 'erasure_mode' => 'delete', 'delete_data_confirmed_gmt' => '2020-01-01 00:00:00' ) );
		$this->assertFalse( Settings::get( 'privacy.delete_data_on_uninstall' ) );
		$this->assertSame( '', Settings::get( 'privacy.delete_data_confirmed_gmt' ) );
	}

	public function test_from_domain_hint(): void {
		$this->assertFalse( SettingsSchema::from_domain_mismatch( 'wordpress@example.org' ) );
		$this->assertTrue( SettingsSchema::from_domain_mismatch( 'me@gmail.com' ) );
	}

	public function test_forms_with_own_values_are_counted(): void {
		$this->create_published_form( null, array( 'appearance' => array( 'mode' => 'base' ), 'email' => array( 'from_name' => 'Other', 'from_address' => 'x@example.org' ) ) );
		$this->create_published_form( null, array( 'appearance' => array( 'mode' => 'inherit' ), 'retention' => array( 'mode' => '90d' ) ) );
		$counts = $this->page()->forms_with_own_values();
		$this->assertGreaterThanOrEqual( 1, $counts['appearance'] );
		$this->assertGreaterThanOrEqual( 1, $counts['email'] );
		$this->assertGreaterThanOrEqual( 1, $counts['retention'] );
	}

	public function test_role_matrix_and_lockout_protection(): void {
		$this->as_admin();
		self::factory()->user->create( array( 'role' => 'editor' ) );
		$before = AccessControl::users_with_cap( Capabilities::READ_ENTRIES );
		$res    = $this->page()->save_tab( 'access', array( 'roles' => array( 'editor' => array( Capabilities::READ_ENTRIES => '1' ) ) ) );
		$this->assertSame( 'success', $res['type'] );
		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::READ_ENTRIES ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::EXPORT_ENTRIES ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ), 'Admin can never lose manage_settings' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::READ_ENTRIES ), 'Unticked caps are removed' );
		$this->assertGreaterThan( 0, AccessControl::users_with_cap( Capabilities::READ_ENTRIES ) );
		$this->assertNotSame( $before, AccessControl::users_with_cap( Capabilities::READ_ENTRIES ) );
		$this->assertStringContainsString( 'read entries', implode( ' ', $res['messages'] ) );

		$this->page()->reset_tab( 'access' );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::READ_ENTRIES ) );
		foreach ( Capabilities::ALL as $cap ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $cap ) );
		}
	}

	public function test_role_changes_need_promote_users(): void {
		add_role( 'fc_settings', 'Settings only', array( 'read' => true, Capabilities::MANAGE_SETTINGS => true ) );
		$this->as_role( 'fc_settings' );
		$res = $this->page()->save_tab( 'access', array( 'roles' => array( 'fc_settings' => array( Capabilities::READ_ENTRIES => '1' ) ) ) );
		$this->assertSame( 'error', $res['type'] );
		$this->assertFalse( get_role( 'fc_settings' )->has_cap( Capabilities::READ_ENTRIES ) );
		remove_role( 'fc_settings' );
	}

	public function test_secret_rotation_keeps_previous_key_for_grace_window(): void {
		$old = get_option( Secret::OPTION );
		$this->page()->rotate_secret();
		$this->assertNotSame( $old, get_option( Secret::OPTION ) );
		$this->assertSame( $old, get_option( 'form_captain_secret_previous' ) );
		$this->assertGreaterThan( time() + 25 * HOUR_IN_SECONDS, (int) get_option( 'form_captain_secret_previous_until' ) );
		$this->page()->rotate_secret( true );
		$this->assertFalse( get_option( 'form_captain_secret_previous' ) );
	}

	public function test_test_mail_is_rate_limited(): void {
		$this->as_admin();
		reset_phpmailer_instance();
		$this->assertSame( 'success', $this->page()->send_test_mail()['type'] );
		$this->assertNotEmpty( tests_retrieve_phpmailer_instance()->get_sent() );
		$this->page()->send_test_mail();
		$this->page()->send_test_mail();
		$this->assertSame( 'error', $this->page()->send_test_mail()['type'] );
	}
}
