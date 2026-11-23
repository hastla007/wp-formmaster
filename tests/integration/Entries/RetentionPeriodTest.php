<?php
/**
 * Free retention periods, migration, grace period and expiry notice (SET-FC-01, SET-FC-08).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Database\Tables;
use FormCaptain\Service\FormService;
use FormCaptain\Service\RetentionService;
use FormCaptain\Settings\RetentionPeriod;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsMigrations;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class RetentionPeriodTest extends TestCase {

	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_retention_seconds_v1' );
		remove_all_filters( 'form_captain_retention_grace_days_v1' );
		remove_all_filters( 'form_captain_expiry_notice_days_v1' );
		parent::tear_down();
	}

	private function retention(): RetentionService {
		return $this->plugin()->get( RetentionService::class );
	}

	public function test_period_normalization_and_limits(): void {
		$this->assertSame( '90d', RetentionPeriod::normalize( '90d' ) );
		$this->assertSame( '12m', RetentionPeriod::normalize( '12m' ) );
		$this->assertSame( 'manual', RetentionPeriod::normalize( 'manual' ) );
		$this->assertSame( '90d', RetentionPeriod::normalize( 'days_90' ), 'spec alias' );
		$this->assertSame( '12m', RetentionPeriod::normalize( 'months_12' ), 'spec alias' );
		$this->assertSame( '6m', RetentionPeriod::normalize( ' 6M ' ) );
		$this->assertSame( '1d', RetentionPeriod::normalize( '1d' ), 'lower limit 1 day' );
		$this->assertSame( '30y', RetentionPeriod::normalize( '30y' ), 'upper limit 30 years' );
		$this->assertSame( '360m', RetentionPeriod::normalize( '360m' ) );
		$this->assertSame( '10950d', RetentionPeriod::normalize( '10950d' ) );
		foreach ( array( '0d', '31y', '361m', '10951d', '-1d', '5w', '12', '', 'global', '1.5y', array( '1y' ) ) as $bad ) {
			$this->assertNull( RetentionPeriod::normalize( $bad ), 'invalid: ' . wp_json_encode( $bad ) );
		}
		$this->assertSame( '6m', RetentionPeriod::from_parts( 'period', '6', 'm' ) );
		$this->assertSame( 'manual', RetentionPeriod::from_parts( 'manual', '6', 'm' ) );
		$this->assertSame( '3 years', RetentionPeriod::label( '3y' ) );
		$this->assertSame( '1 day', RetentionPeriod::label( '1d' ) );
		$this->assertSame( '12 months', RetentionPeriod::label( '12m' ) );
	}

	public function test_expiry_for_free_periods_is_calendar_based(): void {
		$this->assertSame( '2026-02-20 10:00:00', RetentionService::expires_for( '30d', '2026-01-21 10:00:00' ) );
		$this->assertSame( '2026-07-31 10:00:00', RetentionService::expires_for( '6m', '2026-01-31 10:00:00' ), 'PHP month overflow like P12M before' );
		$this->assertSame( '2036-03-20 12:00:00', RetentionService::expires_for( '10y', '2026-03-20 12:00:00' ), '10 years are calendar years, not 3650 days' );
		$this->assertSame( RetentionService::expires_for( '12m', '2024-02-29 00:00:00' ), RetentionService::expires_for( '1y', '2024-02-29 00:00:00' ) );
		$this->assertNull( RetentionService::expires_for( 'manual', '2026-01-01 00:00:00' ) );
		$this->assertNull( RetentionService::expires_for( 'bogus', '2026-01-01 00:00:00' ) );
	}

	public function test_retention_seconds_filter_wins(): void {
		add_filter( 'form_captain_retention_seconds_v1', static fn( $seconds, $mode ) => '6m' === $mode ? 2 * DAY_IN_SECONDS : $seconds, 10, 2 );
		$this->assertSame( '2026-01-03 00:00:00', RetentionService::expires_for( '6m', '2026-01-01 00:00:00' ) );
		$this->assertSame( '2026-04-01 00:00:00', RetentionService::expires_for( '90d', '2026-01-01 00:00:00' ), 'unchanged value keeps calendar result' );
		remove_all_filters( 'form_captain_retention_seconds_v1' );
		add_filter( 'form_captain_retention_seconds_v1', static fn() => null );
		$this->assertNull( RetentionService::expires_for( '90d', '2026-01-01 00:00:00' ), 'filter can switch to manual' );
		remove_all_filters( 'form_captain_retention_seconds_v1' );
		add_filter( 'form_captain_retention_seconds_v1', static fn() => 5 );
		$this->assertSame( '2026-01-02 00:00:00', RetentionService::expires_for( '90d', '2026-01-01 00:00:00' ), 'at least one day' );
	}

	public function test_schema_accepts_period_parts_and_rejects_invalid_periods(): void {
		list( $values, $errors ) = SettingsSchema::sanitize_group(
			'privacy',
			array(
				'retention_kind'    => 'period',
				'retention_amount'  => '3',
				'retention_unit'    => 'y',
				'retention_confirm' => '1',
			)
		);
		$this->assertSame( array(), $errors );
		$this->assertSame( '3y', $values['retention'] );

		list( $values, $errors ) = SettingsSchema::sanitize_group( 'privacy', array( 'retention' => '31y', 'retention_confirm' => '1' ) );
		$this->assertSame( RetentionPeriod::error_message(), $errors['privacy.retention'] );
		$this->assertSame( 'manual', $values['retention'], 'stored value unchanged' );

		list( $values, ) = SettingsSchema::sanitize_group( 'privacy', array( 'retention_kind' => 'manual', 'retention_confirm' => '1' ) );
		$this->assertSame( 'manual', $values['retention'] );
	}

	public function test_unconfirmed_change_is_shown_again_with_preview(): void {
		$this->as_admin();
		$this->make_entry( $this->create_published_form() );
		$page = $this->plugin()->get( SettingsPage::class );
		$res  = $page->save_tab( 'privacy', array( 'retention_kind' => 'period', 'retention_amount' => '6', 'retention_unit' => 'm', 'trash_days' => '30', 'erasure_mode' => 'delete' ) );
		$this->assertSame( 'error', $res['type'] );
		$this->assertArrayHasKey( 'privacy.retention', $res['errors'] );
		$this->assertSame( array( 'privacy.retention' => '6m' ), $res['proposed'] );
		$this->assertSame( 'manual', Settings::get( 'privacy.retention' ) );

		$res = $page->save_tab( 'privacy', array( 'retention_kind' => 'period', 'retention_amount' => '6', 'retention_unit' => 'm', 'trash_days' => '30', 'erasure_mode' => 'delete', 'retention_confirm' => '1' ) );
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( '6m', Settings::get( 'privacy.retention' ) );
		$this->assertStringContainsString( 'never deleted sooner than 14 days', implode( ' ', $res['messages'] ) );

		$_GET['tab'] = 'privacy';
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'name="settings[retention_amount]"', $html );
		$this->assertStringContainsString( 'value="6"', $html );
		$this->assertStringContainsString( 'deleted permanently 6 months after', $html );
	}

	public function test_recalculation_with_free_period_and_configured_grace(): void {
		$now = strtotime( '2026-06-01 12:00:00 UTC' );
		Clock::freeze( $now );
		$form = $this->create_published_form();
		$old  = $this->make_entry( $form, array(), array( 'expires_gmt' => '2027-02-20 00:00:00' ) );
		$this->entries()->update( $old, array( 'created_gmt' => '2026-02-20 00:00:00' ) );

		Settings::set_group( 'privacy', array( 'grace_days' => 3 ) );
		Settings::set_group( 'privacy', array( 'retention' => '30d' ) );
		$this->assertSame( Clock::plus( 3 * DAY_IN_SECONDS ), $this->entries()->find( $old )['expires_gmt'], 'grace from the setting' );

		Settings::set_group( 'privacy', array( 'retention' => '2y' ) );
		$this->assertSame( '2028-02-20 00:00:00', $this->entries()->find( $old )['expires_gmt'] );

		add_filter( 'form_captain_retention_grace_days_v1', static fn() => 0 );
		Settings::set_group( 'privacy', array( 'retention' => '30d' ) );
		$this->assertSame( Clock::now(), $this->entries()->find( $old )['expires_gmt'], 'filter wins, 0 = no grace' );
	}

	public function test_grace_and_notice_settings_validate(): void {
		list( , $errors ) = SettingsSchema::sanitize_group( 'privacy', array( 'grace_days' => '91', 'expiry_notice_days' => '-1' ) );
		$this->assertArrayHasKey( 'privacy.grace_days', $errors );
		$this->assertArrayHasKey( 'privacy.expiry_notice_days', $errors );
		list( $values, $errors ) = SettingsSchema::sanitize_group( 'privacy', array( 'grace_days' => '0', 'expiry_notice_days' => '30' ) );
		$this->assertSame( array(), $errors );
		$this->assertSame( 0, $values['grace_days'] );
		$this->assertSame( 30, $values['expiry_notice_days'] );
	}

	public function test_expiry_notice_days_drive_upcoming(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		$this->make_entry( $form, array(), array( 'expires_gmt' => Clock::plus( 20 * DAY_IN_SECONDS ) ) );
		$this->assertSame( 0, $this->retention()->upcoming()['count'], 'default 14 days' );
		Settings::set_group( 'privacy', array( 'expiry_notice_days' => 30 ) );
		$this->assertSame( 1, $this->retention()->upcoming()['count'] );
		Settings::set_group( 'privacy', array( 'expiry_notice_days' => 0 ) );
		$this->assertSame( 0, $this->retention()->upcoming()['count'], '0 turns the notice off' );
		add_filter( 'form_captain_expiry_notice_days_v1', static fn() => 21 );
		$this->assertSame( 21, RetentionService::notice_days() );
		$this->assertSame( 1, $this->retention()->upcoming()['count'] );
	}

	public function test_form_retention_mode_validation(): void {
		$service = $this->plugin()->get( FormService::class );
		$schema  = array( 'fields' => array() );
		foreach ( array( 'global' => 'global', '' => 'global', '90d' => '90d', '12m' => '12m', 'manual' => 'manual', '18m' => '18m', '7y' => '7y', 'days_90' => '90d' ) as $in => $expected ) {
			$out = $service->sanitize_settings( array( 'retention' => array( 'mode' => $in ) ), $schema );
			$this->assertSame( $expected, $out['settings']['retention']['mode'], (string) $in );
			$this->assertArrayNotHasKey( 'retention.mode', $out['errors'] );
		}
		$out = $service->sanitize_settings( array( 'retention' => array( 'mode' => '99y' ) ), $schema );
		$this->assertSame( 'global', $out['settings']['retention']['mode'] );
		$this->assertSame( 'invalid_retention', $out['errors']['retention.mode'] );
	}

	public function test_form_with_free_period_overrides_global(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form( null, array( 'retention' => array( 'mode' => '3y' ) ) );
		$this->assertSame( '3y', RetentionService::mode_for_form( $form ) );
		$id = $this->make_entry( $form );
		do_action( 'form_captain_entry_created_v1', array( 'entry_id' => $id ) );
		$this->assertSame( '2029-06-01 12:00:00', $this->entries()->find( $id )['expires_gmt'] );
	}

	public function test_migration_keeps_old_values_and_normalizes_aliases(): void {
		global $wpdb;
		$keep   = $this->create_published_form( null, array( 'retention' => array( 'mode' => '90d' ) ) );
		$alias  = $this->create_published_form( null, array( 'retention' => array( 'mode' => 'months_12' ) ) );
		$broken = $this->create_published_form( null, array( 'retention' => array( 'mode' => 'forever' ) ) );
		$global = $this->create_published_form();
		$stored = get_option( Settings::OPTION );
		$stored['privacy']['retention'] = 'days_90';
		update_option( Settings::OPTION, $stored );
		delete_option( SettingsMigrations::OPTION );

		SettingsMigrations::run();

		$this->assertSame( '90d', Settings::get( 'privacy.retention' ) );
		$mode = static fn( array $f ): string => (string) ( json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT settings_json FROM ' . Tables::name( Tables::FORMS ) . ' WHERE id = %d', (int) $f['id'] ) ), true )['retention']['mode'] ?? 'global' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '90d', $mode( $keep ) );
		$this->assertSame( '12m', $mode( $alias ) );
		$this->assertSame( 'global', $mode( $broken ), 'unknown value meant "global" before' );
		$this->assertSame( 'global', $mode( $global ) );
		$this->assertArrayHasKey( 'retention_periods', get_option( SettingsMigrations::OPTION ) );

		// Runs once: a later alias is not touched again.
		$stored                         = get_option( Settings::OPTION );
		$stored['privacy']['retention'] = 'months_12';
		update_option( Settings::OPTION, $stored );
		SettingsMigrations::run();
		$this->assertSame( 'months_12', get_option( Settings::OPTION )['privacy']['retention'] );
	}

	public function test_new_install_is_manual_and_existing_install_keeps_twelve_months(): void {
		$this->assertSame( 'manual', Settings::get( 'privacy.retention' ), 'S9: new installation' );

		// Existing installation that never stored a retention value (earlier default 12m).
		update_option( Settings::OPTION, array( 'spam' => array( 'rate_limit' => 7 ) ) );
		Settings::ensure_defaults();
		$this->assertSame( '12m', Settings::get( 'privacy.retention' ), 'update keeps the earlier behaviour' );
		$this->assertSame( 7, Settings::get( 'spam.rate_limit' ) );

		// Explicit values are never touched.
		Settings::set_group( 'privacy', array( 'retention' => 'manual' ) );
		Settings::ensure_defaults();
		$this->assertSame( 'manual', Settings::get( 'privacy.retention' ) );
	}

	public function test_site_health_reminds_while_no_period_is_set(): void {
		$health = $this->plugin()->get( \FormCaptain\Admin\SiteHealth::class );
		$this->assertArrayHasKey( 'form_captain_retention', $health->tests( array() )['direct'] );
		$result = $health->test_retention();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'form_captain_retention', $result['test'] );
		$this->assertStringContainsString( 'checked legally', $result['description'] );
		Settings::set_group( 'privacy', array( 'retention' => '12m' ) );
		$result = $health->test_retention();
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '12 months', $result['description'] );
	}

	public function test_invalid_global_value_migrates_to_twelve_months(): void {
		$stored                         = get_option( Settings::OPTION );
		$stored['privacy']['retention'] = '5years';
		update_option( Settings::OPTION, $stored );
		delete_option( SettingsMigrations::OPTION );
		SettingsMigrations::run();
		$this->assertSame( '12m', Settings::get( 'privacy.retention' ) );
	}
}
