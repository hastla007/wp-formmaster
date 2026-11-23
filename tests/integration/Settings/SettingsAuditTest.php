<?php
/**
 * Audit of the settings register (SET-FC-P1): every value survives export/import and reset,
 * changes are logged on every path, add-on fields behave like core values.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Settings\ChangeLog;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Settings\SettingsTransfer;
use FormCaptain\Tests\Integration\TestCase;

final class SettingsAuditTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		delete_option( ChangeLog::OPTION );
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_settings_fields_v1' );
		delete_option( 'fc_audit_addon' );
		parent::tear_down();
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	/**
	 * A valid value different from the default for every exportable setting.
	 *
	 * @return array<string,mixed>
	 */
	private static function changed_values(): array {
		$out = array();
		foreach ( SettingsSchema::definitions() as $path => $def ) {
			if ( ! SettingsTransfer::is_exportable( $path ) ) {
				continue;
			}
			$default = $def['default'];
			switch ( $def['type'] ) {
				case 'bool':
					$out[ $path ] = ! $default;
					break;
				case 'int':
					$out[ $path ] = (int) $default === (int) $def['max'] ? (int) $def['min'] : (int) $def['max'];
					break;
				case 'enum':
					$keys         = array_map( 'strval', array_keys( (array) $def['options'] ) );
					$other        = array_values( array_diff( $keys, array( (string) $default ) ) );
					$out[ $path ] = $other[0];
					break;
				case 'retention':
					$out[ $path ] = '18m';
					break;
				case 'hex':
					$out[ $path ] = '#123abc';
					break;
				case 'email':
					$out[ $path ] = 'audit@example.org';
					break;
				case 'string':
					$out[ $path ] = 'general.entry_number_format' === $path ? 'A-{year}-{number}' : 'Audit value';
					break;
				case 'text':
					$out[ $path ] = "first line\nsecond line";
					break;
				case 'css':
					$out[ $path ] = '.fc-form { color: #123; }';
					break;
				case 'attachment':
					break; // needs an image in the media library of the target site
				default:
					$out[ $path ] = null;
			}
		}
		return array_filter( $out, static fn( $v ) => null !== $v );
	}

	public function test_every_exportable_value_survives_export_reset_and_import(): void {
		$this->as_admin();
		grant_super_admin( get_current_user_id() ); // unfiltered_html for custom CSS on multisite test runs
		$values = self::changed_values();
		$by     = array();
		foreach ( $values as $path => $value ) {
			list( $group, $key )  = explode( '.', $path, 2 );
			$by[ $group ][ $key ] = $value;
		}
		foreach ( $by as $group => $group_values ) {
			Settings::set_group( $group, $group_values );
		}
		foreach ( $values as $path => $value ) {
			$this->assertSame( $value, Settings::get( $path ), $path . ' stored' );
		}
		$export = SettingsTransfer::export();
		foreach ( array_keys( $values ) as $path ) {
			$this->assertContains( $path, $export['keys'], $path . ' exported' );
		}
		foreach ( SettingsSchema::GROUPS as $group ) {
			Settings::reset_group( $group );
		}
		$res = SettingsTransfer::import(
			(string) wp_json_encode( $export ),
			array(
				'retention_confirm' => true,
				'captcha_confirm'   => true,
			)
		);
		$this->assertSame( array(), $res['errors'] );
		$this->assertSame( array(), $res['unknown'] );
		foreach ( $values as $path => $value ) {
			$this->assertSame( $value, Settings::get( $path ), $path . ' imported' );
		}
		// A second import of the same file changes nothing (values are stable under the checks).
		$again = SettingsTransfer::preview( (string) wp_json_encode( $export ) );
		$this->assertSame( array(), $again['changes'] );
	}

	public function test_time_limit_setting_is_not_treated_as_secret(): void {
		$this->assertFalse( SettingsSchema::is_secret( 'spam.token_minutes' ) );
		$this->assertTrue( SettingsTransfer::is_exportable( 'spam.token_minutes' ) );
		Settings::set_group( 'spam', array( 'token_minutes' => 240 ) );
		$entry = ChangeLog::latest()[0];
		$this->assertSame( 'spam.token_minutes', $entry['key'] );
		$this->assertSame( '240', $entry['new'] );
		// Secret-looking names of add-on fields stay masked.
		$this->assertTrue( SettingsSchema::is_secret( 'webhooks.secret' ) );
		$this->assertTrue( SettingsSchema::is_secret( 'connector.api_key' ) );
	}

	/** @return array<int,array<string,mixed>> */
	private static function addon_fields(): array {
		$storage = static fn( string $key ): array => array(
			'option' => 'fc_audit_addon',
			'key'    => $key,
		);
		return array(
			array(
				'id'      => 'audit.days',
				'tab'     => 'general',
				'label'   => 'Days',
				'type'    => 'number',
				'min'     => 1,
				'max'     => 30,
				'default' => 7,
				'storage' => $storage( 'audit.days' ),
			),
			array(
				'id'      => 'audit.api_secret',
				'tab'     => 'general',
				'label'   => 'Secret',
				'type'    => 'text',
				'default' => '',
				'storage' => $storage( 'audit.api_secret' ),
			),
			array(
				'id'      => 'uninstall.remove_all_data',
				'tab'     => 'privacy',
				'label'   => 'Delete add-on data on uninstall',
				'type'    => 'confirm',
				'default' => false,
				'storage' => $storage( 'uninstall.remove_all_data' ),
			),
		);
	}

	public function test_addon_field_changes_and_resets_are_in_the_change_log(): void {
		$this->as_admin();
		add_filter( 'form_captain_settings_fields_v1', static fn( $f ) => array_merge( (array) $f, self::addon_fields() ) );
		$this->page()->save_tab(
			'general',
			array(
				'show_pro_hints' => '1',
				'ext'            => array(
					'audit.days'       => '12',
					'audit.api_secret' => 'abc123',
				),
			)
		);
		$log = array_column( ChangeLog::latest(), null, 'key' );
		$this->assertArrayHasKey( 'audit.days', $log );
		$this->assertSame( '7', $log['audit.days']['old'] );
		$this->assertSame( '12', $log['audit.days']['new'] );
		$this->assertSame( 'ui', $log['audit.days']['source'] );
		$this->assertSame( '••••••', $log['audit.api_secret']['new'], 'secret masked' );

		delete_option( ChangeLog::OPTION );
		$this->page()->reset_tab( 'general' );
		$log = array_column( ChangeLog::latest(), null, 'key' );
		$this->assertArrayHasKey( 'audit.days', $log, 'reset of add-on values is logged' );
		$this->assertSame( '7', $log['audit.days']['new'] );
	}

	public function test_confirmed_addon_switch_stays_on_when_the_tab_is_saved_again(): void {
		$this->as_admin();
		add_filter( 'form_captain_settings_fields_v1', static fn( $f ) => array_merge( (array) $f, self::addon_fields() ) );
		$this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => 'DELETE' ) ) );
		$this->assertTrue( get_option( 'fc_audit_addon' )['uninstall.remove_all_data'] );

		// The form sends the ticked box again, without the word: no error, still on.
		$res = $this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => '1' ) ) );
		$this->assertArrayNotHasKey( 'ext.uninstall.remove_all_data', $res['errors'] );
		$this->assertTrue( get_option( 'fc_audit_addon' )['uninstall.remove_all_data'] );

		// Switching off and on again needs the word again.
		$this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => '0' ) ) );
		$this->assertFalse( get_option( 'fc_audit_addon' )['uninstall.remove_all_data'] );
		$res = $this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => '1' ) ) );
		$this->assertArrayHasKey( 'ext.uninstall.remove_all_data', $res['errors'] );
	}

	public function test_privacy_link_setting_presets_new_consent_fields(): void {
		$this->as_admin();
		$this->assertTrue( $this->plugin()->get( \FormCaptain\Admin\EditorPage::class )->bootstrap( 0 )['consentLink'] );
		$consent = static function ( int $form_id ): array {
			$form = ( new \FormCaptain\Repository\FormRepository() )->find( $form_id );
			foreach ( $form['schema']['fields'] as $field ) {
				if ( 'consent' === $field['type'] ) {
					return $field;
				}
			}
			return array();
		};
		$service = $this->plugin()->get( \FormCaptain\Service\FormService::class );
		$this->assertTrue( $consent( (int) $service->create_from_template( 'contact' ) )['privacy_link'] );

		Settings::set_group( 'privacy', array( 'policy_page_hint' => false ) );
		$this->assertFalse( $this->plugin()->get( \FormCaptain\Admin\EditorPage::class )->bootstrap( 0 )['consentLink'] );
		$this->assertFalse( $consent( (int) $service->create_from_template( 'contact' ) )['privacy_link'] );
	}

	public function test_import_preview_names_the_configured_grace_period(): void {
		$this->as_admin();
		Settings::set_group( 'privacy', array( 'grace_days' => 30 ) );
		$data                                     = SettingsTransfer::export();
		$data['settings']['privacy']['retention'] = '90d';
		$this->page()->import_preview( (string) wp_json_encode( $data ) );
		$_GET['tab'] = 'status';
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'never sooner than 30 days after this change', $html );
	}

	public function test_addon_values_survive_export_and_import_without_secrets_and_switches(): void {
		$this->as_admin();
		add_filter( 'form_captain_settings_fields_v1', static fn( $f ) => array_merge( (array) $f, self::addon_fields() ) );
		update_option(
			'fc_audit_addon',
			array(
				'audit.days'                => 21,
				'audit.api_secret'          => 'top-secret',
				'uninstall.remove_all_data' => true,
			)
		);
		$export = SettingsTransfer::export();
		$this->assertSame( array( 'audit.days' => 21 ), $export['extensions'], 'no secret, no confirm switch' );
		$this->assertStringNotContainsString( 'top-secret', SettingsTransfer::export_json() );

		delete_option( 'fc_audit_addon' );
		$export['extensions']['audit.days']                = 22;
		$export['extensions']['audit.api_secret']          = 'from-file';
		$export['extensions']['uninstall.remove_all_data'] = true;
		$export['extensions']['other.addon']               = 1;
		$preview = SettingsTransfer::preview( (string) wp_json_encode( $export ) );
		$this->assertSame( array( 7, 22 ), $preview['changes']['audit.days'] );
		$this->assertContains( 'audit.api_secret', $preview['skipped'] );
		$this->assertContains( 'uninstall.remove_all_data', $preview['skipped'] );
		$this->assertContains( 'other.addon', $preview['unknown'], 'add-on not active on the target site' );
		$this->assertFalse( get_option( 'fc_audit_addon' ), 'preview does not save' );

		$export['extensions']['audit.days'] = 99;
		$res                                = SettingsTransfer::import( (string) wp_json_encode( $export ) );
		$this->assertArrayHasKey( 'ext.audit.days', $res['errors'], 'add-on check applies' );
		$this->assertFalse( get_option( 'fc_audit_addon' ) );

		$export['extensions']['audit.days'] = 22;
		SettingsTransfer::import( (string) wp_json_encode( $export ) );
		$stored = get_option( 'fc_audit_addon' );
		$this->assertSame( 22, $stored['audit.days'] );
		$this->assertArrayNotHasKey( 'audit.api_secret', $stored );
		$this->assertArrayNotHasKey( 'uninstall.remove_all_data', $stored );
		$log = array_column( ChangeLog::latest(), null, 'key' );
		$this->assertSame( 'import', $log['audit.days']['source'] );
	}

	public function test_cli_lists_gets_sets_and_resets_addon_values(): void {
		add_filter( 'form_captain_settings_fields_v1', static fn( $f ) => array_merge( (array) $f, self::addon_fields() ) );
		$cli  = $this->plugin()->get( \FormCaptain\Cli\Commands::class );
		$rows = array_column( $cli->settings_list(), null, 'key' );
		$this->assertSame( '7', $rows['audit.days']['value'] );
		$this->assertSame( 'int', $rows['audit.days']['type'] );
		$this->assertSame( '7', $cli->settings_get( 'audit.days' ) );

		$this->assertInstanceOf( \WP_Error::class, $cli->settings_set( 'audit.days', '31' ) );
		$this->assertSame( array( 7, 12 ), $cli->settings_set( 'audit.days', '12' ) );
		$this->assertSame( 12, get_option( 'fc_audit_addon' )['audit.days'] );
		$this->assertSame( 'cli', ChangeLog::latest()[0]['source'] );

		$cli->settings_set( 'audit.api_secret', 'xyz' );
		$this->assertSame( '••••••', $cli->settings_get( 'audit.api_secret' ) );
		$this->assertSame( '••••••', ChangeLog::latest()[0]['new'] );

		$this->assertInstanceOf( \WP_Error::class, $cli->settings_set( 'uninstall.remove_all_data', 'true' ), 'needs --yes' );
		$this->assertSame( array( false, true ), $cli->settings_set( 'uninstall.remove_all_data', 'true', true ) );
		$this->assertSame( array( true, true ), $cli->settings_set( 'uninstall.remove_all_data', 'true' ), 'already on' );
		$this->assertSame( array( true, false ), $cli->settings_set( 'uninstall.remove_all_data', 'false' ) );

		$this->assertTrue( $cli->settings_reset( 'general', false, true ) );
		$this->assertSame( 7, get_option( 'fc_audit_addon' )['audit.days'], 'reset includes the add-on values of the section' );
	}
}
