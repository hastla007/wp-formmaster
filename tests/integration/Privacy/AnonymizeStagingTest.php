<?php
/**
 * bin/anonymize-staging.php replaces personal data and keeps logic-relevant values (P9-13).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace FormCaptain\Tests\Integration\Privacy;

use FormCaptain\Database\Tables;
use FormCaptain\Tests\Integration\TestCase;

require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';

final class AnonymizeStagingTest extends TestCase {

	use \FormCaptain\Tests\Integration\Entries\EntryFixtures;

	/** @param string[] $args */
	private function run_script( array $args ): string {
		ob_start();
		( static function ( array $args ): void {
			require dirname( __DIR__, 3 ) . '/bin/anonymize-staging.php';
		} )( $args );
		return (string) ob_get_clean();
	}

	public function test_requires_confirmation_and_refuses_production(): void {
		$form  = $this->create_published_form();
		$entry = $this->make_entry( $form );
		$this->assertSame( '', $this->run_script( array() ) );
		$this->assertSame( '', $this->run_script( array( 'yes' ) ), 'test site counts as production' );
		$this->assertSame( 'max@example.org', $this->entries()->values_for( $entry )['f_mail01'] ?? null );
	}

	public function test_replaces_personal_values_and_keeps_choices(): void {
		$form  = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
				array( 'id' => 'f_tel001', 'type' => 'phone', 'key' => 'phone', 'label' => 'Telefon' ),
				array( 'id' => 'f_topic1', 'type' => 'select', 'key' => 'topic', 'label' => 'Thema', 'options' => array( array( 'value' => 'offer', 'label' => 'Angebot' ) ) ),
			)
		);
		$entry = $this->make_entry( $form, array( 'f_name01' => 'Erika Mustermann', 'f_mail01' => 'erika@example.org', 'f_tel001' => '+43 660 1234567', 'f_topic1' => 'offer' ) );
		$this->entries_service_note( $entry );

		$out = $this->run_script( array( 'yes', 'not-production' ) );
		$this->assertStringContainsString( 'values_replaced: 3', $out );
		$this->assertStringNotContainsString( 'erika', strtolower( $out ), 'only counts are printed' );

		$values = $this->entries()->values_for( $entry );
		$this->assertStringEndsWith( '@example.invalid', $values['f_mail01'] );
		$this->assertStringNotContainsString( 'Erika', $values['f_name01'] );
		$this->assertSame( mb_strlen( 'Erika Mustermann' ), mb_strlen( $values['f_name01'] ), 'length kept' );
		$this->assertStringStartsWith( '+00 000 ', $values['f_tel001'] );
		$this->assertSame( 'offer', $values['f_topic1'] );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::ENTRY_NOTES ) . " WHERE message LIKE '%Rückruf%'" ) );
	}

	private function entries_service_note( int $entry ): void {
		global $wpdb;
		$wpdb->insert( Tables::name( Tables::ENTRY_NOTES ), array( 'entry_id' => $entry, 'user_id' => 1, 'type' => 'note', 'message' => 'Rückruf bei Erika', 'created_gmt' => '2026-01-01 00:00:00' ) );
	}
}
