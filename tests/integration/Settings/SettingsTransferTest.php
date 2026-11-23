<?php
/**
 * Settings export/import as JSON (SET-FC-13).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Settings\ChangeLog;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsTransfer;
use FormCaptain\Tests\Integration\TestCase;

final class SettingsTransferTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		delete_option( ChangeLog::OPTION );
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	public function test_export_has_header_and_no_secrets_or_internal_values(): void {
		$data = SettingsTransfer::export();
		$this->assertSame( SettingsTransfer::FORMAT, $data['format'] );
		$this->assertSame( SettingsTransfer::FORMAT_VERSION, $data['format_version'] );
		$this->assertSame( FORM_CAPTAIN_VERSION, $data['plugin_version'] );
		$this->assertContains( 'spam.rate_limit', $data['keys'] );
		$this->assertNotContains( 'privacy.delete_data_confirmed_gmt', $data['keys'] );
		$this->assertNotContains( 'privacy.delete_data_on_uninstall', $data['keys'] );
		$this->assertArrayNotHasKey( 'delete_data_confirmed_gmt', $data['settings']['privacy'] );
		foreach ( $data['keys'] as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/secret|token|password|api_?key/i', $key );
		}
		$json = SettingsTransfer::export_json();
		$this->assertStringNotContainsString( (string) get_option( 'form_captain_secret' ), $json );
	}

	public function test_round_trip_reports_unknown_and_invalid_values_and_keeps_confirmations(): void {
		Settings::set_group( 'spam', array( 'rate_limit' => 9, 'blocklist' => "casino\nspam.example" ) );
		Settings::set_group( 'appearance', array( 'mode' => 'base', 'accent_color' => '#112233' ) );
		$json = SettingsTransfer::export_json();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();

		$data                                     = json_decode( $json, true );
		$data['settings']['spam']['min_seconds']  = 999;
		$data['settings']['spam']['made_up']      = 1;
		$data['settings']['nonsense']             = array( 'a' => 1 );
		$data['settings']['privacy']['retention'] = '90d';
		$data['settings']['privacy']['delete_data_on_uninstall'] = true;

		$preview = SettingsTransfer::preview( (string) wp_json_encode( $data ) );
		$this->assertTrue( $preview['ok'] );
		$this->assertSame( 5, Settings::get( 'spam.rate_limit' ), 'Preview does not save' );
		$this->assertArrayHasKey( 'spam.rate_limit', $preview['changes'] );
		$this->assertArrayHasKey( 'spam.min_seconds', $preview['errors'] );
		$this->assertArrayHasKey( 'privacy.retention', $preview['errors'], 'Retention change still needs confirmation' );
		$this->assertContains( 'spam.made_up', $preview['unknown'] );
		$this->assertContains( 'nonsense', $preview['unknown'] );
		$this->assertContains( 'privacy.delete_data_on_uninstall', $preview['skipped'] );

		$res = SettingsTransfer::import( (string) wp_json_encode( $data ) );
		$this->assertSame( 9, Settings::get( 'spam.rate_limit' ) );
		$this->assertSame( "casino\nspam.example", Settings::get( 'spam.blocklist' ) );
		$this->assertSame( '#112233', Settings::get( 'appearance.accent_color' ) );
		$this->assertSame( 3, Settings::get( 'spam.min_seconds' ), 'Invalid value not applied' );
		$this->assertSame( '12m', Settings::get( 'privacy.retention' ) );
		$this->assertFalse( Settings::get( 'privacy.delete_data_on_uninstall' ) );
		$this->assertArrayHasKey( 'privacy.retention', $res['errors'] );
		$this->assertSame( 'import', ChangeLog::latest()[0]['source'] );

		SettingsTransfer::import( (string) wp_json_encode( $data ), array( 'retention_confirm' => true ) );
		$this->assertSame( '90d', Settings::get( 'privacy.retention' ) );
	}

	public function test_rejects_foreign_and_newer_files(): void {
		$this->assertFalse( SettingsTransfer::preview( '{"format":"other"}' )['ok'] );
		$this->assertFalse( SettingsTransfer::preview( 'not json' )['ok'] );
		$this->assertFalse( SettingsTransfer::preview( (string) wp_json_encode( array( 'format' => SettingsTransfer::FORMAT, 'format_version' => 99, 'settings' => array() ) ) )['ok'] );
	}

	public function test_two_step_import_on_the_settings_page(): void {
		$this->as_admin();
		$data                                   = SettingsTransfer::export();
		$data['settings']['spam']['rate_limit'] = 12;
		$res                                    = $this->page()->import_preview( (string) wp_json_encode( $data ) );
		$this->assertSame( 'success', $res['type'] );
		$_GET['tab'] = 'status';
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'fc-import-preview', $html );
		$this->assertStringContainsString( 'spam.rate_limit', $html );
		$this->assertSame( 5, Settings::get( 'spam.rate_limit' ) );

		$res = $this->page()->import_apply( array() );
		$this->assertSame( 'success', $res['type'], implode( ' ', $res['messages'] ) );
		$this->assertSame( 12, Settings::get( 'spam.rate_limit' ) );
		$this->assertSame( 'error', $this->page()->import_apply( array() )['type'], 'Preview is used once' );
		$this->assertSame( 'error', $this->page()->import_preview( '[]' )['type'] );
	}
}
