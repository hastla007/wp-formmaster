<?php
/**
 * Connector lifecycle: deactivating a connector pauses its open jobs, activating resumes them
 * (docs/CONNECTORS.md §6a, docs/spec/zustaende.md "Job").
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Admin\IntegrationsPage;
use FormCaptain\Admin\SystemStatus;
use FormCaptain\Database\Tables;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\Security\RedirectIntercepted;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/ConnectorContractAssertions.php';
require_once __DIR__ . '/FakeConnector.php';
require_once dirname( __DIR__ ) . '/Security/RedirectIntercepted.php';

final class ConnectorPauseTest extends TestCase {

	use ConnectorContractAssertions;

	private FakeConnector $fake;

	/**
	 * Second connector whose action key contains "fake.send" as a substring (LIKE false positive).
	 *
	 * @var FakeConnector
	 */
	private FakeConnector $other;

	/** @var callable */
	private $filter;

	private array $form;

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
		$this->fake   = new FakeConnector( 'fake' );
		$this->other  = new FakeConnector( 'xfake' );
		$this->filter = fn( array $l ): array => $l + array( 'fake' => $this->fake, 'xfake' => $this->other );
		add_filter( 'form_captain_connectors_v1', $this->filter );
		$this->registry()->reload();
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
		$this->assertTrue( $this->registry()->activate( 'xfake' ) );
		wp_set_current_user( 0 );
		$this->form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
			),
			array(
				'actions' => array(
					self::action_instance( 'fake.send', 'a_0000000001', array( 'email' => 'f_mail01' ) ),
					self::action_instance( 'xfake.send', 'a_0000000002', array( 'email' => 'f_mail01' ) ),
					self::action_instance( 'core.set_status', 'a_0000000003', array(), array( 'status' => 'read' ) ),
				),
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'form_captain_connectors_v1', $this->filter );
		$this->registry()->reset();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function jobs(): JobRepository {
		return $this->plugin()->get( JobRepository::class );
	}

	private function entry(): int {
		return $this->make_entry( $this->form, array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org' ) );
	}

	private function job( int $id ): array {
		return $this->jobs()->find( $id );
	}

	private function run_job( int $id ): ?string {
		return $this->plugin()->get( JobRunner::class )->run_one( $id );
	}

	/** Moves a job to `failed` with the given attempts, due in the future. */
	private function make_failed( int $id, int $attempts ): void {
		global $wpdb;
		$wpdb->update(
			Tables::name( Tables::JOBS ),
			array(
				'status'          => 'failed',
				'attempts'        => $attempts,
				'last_error_code' => 'remote_unavailable',
				'run_after_gmt'   => Clock::plus( 3600 ),
			),
			array( 'id' => $id )
		);
	}

	/** @return array{fake:int,fake_failed:int,xfake:int,core:int,mail:int,fake_done:int} */
	private function seed(): array {
		$entry = $this->entry();
		$ids   = array(
			'fake'        => $this->enqueue_action_job( $this->form, $entry, 'fake.send', 'a_0000000001' ),
			'fake_failed' => $this->enqueue_action_job( $this->form, $this->entry(), 'fake.send', 'a_0000000001' ),
			'xfake'       => $this->enqueue_action_job( $this->form, $entry, 'xfake.send', 'a_0000000002' ),
			'core'        => $this->enqueue_action_job( $this->form, $entry, 'core.set_status', 'a_0000000003' ),
			'mail'        => $this->jobs()->enqueue( 'mail.admin', 'form-captain:1:' . $entry . ':mail.admin', array( 'notification' => 'admin' ), $entry, (int) $this->form['id'] ),
			'fake_done'   => $this->enqueue_action_job( $this->form, $this->entry(), 'fake.send', 'a_0000000001' ),
		);
		$this->make_failed( $ids['fake_failed'], 3 );
		$this->assertSame( 'succeeded', $this->run_job( $ids['fake_done'] ) );
		$this->fake->calls = array();
		return $ids;
	}

	private function admin_cancel( int $job_id, bool $with_nonce = true ): string {
		$_POST = array( 'job_id' => (string) $job_id );
		if ( $with_nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( 'form_captain_job_' . $job_id );
		}
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$redirect                  = static function ( $location, $status ) {
			throw new RedirectIntercepted( (string) $location, (int) $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $redirect, PHP_INT_MAX, 2 );
		set_error_handler( static fn( int $no, string $msg ): bool => str_contains( $msg, 'Cannot modify header information' ), E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		ob_start();
		try {
			$this->plugin()->get( IntegrationsPage::class )->handle_cancel();
			$outcome = 'returned';
		} catch ( \WPDieException $e ) {
			$outcome = 'die';
		} catch ( RedirectIntercepted $e ) {
			$outcome = 'redirect';
		} finally {
			ob_end_clean();
			restore_error_handler();
			remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
		}
		return $outcome;
	}

	public function test_deactivation_pauses_exactly_the_jobs_of_this_connector(): void {
		$ids = $this->seed();
		$this->as_admin();
		$this->registry()->deactivate( 'fake' );

		foreach ( array( 'fake', 'fake_failed' ) as $k ) {
			$job = $this->job( $ids[ $k ] );
			$this->assertSame( 'paused', $job['status'], $k );
			$this->assertSame( 'connector_disabled', $job['last_error_code'], $k );
			$this->assertNull( $job['lock_token'], $k );
			$this->assertNull( $job['locked_until_gmt'], $k );
		}
		$this->assertSame( 3, $this->job( $ids['fake_failed'] )['attempts'], 'Pausing keeps the attempt counter' );
		$this->assertSame( 'pending', $this->job( $ids['xfake'] )['status'], 'Other connector (similar action key) untouched' );
		$this->assertSame( 'pending', $this->job( $ids['core'] )['status'], 'Core actions untouched' );
		$this->assertSame( 'pending', $this->job( $ids['mail'] )['status'], 'Mail jobs untouched' );
		$this->assertSame( 'succeeded', $this->job( $ids['fake_done'] )['status'], 'Finished jobs untouched' );
		$this->assertSame( 0, $this->jobs()->pause_for_connector( 'fake' ), 'Idempotent; registry lookup when no keys are given' );
		$this->assertSame( 0, $this->jobs()->pause_for_connector( 'fake', array( "fake.send' OR 1=1 -- ", 'fake%' ) ), 'Invalid action keys are ignored' );

		$health = $this->jobs()->health();
		$this->assertSame( 2, $health['counts']['paused'] );
		$status = $this->plugin()->get( SystemStatus::class )->collect();
		$this->assertSame( 2, $status['queue']['paused'] );
		$this->assertSame( array( 'fake' => 2 ), $status['integrations']['paused'] );
		$this->assertArrayNotHasKey( 'paused_missing', $this->plugin()->get( SystemStatus::class )->problems( $status ) );
	}

	public function test_worker_never_runs_paused_jobs_and_they_do_not_expire(): void {
		$ids = $this->seed();
		$this->registry()->deactivate( 'fake' );
		Clock::freeze( time() + 30 * DAY_IN_SECONDS );
		$runnable = $this->jobs()->runnable_ids( 100 );
		$this->assertNotContains( $ids['fake'], $runnable );
		$this->assertNotContains( $ids['fake_failed'], $runnable );
		$this->assertNull( $this->jobs()->claim( $ids['fake'] ), 'claim() refuses paused jobs' );
		$stats = $this->plugin()->get( JobRunner::class )->run( 100 );
		$this->assertSame( 0, $stats['paused'] );
		$this->assertCount( 0, $this->fake->calls, 'Callback of the disabled connector never ran' );
		$this->assertSame( 'paused', $this->job( $ids['fake'] )['status'] );
		$this->assertSame( 0, $this->job( $ids['fake'] )['attempts'] );
		$this->assertSame( 'succeeded', $this->job( $ids['xfake'] )['status'], 'Other connector keeps delivering' );

		global $wpdb;
		$wpdb->update( Tables::name( Tables::JOBS ), array( 'finished_gmt' => '2000-01-01 00:00:00', 'updated_gmt' => '2000-01-01 00:00:00' ), array( 'id' => $ids['fake'] ) );
		$this->jobs()->purge_finished();
		$this->assertNotNull( $this->jobs()->find( $ids['fake'] ), 'purge_finished never deletes paused jobs' );
	}

	public function test_reactivation_resumes_and_job_succeeds_with_attempts_kept(): void {
		$ids = $this->seed();
		$this->registry()->deactivate( 'fake' );
		Clock::freeze( time() + 2 * HOUR_IN_SECONDS );
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );

		$job = $this->job( $ids['fake_failed'] );
		$this->assertSame( 'pending', $job['status'] );
		$this->assertSame( 3, $job['attempts'], 'Attempts unchanged by pause/resume' );
		$this->assertSame( Clock::now(), $job['run_after_gmt'], 'Due immediately' );
		$this->assertNull( $job['last_error_code'] );
		$this->assertSame( 'pending', $this->job( $ids['fake'] )['status'] );

		wp_set_current_user( 0 );
		$this->plugin()->get( JobRunner::class )->run( 100 );
		$this->assertSame( 'succeeded', $this->job( $ids['fake'] )['status'] );
		$this->assertSame( 'succeeded', $this->job( $ids['fake_failed'] )['status'] );
		$this->assertSame( 4, $this->job( $ids['fake_failed'] )['attempts'] );
		$this->assertCount( 2, $this->fake->calls );
		$this->assertSame( 0, $this->jobs()->health()['counts']['paused'] );
	}

	public function test_job_claimed_while_connector_disabled_goes_back_to_paused(): void {
		$this->registry()->deactivate( 'fake' );
		// Planned after deactivation (or claimed in the race window): still pending.
		$id = $this->enqueue_action_job( $this->form, $this->entry(), 'fake.send', 'a_0000000001' );
		$this->assertSame( 'pending', $this->job( $id )['status'] );
		$this->assertSame( 'paused', $this->run_job( $id ) );
		$job = $this->job( $id );
		$this->assertSame( 'paused', $job['status'] );
		$this->assertSame( 0, $job['attempts'], 'The parked claim does not count as an attempt' );
		$this->assertNull( $job['lock_token'] );
		$this->assertCount( 0, $this->fake->calls );

		$this->as_admin();
		$this->registry()->activate( 'fake' );
		wp_set_current_user( 0 );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertSame( 1, $this->job( $id )['attempts'] );
	}

	public function test_removed_connector_keeps_jobs_paused_and_admin_can_cancel(): void {
		$ids = $this->seed();
		$this->registry()->deactivate( 'fake' );

		// Next request without the connector plugin.
		remove_filter( 'form_captain_connectors_v1', $this->filter );
		add_filter( 'form_captain_connectors_v1', $this->filter = fn( array $l ): array => $l + array( 'xfake' => $this->other ) );
		$this->registry()->reset();
		$this->registry()->reload();
		$this->assertSame( ConnectorRegistry::STATE_MISSING, $this->registry()->state( 'fake' ) );

		$job = $this->job( $ids['fake'] );
		$this->assertSame( 'paused', $job['status'], 'Jobs stay paused when the plugin is removed' );
		$this->assertSame( 'connector_not_found', $this->registry()->paused_reason( $job ) );
		$this->assertSame( 'Paused (connector missing)', $this->registry()->paused_label( $job ) );
		$this->plugin()->get( JobRunner::class )->run( 100 );
		$this->assertSame( 'paused', $this->job( $ids['fake'] )['status'] );

		$status = $this->plugin()->get( SystemStatus::class )->collect();
		$this->assertSame( array( 'missing' => 2 ), $status['integrations']['paused'] );
		$this->assertArrayHasKey( 'paused_missing', $this->plugin()->get( SystemStatus::class )->problems( $status ) );

		$this->as_admin();
		ob_start();
		$this->plugin()->get( IntegrationsPage::class )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Paused (connector missing)', $html );
		$this->assertStringContainsString( 'paused because their connector is missing', $html );

		// Without the capability: refused, nothing changes.
		$this->as_role( 'editor' );
		$this->assertSame( 'die', $this->admin_cancel( $ids['fake'] ) );
		$this->assertSame( 'paused', $this->job( $ids['fake'] )['status'] );
		// Admin without a valid nonce: refused.
		$this->as_admin();
		$this->assertSame( 'die', $this->admin_cancel( $ids['fake'], false ) );
		$this->assertSame( 'paused', $this->job( $ids['fake'] )['status'] );
		// Admin with nonce: cancelled.
		$this->assertSame( 'redirect', $this->admin_cancel( $ids['fake'] ) );
		$this->assertSame( 'cancelled', $this->job( $ids['fake'] )['status'] );
		$this->assertSame( 'cancelled_by_admin', $this->job( $ids['fake'] )['last_error_code'] );

		// Plugin comes back and is activated: the remaining paused job continues.
		remove_filter( 'form_captain_connectors_v1', $this->filter );
		add_filter( 'form_captain_connectors_v1', $this->filter = fn( array $l ): array => $l + array( 'fake' => $this->fake, 'xfake' => $this->other ) );
		$this->registry()->reset();
		$this->registry()->reload();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
		$this->assertSame( 'pending', $this->job( $ids['fake_failed'] )['status'] );
		$this->assertSame( 'cancelled', $this->job( $ids['fake'] )['status'], 'Cancelled jobs are not resumed' );
	}

	public function test_integrations_page_and_entry_detail_show_paused(): void {
		$ids = $this->seed();
		$this->registry()->deactivate( 'fake' );
		$this->as_admin();
		ob_start();
		$this->plugin()->get( IntegrationsPage::class )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Paused (connector disabled)', $html );
		$this->assertStringContainsString( 'Paused jobs', $html );
		$this->assertStringContainsString( 'form_captain_job_cancel', $html, 'Paused jobs can be cancelled' );
		$this->assertSame( 'Paused (connector disabled)', $this->registry()->paused_label( $this->job( $ids['fake'] ) ) );

		$_GET = array( 'view' => (string) $this->job( $ids['fake'] )['entry_id'] );
		ob_start();
		try {
			$this->plugin()->get( \FormCaptain\Admin\EntriesPage::class )->render();
		} finally {
			$detail = (string) ob_get_clean();
			$_GET   = array();
		}
		$this->assertStringContainsString( 'Paused (connector disabled)', $detail, 'Entry detail shows the paused state' );
	}

	public function test_entry_deletion_cancels_paused_jobs(): void {
		$entry = $this->entry();
		$id    = $this->enqueue_action_job( $this->form, $entry, 'fake.send', 'a_0000000001' );
		$this->registry()->deactivate( 'fake' );
		$this->assertSame( 'paused', $this->job( $id )['status'] );
		$this->assertTrue( $this->plugin()->get( EntryDeletionService::class )->delete( $entry ) );
		$job = $this->job( $id );
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 'entry_deleted', $job['last_error_code'] );

		$this->as_admin();
		$this->registry()->activate( 'fake' );
		$this->assertSame( 'cancelled', $this->job( $id )['status'], 'Activation does not revive cancelled jobs' );
	}

	public function test_anonymization_cancels_paused_jobs(): void {
		$entry = $this->entry();
		$id    = $this->enqueue_action_job( $this->form, $entry, 'fake.send', 'a_0000000001' );
		$this->registry()->deactivate( 'fake' );
		$this->assertSame( 1, $this->jobs()->cancel_for_entry( $entry, 'entry_anonymized' ) );
		$this->assertSame( 'cancelled', $this->job( $id )['status'] );
	}
}
