<?php
/**
 * Settings screen for keyboard and screen reader users (SET-FC-P5): every control has a name,
 * ids are unique, descriptions exist, the error list links to the controls and gets the focus.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Settings;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\TestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API (nodeName).
final class SettingsAccessibilityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		Settings::ensure_defaults();
		$this->as_admin();
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_settings_fields_v1' );
		delete_transient( 'form_captain_settings_notice_' . get_current_user_id() );
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	private function page(): SettingsPage {
		return $this->plugin()->get( SettingsPage::class );
	}

	private function render_tab( string $tab ): \DOMXPath {
		$_GET['tab'] = $tab;
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		$doc  = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		return new \DOMXPath( $doc );
	}

	/** @return array<string, array{0: string}> */
	public static function tabs(): array {
		$out = array();
		foreach ( array_keys( SettingsSchema::tab_labels() ) as $tab ) {
			$out[ $tab ] = array( $tab );
		}
		return $out;
	}

	/**
	 * Ids are unique and every setting control has a name and existing descriptions. Keys without
	 * "_" (radius, blocklist, honeypot …) used to give the row and the control the same id, so the
	 * label pointed at the row and the control had no name.
	 *
	 * @dataProvider tabs
	 */
	public function test_controls_have_names_unique_ids_and_descriptions( string $tab ): void {
		add_filter(
			'form_captain_settings_fields_v1',
			static fn( array $fields ): array => array_merge(
				$fields,
				array(
					array(
						'id'      => 'uploads.max',
						'tab'     => $tab,
						'type'    => 'int',
						'min'     => 1,
						'max'     => 9,
						'default' => 3,
						'label'   => 'Add-on field',
						'help'    => 'Add-on help.',
						'get'     => static fn(): int => 3,
						'set'     => static function (): void {},
					),
				)
			)
		);
		$x   = $this->render_tab( $tab );
		$ids = array();
		foreach ( $x->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		$dupes = array_keys( array_filter( array_count_values( $ids ), static fn( int $n ): bool => $n > 1 ) );
		$dupes = array_values( array_filter( $dupes, static fn( string $id ): bool => '_wpnonce' !== $id ) );
		$this->assertSame( array(), $dupes, "$tab: duplicate ids" );

		foreach ( $x->query( '//label[@for]' ) as $label ) {
			$target = $x->query( '//*[@id="' . $label->getAttribute( 'for' ) . '"]' )->item( 0 );
			$this->assertNotNull( $target, "$tab: label for missing id " . $label->getAttribute( 'for' ) );
			$this->assertContains( $target->nodeName, array( 'input', 'select', 'textarea' ), "$tab: label must point to a control, not " . $target->nodeName );
		}

		$controls = $x->query( '//table[contains(@class,"form-table")]//*[self::input[not(@type="hidden")] or self::select or self::textarea]' );
		foreach ( $controls as $control ) {
			$id    = $control->getAttribute( 'id' );
			$named = '' !== $id && $x->query( '//label[@for="' . $id . '"]' )->length > 0;
			$named = $named || $x->query( 'ancestor::label', $control )->length > 0;
			$named = $named || $x->query( 'ancestor::fieldset/legend', $control )->length > 0;
			$named = $named || '' !== $control->getAttribute( 'aria-label' );
			$this->assertTrue( $named, "$tab: control without a name: " . $control->getAttribute( 'name' ) );
			foreach ( array_filter( explode( ' ', $control->getAttribute( 'aria-describedby' ) ) ) as $ref ) {
				$this->assertSame( 1, $x->query( '//*[@id="' . $ref . '"]' )->length, "$tab: aria-describedby $ref missing" );
			}
		}
		if ( ! in_array( $tab, array( 'access', 'status' ), true ) ) {
			$this->assertSame( 1, $x->query( '//label[@for="fc-ext-uploads-max"]' )->length, 'Add-on field is labelled' );
		}
	}

	public function test_error_list_links_to_controls_and_takes_focus(): void {
		$result = $this->page()->save_tab(
			'appearance',
			array(
				'radius'  => '99',
				'spacing' => '-4',
			)
		);
		$this->assertSame( 'error', $result['type'] );
		set_transient( 'form_captain_settings_notice_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		$x       = $this->render_tab( 'appearance' );
		$summary = $x->query( '//div[contains(@class,"fc-error-summary")]' )->item( 0 );
		$this->assertNotNull( $summary );
		$this->assertSame( '-1', $summary->getAttribute( 'tabindex' ) );
		$this->assertSame( 'alert', $summary->getAttribute( 'role' ) );
		$links = $x->query( './/ul[@class="fc-error-list"]//a', $summary );
		$this->assertSame( 2, $links->length );
		foreach ( $links as $link ) {
			$id      = substr( $link->getAttribute( 'href' ), 1 );
			$control = $x->query( '//*[@id="' . $id . '"]' )->item( 0 );
			$this->assertNotNull( $control, "error link #$id has no target" );
			$this->assertSame( 'input', $control->nodeName, 'the error list jumps to the field itself' );
			$this->assertSame( 'true', $control->getAttribute( 'aria-invalid' ) );
			$this->assertStringContainsString( $id . '-error', $control->getAttribute( 'aria-describedby' ) );
		}
	}
}
