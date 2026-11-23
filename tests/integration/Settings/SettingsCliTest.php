<?php
/**
 * WP-CLI settings commands (SET-FC-14); the logic is tested without WP-CLI.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Cli\Commands;
use FormCaptain\Settings\ChangeLog;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsTransfer;
use FormCaptain\Tests\Integration\TestCase;

final class SettingsCliTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		delete_option( ChangeLog::OPTION );
	}

	private function cli(): Commands {
		return $this->plugin()->get( Commands::class );
	}

	public function test_list_and_get(): void {
		$rows = array_column( $this->cli()->settings_list(), null, 'key' );
		$this->assertSame( 'manual', $rows['privacy.retention']['value'] );
		$this->assertSame( 'true', $rows['spam.honeypot']['value'] );
		$this->assertArrayNotHasKey( 'privacy.delete_data_confirmed_gmt', $rows );
		$this->assertSame( '5', $this->cli()->settings_get( 'spam.rate_limit' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_get( 'spam.nope' ) );
		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_get( 'privacy.delete_data_confirmed_gmt' ) );
	}

	public function test_set_uses_the_same_checks_and_needs_yes_for_retention(): void {
		$res = $this->cli()->settings_set( 'spam.rate_limit', '7' );
		$this->assertSame( array( 5, 7 ), $res );
		$this->assertSame( 'cli', ChangeLog::latest()[0]['source'] );
		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_set( 'spam.rate_limit', '1000' ) );
		$this->assertSame( 7, Settings::get( 'spam.rate_limit' ) );
		$this->assertSame( array( true, false ), $this->cli()->settings_set( 'spam.honeypot', 'false' ) );
		$this->assertTrue( Settings::get( 'general.show_pro_hints' ), 'Other booleans of the group stay unchanged' );
		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_set( 'spam.honeypot', 'maybe' ) );

		$err = $this->cli()->settings_set( 'privacy.retention', '90d' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertStringContainsString( '--yes', $err->get_error_message() );
		$this->assertSame( 'manual', Settings::get( 'privacy.retention' ) );
		$this->cli()->settings_set( 'privacy.retention', '90d', true );
		$this->assertSame( '90d', Settings::get( 'privacy.retention' ) );

		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_set( 'privacy.delete_data_on_uninstall', 'true' ) );
		$this->assertFalse( Settings::get( 'privacy.delete_data_on_uninstall' ) );
	}

	public function test_reset_needs_yes_and_a_known_section(): void {
		$this->cli()->settings_set( 'spam.rate_limit', '9' );
		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_reset( 'spam', false, false ) );
		$this->assertInstanceOf( \WP_Error::class, $this->cli()->settings_reset( 'access', false, true ) );
		$this->assertSame( 9, Settings::get( 'spam.rate_limit' ) );
		$this->assertTrue( $this->cli()->settings_reset( 'spam', false, true ) );
		$this->assertSame( 5, Settings::get( 'spam.rate_limit' ) );
		$this->cli()->settings_set( 'appearance.mode', 'base' );
		$this->assertTrue( $this->cli()->settings_reset( '', true, true ) );
		$this->assertSame( 'inherit', Settings::get( 'appearance.mode' ) );
	}

	public function test_import_dry_run_and_apply(): void {
		$data                                   = SettingsTransfer::export();
		$data['settings']['spam']['rate_limit'] = 11;
		$json                                   = (string) wp_json_encode( $data );
		$res                                    = $this->cli()->settings_import( $json, true, false );
		$this->assertArrayHasKey( 'spam.rate_limit', $res['changes'] );
		$this->assertSame( 5, Settings::get( 'spam.rate_limit' ) );
		$this->cli()->settings_import( $json, false, false );
		$this->assertSame( 11, Settings::get( 'spam.rate_limit' ) );
		$this->assertSame( 'cli', ChangeLog::latest()[0]['source'] );
	}
}
