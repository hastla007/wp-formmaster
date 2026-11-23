<?php
/**
 * Reference numbers (SET-FC-29): global format with {year}/{number}, padding, yearly restart,
 * placeholder {entry_number} in mails and the success message, column migration.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Database\Migrator;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Service\EntryNumbers;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\Frontend\FrontendTestCase;

require_once dirname( __DIR__ ) . '/Frontend/FrontendTestCase.php';
require_once __DIR__ . '/EntryFixtures.php';

final class EntryNumbersTest extends FrontendTestCase {

	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		update_option( 'admin_email', 'site-admin@example.org' );
		$this->reset_mailer();
	}

	/** @param array<string,mixed> $values */
	private function numbering( array $values ): void {
		Settings::set_group( 'general', array_merge( Settings::all()['general'], $values ) );
	}

	private function submit( array $form, string $name ): array {
		return $this->plugin()->get( SubmissionService::class )->submit( $form, $this->payload( $form, array( 'f_name01' => $name, 'f_mail01' => strtolower( $name ) . '@example.org' ) ) );
	}

	public function test_format_validation(): void {
		$this->assertNull( EntryNumbers::validate_format( '' ) );
		$this->assertNull( EntryNumbers::validate_format( 'REQ-{year}-{number}' ) );
		$this->assertNull( EntryNumbers::validate_format( '#{number}/{year}' ) );
		$this->assertNotNull( EntryNumbers::validate_format( 'REQ-{year}' ), '{number} is required' );
		$this->assertNotNull( EntryNumbers::validate_format( '{number}-{number}' ), 'only once' );
		$this->assertNotNull( EntryNumbers::validate_format( 'A B {number}' ), 'no spaces' );
		$this->assertNotNull( EntryNumbers::validate_format( '<b>{number}' ) );
		$this->assertNotNull( EntryNumbers::validate_format( str_repeat( 'A', 25 ) . '{number}' ), 'too long' );
		$this->assertSame( 'R-2026-007', EntryNumbers::format( 'R-{year}-{number}', '2026', 7, 3 ) );
		$this->assertSame( '12345', EntryNumbers::format( '{number}', '2026', 12345, 2 ), 'never cut' );
	}

	public function test_settings_page_validates_and_keeps_defaults(): void {
		$this->assertSame( '', Settings::get( 'general.entry_number_format' ), 'off by default' );
		$this->assertSame( 4, Settings::get( 'general.entry_number_digits' ) );
		$this->assertTrue( Settings::get( 'general.entry_number_yearly_reset' ) );

		list( $values, $errors ) = SettingsSchema::sanitize_group( 'general', array( 'entry_number_format' => 'REQ {year}', 'entry_number_digits' => '11' ) );
		$this->assertArrayHasKey( 'general.entry_number_format', $errors );
		$this->assertArrayHasKey( 'general.entry_number_digits', $errors );
		$this->assertSame( '', $values['entry_number_format'], 'stored value unchanged' );

		$this->as_admin();
		$res = $this->plugin()->get( SettingsPage::class )->save_tab(
			'general',
			array(
				'show_pro_hints'            => '1',
				'entry_number_format'       => 'REQ-{year}-{number}',
				'entry_number_digits'       => '5',
				'entry_number_yearly_reset' => '0',
			)
		);
		$this->assertSame( 'success', $res['type'] );
		$this->assertSame( 'REQ-{year}-{number}', Settings::get( 'general.entry_number_format' ) );
		$this->assertFalse( Settings::get( 'general.entry_number_yearly_reset' ) );

		list( $values, $errors ) = SettingsSchema::sanitize_group( 'general', array( 'entry_number_format' => '' ) );
		$this->assertSame( array(), $errors, 'empty format switches numbering off' );
	}

	public function test_submissions_get_running_numbers_and_spam_gets_none(): void {
		$this->numbering( array( 'entry_number_format' => 'REQ-{year}-{number}', 'entry_number_digits' => 4, 'entry_number_yearly_reset' => true ) );
		$form  = $this->create_published_form();
		$year  = (string) wp_date( 'Y' );
		$repo  = $this->plugin()->get( EntryRepository::class );
		$first = $this->submit( $form, 'Anna' );
		$this->assertTrue( $first['success'] );
		$this->assertSame( "REQ-$year-0001", $repo->find( (int) $first['entry_id'] )['entry_number'] );
		$second = $this->submit( $form, 'Bert' );
		$this->assertSame( "REQ-$year-0002", $repo->find( (int) $second['entry_id'] )['entry_number'] );

		$this->set_spam_setting( 'blocklist', 'viagra' );
		$spam = $this->submit( $form, 'Viagra' );
		$this->assertTrue( $spam['success'] );
		$this->assertNull( $repo->find( (int) $spam['entry_id'] )['entry_number'], 'spam does not use up a number' );
		$this->assertSame( "REQ-$year-0003", $repo->find( (int) $this->submit( $form, 'Carla' )['entry_id'] )['entry_number'] );
	}

	public function test_yearly_restart_and_continuous_counter(): void {
		$this->numbering( array( 'entry_number_format' => '{year}/{number}', 'entry_number_digits' => 3, 'entry_number_yearly_reset' => true ) );
		$form = $this->create_published_form();
		$this->assertSame( '2031/001', EntryNumbers::next( $form, (int) strtotime( '2031-06-01 12:00 UTC' ) ) );
		$this->assertSame( '2031/002', EntryNumbers::next( $form, (int) strtotime( '2031-12-31 12:00 UTC' ) ) );
		$this->assertSame( '2032/001', EntryNumbers::next( $form, (int) strtotime( '2032-01-01 12:00 UTC' ) ), 'restart on 1 January' );

		$this->numbering( array( 'entry_number_format' => 'K{number}', 'entry_number_yearly_reset' => false ) );
		$this->assertSame( 'K001', EntryNumbers::next( $form, (int) strtotime( '2031-06-01 12:00 UTC' ) ) );
		$this->assertSame( 'K002', EntryNumbers::next( $form, (int) strtotime( '2032-06-01 12:00 UTC' ) ), 'no restart' );

		$this->numbering( array( 'entry_number_format' => '' ) );
		$this->assertNull( EntryNumbers::next( $form ) );
	}

	public function test_filter_can_change_the_number(): void {
		$this->numbering( array( 'entry_number_format' => 'A-{number}' ) );
		$form = $this->create_published_form();
		$cb   = static fn( string $number, array $f, int $seq ): string => 'X' . $seq . '-' . (int) $f['id'];
		add_filter( 'form_captain_entry_number_v1', $cb, 10, 3 );
		try {
			$this->assertSame( 'X1-' . (int) $form['id'], EntryNumbers::next( $form ) );
		} finally {
			remove_filter( 'form_captain_entry_number_v1', $cb );
		}
	}

	public function test_placeholder_in_mail_and_success_message(): void {
		$this->numbering( array( 'entry_number_format' => 'REQ-{year}-{number}' ) );
		$form = $this->create_published_form(
			null,
			array(
				'notification' => array(
					'enabled' => true,
					'to'      => array( 'admin@example.org' ),
					'subject' => 'Neu {entry_number}',
					'fields'  => 'all_non_sensitive',
				),
				'confirmation' => array(
					'type'    => 'message',
					'message' => 'Danke! Deine Vorgangsnummer: {entry_number}',
				),
			)
		);
		$res    = $this->submit( $form, 'Anna' );
		$number = 'REQ-' . wp_date( 'Y' ) . '-0001';
		$this->assertSame( "Danke! Deine Vorgangsnummer: $number", $res['message'] );
		$this->runner()->run();
		$mails = $this->sent_mails();
		$this->assertNotEmpty( $mails );
		$this->assertSame( "Neu $number", $mails[0]->subject );
	}

	public function test_placeholder_falls_back_to_the_entry_id(): void {
		$form = $this->create_published_form(
			null,
			array(
				'notification' => array(
					'enabled' => true,
					'to'      => array( 'admin@example.org' ),
					'subject' => 'Eintrag {entry_number}',
					'fields'  => 'all_non_sensitive',
				),
			)
		);
		$id = $this->make_entry( $form, array( 'f_name01' => 'Anna' ) );
		$this->enqueue_mail( $id, (int) $form['id'] );
		$this->runner()->run();
		$this->assertSame( "Eintrag $id", $this->sent_mails()[0]->subject );
		$this->assertSame( (string) $id, EntryNumbers::display( array( 'id' => $id, 'entry_number' => null ) ) );
	}

	public function test_migration_adds_and_verifies_the_column(): void {
		global $wpdb;
		$this->assertSame( 3, Migrator::TARGET_VERSION );
		$table  = $wpdb->prefix . 'fc_entries';
		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'entry_number' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'entry_number', $column );
		$verify = new \ReflectionMethod( Migrator::class, 'verify' );
		$verify->invoke( new Migrator(), 3 ); // Does not throw.
		$this->addToAssertionCount( 1 );
	}
}
