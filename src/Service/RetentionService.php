<?php
/**
 * Retention and cleanup (P5-03, P5-04, datenschutz.md 4).
 *
 * - Runs on `form_captain_cleanup` (daily cron) and as job `cleanup.retention` (continuation).
 * - Deletes expired entries and trash/spam older than privacy.trash_days, batch-wise with a
 *   time budget; failures do not stop the batch and are retried on the next run.
 * - Purges rate-limit buckets, logs and finished jobs.
 * - Recalculates expires_gmt when the effective retention mode changes, with a 14-day grace
 *   period when the period is shortened.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Jobs\JobResult;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;

final class RetentionService {

	public const GRACE_SECONDS     = 14 * DAY_IN_SECONDS;
	public const OPTION_LAST_RUN   = 'form_captain_last_cleanup_at';
	public const OPTION_LAST_STATS = 'form_captain_last_cleanup';
	public const OPTION_FAILURES   = 'form_captain_cleanup_failures';
	public const OPTION_FORM_MODES = 'form_captain_retention_modes';
	public const JOB_TYPE          = 'cleanup.retention';
	public const MODES             = array( '90d', '12m', 'manual' );
	private const RECALC_BATCH     = 500;

	private bool $registered = false;

	private ?Plugin $plugin = null;

	public function __construct(
		private EntryRepository $entries,
		private JobRepository $jobs,
		private FormRepository $forms,
		private EntryDeletionService $deletion
	) {}

	public function register( Plugin $plugin ): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		$this->plugin     = $plugin;
		add_action( 'form_captain_cleanup', array( $this, 'on_cleanup' ), 10, 0 );
		add_filter( 'form_captain_job_handlers', array( $this, 'add_handlers' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'form_captain_form_updated_v1', array( $this, 'on_form_updated' ), 10, 2 );
		add_action( 'form_captain_entry_created_v1', array( $this, 'on_entry_created' ) );
	}

	/**
	 * @param array<string,callable> $handlers
	 * @return array<string,callable>
	 */
	public function add_handlers( array $handlers ): array {
		$handlers[ self::JOB_TYPE ] = array( $this, 'handle_job' );
		return $handlers;
	}

	/** Normalises stored mode names (spec aliases days_90/months_12 accepted). */
	public static function normalize_mode( string $mode ): ?string {
		return match ( $mode ) {
			'90d', 'days_90' => '90d',
			'12m', 'months_12' => '12m',
			'manual' => 'manual',
			default => null,
		};
	}

	/**
	 * Effective mode of a form: own override or the global setting ("global"/"inherit").
	 *
	 * @param array<string,mixed> $form
	 */
	public static function mode_for_form( array $form ): string {
		$own = self::normalize_mode( (string) ( $form['settings']['retention']['mode'] ?? '' ) );
		return $own ?? ( self::normalize_mode( (string) Settings::get( 'privacy.retention' ) ) ?? '12m' );
	}

	/** Expiry for an entry created at $created_gmt (UTC), null for manual. */
	public static function expires_for( string $mode, string $created_gmt ): ?string {
		$mode = self::normalize_mode( $mode );
		if ( null === $mode || 'manual' === $mode ) {
			return null;
		}
		$utc     = new \DateTimeZone( 'UTC' );
		$created = new \DateTimeImmutable( $created_gmt, $utc );
		$expires = $created->add( new \DateInterval( '90d' === $mode ? 'P90D' : 'P12M' ) );
		return $expires->format( 'Y-m-d H:i:s' );
	}

	/**
	 * New expiry after a mode change: shortening never deletes sooner than 14 days from now,
	 * and a grace date set earlier is kept (recalculation is idempotent).
	 */
	public static function recalculated_expiry( string $mode, string $created_gmt, ?string $current ): ?string {
		$target = self::expires_for( $mode, $created_gmt );
		if ( null === $target ) {
			return null;
		}
		$grace = Clock::plus( self::GRACE_SECONDS );
		if ( $target >= $grace ) {
			return $target;
		}
		if ( null !== $current && '' !== $current && $current >= $target && $current < $grace ) {
			return $current;
		}
		return $grace;
	}

	/** Daily cron entry point. */
	public function on_cleanup(): void {
		$this->run();
	}

	/**
	 * Cleanup run with batch size and time budget. Repeatable and resumable.
	 *
	 * @return array{expired:int,trash:int,failed:int,done:bool,purged_jobs:int,purged_logs:int}
	 */
	public function run( ?int $time_budget = null ): array {
		$deadline = microtime( true ) + (float) ( $time_budget ?? Limits::get( 'job_batch_seconds' ) );
		$batch    = Limits::get( 'cleanup_batch' );
		$stats    = array(
			'expired'     => 0,
			'trash'       => 0,
			'failed'      => 0,
			'done'        => true,
			'purged_jobs' => 0,
			'purged_logs' => 0,
		);
		$failures = get_option( self::OPTION_FAILURES, array() );
		$failures = is_array( $failures ) ? $failures : array();
		$skip     = array();
		$cutoff   = Clock::plus( -max( 1, (int) Settings::get( 'privacy.trash_days' ) ) * DAY_IN_SECONDS );

		foreach ( array( 'expired', 'trash' ) as $kind ) {
			while ( true ) {
				$ids = 'expired' === $kind
					? $this->entries->ids_expired( Clock::now(), $batch + count( $skip ) )
					: $this->entries->ids_trashed_before( $cutoff, $batch + count( $skip ) );
				$ids = array_values( array_diff( $ids, $skip ) );
				if ( ! $ids ) {
					break;
				}
				foreach ( array_slice( $ids, 0, $batch ) as $id ) {
					if ( microtime( true ) > $deadline ) {
						$stats['done'] = false;
						break 3;
					}
					$ok = false;
					try {
						$ok = $this->deletion->delete( $id, 'retention' );
					} catch ( \Throwable $e ) {
						Logger::log( 'error', 'cleanup_exception', get_class( $e ), array( 'entry_id' => $id ) );
					}
					if ( $ok ) {
						++$stats[ $kind ];
						unset( $failures[ $id ] );
					} else {
						++$stats['failed'];
						$skip[]          = $id;
						$failures[ $id ] = (int) ( $failures[ $id ] ?? 0 ) + 1;
					}
				}
			}
		}

		$stats['purged_jobs'] = $this->jobs->purge_finished();
		$stats['purged_logs'] = Logger::purge_old();
		$this->purge_rate_limits();

		update_option( self::OPTION_FAILURES, array_slice( $failures, 0, 500, true ), false );
		update_option( self::OPTION_LAST_RUN, Clock::now(), false );
		update_option(
			self::OPTION_LAST_STATS,
			array(
				'at'      => Clock::now(),
				'deleted' => $stats['expired'] + $stats['trash'],
				'failed'  => $stats['failed'],
				'done'    => $stats['done'],
			),
			false
		);
		if ( $stats['expired'] + $stats['trash'] + $stats['failed'] > 0 ) {
			Logger::log( 'info', 'cleanup_run', sprintf( 'expired=%d trash=%d failed=%d done=%d', $stats['expired'], $stats['trash'], $stats['failed'], (int) $stats['done'] ) );
		}
		if ( ! $stats['done'] ) {
			$this->jobs->enqueue( self::JOB_TYPE, self::JOB_TYPE . ':run:' . gmdate( 'Y-m-d-H-i', Clock::timestamp() ), array( 'task' => 'run' ) );
		}
		return $stats;
	}

	/** Entries that failed to delete at least 3 times (system status hint). */
	public function persistent_failures(): int {
		$failures = get_option( self::OPTION_FAILURES, array() );
		return count( array_filter( is_array( $failures ) ? $failures : array(), static fn( $n ): bool => (int) $n >= 3 ) );
	}

	/**
	 * DS-B-03: restored backup suspected (expired entries older than 2 days still present).
	 */
	public function restore_suspected(): bool {
		$last = (string) get_option( self::OPTION_LAST_RUN, '' );
		return '' !== $last && $last < Clock::now() && (bool) $this->entries->ids_expired( Clock::plus( -2 * DAY_IN_SECONDS ), 1 );
	}

	/**
	 * Upcoming automatic deletions for dashboard/settings hints (DS-T-10).
	 *
	 * @return array{count:int,earliest:string|null}
	 */
	public function upcoming( int $days = 14, int $form_id = 0 ): array {
		return $this->entries->expiring_summary( Clock::now(), Clock::plus( max( 1, $days ) * DAY_IN_SECONDS ), $form_id );
	}

	/**
	 * Preview for a retention change (DS-T-05): affected entries and earliest deletion.
	 *
	 * @return array{affected:int,earliest:string|null}
	 */
	public function preview( string $mode, int $form_id = 0 ): array {
		$affected = 0;
		$earliest = null;
		foreach ( $this->target_forms( $form_id ) as $fid ) {
			$after = 0;
			do {
				$batch = $this->entries->batch_after( $after, self::RECALC_BATCH, array( 'form_id' => $fid, 'any_status' => true ) );
				foreach ( $batch as $entry ) {
					$after = (int) $entry['id'];
					$new   = self::recalculated_expiry( $mode, (string) $entry['created_gmt'], $entry['expires_gmt'] ?? null );
					if ( $new !== ( $entry['expires_gmt'] ?? null ) ) {
						++$affected;
					}
					if ( null !== $new && ( null === $earliest || $new < $earliest ) ) {
						$earliest = $new;
					}
				}
			} while ( $batch );
		}
		return array(
			'affected' => $affected,
			'earliest' => $earliest,
		);
	}

	/**
	 * Recalculates expires_gmt for entries of one form (form_id > 0) or of all forms that
	 * inherit the global setting (form_id = 0). Continues as a queued job if the budget runs out.
	 *
	 * @return array{updated:int,done:bool}
	 */
	public function recalculate( string $mode, int $form_id = 0, int $time_budget = 10, int $start_form = 0, int $after_id = 0 ): array {
		$mode = self::normalize_mode( $mode );
		if ( null === $mode ) {
			return array(
				'updated' => 0,
				'done'    => true,
			);
		}
		$deadline = microtime( true ) + max( 1, $time_budget );
		$updated  = 0;
		foreach ( $this->target_forms( $form_id ) as $fid ) {
			if ( $fid < $start_form ) {
				continue;
			}
			$after = $fid === $start_form ? $after_id : 0;
			do {
				$batch = $this->entries->batch_after( $after, self::RECALC_BATCH, array( 'form_id' => $fid, 'any_status' => true ) );
				foreach ( $batch as $entry ) {
					$after   = (int) $entry['id'];
					$current = $entry['expires_gmt'] ?? null;
					$new     = self::recalculated_expiry( $mode, (string) $entry['created_gmt'], $current );
					if ( $new !== $current ) {
						$this->entries->set_expires( (int) $entry['id'], $new );
						++$updated;
					}
				}
				if ( $batch && microtime( true ) > $deadline ) {
					$this->jobs->enqueue(
						self::JOB_TYPE,
						sprintf( '%s:recalculate:%d:%d:%d:%d', self::JOB_TYPE, $form_id, $fid, $after, Clock::timestamp() ),
						array(
							'task'       => 'recalculate',
							'mode'       => $mode,
							'form_id'    => $form_id,
							'start_form' => $fid,
							'after_id'   => $after,
						)
					);
					Logger::log( 'info', 'retention_recalculated', sprintf( 'mode=%s updated=%d partial', $mode, $updated ), array( 'form_id' => $form_id ) );
					return array(
						'updated' => $updated,
						'done'    => false,
					);
				}
			} while ( $batch );
		}
		Logger::log( 'info', 'retention_recalculated', sprintf( 'mode=%s updated=%d', $mode, $updated ), array( 'form_id' => $form_id ) );
		return array(
			'updated' => $updated,
			'done'    => true,
		);
	}

	/** @param array<string,mixed> $job */
	public function handle_job( array $job ): JobResult {
		$payload = (array) ( $job['payload'] ?? array() );
		if ( 'recalculate' === ( $payload['task'] ?? '' ) ) {
			$this->recalculate( (string) ( $payload['mode'] ?? '' ), (int) ( $payload['form_id'] ?? 0 ), Limits::get( 'job_batch_seconds' ), (int) ( $payload['start_form'] ?? 0 ), (int) ( $payload['after_id'] ?? 0 ) );
			return JobResult::success();
		}
		$stats = $this->run();
		return JobResult::success( array( 'deleted' => $stats['expired'] + $stats['trash'] ) );
	}

	/**
	 * Global retention changed → recalculate forms that inherit it.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 */
	public function on_settings_updated( $old_value, $new_value ): void {
		$old = self::normalize_mode( (string) ( is_array( $old_value ) ? ( $old_value['privacy']['retention'] ?? '' ) : '' ) );
		$new = self::normalize_mode( (string) ( is_array( $new_value ) ? ( $new_value['privacy']['retention'] ?? '' ) : '' ) );
		if ( null !== $new && $old !== $new ) {
			$this->recalculate( $new, 0, 5 );
		}
	}

	/**
	 * Form retention override changed → queue recalculation for that form.
	 *
	 * @param mixed $form
	 */
	public function on_form_updated( $form, $change = '' ): void {
		unset( $change );
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return;
		}
		$full = isset( $form['settings'] ) ? $form : $this->forms->find( (int) $form['id'] );
		if ( ! $full ) {
			return;
		}
		$form_id = (int) $full['id'];
		$mode    = self::mode_for_form( $full );
		$known   = get_option( self::OPTION_FORM_MODES, array() );
		$known   = is_array( $known ) ? $known : array();
		$before  = $known[ $form_id ] ?? null;
		if ( $before === $mode ) {
			return;
		}
		$known[ $form_id ] = $mode;
		update_option( self::OPTION_FORM_MODES, $known, false );
		if ( null !== $before ) {
			$this->jobs->enqueue(
				self::JOB_TYPE,
				sprintf( '%s:recalculate:form:%d:%s:%d', self::JOB_TYPE, $form_id, $mode, Clock::timestamp() ),
				array(
					'task'    => 'recalculate',
					'mode'    => $mode,
					'form_id' => $form_id,
				)
			);
		}
	}

	/**
	 * Safety net: sets expires_gmt for new entries when the submission did not.
	 *
	 * @param mixed $event
	 */
	public function on_entry_created( $event ): void {
		$entry_id = (int) ( is_array( $event ) ? ( $event['entry_id'] ?? 0 ) : $event );
		$entry    = $entry_id ? $this->entries->find( $entry_id ) : null;
		if ( ! $entry || ! empty( $entry['expires_gmt'] ) ) {
			return;
		}
		$form = $this->forms->find( (int) $entry['form_id'] );
		if ( $form ) {
			$expires = self::expires_for( self::mode_for_form( $form ), (string) $entry['created_gmt'] );
			if ( null !== $expires ) {
				$this->entries->set_expires( $entry_id, $expires );
			}
		}
	}

	/**
	 * Form IDs affected by a change: one form, or all forms inheriting the global mode.
	 *
	 * @return int[]
	 */
	private function target_forms( int $form_id ): array {
		if ( $form_id > 0 ) {
			return array( $form_id );
		}
		$ids = array();
		foreach ( array( '', 'archived' ) as $status ) {
			$page = 1;
			do {
				$result = $this->forms->list(
					array(
						'status'   => $status,
						'per_page' => 200,
						'page'     => $page,
					)
				);
				foreach ( $result['items'] as $form ) {
					if ( null === self::normalize_mode( (string) ( $form['settings']['retention']['mode'] ?? '' ) ) ) {
						$ids[] = (int) $form['id'];
					}
				}
				++$page;
				$more = 200 === count( $result['items'] );
			} while ( $more );
		}
		sort( $ids );
		return array_values( array_unique( $ids ) );
	}

	private function purge_rate_limits(): void {
		foreach ( array( 'FormCaptain\\Security\\RateLimiter', 'FormCaptain\\Service\\RateLimiter', 'FormCaptain\\Frontend\\RateLimiter' ) as $class ) {
			if ( ! class_exists( $class ) || ! method_exists( $class, 'purge_old' ) ) {
				continue;
			}
			try {
				$method = new \ReflectionMethod( $class, 'purge_old' );
				if ( $method->isStatic() ) {
					$class::purge_old();
				} else {
					( $this->plugin ?? Plugin::instance() )->get( $class )->purge_old();
				}
			} catch ( \Throwable $e ) {
				Logger::log( 'warning', 'rate_limit_purge_failed', get_class( $e ) );
			}
			return;
		}
	}
}
