<?php
/**
 * Two-plugin test: Form Captain + reference connector plugin (P8-16, P8-34, P8-37).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;
use FormCaptainReferenceConnector\Reference_Connector;

require_once __DIR__ . '/ConnectorContractAssertions.php';
require_once __DIR__ . '/FakeConnector.php';

final class ReferenceConnectorTest extends TestCase {

	use ConnectorContractAssertions;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		// Loaded like a second plugin (after form_captain_loaded_v1 already fired).
		require_once dirname( __DIR__, 3 ) . '/connectors/form-captain-reference-connector/form-captain-reference-connector.php';
	}

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		delete_option( Reference_Connector::OPTION );
		delete_option( Reference_Connector::MODE_OPTION );
		delete_option( Reference_Connector::SEEN_OPTION );
		fcrc_boot(); // Re-attach hooks (the WP test case restores hooks after each test).
		$this->registry()->reset();
		$this->registry()->reload();
	}

	public function tear_down(): void {
		$this->registry()->reset();
		parent::tear_down();
	}

	private function activate(): void {
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'reference' ) );
		wp_set_current_user( 0 );
	}

	/** @return array{0:array<string,mixed>,1:int,2:int} form, entry id, job id */
	private function submission(): array {
		$form  = $this->create_published_form(
			null,
			array(
				'actions' => array(
					self::action_instance(
						'reference.create_ticket',
						'a_0a1b2c3d4e',
						array( 'email' => 'f_mail01', 'subject' => 'f_name01', 'message' => 'f_msg001' ),
						array( 'queue' => 'sales' )
					),
				),
			)
		);
		$entry = $this->make_entry( $form, array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org', 'f_msg001' => 'Call me' ) );
		return array( $form, $entry, $this->enqueue_action_job( $form, $entry, 'reference.create_ticket', 'a_0a1b2c3d4e' ) );
	}

	private function run_job( int $id ): ?string {
		return $this->plugin()->get( JobRunner::class )->run_one( $id );
	}

	/** @return array<string,array<string,mixed>> */
	private function tickets(): array {
		return (array) get_option( Reference_Connector::OPTION, array() );
	}

	public function test_reference_connector_follows_the_contract(): void {
		$connector = new Reference_Connector();
		$this->assertConnectorShape( $connector );
		$this->assertSame( ConnectorRegistry::STATE_DETECTED, $this->registry()->state( 'reference' ), 'Disabled after installation' );
		$this->assertArrayHasKey( 'reference.create_ticket', $this->registry()->actions() );
		$job = self::contract_fixture( 'job' );
		$this->assertJobArrayShape( $job );
		$this->assertIdempotentCallback( array( $connector, 'create_ticket' ), $job );
		$this->assertCount( 1, $this->tickets() );
	}

	public function test_successful_delivery_and_duplicate_delivery(): void {
		$this->activate();
		list( , , $id ) = $this->submission();
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$jobs   = $this->plugin()->get( JobRepository::class );
		$remote = $jobs->find( $id )['result']['remote_id'];
		$this->assertMatchesRegularExpression( '/^T-[0-9A-F]{10}$/', $remote );
		$ticket = array_values( $this->tickets() )[0];
		$this->assertSame( 'sales', $ticket['queue'] );
		$this->assertSame( 'Ada', $ticket['subject'] );

		// Duplicate queue delivery: same idempotency key → same job; re-running the handler → same ticket.
		list( $form, $entry ) = array( $this->plugin()->get( \FormCaptain\Repository\FormRepository::class )->find( (int) $jobs->find( $id )['form_id'] ), (int) $jobs->find( $id )['entry_id'] );
		$this->assertSame( $id, $this->enqueue_action_job( $form, $entry, 'reference.create_ticket', 'a_0a1b2c3d4e' ) );
		$again = $this->registry()->handle_job( $jobs->find( $id ) );
		$this->assertSame( 'success', $again->status );
		$this->assertSame( $remote, $again->data['remote_id'] );
		$this->assertCount( 1, $this->tickets() );
	}

	public function test_timeout_after_success_does_not_create_a_second_ticket(): void {
		$this->activate();
		update_option( Reference_Connector::MODE_OPTION, 'timeout_after_success' );
		list( , , $id ) = $this->submission();
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$jobs = $this->plugin()->get( JobRepository::class );
		$this->assertSame( 'exception', $jobs->find( $id )['last_error_code'] );
		$this->assertCount( 1, $this->tickets(), 'Remote side stored the ticket' );
		Clock::freeze( time() + 7 * HOUR_IN_SECONDS );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertCount( 1, $this->tickets() );
		$this->assertSame( array_values( $this->tickets() )[0]['id'], $jobs->find( $id )['result']['remote_id'], 'Result is not lost' );
	}

	public function test_temporary_outage_is_retried(): void {
		$this->activate();
		update_option( Reference_Connector::MODE_OPTION, 'fail_once' );
		list( , , $id ) = $this->submission();
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$this->assertSame( 'remote_unavailable', $this->plugin()->get( JobRepository::class )->find( $id )['last_error_code'] );
		Clock::freeze( time() + 7 * HOUR_IN_SECONDS );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertCount( 1, $this->tickets() );
	}

	public function test_permanent_failure_goes_to_dead_letter_and_entry_stays(): void {
		$this->activate();
		update_option( Reference_Connector::MODE_OPTION, 'permanent' );
		list( $form, $entry, $id ) = $this->submission();
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'remote_rejected', $this->plugin()->get( JobRepository::class )->find( $id )['last_error_code'] );
		$this->assertNotNull( $this->plugin()->get( \FormCaptain\Repository\EntryRepository::class )->find( $entry ), 'External errors never roll back entries' );
		$this->assertCount( 0, $this->tickets() );
	}

	public function test_deactivated_connector_does_not_deliver(): void {
		list( , , $id ) = $this->submission();
		$this->assertSame( 'failed', $this->run_job( $id ) );
		$this->assertSame( 'connector_disabled', $this->plugin()->get( JobRepository::class )->find( $id )['last_error_code'] );
		$this->assertCount( 0, $this->tickets() );
	}

	public function test_deleted_entry_is_not_delivered(): void {
		$this->activate();
		list( , $entry, $id ) = $this->submission();
		$this->plugin()->get( \FormCaptain\Repository\EntryRepository::class )->purge( $entry );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'entry_deleted', $this->plugin()->get( JobRepository::class )->find( $id )['last_error_code'] );
		$this->assertCount( 0, $this->tickets() );
	}

	public function test_test_mode_has_no_side_effects(): void {
		$this->activate();
		$res = $this->registry()->test_action( 'reference.create_ticket', array( 'queue' => 'x' ) );
		$this->assertSame( 'success', $res['status'] );
		$this->assertSame( 'test_ok', $res['code'] );
		$this->assertCount( 0, $this->tickets() );
	}

	public function test_unknown_payload_fields_are_tolerated(): void {
		$result = ( new Reference_Connector() )->create_ticket( self::contract_fixture( 'job-unknown-fields' ) );
		$this->assertActionResultShape( $result );
		$this->assertSame( 'success', $result['status'] );
		$this->assertEntryEventShape( self::contract_fixture( 'entry-created-event' ) );
		$this->assertEntryEventShape( self::contract_fixture( 'entry-created-event-minor' ) );
		$this->assertActionResultShape( self::contract_fixture( 'result-success' ) );
	}

	public function test_incompatible_version_of_a_connector_is_reported(): void {
		add_filter(
			'form_captain_connectors_v1',
			static fn( array $l ): array => $l + array( 'reference_v2' => new FakeConnector( 'reference_v2', '2.0' ) )
		);
		$this->registry()->reload();
		$this->assertSame( ConnectorRegistry::STATE_INCOMPATIBLE, $this->registry()->state( 'reference_v2' ) );
		$this->assertSame( ConnectorRegistry::STATE_DETECTED, $this->registry()->state( 'reference' ), 'Other connectors unaffected' );
	}
}
