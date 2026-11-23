<?php
/**
 * Pro hint rows, add-on settings fields and help texts on the settings screen (S1, SET-FC-06, SET-FC-22).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\TestCase;

final class SettingsHintsTest extends TestCase {

	/** @var mixed */
	private $addon_value = 7;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		$this->addon_value = 7;
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_settings_fields_v1' );
		delete_option( 'fc_test_addon_settings' );
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	private function render_tab( string $tab ): string {
		$this->as_admin();
		$_GET['tab'] = $tab;
		ob_start();
		$this->page()->render();
		return (string) ob_get_clean();
	}

	private function register_addon_field( string $id = 'multistep.draft_days' ): void {
		add_filter(
			'form_captain_settings_fields_v1',
			function ( array $fields ) use ( $id ): array {
				$fields[] = array(
					'id'       => $id,
					'tab'      => 'general',
					'type'     => 'int',
					'min'      => 1,
					'max'      => 30,
					'default'  => 7,
					'label'    => 'Keep drafts (days)',
					'help'     => 'Add-on help.',
					'get'      => fn() => $this->addon_value,
					'set'      => function ( $v ): void {
						$this->addon_value = $v;
					},
					'validate' => static fn( $v ) => 13 === $v ? 'Unlucky number.' : null,
				);
				$fields[] = array( 'id' => 'broken', 'tab' => 'general', 'type' => 'int', 'label' => 'No callbacks' );
				return $fields;
			}
		);
	}

	public function test_hint_rows_are_text_only_without_input_and_without_entitlement_names(): void {
		$html = $this->render_tab( 'general' );
		$this->assertStringContainsString( 'class="fc-pro-hints"', $html );
		$this->assertStringContainsString( 'data-fc-hint="multistep.draft_days"', $html );
		preg_match( '#<section class="fc-pro-hints".*?</section>#s', $html, $m );
		$this->assertNotEmpty( $m );
		$this->assertDoesNotMatchRegularExpression( '#<(input|select|textarea|button)\b#i', $m[0], 'No input element in hint rows' );
		$this->assertStringNotContainsString( 'disabled', $m[0] );
		foreach ( SettingsSchema::pro_hints() as $hint ) {
			$this->assertContains( $hint['tab'], SettingsSchema::GROUPS );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_.-]+$/', $hint['id'] );
			$this->assertDoesNotMatchRegularExpression( '/\\bform\\.[a-z]/', $hint['id'] . ' ' . $hint['title'] . ' ' . $hint['text'], 'No entitlement key names in the core' );
			$this->assertSame( array( 'id', 'tab', 'title', 'text', 'link' ), array_keys( $hint ) );
		}
		// The hints come after the save form, at the end of the tab.
		$this->assertGreaterThan( strpos( $html, 'fc-settings-form' ), strpos( $html, 'fc-pro-hints' ) );
	}

	/**
	 * Guard (S1): the core names no entitlement keys and the settings code knows nothing about licences.
	 */
	public function test_core_contains_no_entitlement_names_and_settings_no_licence_code(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( FORM_CAPTAIN_DIR . 'src' ) );
		foreach ( $it as $php ) {
			if ( ! $php->isFile() || 'php' !== $php->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $php->getPathname() );
			$this->assertDoesNotMatchRegularExpression( '/[\'"]form\.(uploads|advanced_logic|multi_step|webhooks|pdf|woocommerce_questionnaires|translations|email_designer)[\'"]/', $code, $php->getPathname() );
		}
		foreach ( array( 'src/Settings/SettingsSchema.php', 'src/Admin/SettingsPage.php', 'src/Settings/Settings.php' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/licen[cs]|entitlement|premium|is_pro\b|pro_active/i', (string) file_get_contents( FORM_CAPTAIN_DIR . $file ), $file );
		}
	}

	public function test_hints_off_shows_no_rows(): void {
		Settings::set_group( 'general', array( 'show_pro_hints' => false ) );
		$this->assertSame( array(), SettingsSchema::visible_pro_hints( 'general' ) );
		$this->assertStringNotContainsString( 'fc-pro-hints', $this->render_tab( 'general' ) );
	}

	public function test_registered_field_replaces_the_hint_row_and_is_saved_by_the_addon(): void {
		$this->register_addon_field();
		$this->assertArrayHasKey( 'multistep.draft_days', SettingsSchema::extension_fields() );
		$this->assertArrayNotHasKey( 'broken', SettingsSchema::extension_fields(), 'Fields without callbacks are ignored' );
		$ids = array_column( SettingsSchema::visible_pro_hints( 'general' ), 'id' );
		$this->assertNotContains( 'multistep.draft_days', $ids );
		$this->assertContains( 'uploads.types', $ids );

		$html = $this->render_tab( 'general' );
		$this->assertStringNotContainsString( 'data-fc-hint="multistep.draft_days"', $html );
		$this->assertStringContainsString( 'name="settings[ext][multistep.draft_days]"', $html );
		$this->assertStringContainsString( 'value="7"', $html );

		$res = $this->page()->save_tab( 'general', array( 'show_pro_hints' => '1', 'ext' => array( 'multistep.draft_days' => '99' ) ) );
		$this->assertSame( 'error', $res['type'] );
		$this->assertArrayHasKey( 'ext.multistep.draft_days', $res['errors'] );
		$this->assertSame( 7, $this->addon_value );
		$res = $this->page()->save_tab( 'general', array( 'show_pro_hints' => '1', 'ext' => array( 'multistep.draft_days' => '13' ) ) );
		$this->assertSame( 'Unlucky number.', $res['errors']['ext.multistep.draft_days'] );
		$res = $this->page()->save_tab( 'general', array( 'show_pro_hints' => '1', 'ext' => array( 'multistep.draft_days' => '14' ) ) );
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( 14, $this->addon_value );
		$this->page()->reset_tab( 'general' );
		$this->assertSame( 7, $this->addon_value, 'Reset uses the declared default' );
	}

	public function test_every_visible_setting_has_help_linked_by_aria_describedby(): void {
		foreach ( SettingsSchema::definitions() as $path => $def ) {
			if ( empty( $def['internal'] ) ) {
				$this->assertNotSame( '', trim( (string) ( $def['help'] ?? '' ) ), "$path has no help text" );
			}
		}
		$html = $this->render_tab( 'spam' );
		$this->assertMatchesRegularExpression( '#id="fc-spam-min-seconds"[^>]*aria-describedby="fc-spam-min-seconds-help"#', $html );
		$this->assertStringContainsString( 'id="fc-spam-min-seconds-help"', $html );
	}

	/**
	 * Fields in the storage form (as Form Captain Pro sends them): the core checks with the add-on's
	 * sanitize callback and writes into the add-on's option.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function storage_fields(): array {
		$stored  = get_option( 'fc_test_addon_settings', array() );
		$storage = static fn( string $key ): array => array(
			'option' => 'fc_test_addon_settings',
			'key'    => $key,
		);
		return array(
			array(
				'id'       => 'uploads.types',
				'owner'    => 'test-addon',
				'tab'      => 'uploads',
				'label'    => 'Allowed file types',
				'help'     => 'Comma separated.',
				'type'     => 'text',
				'default'  => 'pdf,jpg',
				'value'    => $stored['uploads.types'] ?? 'pdf,jpg',
				'storage'  => $storage( 'uploads.types' ),
				'sanitize' => static function ( $raw, $submitted = array() ) {
					unset( $submitted );
					$raw = strtolower( trim( (string) $raw ) );
					return preg_match( '/^[a-z0-9]+(,[a-z0-9]+)*$/', $raw ) ? $raw : new \WP_Error( 'bad_types', 'Use file extensions such as pdf,jpg.' );
				},
			),
			array(
				'id'      => 'uploads.max_files',
				'owner'   => 'test-addon',
				'tab'     => 'uploads',
				'label'   => 'Files per field',
				'help'    => '1 to 10.',
				'type'    => 'number',
				'min'     => 1,
				'max'     => 10,
				'default' => 1,
				'value'   => $stored['uploads.max_files'] ?? 1,
				'storage' => $storage( 'uploads.max_files' ),
			),
			array(
				'id'        => 'woo.release_statuses',
				'tab'       => 'woocommerce',
				'tab_label' => 'WooCommerce',
				'label'   => 'Release with status',
				'help'    => 'Order statuses.',
				'type'    => 'multiselect',
				'options' => array(
					'processing' => 'Processing',
					'completed'  => 'Completed',
				),
				'default' => array( 'completed' ),
				'storage' => $storage( 'woo.release_statuses' ),
			),
			array(
				'id'      => 'uninstall.remove_all_data',
				'tab'     => 'privacy',
				'label'   => 'Delete add-on data on uninstall',
				'help'    => 'Needs the confirmation word.',
				'type'    => 'confirm',
				'default' => false,
				'storage' => $storage( 'uninstall.remove_all_data' ),
			),
			array(
				'id'      => 'evil.core_option',
				'tab'     => 'general',
				'label'   => 'Writes the core option',
				'type'    => 'checkbox',
				'storage' => array(
					'option' => Settings::OPTION,
					'key'    => 'general',
				),
			),
		);
	}

	public function test_storage_form_as_list_creates_tabs_saves_into_the_addon_option_and_hides_hints(): void {
		add_filter( 'form_captain_settings_fields_v1', static fn( $fields ) => array_merge( (array) $fields, self::storage_fields() ) );
		$fields = SettingsSchema::extension_fields();
		$this->assertArrayHasKey( 'uploads.types', $fields );
		$this->assertSame( 'string', $fields['uploads.types']['type'], 'storage type "text" is one line' );
		$this->assertSame( 'int', $fields['uploads.max_files']['type'] );
		$this->assertArrayNotHasKey( 'evil.core_option', $fields, 'the core option is never an add-on storage' );

		$this->assertSame( array( 'uploads' => 'Uploads', 'woocommerce' => 'WooCommerce' ), SettingsSchema::extension_tabs() );
		$labels = SettingsSchema::tab_labels();
		$this->assertSame( array( 'general', 'appearance', 'email', 'privacy', 'spam', 'export', 'uploads', 'woocommerce', 'access', 'status' ), array_keys( $labels ) );
		$this->assertNotContains( 'uploads.types', array_column( SettingsSchema::visible_pro_hints( 'general' ), 'id' ), 'hint row disappears with the field' );
		$this->assertContains( 'webhooks', array_column( SettingsSchema::visible_pro_hints( 'general' ), 'id' ) );

		$html = $this->render_tab( 'uploads' );
		$this->assertStringContainsString( 'tab=uploads', $html );
		$this->assertStringContainsString( 'name="settings[ext][uploads.types]"', $html );
		$this->assertStringContainsString( 'value="pdf,jpg"', $html );
		$this->assertStringNotContainsString( 'name="settings[ext][woo.release_statuses]', $html, 'only fields of this tab' );

		$res = $this->page()->save_tab( 'uploads', array( 'ext' => array( 'uploads.types' => 'p d f', 'uploads.max_files' => '3' ) ) );
		$this->assertSame( 'error', $res['type'] );
		$this->assertSame( 'Use file extensions such as pdf,jpg.', $res['errors']['ext.uploads.types'] );
		$this->assertSame( 3, get_option( 'fc_test_addon_settings' )['uploads.max_files'], 'valid values of the tab are saved' );
		$res = $this->page()->save_tab( 'uploads', array( 'ext' => array( 'uploads.types' => 'PDF,png', 'uploads.max_files' => '11' ) ) );
		$this->assertArrayHasKey( 'ext.uploads.max_files', $res['errors'], 'core range check without sanitize callback' );
		$this->assertSame( 'pdf,png', get_option( 'fc_test_addon_settings' )['uploads.types'] );

		$this->page()->save_tab( 'woocommerce', array( 'ext' => array( 'woo.release_statuses' => array( 'processing', 'completed' ) ) ) );
		$this->assertSame( array( 'processing', 'completed' ), get_option( 'fc_test_addon_settings' )['woo.release_statuses'] );
		$res = $this->page()->save_tab( 'woocommerce', array( 'ext' => array( 'woo.release_statuses' => array( 'cancelled' ) ) ) );
		$this->assertArrayHasKey( 'ext.woo.release_statuses', $res['errors'] );

		$this->page()->reset_tab( 'uploads' );
		$this->assertSame( 'pdf,jpg', get_option( 'fc_test_addon_settings' )['uploads.types'] );
		$this->assertSame( 1, get_option( 'fc_test_addon_settings' )['uploads.max_files'] );
		$this->assertTrue( Settings::get( 'general.show_pro_hints' ), 'core values untouched' );
	}

	public function test_storage_form_as_tab_map_and_confirm_type(): void {
		add_filter(
			'form_captain_settings_fields_v1',
			static function ( $fields ) {
				$fields = (array) $fields;
				foreach ( self::storage_fields() as $field ) {
					$tab = $field['tab'];
					unset( $field['tab'] ); // the map key names the tab
					$fields[ $tab ][] = $field;
				}
				return $fields;
			}
		);
		$fields = SettingsSchema::extension_fields();
		$this->assertSame( 'uploads', $fields['uploads.types']['tab'] );
		$this->assertSame( 'confirm', $fields['uninstall.remove_all_data']['type'] );

		$html = $this->render_tab( 'privacy' );
		$this->assertStringContainsString( 'name="settings[ext][uninstall.remove_all_data_confirm]"', $html );

		$res = $this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => '1' ) ) );
		$this->assertArrayHasKey( 'ext.uninstall.remove_all_data', $res['errors'], 'box without the word is refused' );
		$this->assertFalse( (bool) ( get_option( 'fc_test_addon_settings', array() )['uninstall.remove_all_data'] ?? false ) );
		$this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => '1', 'uninstall.remove_all_data_confirm' => 'löschen' ) ) );
		$this->assertTrue( get_option( 'fc_test_addon_settings' )['uninstall.remove_all_data'] );
		$this->page()->save_tab( 'privacy', array( 'ext' => array() ) );
		$this->assertFalse( get_option( 'fc_test_addon_settings' )['uninstall.remove_all_data'], 'unchecked box switches off' );
		$this->page()->save_tab( 'privacy', array( 'ext' => array( 'uninstall.remove_all_data' => 'DELETE' ) ) );
		$this->assertTrue( get_option( 'fc_test_addon_settings' )['uninstall.remove_all_data'], 'the word as value' );
	}

	public function test_extension_tab_without_fields_does_not_exist(): void {
		$this->assertFalse( SettingsSchema::is_tab( 'uploads' ) );
		$this->assertArrayNotHasKey( 'uploads', SettingsSchema::tab_labels() );
		$this->assertSame( 'error', $this->page()->save_tab( 'uploads', array( 'ext' => array( 'uploads.types' => 'pdf' ) ) )['type'] );
		add_filter(
			'form_captain_settings_fields_v1',
			static fn() => array(
				array(
					'id'        => 'webhooks.timeout',
					'tab'       => 'integrations',
					'tab_label' => 'Webhooks & more',
					'label'     => 'Timeout',
					'type'      => 'number',
					'min'       => 1,
					'max'       => 30,
					'default'   => 10,
					'storage'   => array(
						'option' => 'fc_test_addon_settings',
						'key'    => 'webhooks.timeout',
					),
				),
				array( 'id' => 'x.status', 'tab' => 'status', 'label' => 'Reserved tab', 'type' => 'checkbox', 'storage' => array( 'option' => 'fc_test_addon_settings', 'key' => 'x' ) ),
			)
		);
		$this->assertSame( array( 'integrations' => 'Webhooks & more' ), SettingsSchema::extension_tabs() );
		$this->assertArrayNotHasKey( 'x.status', SettingsSchema::extension_fields() );
		$this->assertNotContains( 'webhooks.timeout', array_column( SettingsSchema::visible_pro_hints( 'general' ), 'id' ) );
	}
}
