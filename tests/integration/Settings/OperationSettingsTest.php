<?php
/**
 * Log level and log retention, trusted proxy header, settable limits, export defaults and
 * country profiles (SET-FC-09, SET-FC-12, SET-FC-16, SET-FC-31, SET-FC-34).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Admin\SettingsPage;
use FormCaptain\Admin\SystemStatus;
use FormCaptain\Database\Tables;
use FormCaptain\Security\RateLimiter;
use FormCaptain\Settings\CountryPresets;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;
use FormCaptain\Tests\Integration\TestCase;

final class OperationSettingsTest extends TestCase {

	/** @var array<string,mixed> */
	private array $server = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		Settings::flush_caches();
		$this->server = $_SERVER;
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		foreach ( array( 'form_captain_log_level_v1', 'form_captain_log_days_v1', 'form_captain_limits_v1', 'form_captain_client_ip', 'form_captain_country_presets_v1' ) as $hook ) {
			remove_all_filters( $hook );
		}
		Settings::flush_caches();
		parent::tear_down();
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	/** @return string[] */
	private function logged_codes(): array {
		global $wpdb;
		return $wpdb->get_col( 'SELECT code FROM ' . Tables::name( Tables::LOG ) . ' ORDER BY id' );
	}

	// SET-FC-09 ------------------------------------------------------------

	public function test_log_level_threshold(): void {
		$this->assertSame( 'info', Settings::get( 'general.log_level' ) );
		$this->assertTrue( Logger::enabled( 'debug' ), 'WP_DEBUG keeps writing debug entries in the test suite' );
		add_filter( 'form_captain_log_level_v1', static fn() => 'warning' );
		Logger::reset_level();
		$this->assertTrue( Logger::enabled( 'error' ) );
		$this->assertFalse( Logger::debug_by_setting() );

		Settings::set_group( 'general', array( 'log_level' => 'debug' ) );
		remove_all_filters( 'form_captain_log_level_v1' );
		Logger::reset_level();
		$this->assertTrue( Logger::debug_by_setting(), 'debug without WP_DEBUG via setting' );
		$problems = $this->plugin()->get( SystemStatus::class )->problems();
		$this->assertArrayHasKey( 'debug_logging', $problems );

		Settings::set_group( 'general', array( 'log_level' => 'info' ) );
		$this->assertArrayNotHasKey( 'debug_logging', $this->plugin()->get( SystemStatus::class )->problems() );

		list( , $errors ) = SettingsSchema::sanitize_group( 'general', array( 'log_level' => 'verbose' ) );
		$this->assertArrayHasKey( 'general.log_level', $errors );
	}

	public function test_log_level_filters_entries_by_rank(): void {
		// Without WP_DEBUG the level decides alone; simulate with the filter and a check of enabled().
		add_filter( 'form_captain_log_level_v1', static fn() => 'error' );
		Logger::reset_level();
		$rank = array_flip( Logger::LEVELS );
		foreach ( Logger::LEVELS as $level ) {
			$this->assertSame( $rank[ $level ] <= $rank[ Logger::level() ], Logger::enabled( $level ) );
		}
		$this->assertFalse( Logger::enabled( 'nonsense' ) );
	}

	public function test_log_days_setting_and_filter(): void {
		global $wpdb;
		$table = Tables::name( Tables::LOG );
		$wpdb->query( "DELETE FROM {$table}" );
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		Logger::log( 'error', 'old_entry' );
		Clock::freeze( strtotime( '2026-06-21 12:00:00 UTC' ) );
		Logger::log( 'error', 'new_entry' );
		Clock::freeze( strtotime( '2026-06-26 12:00:00 UTC' ) );

		$this->assertSame( 30, Logger::retention_days() );
		Logger::purge_old();
		$this->assertSame( array( 'old_entry', 'new_entry' ), $this->logged_codes() );

		Settings::set_group( 'privacy', array( 'log_days' => 10 ) );
		Logger::purge_old();
		$this->assertSame( array( 'new_entry' ), $this->logged_codes() );

		add_filter( 'form_captain_log_days_v1', static fn() => 1 );
		Logger::purge_old();
		$this->assertSame( array(), $this->logged_codes(), 'filter wins' );

		list( , $errors ) = SettingsSchema::sanitize_group( 'privacy', array( 'log_days' => '366' ) );
		$this->assertArrayHasKey( 'privacy.log_days', $errors );
	}

	// SET-FC-16 ------------------------------------------------------------

	public function test_client_ip_source(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.7, 10.0.0.1';
		$_SERVER['HTTP_X_REAL_IP']        = '2001:db8::1';

		$this->assertSame( '10.0.0.1', RateLimiter::client_ip(), 'default: direct address, headers ignored' );
		Settings::set_group( 'spam', array( 'client_ip_source' => 'cf_connecting_ip' ) );
		$this->assertSame( '203.0.113.5', RateLimiter::client_ip() );
		Settings::set_group( 'spam', array( 'client_ip_source' => 'x_forwarded_for' ) );
		$this->assertSame( '198.51.100.7', RateLimiter::client_ip(), 'first address of the list' );
		Settings::set_group( 'spam', array( 'client_ip_source' => 'x_real_ip' ) );
		$this->assertSame( '2001:db8::1', RateLimiter::client_ip() );

		$_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';
		$this->assertSame( '10.0.0.1', RateLimiter::client_ip(), 'invalid header falls back to the direct address' );
		unset( $_SERVER['HTTP_X_REAL_IP'] );
		$this->assertSame( '10.0.0.1', RateLimiter::client_ip(), 'missing header falls back' );

		add_filter( 'form_captain_client_ip', static fn() => '192.0.2.9' );
		$this->assertSame( '192.0.2.9', RateLimiter::client_ip(), 'filter still wins' );

		list( , $errors ) = SettingsSchema::sanitize_group( 'spam', array( 'client_ip_source' => 'HTTP_TRUE_CLIENT_IP' ) );
		$this->assertArrayHasKey( 'spam.client_ip_source', $errors );
		$this->assertStringContainsString( 'really behind this service', SettingsSchema::definitions()['spam.client_ip_source']['help'] );
	}

	// SET-FC-34 ------------------------------------------------------------

	public function test_limits_follow_settings_up_to_hard_ceiling(): void {
		$this->assertSame( 1000, Limits::get( 'text_max' ) );
		$this->assertSame( 10000, Limits::get( 'textarea_max' ) );
		$this->assertSame( 7200, Limits::get( 'token_ttl' ) );
		$this->assertSame( 20, Limits::get( 'rate_global_per_ip' ) );
		$this->assertSame( 65536, Limits::get( 'request_bytes' ), 'unchanged by default' );

		Settings::set_group( 'general', array( 'text_max' => 3000, 'textarea_max' => 40000 ) );
		Settings::set_group( 'spam', array( 'token_minutes' => 480, 'global_per_ip' => 200 ) );
		$this->assertSame( 3000, Limits::get( 'text_max' ) );
		$this->assertSame( 40000, Limits::get( 'textarea_max' ) );
		$this->assertSame( 480 * 60, Limits::get( 'token_ttl' ) );
		$this->assertSame( 200, Limits::get( 'rate_global_per_ip' ) );
		$this->assertSame( 40000 * 4 + 16384, Limits::get( 'request_bytes' ), 'request size follows long paragraphs' );

		// Filter wins in both directions, but never above the hard ceiling.
		add_filter( 'form_captain_limits_v1', static fn( array $l ): array => array_merge( $l, array( 'text_max' => 500, 'textarea_max' => 999999, 'rate_global_per_ip' => 5000, 'fields_per_form' => 500 ) ) );
		Limits::reset();
		$this->assertSame( 500, Limits::get( 'text_max' ) );
		$this->assertSame( Limits::SETTABLE['textarea_max'][2], Limits::get( 'textarea_max' ) );
		$this->assertSame( Limits::SETTABLE['rate_global_per_ip'][2], Limits::get( 'rate_global_per_ip' ) );
		$this->assertSame( 100, Limits::get( 'fields_per_form' ), 'technical limits can still only be lowered' );

		list( , $errors ) = SettingsSchema::sanitize_group( 'general', array( 'text_max' => '5001', 'textarea_max' => '50001' ) );
		$this->assertArrayHasKey( 'general.text_max', $errors );
		$this->assertArrayHasKey( 'general.textarea_max', $errors );
		list( , $errors ) = SettingsSchema::sanitize_group( 'spam', array( 'token_minutes' => '1441', 'global_per_ip' => '1001' ) );
		$this->assertArrayHasKey( 'spam.token_minutes', $errors );
		$this->assertArrayHasKey( 'spam.global_per_ip', $errors );
	}

	public function test_rate_limiter_uses_global_per_ip_setting_and_filter(): void {
		Settings::set_group( 'spam', array( 'global_per_ip' => 2, 'rate_limit' => 100 ) );
		$limiter = new RateLimiter();
		$ip      = '192.0.2.77';
		$this->assertTrue( $limiter->check_submission( 9101, $ip )['allowed'] );
		$this->assertTrue( $limiter->check_submission( 9102, $ip )['allowed'] );
		$third = $limiter->check_submission( 9103, $ip );
		$this->assertFalse( $third['allowed'], 'third submission across forms exceeds spam.global_per_ip = 2' );
		$this->assertGreaterThan( 0, $third['retry_after'] );
		$this->assertTrue( $limiter->check_submission( 9104, '192.0.2.78' )['allowed'], 'other visitors are not affected' );

		// Filter form_captain_limits_v1 wins over the setting.
		add_filter( 'form_captain_limits_v1', static fn( array $l ): array => array_merge( $l, array( 'rate_global_per_ip' => 10 ) ) );
		Limits::reset();
		$this->assertTrue( $limiter->check_submission( 9105, $ip )['allowed'] );
	}

	// SET-FC-31 ------------------------------------------------------------

	public function test_export_defaults_preselect_the_export_form(): void {
		$this->as_admin();
		$this->assertSame( ',', Settings::get( 'export.delimiter' ) );
		$this->assertTrue( Settings::get( 'export.bom' ) );
		$res = $this->page()->save_tab( 'export', array( 'delimiter' => ';' ) );
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( ';', Settings::get( 'export.delimiter' ) );
		$this->assertFalse( Settings::get( 'export.bom' ), 'unchecked box' );

		$form            = $this->create_published_form();
		$_GET['export']  = '1';
		$_GET['form_id'] = (string) $form['id'];
		ob_start();
		$this->plugin()->get( EntriesPage::class )->render();
		$html = (string) ob_get_clean();
		unset( $_GET['export'], $_GET['form_id'] );
		$this->assertMatchesRegularExpression( '/<option value=";"\s+selected/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="bom" value="1"\s+checked/', $html );

		list( , $errors ) = SettingsSchema::sanitize_group( 'export', array( 'delimiter' => '|' ) );
		$this->assertArrayHasKey( 'export.delimiter', $errors );
	}

	// SET-FC-12 ------------------------------------------------------------

	public function test_every_profile_suggests_twelve_months_and_valid_values(): void {
		$profiles = CountryPresets::all();
		$this->assertSame( CountryPresets::KEYS, array_keys( $profiles ) );
		foreach ( $profiles as $key => $profile ) {
			$this->assertSame( '12m', $profile['values']['privacy.retention'], $key );
			$this->assertSame( array(), $profile['reviewed'], "$key: nothing is legally reviewed yet" );
			$this->assertSame( in_array( $key, array( 'DE', 'AT', 'CH' ), true ) ? 'pending' : 'unreviewed', $profile['review'], "$key: S8 review status" );
			$this->assertStringContainsString( 'Not legally reviewed', CountryPresets::review_label( $profile['review'] ) );
			$expected = in_array( $key, array( 'DE', 'AT', 'CH' ), true ) ? ';' : ',';
			$this->assertSame( $expected, $profile['values']['export.delimiter'], $key );
			foreach ( CountryPresets::diff( $key ) as $row ) {
				$this->assertSame( '', $row['error'], "$key {$row['path']}" );
				if ( $row['legal'] ) {
					$this->assertSame( '', $row['reviewed'] );
				}
			}
		}
	}

	public function test_profile_is_a_suggestion_until_applied(): void {
		$this->as_admin();
		Settings::set_group( 'privacy', array( 'retention' => '90d', 'trash_days' => 7 ) );
		$rows = array_column( CountryPresets::diff( 'DE' ), null, 'path' );
		$this->assertSame( '90d', $rows['privacy.retention']['before'] );
		$this->assertSame( '12m', $rows['privacy.retention']['after'] );
		$this->assertTrue( $rows['privacy.retention']['changed'] );
		$this->assertTrue( $rows['privacy.retention']['legal'] );
		$this->assertFalse( $rows['export.bom']['changed'] );
		$this->assertSame( '90d', Settings::get( 'privacy.retention' ), 'preview changes nothing' );

		// Preview page shows before/after and the unreviewed marker, still without changes.
		$_GET['tab']        = 'general';
		$_GET['fc_profile'] = 'de';
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'], $_GET['fc_profile'] );
		$this->assertStringContainsString( 'Not legally reviewed', $html );
		$this->assertStringContainsString( 'review before the sales start is planned', $html );
		$this->assertStringContainsString( 'International (other countries) – not legally reviewed', $html );
		$this->assertStringContainsString( 'value="privacy.retention" checked', $html );
		// SET-FC-P3-3: without existing submissions the preview must not claim that nothing is deleted.
		$this->assertStringContainsString( 'New submissions are deleted automatically 12 months after they were received.', $html );
		$this->assertStringNotContainsString( 'nothing is deleted automatically', $html );
		$this->assertSame( '90d', Settings::get( 'privacy.retention' ) );

		$res = $this->page()->apply_country_profile( 'DE', array( 'privacy.retention', 'export.delimiter' ), false );
		$this->assertSame( 'error', $res['type'], 'confirmation required' );
		$this->assertSame( '90d', Settings::get( 'privacy.retention' ) );

		$res = $this->page()->apply_country_profile( 'DE', array( 'privacy.retention', 'export.delimiter' ), true );
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( '12m', Settings::get( 'privacy.retention' ) );
		$this->assertSame( ';', Settings::get( 'export.delimiter' ) );
		$this->assertSame( 7, Settings::get( 'privacy.trash_days' ), 'deselected row stays' );
		$this->assertSame( 'DE', Settings::get( 'general.country_profile' ) );
		$this->assertStringContainsString( 'No existing submissions are affected', implode( ' ', $res['messages'] ), 'retention note' );

		$this->assertSame( 'error', $this->page()->apply_country_profile( 'XX', array(), true )['type'] );
	}

	public function test_each_profile_applies(): void {
		foreach ( CountryPresets::KEYS as $key ) {
			delete_option( Settings::OPTION );
			Settings::ensure_defaults();
			CountryPresets::apply( $key, array_column( CountryPresets::diff( $key ), 'path' ) );
			$this->assertSame( $key, Settings::get( 'general.country_profile' ) );
			foreach ( CountryPresets::all()[ $key ]['values'] as $path => $value ) {
				$this->assertSame( $value, Settings::get( $path ), "$key $path" );
			}
			$this->assertSame( array(), array_filter( array_column( CountryPresets::diff( $key ), 'changed' ) ), "$key: nothing left to change" );
		}
	}

	public function test_profile_filter_and_invalid_values(): void {
		add_filter(
			'form_captain_country_presets_v1',
			static function ( array $p ): array {
				$p['DE']['values']['privacy.trash_days'] = 999;
				$p['DE']['values']['privacy.log_days']   = 90;
				$p['DE']['values']['unknown.key']        = 1;
				$p['DE']['reviewed']['privacy.log_days'] = '2026-11-01';
				$p['nope']                               = array( 'values' => array() );
				return $p;
			}
		);
		$this->assertArrayNotHasKey( 'nope', CountryPresets::all() );
		$rows = array_column( CountryPresets::diff( 'DE' ), null, 'path' );
		$this->assertArrayNotHasKey( 'unknown.key', $rows );
		$this->assertNotSame( '', $rows['privacy.trash_days']['error'] );
		$this->assertSame( '2026-11-01', $rows['privacy.log_days']['reviewed'] );
		$result = CountryPresets::apply( 'DE', array( 'privacy.trash_days', 'privacy.log_days' ) );
		$this->assertArrayHasKey( 'privacy.trash_days', $result['errors'] );
		$this->assertSame( 30, Settings::get( 'privacy.trash_days' ), 'invalid value never applied' );
		$this->assertSame( 90, Settings::get( 'privacy.log_days' ) );
	}

	public function test_country_profile_suggests_patterns_for_new_fields(): void {
		$this->assertSame( array(), CountryPresets::field_suggestions(), 'no profile applied yet' );
		$expect = array(
			'DE'    => array( 'zip_de', '' ),
			'AT'    => array( 'zip_at', '' ),
			'CH'    => array( 'zip_ch', '' ),
			'EU'    => array( 'zip_generic', 'international' ),
			'OTHER' => array( 'zip_generic', 'international' ),
		);
		foreach ( $expect as $key => $fields ) {
			CountryPresets::apply( $key, array() );
			$s = CountryPresets::field_suggestions();
			$this->assertSame( $key, $s['profile'] );
			$this->assertSame( $fields[0], $s['zip_pattern'], $key );
			$this->assertSame( \FormCaptain\Domain\ValidationPatterns::all()[ $fields[0] ]['label'], $s['zip_label'] );
			$this->assertSame( $fields[1], $s['tel_format'], $key );
		}
		$this->assertSame( 'manual', Settings::get( 'privacy.retention' ), 'applying without selected rows changes no setting' );

		// Unknown patterns of a filtered profile are dropped, never suggested.
		add_filter(
			'form_captain_country_presets_v1',
			static function ( array $p ): array {
				$p['OTHER']['fields'] = array( 'zip_pattern' => 'custom', 'tel_format' => 'weird' );
				return $p;
			}
		);
		$this->assertSame( array(), CountryPresets::field_suggestions() );

		$this->as_admin();
		$boot = $this->plugin()->get( \FormCaptain\Admin\EditorPage::class )->bootstrap( 0 );
		$this->assertEquals( (object) array(), $boot['fieldSuggestions'], 'editor gets an empty object without suggestions' );
	}
}
