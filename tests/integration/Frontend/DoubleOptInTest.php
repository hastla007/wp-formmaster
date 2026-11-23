<?php
/**
 * Double opt-in per form (SET-FC-10) and language of visitor mails (SET-FC-21).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Database\Migrator;
use FormCaptain\Database\Schema;
use FormCaptain\Database\Tables;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\DoubleOptIn;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\FormService;
use FormCaptain\Service\RetentionService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;

require_once __DIR__ . '/FrontendTestCase.php';

final class DoubleOptInTest extends FrontendTestCase {

	private ?\WP_Locale_Switcher $switcher = null;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_double_opt_in_v1' );
		remove_all_filters( 'form_captain_entry_created_v1' );
		remove_all_filters( 'get_available_languages' );
		remove_all_filters( 'gettext' );
		if ( null !== $this->switcher ) {
			$GLOBALS['wp_locale_switcher'] = $this->switcher;
		}
		parent::tear_down();
	}

	/** @param array<string,mixed> $doi */
	private function doi_form( array $doi = array(), array $extra = array() ): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'confirmation_recipient' => true ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht' ),
			),
			array_merge(
				array(
					'double_opt_in' => array_merge(
						array(
							'enabled'     => true,
							'email_field' => 'f_mail01',
							'subject'     => 'Bitte bestätigen: {form_title}',
							'body'        => '<p>Hallo {field:name},</p><p><a href="{confirm_link}">Jetzt bestätigen</a> (gültig {link_hours} Stunden)</p>',
						),
						$doi
					),
				),
				$extra
			)
		);
	}

	private function service(): SubmissionService {
		return $this->plugin()->get( SubmissionService::class );
	}

	private function doi(): DoubleOptIn {
		return $this->plugin()->get( DoubleOptIn::class );
	}

	private function entries(): EntryRepository {
		return $this->plugin()->get( EntryRepository::class );
	}

	/** @return string[] job types of an entry */
	private function job_types( int $entry_id ): array {
		return array_column( $this->plugin()->get( JobRepository::class )->for_entry( $entry_id ), 'type' );
	}

	/** Runs the queue and returns the token from the confirmation link of the last mail. */
	private function mailed_token(): string {
		$this->plugin()->get( JobRunner::class )->run();
		$mails = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertNotEmpty( $mails );
		$body = html_entity_decode( (string) end( $mails )['body'] );
		$this->assertSame( 1, preg_match( '/[?&]t=([0-9]+(?:\.|%2E)[A-Za-z0-9_-]{32})/', $body, $m ), 'link with token in the mail' );
		return rawurldecode( $m[1] );
	}

	public function test_form_settings_are_checked(): void {
		$service = $this->plugin()->get( FormService::class );
		$schema  = array(
			'fields' => array(
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => false ),
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			),
		);
		$this->assertFalse( $service->default_settings()['double_opt_in']['enabled'] );
		$this->assertSame( 48, $service->default_settings()['double_opt_in']['link_hours'] );

		$res = $service->sanitize_settings( array( 'double_opt_in' => array( 'enabled' => true ) ), $schema );
		$this->assertSame( 'doi_email_field_required', $res['errors']['double_opt_in.email_field'] );
		$res = $service->sanitize_settings( array( 'double_opt_in' => array( 'enabled' => true, 'email_field' => 'f_name01' ) ), $schema );
		$this->assertSame( 'invalid_doi_email_field', $res['errors']['double_opt_in.email_field'] );
		$res = $service->sanitize_settings( array( 'double_opt_in' => array( 'enabled' => true, 'email_field' => 'f_mail01' ) ), $schema );
		$this->assertSame( 'doi_email_field_not_required', $res['errors']['double_opt_in.email_field'] );
		$res = $service->sanitize_settings(
			array(
				'double_opt_in' => array(
					'enabled'           => false,
					'email_field'       => 'f_mail01',
					'link_hours'        => '72',
					'delete_after_days' => '2',
					'body'              => '<p onclick="x()">Hi <script>alert(1)</script><a href="https://example.org">x</a></p>',
				),
			),
			$schema
		);
		$this->assertSame( 'doi_delete_before_expiry', $res['errors']['double_opt_in.delete_after_days'] );
		$this->assertSame( 3, $res['settings']['double_opt_in']['delete_after_days'] );
		$this->assertSame( 72, $res['settings']['double_opt_in']['link_hours'] );
		$this->assertStringNotContainsString( 'onclick', $res['settings']['double_opt_in']['body'] );
		$this->assertStringNotContainsString( '<script', $res['settings']['double_opt_in']['body'] );
		$res = $service->sanitize_settings( array( 'double_opt_in' => array( 'link_hours' => '0' ) ), $schema );
		$this->assertSame( 'doi_link_hours_range', $res['errors']['double_opt_in.link_hours'] );
	}

	public function test_submission_waits_for_confirmation_then_runs_like_a_normal_entry(): void {
		$form   = $this->doi_form( array(), array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => 'reference.create_ticket', 'enabled' => true, 'settings' => array(), 'mapping' => array() ) ) ) );
		$events = array();
		add_action(
			'form_captain_entry_created_v1',
			static function ( array $e ) use ( &$events ): void {
				$events[] = $e['entry_id'];
			}
		);
		$pending = array();
		add_action(
			'form_captain_entry_pending_v1',
			static function ( int $entry_id, int $form_id ) use ( &$pending ): void {
				$pending[] = array( $entry_id, $form_id );
			},
			10,
			2
		);
		$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() + array( 'f_name01' => 'Erika' ) ) );
		$this->assertTrue( $res['success'] );
		$this->assertSame( array( array( (int) $res['entry_id'], (int) $form['id'] ) ), $pending, 'SET-FC-P4-04: extensions can link request data (uploads) right away' );
		$this->assertStringContainsString( 'confirm', $res['message'] );
		$this->assertArrayNotHasKey( 'redirect_url', $res );
		$id    = (int) $res['entry_id'];
		$entry = $this->entries()->find( $id );
		$this->assertSame( 'pending', $entry['confirmation_status'] );
		$this->assertNotEmpty( $entry['unconfirmed_until_gmt'] );
		$this->assertArrayNotHasKey( 'confirmation_token', $entry, 'hash never leaves the repository' );
		$this->assertSame( array( DoubleOptIn::JOB_TYPE ), $this->job_types( $id ), 'no notification, no connector job before the confirmation' );
		$this->assertSame( array(), $events, 'no public event before the confirmation' );

		$token = $this->mailed_token();
		$mail  = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'erika@example.org', $mail->to[0][0] );
		$this->assertSame( 'Bitte bestätigen: Kontakt', $mail->subject );
		$this->assertStringContainsString( 'Hallo Erika', $mail->body );
		$this->assertStringContainsString( 'gültig 48 Stunden', $mail->body );
		$this->assertStringContainsString( 'Jetzt bestätigen (http', $mail->body, 'text part shows the link target' );

		// GET only shows a button (link scanners must not confirm).
		$page = $this->doi()->page( $token, false );
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( 'name="t" value="' . $token . '"', $page['html'] );
		$this->assertSame( 'pending', $this->entries()->find( $id )['confirmation_status'] );

		$page = $this->doi()->page( $token, true );
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( 'Danke', $page['html'], 'form confirmation message' );
		$entry = $this->entries()->find( $id );
		$this->assertSame( 'confirmed', $entry['confirmation_status'] );
		$this->assertNotEmpty( $entry['confirmed_gmt'] );
		$this->assertNull( $entry['unconfirmed_until_gmt'] );
		$this->assertSame( array( DoubleOptIn::JOB_TYPE, 'mail.admin', 'connector.action' ), $this->job_types( $id ) );
		$this->assertSame( array( $id ), $events );
		$this->assertCount( 1, $pending, 'the confirmation does not fire the pending action again' );

		// Single use: a second click changes nothing.
		$again = $this->doi()->page( $token, true );
		$this->assertStringContainsString( 'already been confirmed', $again['html'] );
		$this->assertSame( array( $id ), $events );
		$this->assertCount( 3, $this->job_types( $id ) );
		// SET-FC-P4-05: without the right token nobody learns that this entry exists or was confirmed.
		$this->assertSame( 'invalid', $this->doi()->check( $id . '.' . str_repeat( 'A', 32 ) )['code'] );
		$this->assertStringContainsString( 'invalid', $this->doi()->page( $id . '.' . str_repeat( 'B', 32 ), false )['html'] );

		// Privacy export carries the proof (time only, no IP).
		$export = $this->plugin()->get( PrivacyIntegration::class )->export_item( $entry );
		$names  = array_column( $export, 'value', 'name' );
		$this->assertStringStartsWith( 'Confirmed on', $names['E-mail confirmation (double opt-in)'] );
	}

	public function test_invalid_and_expired_links_do_not_confirm(): void {
		$form  = $this->doi_form( array( 'link_hours' => 2 ) );
		$res   = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$token = $this->mailed_token();
		$id    = (int) $res['entry_id'];

		$this->assertSame( 'invalid', $this->doi()->check( $id . '.' . str_repeat( 'A', 32 ) )['code'] );
		$this->assertSame( 'invalid', $this->doi()->check( 'garbage' )['code'] );
		$this->assertSame( 404, $this->doi()->page( 'garbage', true )['status'] );

		Clock::freeze( time() + 3 * HOUR_IN_SECONDS );
		$page = $this->doi()->page( $token, true );
		$this->assertSame( 410, $page['status'] );
		$this->assertSame( 'pending', $this->entries()->find( $id )['confirmation_status'] );
		$this->assertSame( array( DoubleOptIn::JOB_TYPE ), $this->job_types( $id ) );
	}

	/** SET-FC-P2-2: a late delivery attempt does not mail a link that has already expired. */
	public function test_no_mail_with_an_already_expired_link(): void {
		$form = $this->doi_form( array( 'link_hours' => 1 ) );
		$id   = (int) $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) )['entry_id'];

		Clock::freeze( time() + 2 * HOUR_IN_SECONDS ); // Queue stopped for two hours.
		$this->plugin()->get( JobRunner::class )->run();
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'no mail with a dead link' );
		$job = $this->plugin()->get( JobRepository::class )->for_entry( $id )[0];
		$this->assertSame( 'link_expired', $job['last_error_code'] );
	}

	/**
	 * SET-FC-P2-3: unconfirmed entries whose link has expired do not block the maximum of the
	 * form; the expired-link page asks the visitor to submit again.
	 */
	public function test_expired_unconfirmed_entries_do_not_block_the_maximum(): void {
		$form  = $this->doi_form( array( 'link_hours' => 1, 'delete_after_days' => 1 ), array( 'limits' => array( 'max_entries' => 1 ) ) );
		$first = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertTrue( $first['success'] );
		$this->assertFalse( \FormCaptain\Service\FormLimits::state( $form )['open'], 'a confirmable entry holds its place' );

		$payload = $this->payload( $form, array( 'f_name01' => 'Erika', 'f_mail01' => 'erika@example.org' ) );
		Clock::freeze( time() + HOUR_IN_SECONDS + 120 ); // Link expired, the new form token is still valid.
		$this->assertTrue( \FormCaptain\Service\FormLimits::state( $form )['open'], 'expired link: the place is free again' );
		$again = $this->service()->submit( $form, $payload );
		$this->assertTrue( $again['success'] );
		$this->assertFalse( \FormCaptain\Service\FormLimits::state( $form )['open'] );
	}

	public function test_admin_mails_are_refused_for_unconfirmed_entries(): void {
		$form = $this->doi_form();
		$res  = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$id   = (int) $res['entry_id'];
		$job  = $this->plugin()->get( JobRepository::class )->enqueue( 'mail.admin', 'manual:' . $id, array( 'manual' => true ), $id, (int) $form['id'] );
		$this->plugin()->get( JobRunner::class )->run();
		$row = $this->plugin()->get( JobRepository::class )->find( $job );
		$this->assertSame( 'entry_unconfirmed', $row['last_error_code'] );
	}

	/** SET-FC-P3-1/-2: unconfirmed entries are marked in list, detail and CSV and cannot be notified by hand. */
	public function test_unconfirmed_entries_are_marked_in_the_admin(): void {
		$this->as_admin();
		$form      = $this->doi_form( array( 'delete_after_days' => 5 ) );
		$pending   = (int) $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) )['entry_id'];
		$confirmed = (int) $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'B', 'f_mail01' => 'b@example.org' ) ) )['entry_id'];
		$this->entries()->mark_confirmed( $confirmed, Clock::now() );

		$this->assertSame( 'New – not confirmed yet', CsvExporter::status_label( $this->entries()->find( $pending ) ) );
		$this->assertSame( 'New', CsvExporter::status_label( $this->entries()->find( $confirmed ) ) );

		$render = function ( int $id ): string {
			$_GET = array( 'page' => EntriesPage::SLUG, 'view' => (string) $id );
			ob_start();
			try {
				$this->plugin()->get( EntriesPage::class )->render();
			} finally {
				$out  = (string) ob_get_clean();
				$_GET = array();
			}
			return $out;
		};
		$html  = $render( $pending );
		$until = (string) $this->entries()->find( $pending )['unconfirmed_until_gmt'];
		$this->assertStringContainsString( 'fc-doi-pending', $html );
		$this->assertStringContainsString( get_date_from_gmt( $until, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), $html, 'deadline of the double opt-in' );
		$this->assertStringNotContainsString( 'Will be deleted automatically on', $html, 'no retention date for an unconfirmed entry' );
		$this->assertStringNotContainsString( 'value="resend"', $html, 'no manual notification before the confirmation' );
		$this->assertStringContainsString( 'Confirmation link (double opt-in)', $html );

		$html = $render( $confirmed );
		$this->assertStringContainsString( 'fc-doi-confirmed', $html );
		$this->assertStringNotContainsString( 'fc-doi-pending', $html );

		$result = $this->plugin()->get( EntryService::class )->resend_notification( $pending );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotContains( 'mail.admin', $this->job_types( $pending ) );
		$this->assertTrue( $this->plugin()->get( EntryService::class )->resend_notification( $confirmed ) );
	}

	/** SET-FC-P3-7: periods and texts of the double opt-in carry the "legal review" hint. */
	public function test_settings_help_asks_for_a_legal_review(): void {
		$this->assertStringContainsString( 'have them checked legally', \FormCaptain\Settings\FormSettingsSchema::definitions()['double_opt_in.enabled']['help'] );
	}

	public function test_unconfirmed_entries_are_deleted_after_the_period(): void {
		$form      = $this->doi_form( array( 'delete_after_days' => 3 ) );
		$pending   = (int) $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) )['entry_id'];
		$confirmed = (int) $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'B', 'f_mail01' => 'b@example.org' ) ) )['entry_id'];
		$this->entries()->mark_confirmed( $confirmed, Clock::now() );

		Clock::freeze( time() + 2 * DAY_IN_SECONDS );
		$this->plugin()->get( RetentionService::class )->run( 30 );
		$this->assertNotNull( $this->entries()->find( $pending ), 'not yet' );

		Clock::freeze( time() + 3 * DAY_IN_SECONDS + 60 );
		$stats = $this->plugin()->get( RetentionService::class )->run( 30 );
		$this->assertSame( 1, $stats['unconfirmed'] );
		$this->assertNull( $this->entries()->find( $pending ) );
		$this->assertNotNull( $this->entries()->find( $confirmed ) );
	}

	public function test_trusted_submissions_and_the_filter_skip_the_confirmation(): void {
		$form = $this->doi_form();
		$res  = $this->service()->submit( $form, array( 'f_name01' => 'A', 'f_mail01' => 'a@example.org' ), array( 'trusted' => true, 'plugin' => 'test' ) );
		$this->assertTrue( $res['success'] );
		$this->assertNull( $this->entries()->find( (int) $res['entry_id'] )['confirmation_status'] );

		add_filter( 'form_captain_double_opt_in_v1', static fn( array $s ): array => array( 'enabled' => false ) + $s );
		$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 'Danke', $res['message'] );
		$this->assertSame( array( 'mail.admin' ), $this->job_types( (int) $res['entry_id'] ) );
	}

	public function test_locale_is_stored_and_visitor_mails_use_it(): void {
		add_filter( 'get_available_languages', static fn( array $l ): array => array_values( array_unique( array_merge( $l, array( 'de_DE' ) ) ) ) );
		$this->switcher                = $GLOBALS['wp_locale_switcher'];
		$GLOBALS['wp_locale_switcher'] = new \WP_Locale_Switcher();
		$GLOBALS['wp_locale_switcher']->init();
		add_filter(
			'gettext',
			static fn( string $t, string $text, string $domain ): string => 'form-captain' === $domain && 'Thank you for your message: %s' === $text && 'de_DE' === determine_locale() ? 'Vielen Dank für deine Nachricht: %s' : $t,
			10,
			3
		);
		$form = $this->create_published_form(
			array( array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'confirmation_recipient' => true ) ),
			array( 'confirmation_mail' => array( 'enabled' => true ) )
		);
		$de   = $this->service()->submit( $form, array( '_fc_locale' => 'de_DE' ) + $this->payload( $form, array( 'f_mail01' => 'de@example.org' ) ) );
		$en   = $this->service()->submit( $form, array( '_fc_locale' => 'en_US' ) + $this->payload( $form, array( 'f_mail01' => 'en@example.org' ) ) );
		$bad  = $this->service()->submit( $form, array( '_fc_locale' => 'xx_YY' ) + $this->payload( $form, array( 'f_mail01' => 'xx@example.org' ) ) );
		$this->assertSame( 'de_DE', $this->entries()->find( (int) $de['entry_id'] )['locale'] );
		$this->assertSame( 'en_US', $this->entries()->find( (int) $en['entry_id'] )['locale'] );
		$this->assertNull( $this->entries()->find( (int) $bad['entry_id'] )['locale'], 'unknown languages are not stored' );

		$this->plugin()->get( JobRunner::class )->run();
		$subjects = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$subjects[ $mail['to'][0][0] ] = $mail['subject'];
		}
		$this->assertSame( 'Vielen Dank für deine Nachricht: Kontakt', $subjects['de@example.org'] );
		$this->assertSame( 'Thank you for your message: Kontakt', $subjects['en@example.org'] );
		$this->assertSame( 'en_US', determine_locale(), 'previous language restored' );
	}

	public function test_migration_from_v2_adds_the_columns(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$real = $wpdb->prefix;
		try {
			$wpdb->prefix = $real . 'fcv2_';
			$v2           = str_replace(
				array( "  locale varchar(20) DEFAULT NULL,\n", "  confirmation_status varchar(10) DEFAULT NULL,\n", "  confirmation_token char(64) DEFAULT NULL,\n", "  confirmed_gmt datetime DEFAULT NULL,\n", "  unconfirmed_until_gmt datetime DEFAULT NULL,\n", ",\n  KEY unconfirmed (confirmation_status,unconfirmed_until_gmt)" ),
				'',
				Schema::definitions()[ Tables::ENTRIES ]
			);
			$wpdb->query( $v2 );
			( new Migrator() )->migrate_v3();
			( new Migrator() )->migrate_v3();
			$columns = $wpdb->get_col( 'DESCRIBE ' . Tables::name( Tables::ENTRIES ) );
			$keys    = $wpdb->get_col( 'SHOW INDEX FROM ' . Tables::name( Tables::ENTRIES ), 2 );
		} finally {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $real . 'fcv2_' . Tables::ENTRIES );
			$wpdb->prefix = $real;
		}
		foreach ( array( 'locale', 'confirmation_status', 'confirmation_token', 'confirmed_gmt', 'unconfirmed_until_gmt' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
		$this->assertContains( 'unconfirmed', $keys );
		$this->assertSame( 3, Migrator::TARGET_VERSION );
	}
}
