<?php
/**
 * Processes queued jobs with row claims, batching, backoff and dead-letter (P4-14).
 *
 * Handlers are registered per job type via the filter `form_captain_job_handlers`
 * (internal). Each handler receives the hydrated job and returns a JobResult.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Jobs;

use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;

final class JobRunner {

	/** @var array<string,callable(array<string,mixed>):JobResult>|null */
	private ?array $handlers = null;

	public function __construct( private JobRepository $jobs ) {}

	/** @return array<string,callable(array<string,mixed>):JobResult> */
	private function handlers(): array {
		if ( null === $this->handlers ) {
			$this->handlers = (array) apply_filters( 'form_captain_job_handlers', array() );
		}
		return $this->handlers;
	}

	/** For tests. */
	public function set_handler( string $type, callable $handler ): void {
		$this->handlers          = $this->handlers();
		$this->handlers[ $type ] = $handler;
	}

	/**
	 * Runs up to $limit jobs or until the time budget is exhausted.
	 *
	 * @return array{processed:int,succeeded:int,failed:int,dead:int}
	 */
	public function run( ?int $limit = null ): array {
		$stats = array( 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'dead' => 0 );
		$this->jobs->release_expired_locks();
		$limit    = $limit ?? Limits::get( 'job_batch' );
		$deadline = microtime( true ) + Limits::get( 'job_batch_seconds' );
		foreach ( $this->jobs->runnable_ids( $limit ) as $id ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			$outcome = $this->run_one( $id );
			if ( null === $outcome ) {
				continue;
			}
			++$stats['processed'];
			++$stats[ $outcome ];
		}
		return $stats;
	}

	/** Returns 'succeeded'|'failed'|'dead' or null if the claim was lost. */
	public function run_one( int $id ): ?string {
		$token = $this->jobs->claim( $id );
		if ( null === $token ) {
			return null;
		}
		$job = $this->jobs->find( $id );
		if ( ! $job ) {
			return null;
		}
		Logger::set_correlation_id( substr( hash( 'sha256', (string) $job['idempotency_key'] ), 0, 32 ) );
		$handler = $this->handlers()[ $job['type'] ] ?? null;
		if ( null === $handler ) {
			$this->jobs->fail( $id, $token, 'no_handler', 'No handler registered for job type ' . $job['type'], true );
			Logger::log( 'error', 'job_no_handler', (string) $job['type'], array( 'job_id' => $id ) );
			return 'dead';
		}
		// T-JOB-06: handlers never run with the capabilities of whoever triggered the worker
		// (admin fallback, WP-CLI --user, loopback). Restored afterwards for the surrounding request.
		$previous_user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$result = $handler( $job );
			if ( ! $result instanceof JobResult ) {
				$result = JobResult::retry( 'invalid_result', 'Handler returned no JobResult' );
			}
		} catch ( \Throwable $e ) {
			$result = JobResult::retry( 'exception', $e->getMessage() );
		} finally {
			wp_set_current_user( $previous_user );
		}
		if ( JobResult::SUCCESS === $result->status ) {
			$this->jobs->complete( $id, $token, $result->data );
			return 'succeeded';
		}
		$state = $this->jobs->fail( $id, $token, $result->code, $result->message, JobResult::FAIL === $result->status );
		// Only the error code goes to fc_log: handler/connector messages are free text and may echo field values (P5-09).
		Logger::log( 'dead' === $state ? 'error' : 'warning', 'job_' . $state, 'code=' . $result->code, array( 'job_id' => $id, 'entry_id' => $job['entry_id'] ?? 0, 'form_id' => $job['form_id'] ?? 0 ) );
		if ( 'dead' === $state && $job['entry_id'] && str_starts_with( (string) $job['type'], 'mail.' ) ) {
			do_action( 'form_captain_notification_failed_v1', (int) $job['entry_id'], (int) $job['form_id'], (string) $job['type'], $result->code );
		}
		return 'dead' === $state ? 'dead' : 'failed';
	}
}
