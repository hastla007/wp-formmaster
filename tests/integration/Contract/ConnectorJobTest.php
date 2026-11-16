<?php
/**
 * Outbox execution of connector actions: scopes, sensitive fields, retry/dead-letter, lifecycle
 * (P8-03, P8-18, P8-28…P8-32, P8-36).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/ConnectorContractAssertions.php';
require_once __DIR__ . '/FakeConnector.php';

final class ConnectorJobTest extends TestCase {

	use ConnectorContractAssertions;

	private FakeConnector $fake;

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
		$this->fake = new FakeConnector(
			'fake',
			'1.0',
			true,
			array(
				'fake.send'   => array(
					'mapping' => array(
						array( 'key' => 'email', 'label' => 'E-Mail', 'types' => array( 'email' ), 'required' => true ),
						array( 'key' => 'name', 'label' => 'Name', 'types' => array( 'text' ) ),
					),
				),
				'fake.secret' => array(
					'mapping' => array(
						array( 'key' => 'code', 'label' => 'Code', 'types' => array( 'text' ), 'allow_sensitive' => true ),
					),
				),
			)
		);
		$fake = $this->fake;
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'fake' => $fake ) );
		$this->registry()->reload();
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->registry()->reset();
		parent::tear_down();
	}

	/** @return array<string,mixed> */
	private function form_with_action( array $mapping, string $action = 'fake.send', bool $enabled = true ): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
				array( 'id' => 'f_iban01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht' ),
			),
			array( 'actions' => array( self::action_instance( $action, 'a_0123456789', $mapping, array( 'list' => 'newsletter', 'evil' => 'x' ), $enabled ) ) )
		);
	}

	private function entry( array $form ): int {
		return $this->make_entry(
			$form,
			array(
				'f_name01' => 'Ada',
				'f_mail01' => 'ada@example.org',
				'f_iban01' => 'DE00123456781234567890',
				'f_msg001' => 'Hello',
			)
		);
	}

	private function run_job( int $id ): ?string {
		return $this->plugin()->get( JobRunner::class )->run_one( $id );
	}

	private function job( int $id ): array {
		return $this->plugin()->get( JobRepository::class )->find( $id );
	}

	public function test_job_receives_only_mapped_values(): void {
		$form  = $this->form_with_action( array( 'email' => 'f_mail01', 'name' => 'f_name01' ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertCount( 1, $this->fake->calls );
		$job = $this->fake->calls[0];
		$this->assertJobArrayShape( $job );
		$this->assertOnlyMappedValues( $job, array( 'email', 'name' ) );
		$this->assertSame( 'ada@example.org', $job['values']['email'] );
		$this->assertSame( array( 'list' => 'newsletter' ), $job['settings'], 'Only declared settings are passed' );
		$this->assertFalse( $job['test_mode'] );
		$this->assertSame( 'R-' . $entry, $this->job( $id )['result']['remote_id'] );
		$this->assertStringNotContainsString( 'ada@example.org', (string) wp_json_encode( $this->job( $id )['payload'] ), 'No values in job payload' );
	}

	public function test_sensitive_field_is_blocked_by_default(): void {
		$form  = $this->form_with_action( array( 'email' => 'f_mail01', 'name' => 'f_iban01' ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'sensitive_field', $this->job( $id )['last_error_code'] );
		$this->assertCount( 0, $this->fake->calls );
	}

	public function test_sensitive_field_allowed_when_action_declares_it(): void {
		$form  = $this->form_with_action( array( 'code' => 'f_iban01' ), 'fake.secret' );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, 'fake.secret', 'a_0123456789' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertSame( 'DE00123456781234567890', $this->fake->calls[0]['values']['code'] );
	}

	public function test_missing_required_mapping_and_type_mismatch(): void {
		$form = $this->form_with_action( array( 'name' => 'f_name01' ) );
		$id   = $this->enqueue_action_job( $form, $this->entry( $form ), 'fake.send', 'a_0123456789' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'mapping_incomplete', $this->job( $id )['last_error_code'] );

		$form = $this->form_with_action( array( 'email' => 'f_name01' ) );
		$id   = $this->enqueue_action_job( $form, $this->entry( $form ), 'fake.send', 'a_0123456789' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'mapping_type_mismatch', $this->job( $id )['last_error_code'] );
	}

	public function test_disabled_connector_keeps_jobs_until_dead_and_requeues_on_activation(): void {
		$form  = $this->form_with_action( array( 'email' => 'f_mail01' ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789', 2 );
		$this->registry()->deactivate( 'fake' );
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$this->assertSame( 'connector_disabled', $this->job( $id )['last_error_code'] );
		Clock::freeze( time() + 7 * HOUR_IN_SECONDS );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertCount( 0, $this->fake->calls, 'Disabled connectors never run' );
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
		$this->assertSame( 'pending', $this->job( $id )['status'], 'Dead connector_disabled jobs are re-queued on activation' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
	}

	public function test_deleted_entry_fails_without_calling_connector(): void {
		$form  = $this->form_with_action( array( 'email' => 'f_mail01' ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$this->plugin()->get( EntryRepository::class )->purge( $entry );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'entry_deleted', $this->job( $id )['last_error_code'] );
		$this->assertCount( 0, $this->fake->calls );
	}

	public function test_exception_is_retried_and_failure_is_final(): void {
		$form  = $this->form_with_action( array( 'email' => 'f_mail01' ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$this->fake->behaviour = static function (): array {
			throw new \RuntimeException( 'boom for ada@example.org' );
		};
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'exception', $job['last_error_code'] );
		$this->assertStringNotContainsString( 'ada@example.org', (string) $job['last_error_message'], 'Error messages are redacted' );

		$this->fake->behaviour = static fn(): array => array( 'status' => 'fail', 'code' => 'Remote Rejected!', 'message' => 'no' );
		Clock::freeze( time() + 7 * HOUR_IN_SECONDS );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'remoterejected', $this->job( $id )['last_error_code'] );
	}

	public function test_invalid_result_is_retry(): void {
		$form                  = $this->form_with_action( array( 'email' => 'f_mail01' ) );
		$id                    = $this->enqueue_action_job( $form, $this->entry( $form ), 'fake.send', 'a_0123456789' );
		$this->fake->behaviour = static fn(): string => 'ok';
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$this->assertSame( 'invalid_result', $this->job( $id )['last_error_code'] );
	}

	public function test_action_disabled_or_removed_in_form(): void {
		$form = $this->form_with_action( array( 'email' => 'f_mail01' ), 'fake.send', false );
		$id   = $this->enqueue_action_job( $form, $this->entry( $form ), 'fake.send', 'a_0123456789' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'action_disabled', $this->job( $id )['last_error_code'] );

		$form = $this->form_with_action( array( 'email' => 'f_mail01' ) );
		$id   = $this->enqueue_action_job( $form, $this->entry( $form ), 'fake.send', 'a_other00000' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'action_removed', $this->job( $id )['last_error_code'] );
	}

	public function test_get_entry_inside_callback_is_scoped_to_mapping(): void {
		$form   = $this->form_with_action( array( 'email' => 'f_mail01' ) );
		$entry  = $this->entry( $form );
		$other  = $this->entry( $form );
		$seen   = array();
		$this->fake->behaviour = static function ( array $job ) use ( &$seen, $other ): array {
			$seen['values']    = form_captain_get_entry_v1( $job['entry_id'], array( 'meta', 'values' ) );
			$seen['sensitive'] = form_captain_get_entry_v1( $job['entry_id'], array( 'sensitive' ) );
			$seen['other']     = form_captain_get_entry_v1( $other, array( 'meta' ) );
			return array( 'status' => 'success', 'code' => 'ok' );
		};
		$id = $this->enqueue_action_job( $form, $entry, 'fake.send', 'a_0123456789' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertSame( array( 'email' => 'ada@example.org' ), $seen['values']['values'] );
		$this->assertSame( 'forbidden', $seen['sensitive']->get_error_code() );
		$this->assertSame( 'forbidden', $seen['other']->get_error_code() );
		$this->assertSame( 'forbidden', form_captain_get_entry_v1( $entry )->get_error_code(), 'Grant is closed after the callback' );
	}

	public function test_test_run_uses_sample_values_and_creates_nothing(): void {
		$jobs_before = $this->plugin()->get( JobRepository::class )->list( array( 'per_page' => 1 ) )['total'];
		$res         = $this->registry()->test_action( 'fake.send', array( 'list' => 'demo' ) );
		$this->assertIsArray( $res );
		$this->assertSame( 'success', $res['status'] );
		$this->assertTrue( $this->fake->calls[0]['test_mode'] );
		$this->assertSame( 0, $this->fake->calls[0]['entry_id'] );
		$this->assertSame( $jobs_before, $this->plugin()->get( JobRepository::class )->list( array( 'per_page' => 1 ) )['total'] );
		$this->registry()->deactivate( 'fake' );
		$this->assertSame( 'connector_disabled', $this->registry()->test_action( 'fake.send' )->get_error_code() );
	}

	public function test_unknown_action_keeps_retrying_instead_of_losing_job(): void {
		$form = $this->form_with_action( array( 'email' => 'f_mail01' ), 'vanished.action' );
		$id   = $this->enqueue_action_job( $form, $this->entry( $form ), 'vanished.action', 'a_0123456789' );
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$this->assertSame( 'action_not_found', $this->job( $id )['last_error_code'] );
	}
}
