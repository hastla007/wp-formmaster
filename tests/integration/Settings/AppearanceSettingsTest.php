<?php
/**
 * Appearance settings (SET-FC-32): corner radius, spacing and font size of the base styles,
 * custom CSS only for users with unfiltered_html.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Frontend\Assets;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\TestCase;

final class AppearanceSettingsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Assets::reset();
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['wp_scripts'] = null;
	}

	private function inline_css(): string {
		return implode( "\n", (array) wp_styles()->get_data( Assets::HANDLE, 'after' ) );
	}

	/** @param array<string,mixed> $values */
	private function appearance( array $values ): void {
		Settings::set_group( 'appearance', array_merge( Settings::all()['appearance'], $values ) );
	}

	public function test_defaults_keep_the_previous_output(): void {
		$this->assertSame( 4, Settings::get( 'appearance.radius' ) );
		$this->assertSame( 16, Settings::get( 'appearance.spacing' ) );
		$this->assertSame( 'theme', Settings::get( 'appearance.font_size' ) );
		$this->assertSame( '', Settings::get( 'appearance.custom_css' ) );
		$this->assertSame( '.fc-mode-base{--fc-accent:#2271b1;}', Assets::base_css() );
		Assets::enqueue( 'inherit' );
		$this->assertSame( '', $this->inline_css(), 'nothing extra for theme styling' );
	}

	public function test_base_style_variables(): void {
		$this->appearance(
			array(
				'radius'    => 0,
				'spacing'   => 24,
				'font_size' => '18',
			)
		);
		$this->assertSame( '.fc-mode-base{--fc-accent:#2271b1;--fc-radius:0px;--fc-gap:24px;font-size:18px;}', Assets::base_css() );
		Assets::enqueue( 'base' );
		Assets::enqueue( 'base' );
		$this->assertSame( 1, substr_count( $this->inline_css(), '--fc-gap:24px' ), 'added once per page' );
	}

	public function test_ranges_are_checked(): void {
		list( , $errors ) = SettingsSchema::sanitize_group(
			'appearance',
			array(
				'radius'    => '25',
				'spacing'   => '-1',
				'font_size' => '30',
			)
		);
		$this->assertArrayHasKey( 'appearance.radius', $errors );
		$this->assertArrayHasKey( 'appearance.spacing', $errors );
		$this->assertArrayHasKey( 'appearance.font_size', $errors );
	}

	public function test_custom_css_needs_unfiltered_html_and_is_checked(): void {
		$this->as_admin();
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$page = $this->plugin()->get( SettingsPage::class );
		$css  = ".fc-form-wrap .fc-submit {\n\ttext-transform: uppercase;\n}\n.fc-option > label { color: #333; }";
		$res  = $page->save_tab( 'appearance', array_merge( Settings::all()['appearance'], array( 'custom_css' => $css ) ) );
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( $css, Settings::get( 'appearance.custom_css' ), 'line breaks and > are kept' );

		Assets::enqueue( 'inherit' );
		$this->assertStringContainsString( 'text-transform: uppercase', $this->inline_css(), 'loaded for theme styling too' );

		foreach ( array( '</style><script>alert(1)</script>', '@import url(https://fonts.example.com/x.css);' ) as $bad ) {
			$res = $page->save_tab( 'appearance', array_merge( Settings::all()['appearance'], array( 'custom_css' => $bad ) ) );
			$this->assertSame( 'error', $res['type'], $bad );
			$this->assertArrayHasKey( 'appearance.custom_css', $res['errors'] );
			$this->assertSame( $css, Settings::get( 'appearance.custom_css' ) );
		}
	}

	public function test_users_without_unfiltered_html_cannot_change_custom_css(): void {
		$this->appearance( array( 'custom_css' => '.fc-form{color:red}' ) );
		$id = $this->as_admin();
		$cb = static function ( array $caps, string $cap ): array {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $cb, 10, 2 );
		try {
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
			$res = $this->plugin()->get( SettingsPage::class )->save_tab( 'appearance', array_merge( Settings::all()['appearance'], array( 'custom_css' => '.x{}', 'radius' => '8' ) ) );
			$this->assertSame( 'You are not allowed to change this setting.', $res['errors']['appearance.custom_css'] );
			$this->assertSame( '.fc-form{color:red}', Settings::get( 'appearance.custom_css' ), 'unchanged' );
			$this->assertSame( 8, Settings::get( 'appearance.radius' ), 'other values are saved' );

			$_GET['tab'] = 'appearance';
			ob_start();
			$this->plugin()->get( SettingsPage::class )->render();
			$html = (string) ob_get_clean();
		} finally {
			unset( $_GET['tab'] );
			remove_filter( 'map_meta_cap', $cb, 10 );
		}
		$this->assertMatchesRegularExpression( '/<textarea[^>]*id="fc-appearance-custom-css" readonly maxlength/', $html, 'read-only, not submitted' );
		$this->assertStringNotContainsString( 'name="settings[custom_css]"', $html );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_stored_css_can_never_end_the_style_element(): void {
		$this->appearance( array( 'custom_css' => 'a{}</style><script>x</script>' ) );
		$this->assertStringNotContainsString( '<', Assets::custom_css() );
	}
}
