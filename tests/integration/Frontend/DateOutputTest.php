<?php
/**
 * Date fields in mails and admin views use the date format of the site; CSV keeps ISO (SET-FC-P3-5).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Jobs\JobRunner;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Service\SubmissionService;

require_once __DIR__ . '/FrontendTestCase.php';

final class DateOutputTest extends FrontendTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		update_option( 'date_format', 'd.m.Y' );
	}

	public function test_local_date_keeps_the_day_and_ignores_other_values(): void {
		update_option( 'timezone_string', 'Pacific/Kiritimati' );
		$this->assertSame( '01.02.2000', MailBuilder::local_date( '2000-02-01' ) );
		$this->assertSame( '31.12.1999', MailBuilder::local_date( '1999-12-31' ) );
		$this->assertSame( '2000-02-30', MailBuilder::local_date( '2000-02-30' ), 'invalid dates stay as stored' );
		$this->assertSame( 'morgen', MailBuilder::local_date( 'morgen' ) );
		update_option( 'timezone_string', '' );

		$field = array(
			'id'   => 'f_date01',
			'type' => 'date',
		);
		$this->assertSame( '2000-02-01', MailBuilder::display_value( $field, '2000-02-01' ), 'default (CSV): ISO' );
		$this->assertSame( '01.02.2000', MailBuilder::display_value( $field, '2000-02-01', true ) );
		$this->assertSame( '2000-02-01', MailBuilder::display_value( array( 'type' => 'text' ), '2000-02-01', true ), 'only date fields' );
	}

	public function test_mail_and_privacy_export_show_the_local_format(): void {
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
				array( 'id' => 'f_date01', 'type' => 'date', 'key' => 'termin', 'label' => 'Termin' ),
			),
			array(
				'notification' => array(
					'enabled' => true,
					'to'      => array( 'admin@example.org' ),
					'subject' => 'Termin {field:termin}',
					'fields'  => 'all_non_sensitive',
				),
			)
		);
		$res  = $this->plugin()->get( SubmissionService::class )->submit( $form, $this->payload( $form, array( 'f_name01' => 'Erika', 'f_mail01' => 'erika@example.org', 'f_date01' => '2027-03-04' ) ) );
		$this->assertTrue( $res['success'] );
		$this->plugin()->get( JobRunner::class )->run();
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'Termin 04.03.2027', $mail->subject );
		$this->assertStringContainsString( '04.03.2027', $mail->body );
		$this->assertStringNotContainsString( '2027-03-04', $mail->body );

		$entry  = $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] );
		$export = array_column( $this->plugin()->get( PrivacyIntegration::class )->export_item( $entry ), 'value', 'name' );
		$this->assertSame( '04.03.2027', $export['Termin'] );
		$this->assertSame( '2027-03-04', $this->plugin()->get( EntryRepository::class )->values_for( (int) $res['entry_id'] )['f_date01'], 'stored value stays ISO' );
	}
}
