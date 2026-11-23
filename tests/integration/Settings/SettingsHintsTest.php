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

	private function register_addon_field( string $id = 'draft_lifetime' ): void {
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
		$this->assertStringContainsString( 'data-fc-hint="draft_lifetime"', $html );
		preg_match( '#<section class="fc-pro-hints".*?</section>#s', $html, $m );
		$this->assertNotEmpty( $m );
		$this->assertDoesNotMatchRegularExpression( '#<(input|select|textarea|button)\b#i', $m[0], 'No input element in hint rows' );
		$this->assertStringNotContainsString( 'disabled', $m[0] );
		foreach ( SettingsSchema::pro_hints() as $hint ) {
			$this->assertContains( $hint['tab'], SettingsSchema::GROUPS );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $hint['id'] );
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
		$this->assertArrayHasKey( 'draft_lifetime', SettingsSchema::extension_fields() );
		$this->assertArrayNotHasKey( 'broken', SettingsSchema::extension_fields(), 'Fields without callbacks are ignored' );
		$ids = array_column( SettingsSchema::visible_pro_hints( 'general' ), 'id' );
		$this->assertNotContains( 'draft_lifetime', $ids );
		$this->assertContains( 'upload_defaults', $ids );

		$html = $this->render_tab( 'general' );
		$this->assertStringNotContainsString( 'data-fc-hint="draft_lifetime"', $html );
		$this->assertStringContainsString( 'name="settings[ext][draft_lifetime]"', $html );
		$this->assertStringContainsString( 'value="7"', $html );

		$res = $this->page()->save_tab( 'general', array( 'show_pro_hints' => '1', 'ext' => array( 'draft_lifetime' => '99' ) ) );
		$this->assertSame( 'error', $res['type'] );
		$this->assertArrayHasKey( 'ext.draft_lifetime', $res['errors'] );
		$this->assertSame( 7, $this->addon_value );
		$res = $this->page()->save_tab( 'general', array( 'show_pro_hints' => '1', 'ext' => array( 'draft_lifetime' => '13' ) ) );
		$this->assertSame( 'Unlucky number.', $res['errors']['ext.draft_lifetime'] );
		$res = $this->page()->save_tab( 'general', array( 'show_pro_hints' => '1', 'ext' => array( 'draft_lifetime' => '14' ) ) );
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
}
