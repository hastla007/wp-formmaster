<?php
/**
 * Queue failure paths (P4-15): concurrent workers, expired locks, handler exceptions,
 * timeouts, manual retry, deleted entries.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Jobs\JobResult;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class QueueRobustnessTest extends TestCase {

	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->reset_mailer();
	}

	public function test_two_workers_compete_only_one_claims_and_one_mail_is_sent(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );

		$token_a = $this->jobs()->claim( $job );
		$token_b = $this->jobs()->claim( $job );
		$this->assertNotNull( $token_a );
		$this->assertNull( $token_b, 'second worker loses the claim' );
		$this->assertNull( $this->runner()->run_one( $job ), 'worker B does not execute a claimed job' );
		$this->assertSame( array(), $this->sent_mails() );

		// Worker A finishes with a separate runner instance (second process).
		$this->jobs()->fail( $job, $token_a, 'test', 'simulated', false );
		$this->jobs()->retry( $job );
		$runner_a = new JobRunner( $this->plugin()->get( JobRepository::class ) );
		$runner_b = new JobRunner( $this->plugin()->get( JobRepository::class ) );
		$this->assertSame( 'succeeded', $runner_a->run_one( $job ) );
		$this->assertNull( $runner_b->run_one( $job ) );
		$this->assertCount( 1, $this->sent_mails() );
	}

	public function test_expired_lock_makes_job_runnable_again_and_stale_worker_cannot_finish(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );
		$now  = time();
		Clock::freeze( $now );
		$stale = $this->jobs()->claim( $job );
		$this->assertNotNull( $stale );
		$this->assertSame( array(), $this->jobs()->runnable_ids( 10 ) );

		Clock::freeze( $now + 301 ); // Lock (5 min) expired – worker crashed or timed out.
		$this->assertSame( 1, $this->jobs()->release_expired_locks() );
		$row = $this->jobs()->find( $job );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'lock_expired', $row['last_error_code'] );
		$this->assertContains( $job, $this->jobs()->runnable_ids( 10 ) );

		$this->assertSame( 'succeeded', $this->runner()->run_one( $job ) );
		$this->assertFalse( $this->jobs()->complete( $job, $stale ), 'stale token cannot overwrite the result' );
		$this->assertCount( 1, $this->sent_mails() );
		$this->assertSame( 2, $this->jobs()->find( $job )['attempts'] );
	}

	public function test_exception_in_handler_is_retry_with_backoff(): void {
		$runner = new JobRunner( $this->plugin()->get( JobRepository::class ) );
		$runner->set_handler(
			'test.boom',
			static function (): JobResult {
				throw new \RuntimeException( 'Provider failed for max@example.org token=abc' );
			}
		);
		$job = $this->jobs()->enqueue( 'test.boom', 'test.boom:1', array(), null, null, 0, 3 );
		$this->assertSame( 'failed', $runner->run_one( $job ) );
		$row = $this->jobs()->find( $job );
		$this->assertSame( 'exception', $row['last_error_code'] );
		$this->assertStringNotContainsString( 'max@example.org', (string) $row['last_error_message'] );
		$this->assertStringNotContainsString( 'abc', (string) $row['last_error_message'] );
		$this->assertGreaterThan( Clock::now(), $row['run_after_gmt'] );
		$this->assertSame( array(), $this->jobs()->runnable_ids( 10 ), 'not runnable before backoff' );
	}

	public function test_recurring_provider_errors_end_in_dead_and_manual_retry_restarts(): void {
		$calls  = 0;
		$runner = new JobRunner( $this->plugin()->get( JobRepository::class ) );
		$runner->set_handler(
			'test.flaky',
			static function () use ( &$calls ): JobResult {
				++$calls;
				return $calls <= 3 ? JobResult::retry( 'http_503' ) : JobResult::success( array( 'remote_id' => 'R1' ) );
			}
		);
		$job = $this->jobs()->enqueue( 'test.flaky', 'test.flaky:1', array(), null, null, 0, 3 );
		$t   = time();
		for ( $i = 0; $i < 3; $i++ ) {
			Clock::freeze( $t + $i * 30000 );
			$runner->run_one( $job );
		}
		$this->assertSame( 'dead', $this->jobs()->find( $job )['status'] );
		Clock::freeze( $t + 200000 );
		$this->assertNull( $runner->run_one( $job ), 'dead jobs are not claimed' );
		$this->assertTrue( $this->jobs()->retry( $job ) );
		$this->assertSame( 'succeeded', $runner->run_one( $job ) );
		$this->assertSame( array( 'remote_id' => 'R1' ), $this->jobs()->find( $job )['result'] );
		$this->assertFalse( $this->jobs()->retry( $job ), 'succeeded jobs are not retryable' );
	}

	public function test_permanent_failure_goes_dead_immediately(): void {
		$runner = new JobRunner( $this->plugin()->get( JobRepository::class ) );
		$runner->set_handler( 'test.fatal', static fn(): JobResult => JobResult::fail( 'invalid_config' ) );
		$job = $this->jobs()->enqueue( 'test.fatal', 'test.fatal:1', array() );
		$this->assertSame( 'dead', $runner->run_one( $job ) );
		$this->assertSame( 1, $this->jobs()->find( $job )['attempts'] );
	}

	public function test_unknown_job_type_is_dead_letter(): void {
		$job = $this->jobs()->enqueue( 'test.nohandler', 'test.nohandler:1', array() );
		$this->assertSame( 'dead', $this->runner()->run_one( $job ) );
		$this->assertSame( 'no_handler', $this->jobs()->find( $job )['last_error_code'] );
	}

	public function test_idempotency_key_prevents_duplicate_jobs(): void {
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$a    = $this->enqueue_mail( $id, (int) $form['id'] );
		$b    = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->assertSame( $a, $b );
		$this->assertCount( 1, $this->jobs()->for_entry( $id ) );
	}

	public function test_entry_survives_while_jobs_are_pending_outbox(): void {
		// Commit happened, worker never ran (process abort): job stays pending and is delivered later.
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		$job  = $this->enqueue_mail( $id, (int) $form['id'] );
		$this->assertSame( 'pending', $this->jobs()->find( $job )['status'] );
		$this->assertNotNull( $this->entries()->find( $id ) );
		do_action( 'form_captain_process_jobs' );
		$this->assertSame( 'succeeded', $this->jobs()->find( $job )['status'] );
		$this->assertCount( 1, $this->sent_mails() );
	}
}
