<?php
/**
 * Privacy exporter/eraser (P5-01, P5-02, P8-22).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Privacy;

use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\Settings;
use FormCaptain\Tests\Integration\TestCase;

final class PrivacyIntegrationTest extends TestCase {

	private function privacy(): PrivacyIntegration {
		return $this->plugin()->get( PrivacyIntegration::class );
	}

	private function entries(): EntryRepository {
		return $this->plugin()->get( EntryRepository::class );
	}

	/** @return array<string,mixed> */
	private function form(): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_pname1', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
				array( 'id' => 'f_pmail1', 'type' => 'email', 'key' => 'email', 'label' => 'Ihre E-Mail' ),
				array( 'id' => 'f_pgift1', 'type' => 'email', 'key' => 'gift', 'label' => 'E-Mail Beschenkte', 'privacy_lookup' => false ),
				array( 'id' => 'f_pnote1', 'type' => 'textarea', 'key' => 'note', 'label' => 'Nachricht' ),
				array(
					'id'      => 'f_ptopic',
					'type'    => 'select',
					'key'     => 'topic',
					'label'   => 'Thema',
					'options' => array( array( 'value' => 'demo', 'label' => 'Demo-Termin' ) ),
				),
				array( 'id' => 'f_pcons1', 'type' => 'consent', 'key' => 'consent', 'label' => 'Einwilligung', 'consent_text' => 'Ja' ),
			)
		);
	}

	private function entry( array $form, string $email, array $extra = array() ): int {
		$id = $this->entries()->insert(
			array_merge(
				array(
					'form_id'         => $form['id'],
					'form_version_id' => $form['published_version_id'],
					'request_key'     => hash( 'sha256', uniqid( '', true ) ),
					'source_url'      => 'https://example.org/kontakt',
					'consent'         => array( 'f_pcons1' => array( 'given' => true, 'revision' => 2 ) ),
				),
				$extra
			)
		);
		$this->entries()->insert_values(
			$id,
			array(
				'f_pname1' => 'Ada',
				'f_pmail1' => $email,
				'f_pgift1' => 'gift@example.org',
				'f_pnote1' => 'Bitte melden: ' . $email,
				'f_ptopic' => 'demo',
				'f_pcons1' => '1',
			)
		);
		return $id;
	}

	public function test_exporter_and_eraser_are_registered(): void {
		\FormCaptain\Settings\Settings::set_group( 'privacy', array( 'retention' => '12m' ) ); // S9: new installs default to manual; this test needs a period.
		$this->assertArrayHasKey( 'form-captain', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'form-captain', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
		$this->assertStringContainsString( '12 months', PrivacyIntegration::policy_text() );
	}

	public function test_export_is_readable_and_matches_only_lookup_fields(): void {
		$form  = $this->form();
		$mine  = $this->entry( $form, 'person@example.org' );
		$this->entry( $form, 'other@example.org' );
		$res = $this->privacy()->export( ' Person@Example.org ', 1 );
		$this->assertTrue( $res['done'] );
		$this->assertCount( 1, $res['data'] );
		$item = $res['data'][0];
		$this->assertSame( 'form-captain-entry-' . $mine, $item['item_id'] );
		$this->assertSame( 'form-captain-entries', $item['group_id'] );
		$pairs = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'Kontakt', $pairs['Form'] );
		$this->assertSame( 'person@example.org', $pairs['Ihre E-Mail'] );
		$this->assertSame( 'Demo-Termin', $pairs['Thema'], 'Option label instead of value' );
		$this->assertStringContainsString( 'revision 2', $pairs['Einwilligung'] );
		$this->assertSame( 'New', $pairs['Status'] );

		$this->assertSame( array(), $this->privacy()->export( 'gift@example.org' )['data'], 'privacy_lookup=false fields are not used' );
		$this->assertSame( array(), $this->privacy()->export( 'not-an-email' )['data'] );
		$this->assertTrue( $this->privacy()->export( 'not-an-email' )['done'] );
	}

	/** SET-FC-P4-02: storage period, reference number, withdrawal and extension values in the access export. */
	public function test_export_names_storage_period_withdrawal_and_extension_values(): void {
		$form    = $this->form();
		$kept    = $this->entry( $form, 'kept@example.org', array( 'entry_number' => 'K-2026-0007', 'expires_gmt' => '2027-11-23 10:00:00' ) );
		$this->entries()->update( $kept, array( 'consent_withdrawn_gmt' => '2026-11-20 09:30:00' ) );
		$pending = $this->entry( $form, 'pending@example.org', array( 'expires_gmt' => '2027-11-23 10:00:00', 'confirmation_status' => 'pending', 'unconfirmed_until_gmt' => '2026-11-30 10:00:00' ) );
		$manual  = $this->entry( $form, 'manual@example.org' );

		$pairs = array_column( $this->privacy()->export_item( $this->entries()->find( $kept ) ), 'value', 'name' );
		$this->assertSame( 'K-2026-0007', $pairs['Reference number'] );
		$this->assertSame( get_date_from_gmt( '2027-11-23 10:00:00', get_option( 'date_format' ) ), $pairs['Scheduled deletion'] );
		$this->assertArrayHasKey( 'Consent withdrawn', $pairs );

		$pairs = array_column( $this->privacy()->export_item( $this->entries()->find( $pending ) ), 'value', 'name' );
		$this->assertSame( get_date_from_gmt( '2026-11-30 10:00:00', get_option( 'date_format' ) ), $pairs['Scheduled deletion'], 'unconfirmed entries go earlier' );
		$this->assertArrayNotHasKey( 'Consent withdrawn', $pairs );

		$this->entries()->update( $manual, array( 'expires_gmt' => null ) );
		$pairs = array_column( $this->privacy()->export_item( $this->entries()->find( $manual ) ), 'value', 'name' );
		$this->assertStringContainsString( 'deleted manually', $pairs['Scheduled deletion'] );

		// Extension field types show their readable value (e.g. a file name), not the internal reference.
		$types = static fn( array $t ): array => $t + array( 'demo_file' => array( 'label' => 'Demo file' ) );
		$shown = static fn( $display, $field, $value ) => 'demo_file' === ( $field['type'] ?? '' ) ? 'lebenslauf.pdf (12 KB)' : $display;
		add_filter( 'form_captain_field_types_v1', $types );
		add_filter( 'form_captain_display_value_v1', $shown, 10, 3 );
		\FormCaptain\Domain\Fields\FieldTypes::reset();
		try {
			$upload_form = $this->create_published_form(
				array(
					array( 'id' => 'f_pmail1', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
					array( 'id' => 'f_pfile1', 'type' => 'demo_file', 'key' => 'cv', 'label' => 'Lebenslauf' ),
				)
			);
			$id = $this->entries()->insert(
				array(
					'form_id'         => $upload_form['id'],
					'form_version_id' => $upload_form['published_version_id'],
					'request_key'     => hash( 'sha256', uniqid( '', true ) ),
				)
			);
			$this->entries()->insert_values( $id, array( 'f_pmail1' => 'file@example.org', 'f_pfile1' => 'upload:0123456789abcdef0123456789abcdef' ) );
			$pairs = array_column( $this->privacy()->export_item( $this->entries()->find( $id ) ), 'value', 'name' );
			$this->assertSame( 'lebenslauf.pdf (12 KB)', $pairs['Lebenslauf'] );
		} finally {
			remove_filter( 'form_captain_field_types_v1', $types );
			remove_filter( 'form_captain_display_value_v1', $shown, 10 );
			\FormCaptain\Domain\Fields\FieldTypes::reset();
		}
	}

	public function test_export_matches_logged_in_user_and_paginates(): void {
		$form = $this->form();
		$user = self::factory()->user->create( array( 'user_email' => 'member@example.org' ) );
		for ( $i = 0; $i < 51; $i++ ) {
			$this->entry( $form, 'x' . $i . '@example.org', array( 'user_id' => $user ) );
		}
		$p1 = $this->privacy()->export( 'member@example.org', 1 );
		$this->assertCount( 50, $p1['data'] );
		$this->assertFalse( $p1['done'] );
		$p2 = $this->privacy()->export( 'member@example.org', 2 );
		$this->assertCount( 1, $p2['data'] );
		$this->assertTrue( $p2['done'] );
	}

	public function test_eraser_delete_mode(): void {
		Settings::set_group( 'privacy', array( 'erasure_mode' => 'delete' ) );
		$form    = $this->form();
		$id      = $this->entry( $form, 'erase@example.org' );
		$keep    = $this->entry( $form, 'keep@example.org' );
		$job     = $this->plugin()->get( JobRepository::class )->enqueue( 'mail.admin', 'test:' . $id, array(), $id, $form['id'] );
		$reasons = array();
		add_action( 'form_captain_entry_deleted_v1', static function ( $eid, $fid, $reason ) use ( &$reasons ) {
			$reasons[ $eid ] = $reason;
		}, 10, 3 );
		$res = $this->privacy()->erase( 'erase@example.org', 1 );
		$this->assertTrue( $res['items_removed'] );
		$this->assertFalse( $res['items_retained'] );
		$this->assertTrue( $res['done'] );
		$this->assertNull( $this->entries()->find( $id ) );
		$this->assertSame( array(), $this->entries()->values_for( $id ) );
		$this->assertNotNull( $this->entries()->find( $keep ) );
		$this->assertSame( 'erasure', $reasons[ $id ] );
		$this->assertSame( 'cancelled', $this->plugin()->get( JobRepository::class )->find( $job )['status'] );
	}

	public function test_eraser_anonymize_mode(): void {
		Settings::set_group( 'privacy', array( 'erasure_mode' => 'anonymize' ) );
		$form = $this->form();
		$id   = $this->entry( $form, 'anon@example.org', array( 'user_id' => 1 ) );
		$this->entries()->add_note( $id, 1, 'Called anon@example.org' );
		$job     = $this->plugin()->get( JobRepository::class )->enqueue( 'connector.action', 'test:anon:' . $id, array( 'action_key' => 'x.y' ), $id, $form['id'] );
		$reasons = array();
		add_action( 'form_captain_entry_deleted_v1', static function ( $eid, $fid, $reason ) use ( &$reasons ) {
			$reasons[ $eid ] = $reason;
		}, 10, 3 );
		$res = $this->privacy()->erase( 'anon@example.org', 1 );
		$this->assertTrue( $res['items_removed'] );
		$this->assertStringContainsString( 'anonymized', implode( ' ', $res['messages'] ) );

		$entry  = $this->entries()->find( $id );
		$values = $this->entries()->values_for( $id );
		$this->assertNotNull( $entry['anonymized_gmt'] );
		$this->assertNull( $entry['user_id'] );
		$this->assertNull( $entry['source_url'] );
		$this->assertSame( '[deleted]', $values['f_pmail1'] );
		$this->assertSame( '[deleted]', $values['f_pname1'] );
		$this->assertSame( '[deleted]', $values['f_pnote1'] );
		$this->assertSame( 'demo', $values['f_ptopic'], 'Choices are kept' );
		$this->assertSame( '1', $values['f_pcons1'], 'Consent value is kept' );
		$this->assertTrue( $entry['consent']['f_pcons1']['given'], 'Consent proof is kept' );
		$this->assertSame( array(), $this->entries()->notes_for( $id ) );
		$this->assertSame( 'cancelled', $this->plugin()->get( JobRepository::class )->find( $job )['status'] );
		$this->assertSame( 'anonymized', $reasons[ $id ] );
		$this->assertSame( array(), $this->privacy()->export( 'anon@example.org' )['data'], 'Not found again' );
		$this->assertFalse( $this->privacy()->erase( 'anon@example.org' )['items_removed'] );
	}
}
