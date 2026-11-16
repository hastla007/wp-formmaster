<?php
/**
 * P5-09: after submissions (ok, spam, invalid), mail errors, connector errors, exports and
 * admin actions, fc_log contains no e-mail addresses, IP addresses, field values, tokens or
 * secrets – also when third-party code puts personal data into error messages.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Security;

use FormCaptain\Export\CsvExporter;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Security\RateLimiter;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;
use FormCaptain\Support\Secret;
use FormCaptain\Tests\Integration\Contract\ConnectorContractAssertions;
use FormCaptain\Tests\Integration\Contract\FakeConnector;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/SecurityFixtures.php';
require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';
require_once dirname( __DIR__ ) . '/Contract/ConnectorContractAssertions.php';
require_once dirname( __DIR__ ) . '/Contract/FakeConnector.php';

final class LogPrivacyTest extends TestCase {

	use SecurityFixtures;
	use EntryFixtures;
	use ConnectorContractAssertions {
		EntryFixtures::make_entry insteadof ConnectorContractAssertions;
	}

	private const IP    = '203.0.113.77';
	private const IPV6  = '2001:db8:85a3::8a2e:370:7334';
	private const NAME  = 'Wilhelmina Geheimfeld';
	private const MAIL  = 'wilhelmina.geheim@example.org';
	private const TEXT  = 'Meine Kontonummer ist 9876543210 und mein Passwort Sonnenblume';
	private const PHONE = '+49 170 5551234';

	private FakeConnector $fake;

	/** @var string[] tokens seen during the test */
	private array $tokens = array();

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = self::IP;
		update_option( 'admin_email', 'site-admin@example.org' );
		$this->reset_mailer();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
		$this->fake = new FakeConnector();
		$fake       = $this->fake;
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'fake' => $fake ) );
		$this->registry()->reload();
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
		$this->clear_log();
	}

	public function tear_down(): void {
		$this->registry()->reset();
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	private function form(): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true, 'confirmation_recipient' => true ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht' ),
				array( 'id' => 'f_tel001', 'type' => 'text', 'key' => 'phone', 'label' => 'Telefon' ),
			),
			array(
				'notification'      => array( 'enabled' => true, 'to' => array( 'team@example.org' ), 'subject' => 'Neu von {field:name}', 'fields' => 'all_non_sensitive', 'reply_to_field' => 'f_mail01' ),
				'confirmation_mail' => array( 'enabled' => true, 'subject' => 'Danke', 'body' => 'Danke', 'fields' => array() ),
				'actions'           => array( self::action_instance( 'fake.send', 'a_0123456789', array( 'email' => 'f_mail01', 'name' => 'f_name01' ) ) ),
			)
		);
	}

	private function fields(): array {
		return array(
			'f_name01' => self::NAME,
			'f_mail01' => self::MAIL,
			'f_msg001' => self::TEXT,
			'f_tel001' => self::PHONE,
		);
	}

	private function submit( array $form, array $raw_extra = array(), int $age = 30 ): array {
		Clock::freeze( time() - $age );
		$token = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] )['token'];
		Clock::freeze( null );
		$this->tokens[] = $token;
		return $this->plugin()->get( SubmissionService::class )->submit(
			$form,
			array_merge(
				array(
					'_fc_token' => $token,
					'fields'    => $this->fields(),
					SpamGuard::honeypot_name( (string) $form['public_id'] ) => '',
				),
				$raw_extra
			),
			array(
				'ip'         => self::IP,
				'source_url' => home_url( '/kontakt/?email=' . rawurlencode( self::MAIL ) ),
			)
		);
	}

	/** @return string[] values that must never reach fc_log */
	private function forbidden(): array {
		$secret = (string) get_option( Secret::OPTION );
		$salt   = (array) get_option( RateLimiter::SALT_OPTION );
		return array_filter(
			array_merge(
				array( self::IP, self::IPV6, self::NAME, 'Wilhelmina', self::MAIL, 'wilhelmina', 'example.org', '9876543210', 'Sonnenblume', '5551234', $secret, (string) ( $salt['current'] ?? '' ), wp_salt( 'auth' ) ),
				$this->tokens
			)
		);
	}

	private function assert_log_clean( string $scenario ): void {
		$rows = $this->log_rows();
		$this->assertNotEmpty( $rows, "$scenario should write at least one log entry" );
		$dump = (string) wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		foreach ( $this->forbidden() as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $dump, "$scenario: fc_log contains '$needle'" );
		}
		$this->assertDoesNotMatchRegularExpression( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $dump, "$scenario: e-mail address in fc_log" );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $dump, "$scenario: IPv4 address in fc_log" );
	}

	public function test_submissions_ok_spam_and_errors_leave_no_personal_data(): void {
		$form = $this->form();
		wp_set_current_user( 0 );
		$this->assertSame( 'created', $this->submit( $form )['code'] );
		$this->assertSame( 'created', $this->submit( $form, array( SpamGuard::honeypot_name( (string) $form['public_id'] ) => self::MAIL ) )['code'], 'Honeypot hit masked as success' );
		$this->submit( $form, array( 'fields' => array( 'f_name01' => self::NAME, 'f_mail01' => 'not-an-email ' . self::MAIL ) ) );
		$this->submit( $form, array( '_fc_token' => 'forged.' . self::MAIL ) );
		$this->submit( $form, array( '_fc_ctx' => 'eyJ' . str_repeat( 'A', 40 ) . '.' . str_repeat( 'B', 43 ) ) );
		Logger::log( 'info', 'test_marker', 'ip ' . self::IP . ' v6 ' . self::IPV6 . ' mail ' . self::MAIL . ' token=' . $this->tokens[0] );
		$this->assert_log_clean( 'submissions' );
	}

	public function test_mail_errors_with_personal_data_in_provider_message(): void {
		$form  = $this->form();
		$entry = $this->make_entry( $form, $this->fields() );
		$this->enqueue_mail( $entry, (int) $form['id'] );
		$this->enqueue_mail( $entry, (int) $form['id'], 'mail.confirmation' );
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action(
					'wp_mail_failed', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
					new \WP_Error(
						'wp_mail_failed',
						'SMTP 550: mailbox ' . self::MAIL . ' (' . self::NAME . ', ' . self::PHONE . ') from ' . self::IP . ' rejected'
					)
				);
				return false;
			}
		);
		wp_set_current_user( 0 );
		$stats = $this->plugin()->get( JobRunner::class )->run();
		$this->assertGreaterThanOrEqual( 2, $stats['failed'] );
		$this->assert_log_clean( 'mail failure' );
	}

	public function test_connector_failures_and_exceptions_with_personal_data(): void {
		$form  = $this->form();
		$entry = $this->make_entry( $form, $this->fields() );
		$job   = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );

		$this->fake->behaviour = static fn( array $j ): array => array(
			'status'  => 'retry',
			'code'    => 'remote_rejected',
			'message' => 'Contact ' . $j['values']['name'] . ' <' . $j['values']['email'] . '> rejected by CRM, IP ' . self::IP,
		);
		$this->assertSame( 'failed', $this->plugin()->get( JobRunner::class )->run_one( $job ) );
		$this->assertSame( 'remote_rejected', $this->jobs()->find( $job )['last_error_code'] );

		$this->jobs()->retry( $job );
		$this->fake->behaviour = static function ( array $j ): array {
			throw new \RuntimeException( 'Duplicate contact ' . $j['values']['name'] . ' / ' . $j['values']['email'] . ' api_key=sk_live_' . str_repeat( 'x', 24 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		$this->assertSame( 'failed', $this->plugin()->get( JobRunner::class )->run_one( $job ) );
		$this->assertSame( 'exception', $this->jobs()->find( $job )['last_error_code'] );
		$this->assert_log_clean( 'connector failure' );
		$this->assertStringNotContainsString( 'sk_live_', (string) wp_json_encode( $this->log_rows() ) );
	}

	public function test_provider_error_texts_do_not_outlive_the_entry(): void {
		$form                  = $this->form();
		$entry                 = $this->make_entry( $form, $this->fields() );
		$job                   = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$this->fake->behaviour = static fn( array $j ): array => array(
			'status'  => 'fail',
			'code'    => 'remote_rejected',
			'message' => 'Kontakt ' . $j['values']['name'] . ' abgelehnt',
		);
		$this->assertSame( 'dead', $this->plugin()->get( JobRunner::class )->run_one( $job ) );
		$this->assertStringContainsString( self::NAME, (string) $this->jobs()->find( $job )['last_error_message'], 'Precondition: provider text kept for diagnosis' );
		$this->assertTrue( $this->plugin()->get( \FormCaptain\Service\EntryDeletionService::class )->delete( $entry, 'manual' ) );
		$this->assertNull( $this->jobs()->find( $job )['last_error_message'] );
	}

	public function test_worker_runs_handlers_without_the_triggering_users_capabilities(): void {
		// T-JOB-06: admin fallback / WP-CLI --user must not lend capabilities to connector callbacks.
		$form                  = $this->form();
		$entry                 = $this->make_entry( $form, $this->fields() );
		$job                   = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$admin                 = $this->as_admin();
		$seen                  = array();
		$this->fake->behaviour = static function ( array $j ) use ( &$seen ): array {
			$seen[] = array(
				'user'      => get_current_user_id(),
				'sensitive' => form_captain_get_entry_v1( (int) $j['entry_id'], array( 'sensitive' ) ),
				'values'    => form_captain_get_entry_v1( (int) $j['entry_id'], array( 'values' ) ),
			);
			return array( 'status' => 'success', 'code' => 'ok' );
		};
		$this->assertSame( 'succeeded', $this->plugin()->get( JobRunner::class )->run_one( $job ) );
		$this->assertSame( 0, $seen[0]['user'] );
		$this->assertInstanceOf( \WP_Error::class, $seen[0]['sensitive'], 'No sensitive scope through the admin who triggered the worker' );
		$this->assertEqualsCanonicalizing( array( 'email', 'name' ), array_keys( $seen[0]['values']['values'] ), 'Only mapped fields via the job grant' );
		$this->assertSame( $admin, get_current_user_id(), 'User restored after the job' );
	}

	public function test_admin_actions_export_and_deletion_log_ids_only(): void {
		$form  = $this->form();
		$entry = $this->make_entry( $form, $this->fields() );
		$this->plugin()->get( EntryService::class )->add_note( $entry, 'Rückruf an ' . self::NAME . ' ' . self::PHONE );
		$this->plugin()->get( EntryService::class )->set_status( $entry, 'archived' );
		$stream = fopen( 'php://memory', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->plugin()->get( CsvExporter::class )->export( array( 'form_id' => (int) $form['id'], 'columns' => array( 'meta:id', 'field:f_mail01' ) ), $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->plugin()->get( EntryService::class )->set_status( $entry, 'trash' );
		$this->plugin()->get( EntryService::class )->delete( $entry );
		$this->plugin()->get( \FormCaptain\Admin\SettingsPage::class )->save_tab( 'email', array( 'from_name' => self::NAME, 'from_address' => 'noreply@example.net' ) );
		$this->assert_log_clean( 'admin actions' );
		$codes = array_column( $this->log_rows(), 'code' );
		$this->assertContains( 'export_created', $codes );
		$this->assertContains( 'entry_purged', $codes );
	}

	public function test_redact_covers_common_identifiers(): void {
		$out = Logger::redact( 'a ' . self::MAIL . ' b ' . self::IP . ' c ' . self::IPV6 . ' d token=abc123 e ' . str_repeat( 'k', 40 ) . ' f 4917012345678' );
		foreach ( array( self::MAIL, self::IP, 'abc123', str_repeat( 'k', 40 ), '4917012345678', '8a2e:370:7334' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $out );
		}
	}
}
