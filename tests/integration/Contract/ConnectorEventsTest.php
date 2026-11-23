<?php
/**
 * Connector events after entry_created (SET-FC-40, contract 1.4.0): entry_status_changed,
 * consent_withdrawn, entry_deleted. Jobs only for subscribed actions; withdrawal and deletion
 * carry IDs only.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Database\Tables;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\ConsentService;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\OutboxPlanner;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/ConnectorContractAssertions.php';
require_once __DIR__ . '/FakeConnector.php';

final class ConnectorEventsTest extends TestCase {

	use ConnectorContractAssertions;

	private const ALL     = 'a_00000000a1';
	private const CREATED = 'a_00000000b2';

	private FakeConnector $fake;

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
		$mapping    = array(
			array( 'key' => 'email', 'label' => 'E-Mail', 'types' => array( 'email' ), 'required' => true ),
		);
		$this->fake = new FakeConnector(
			'fake',
			'1.0',
			true,
			array(
				// Subscribes to every event; the required mapping must not block ID-only events.
				'fake.all'     => array(
					'mapping' => $mapping,
					'events'  => array( 'entry_created', 'entry_status_changed', 'consent_withdrawn', 'entry_deleted', 'unknown_event' ),
				),
				// Default subscription: entry_created only.
				'fake.created' => array( 'mapping' => $mapping ),
			)
		);
		$fake       = $this->fake;
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'fake' => $fake ) );
		$this->registry()->reload();
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
	}

	public function tear_down(): void {
		$this->registry()->reset();
		parent::tear_down();
	}

	/** @return array<string,mixed> */
	private function form(): array {
		return $this->create_published_form(
			null,
			array(
				'actions' => array(
					self::action_instance( 'fake.all', self::ALL, array( 'email' => 'f_mail01' ), array( 'list' => 'crm' ) ),
					self::action_instance( 'fake.created', self::CREATED, array( 'email' => 'f_mail01' ) ),
				),
			)
		);
	}

	private function entry( array $form, array $extra = array() ): int {
		return $this->make_entry(
			$form,
			array(
				'f_name01' => 'Ada Lovelace',
				'f_mail01' => 'ada@example.org',
				'f_msg001' => 'Secret message',
			),
			$extra + array( 'entry_number' => 'K-2026-0042' )
		);
	}

	/** @return array<int,array<string,mixed>> connector jobs of one event */
	private function event_jobs( string $event ): array {
		global $wpdb;
		$table = Tables::name( Tables::JOBS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s ORDER BY id", ConnectorRegistry::JOB_TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $row ) {
			$job = $this->plugin()->get( JobRepository::class )->find( (int) $row['id'] );
			if ( ( $job['payload']['event'] ?? 'entry_created' ) === $event ) {
				$out[] = $job;
			}
		}
		return $out;
	}

	private function run_job( int $id ): ?string {
		return $this->plugin()->get( JobRunner::class )->run_one( $id );
	}

	private function assertNoValues( array $haystack ): void {
		$json = (string) wp_json_encode( $haystack );
		foreach ( array( 'ada@example.org', 'Ada Lovelace', 'Secret message' ) as $value ) {
			$this->assertStringNotContainsString( $value, $json, 'No field values' );
		}
	}

	public function test_registry_accepts_the_new_events_and_drops_unknown_ones(): void {
		$this->assertSame( array( 'entry_created', 'entry_status_changed', 'consent_withdrawn', 'entry_deleted' ), $this->registry()->action( 'fake.all' )['events'] );
		$this->assertSame( array( 'entry_created' ), $this->registry()->action( 'fake.created' )['events'] );
	}

	public function test_status_change_queues_a_job_for_subscribed_actions_only(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$this->assertTrue( $this->plugin()->get( EntryService::class )->transition( $entry, 'archived', 'manual' ) );

		$jobs = $this->event_jobs( 'entry_status_changed' );
		$this->assertCount( 1, $jobs, 'Only the subscribed action gets a job' );
		$this->assertSame( 'fake.all', $jobs[0]['payload']['action_key'] );
		$this->assertSame( $entry, $jobs[0]['entry_id'], 'Status job hangs on the entry' );
		$this->assertMatchesRegularExpression( '/^form-captain:\d+:' . $entry . ':entry_status_changed:new-archived:\d+:fake\.all:' . self::ALL . '$/', $jobs[0]['idempotency_key'] );
		$this->assertNoValues( $jobs[0]['payload'] );

		$this->assertSame( 'succeeded', $this->run_job( (int) $jobs[0]['id'] ) );
		$call = $this->fake->calls[0];
		$this->assertJobArrayShape( $call );
		$this->assertSame( 'entry_status_changed', $call['event'] );
		$this->assertSame( array( 'email' => 'ada@example.org' ), $call['values'], 'Mapped values as for entry_created' );
		$this->assertSame( 'new', $call['event_data']['old_status'] );
		$this->assertSame( 'archived', $call['event_data']['new_status'] );
		$this->assertSame( 'K-2026-0042', $call['event_data']['entry_number'] );
		$this->assertSame( $form['public_id'], $call['event_data']['form_public_id'] );

		// Spam: no job either way.
		$this->plugin()->get( EntryService::class )->transition( $entry, 'spam', 'manual' );
		$this->assertCount( 1, $this->event_jobs( 'entry_status_changed' ) );
	}

	public function test_consent_withdrawal_queues_one_id_only_job_that_survives_the_deletion(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$res   = $this->plugin()->get( ConsentService::class )->withdraw( $entry, 'admin', 'delete' );
		$this->assertSame( 'deleted', $res['result'] );

		$jobs = $this->event_jobs( 'consent_withdrawn' );
		$this->assertCount( 1, $jobs );
		$this->assertNull( $jobs[0]['entry_id'], 'ID-only job is not tied to the entry row' );
		$this->assertSame( 'pending', $jobs[0]['status'], 'Deleting the entry does not cancel the withdrawal job' );
		$this->assertSame( sprintf( 'form-captain:%d:%d:consent_withdrawn:fake.all:%s', get_current_blog_id(), $entry, self::ALL ), $jobs[0]['idempotency_key'] );
		$this->assertNoValues( $jobs[0]['payload'] );
		// The exception covers only the withdrawal event: the deletion after it creates no job.
		$this->assertCount( 0, $this->event_jobs( 'entry_deleted' ) );

		$this->assertSame( 'succeeded', $this->run_job( (int) $jobs[0]['id'] ) );
		$call = $this->fake->calls[0];
		$this->assertJobArrayShape( $call );
		$this->assertSame( 'consent_withdrawn', $call['event'] );
		$this->assertSame( $entry, $call['entry_id'] );
		$this->assertSame( array(), $call['values'] );
		$this->assertSame( array(), $call['context'] );
		$this->assertSame( array( 'list' => 'crm' ), $call['settings'] );
		$this->assertSame( 'delete', $call['event_data']['then'] );
		$this->assertSame( 'admin', $call['event_data']['source'] );
		$this->assertSame( 'K-2026-0042', $call['event_data']['entry_number'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $call['event_data']['withdrawn_gmt'] );
		$this->assertNoValues( $call );

		// Withdrawing again creates nothing new; no later event creates jobs.
		$this->assertCount( 1, $this->event_jobs( 'consent_withdrawn' ) );
	}

	public function test_no_status_or_deletion_jobs_after_a_withdrawal(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$this->plugin()->get( ConsentService::class )->withdraw( $entry, 'admin', 'keep' );
		$this->plugin()->get( EntryService::class )->transition( $entry, 'archived', 'manual' );
		$this->plugin()->get( EntryDeletionService::class )->delete( $entry, 'manual' );
		$this->assertCount( 1, $this->event_jobs( 'consent_withdrawn' ) );
		$this->assertCount( 0, $this->event_jobs( 'entry_status_changed' ) );
		$this->assertCount( 0, $this->event_jobs( 'entry_deleted' ) );
	}

	public function test_deletion_queues_an_id_only_job_with_the_reference_number(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$this->plugin()->get( EntryService::class )->transition( $entry, 'archived', 'manual' );
		$this->assertTrue( $this->plugin()->get( EntryDeletionService::class )->delete( $entry, 'retention' ) );

		$this->assertSame( 'cancelled', $this->event_jobs( 'entry_status_changed' )[0]['status'], 'Status jobs are cancelled with the entry' );
		$jobs = $this->event_jobs( 'entry_deleted' );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'pending', $jobs[0]['status'] );
		$this->assertNull( $jobs[0]['entry_id'] );
		$this->assertSame( sprintf( 'form-captain:%d:%d:entry_deleted:fake.all:%s', get_current_blog_id(), $entry, self::ALL ), $jobs[0]['idempotency_key'] );
		$this->assertNoValues( $jobs[0]['payload'] );

		$this->assertSame( 'succeeded', $this->run_job( (int) $jobs[0]['id'] ) );
		$call = $this->fake->calls[ count( $this->fake->calls ) - 1 ];
		$this->assertJobArrayShape( $call );
		$this->assertSame( 'entry_deleted', $call['event'] );
		$this->assertSame( array(), $call['values'] );
		$this->assertSame( array(), $call['context'] );
		$this->assertSame(
			array( 'event', 'contract', 'entry_id', 'form_id', 'form_public_id', 'entry_number', 'created_gmt', 'deleted_gmt', 'reason' ),
			array_keys( $call['event_data'] )
		);
		$this->assertSame( FORM_CAPTAIN_CONTRACT_VERSION, $call['event_data']['contract'] );
		$this->assertSame( 'retention', $call['event_data']['reason'] );
		$this->assertSame( 'K-2026-0042', $call['event_data']['entry_number'] );
		$this->assertSame( $entry, $call['event_data']['entry_id'] );
		$this->assertNoValues( $call );
	}

	public function test_anonymization_sends_one_deletion_event(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$this->assertTrue( $this->plugin()->get( PrivacyIntegration::class )->anonymize( $entry ) );
		$jobs = $this->event_jobs( 'entry_deleted' );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'anonymized', $jobs[0]['payload']['data']['reason'] );
		$this->assertNoValues( $jobs[0]['payload'] );
		// Deleting the anonymized entry later sends nothing more.
		$this->plugin()->get( EntryDeletionService::class )->delete( $entry, 'manual' );
		$this->assertCount( 1, $this->event_jobs( 'entry_deleted' ) );
	}

	public function test_unsubscribed_or_unannounced_entries_get_no_jobs(): void {
		// Only the default action on the form: nothing subscribes to the later events.
		$form = $this->create_published_form( null, array( 'actions' => array( self::action_instance( 'fake.created', self::CREATED, array( 'email' => 'f_mail01' ) ) ) ) );
		$a    = $this->entry( $form );
		$this->plugin()->get( EntryService::class )->transition( $a, 'read', 'manual' );
		$this->plugin()->get( ConsentService::class )->withdraw( $a, 'admin', 'delete' );
		$b = $this->entry( $form );
		$this->plugin()->get( EntryDeletionService::class )->delete( $b, 'manual' );
		foreach ( array( 'entry_status_changed', 'consent_withdrawn', 'entry_deleted' ) as $event ) {
			$this->assertCount( 0, $this->event_jobs( $event ), "connector not subscribed → no $event job" );
		}

		// Subscribed, but spam, imported, unconfirmed or whole-form deletion: no jobs.
		$form = $this->form();
		$spam = $this->entry( $form, array( 'status' => 'spam' ) );
		$imp  = $this->entry( $form, array( 'context' => array( 'import' => 'imp_1' ) ) );
		$doi  = $this->entry( $form, array( 'confirmation_status' => 'pending' ) );
		$this->plugin()->get( EntryService::class )->transition( $imp, 'read', 'manual' );
		$this->plugin()->get( EntryService::class )->transition( $doi, 'read', 'manual' );
		foreach ( array( $spam, $imp, $doi ) as $id ) {
			$this->plugin()->get( EntryDeletionService::class )->delete( $id, 'manual' );
		}
		$c = $this->entry( $form );
		$this->plugin()->get( EntryDeletionService::class )->delete( $c, 'form_deleted' );
		foreach ( array( 'entry_status_changed', 'consent_withdrawn', 'entry_deleted' ) as $event ) {
			$this->assertCount( 0, $this->event_jobs( $event ), "no $event job for entries connectors never received" );
		}
	}

	public function test_entry_created_planning_respects_the_subscription(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$this->plugin()->get( OutboxPlanner::class )->plan( $form, $entry );
		$keys = array_map( static fn( array $j ): string => $j['payload']['action_key'], $this->event_jobs( 'entry_created' ) );
		sort( $keys );
		$this->assertSame( array( 'fake.all', 'fake.created' ), $keys );

		// An action that lists only later events gets no entry_created job.
		$this->registry()->reset();
		$only_deleted = new FakeConnector( 'fake', '1.0', true, array( 'fake.all' => array( 'events' => array( 'entry_deleted' ) ) ) );
		remove_all_filters( 'form_captain_connectors_v1' );
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'fake' => $only_deleted ) );
		$this->registry()->reload();
		$second = $this->entry( $form );
		$this->plugin()->get( OutboxPlanner::class )->plan( $form, $second );
		$created = array_filter( $this->event_jobs( 'entry_created' ), static fn( array $j ): bool => $second === $j['entry_id'] );
		// fake.all lists only entry_deleted now; fake.created is not registered at the moment and is
		// queued as before (no lost jobs while a provider is inactive).
		$this->assertSame( array( 'fake.created' ), array_values( array_map( static fn( array $j ): string => $j['payload']['action_key'], $created ) ) );
	}

	public function test_job_of_an_event_the_action_dropped_ends_with_event_not_subscribed(): void {
		$form  = $this->form();
		$entry = $this->entry( $form );
		$id    = $this->plugin()->get( JobRepository::class )->enqueue(
			ConnectorRegistry::JOB_TYPE,
			'form-captain:1:' . $entry . ':entry_deleted:fake.created:' . self::CREATED,
			array(
				'action_key'  => 'fake.created',
				'instance_id' => self::CREATED,
				'event'       => 'entry_deleted',
				'entry_id'    => $entry,
				'data'        => array(),
			),
			null,
			(int) $form['id']
		);
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'event_not_subscribed', $this->plugin()->get( JobRepository::class )->find( $id )['last_error_code'] );
		$this->assertCount( 0, $this->fake->calls );
	}
}
