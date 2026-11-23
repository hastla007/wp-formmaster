<?php
/**
 * Settings change log (SET-FC-15).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Install\Capabilities;
use FormCaptain\Settings\ChangeLog;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

final class SettingsChangeLogTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		delete_option( ChangeLog::OPTION );
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	public function test_saving_records_user_source_key_old_and_new_value(): void {
		$uid = $this->as_admin();
		$this->page()->save_tab( 'spam', array( 'honeypot' => '1', 'min_seconds' => '9', 'rate_limit' => '5', 'blocklist' => '', 'captcha_provider' => 'none' ) );
		$entries = ChangeLog::latest();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'spam.min_seconds', $entries[0]['key'] );
		$this->assertSame( '3', $entries[0]['old'] );
		$this->assertSame( '9', $entries[0]['new'] );
		$this->assertSame( $uid, $entries[0]['user'] );
		$this->assertSame( 'ui', $entries[0]['source'] );

		ChangeLog::with_source( 'cli', static fn() => Settings::set_group( 'spam', array( 'rate_limit' => 8 ) ) );
		$this->assertSame( 'cli', ChangeLog::latest()[0]['source'] );
		$this->assertSame( 'spam.rate_limit', ChangeLog::latest()[0]['key'] );
	}

	public function test_secrets_are_masked_and_long_values_shortened(): void {
		ChangeLog::record(
			array(
				'spam.captcha_secret_key' => array( '', 'abc-very-secret' ),
				'spam.blocklist'          => array( '', str_repeat( 'x', 500 ) ),
			)
		);
		$all = ChangeLog::all();
		$this->assertSame( '', $all[0]['old'] );
		$this->assertSame( '••••••', $all[0]['new'] );
		$this->assertStringNotContainsString( 'abc', (string) wp_json_encode( $all ) );
		$this->assertLessThanOrEqual( 201, mb_strlen( $all[1]['new'] ) );
	}

	/** SET-FC-P4-03: values of add-on fields live in the add-on's option and are logged too. */
	public function test_addon_field_changes_and_resets_are_recorded_and_secrets_masked(): void {
		$this->as_admin();
		$fields = static fn( $f ) => array_merge(
			(array) $f,
			array(
				array( 'id' => 'uploads.max_files', 'tab' => 'uploads', 'label' => 'Files', 'type' => 'number', 'min' => 1, 'max' => 10, 'default' => 1, 'storage' => array( 'option' => 'fc_test_log_addon', 'key' => 'uploads.max_files' ) ),
				array( 'id' => 'uploads.scan_key', 'tab' => 'uploads', 'label' => 'Scanner key', 'type' => 'text', 'secret' => true, 'storage' => array( 'option' => 'fc_test_log_addon', 'key' => 'uploads.scan_key' ) ),
			)
		);
		add_filter( 'form_captain_settings_fields_v1', $fields );
		try {
			$this->page()->save_tab( 'uploads', array( 'ext' => array( 'uploads.max_files' => '4', 'uploads.scan_key' => 'k-very-secret' ) ) );
			$log = array_column( ChangeLog::latest(), null, 'key' );
			$this->assertSame( '4', $log['uploads.max_files']['new'] );
			$this->assertSame( 'ui', $log['uploads.max_files']['source'] );
			$this->assertSame( '••••••', $log['uploads.scan_key']['new'] );
			$this->assertStringNotContainsString( 'very-secret', (string) wp_json_encode( ChangeLog::all() ) );

			delete_option( ChangeLog::OPTION );
			$this->page()->reset_tab( 'uploads' );
			$log = ChangeLog::latest();
			$this->assertCount( 1, $log, 'only fields with a default are reset' );
			$this->assertSame( 'uploads.max_files', $log[0]['key'] );
			$this->assertSame( '4', $log[0]['old'] );
			$this->assertSame( '1', $log[0]['new'] );
		} finally {
			remove_filter( 'form_captain_settings_fields_v1', $fields );
			delete_option( 'fc_test_log_addon' );
		}
	}

	public function test_role_changes_are_recorded_with_role_and_capability(): void {
		$this->as_admin();
		$this->page()->save_tab( 'access', array( 'roles' => array( 'editor' => array( Capabilities::READ_ENTRIES => '1' ) ) ) );
		$keys = array_column( ChangeLog::all(), 'key' );
		$this->assertContains( 'access.editor.' . Capabilities::READ_ENTRIES, $keys );
		$this->page()->reset_tab( 'access' );
		$last = ChangeLog::latest( 100 );
		$this->assertContains( 'access.editor.' . Capabilities::READ_ENTRIES, array_column( $last, 'key' ) );
	}

	public function test_old_entries_are_pruned_after_the_configured_months_and_filter_wins(): void {
		Clock::freeze( time() - 13 * MONTH_IN_SECONDS );
		ChangeLog::record( array( 'general.show_pro_hints' => array( true, false ) ) );
		Clock::freeze( null );
		ChangeLog::record( array( 'general.show_pro_hints' => array( false, true ) ) );
		$this->assertSame( 12, ChangeLog::months() );
		ChangeLog::prune();
		$this->assertCount( 1, ChangeLog::all() );

		add_filter( 'form_captain_change_log_months_v1', static fn() => 1 );
		$this->assertSame( 1, ChangeLog::months() );
		remove_all_filters( 'form_captain_change_log_months_v1' );
	}

	public function test_status_tab_shows_the_log(): void {
		$this->as_admin();
		$this->page()->save_tab( 'general', array( 'show_pro_hints' => '0' ) );
		$_GET['tab'] = 'status';
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'fc-change-log', $html );
		$this->assertStringContainsString( 'general.show_pro_hints', $html );
	}
}
