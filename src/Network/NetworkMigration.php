<?php
/**
 * Mass migration of all sites (SET-FC-44, docs/planung/multisite.md section 4).
 *
 * Every site runs its own migrator (`form_captain_db_version` per site). The network action
 * "Migrate all sites" queues a job on the main site that handles 10 sites per run and then
 * queues the next run; `wp form-captain migrate --network` walks the same batches without the
 * queue. Progress lives in `form_captain_network.migration` ({status, target, total, done,
 * failed: {site_id: code}, cursor}). A failing site is recorded and never blocks the others.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Network;

use FormCaptain\Database\Migrator;
use FormCaptain\Install\Activator;
use FormCaptain\Jobs\JobResult;
use FormCaptain\Plugin;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Secret;

final class NetworkMigration {

	public const JOB_TYPE = 'network.migrate';

	/** Sites per queue run. */
	public const PER_RUN = 10;

	public function register( Plugin $plugin ): void {
		if ( is_multisite() ) {
			add_filter( 'form_captain_job_handlers', array( $this, 'add_handler' ) );
		}
	}

	/**
	 * @param array<string,callable> $handlers
	 * @return array<string,callable>
	 */
	public function add_handler( array $handlers ): array {
		$handlers[ self::JOB_TYPE ] = array( $this, 'handle_job' );
		return $handlers;
	}

	/**
	 * Starts a new run over all sites. With $queue the work is done by jobs on the main site;
	 * without, the caller runs the batches itself (WP-CLI). A run started earlier stops.
	 *
	 * @return array<string,mixed> the new progress
	 */
	public static function start( bool $queue = true ): array {
		$progress = array_merge(
			NetworkSettings::idle_migration(),
			array(
				'status'      => 'running',
				'run'         => Secret::random_id( 12 ),
				'target'      => Migrator::TARGET_VERSION,
				'total'       => Network::site_count(),
				'started_gmt' => Clock::now(),
			)
		);
		NetworkSettings::update( array( 'migration' => $progress ) );
		if ( $queue ) {
			self::enqueue( (string) $progress['run'], 0 );
		}
		return $progress;
	}

	/** Queues the run's next batch on the main site. */
	private static function enqueue( string $run, int $cursor ): void {
		$queue = static function () use ( $run, $cursor ): void {
			Plugin::instance()->get( JobRepository::class )->enqueue( self::JOB_TYPE, 'network_migrate:' . $run . ':' . $cursor, array( 'run' => $run, 'cursor' => $cursor ), null, null, 0, 3 );
		};
		$main  = get_main_site_id();
		if ( get_current_blog_id() === $main ) {
			$queue();
			return;
		}
		switch_to_blog( $main );
		try {
			$queue();
		} finally {
			restore_current_blog();
		}
	}

	/** @param array<string,mixed> $job */
	public function handle_job( array $job ): JobResult {
		if ( ! is_multisite() || ! is_main_site() ) {
			return JobResult::fail( 'not_main_site' );
		}
		$progress = NetworkSettings::get( 'migration' );
		if ( 'running' !== ( $progress['status'] ?? '' ) || (string) ( $job['payload']['run'] ?? '' ) !== (string) $progress['run'] ) {
			return JobResult::success( array( 'skipped' => 'run_replaced' ) );
		}
		$progress = self::run_batch();
		if ( 'running' === $progress['status'] ) {
			self::enqueue( (string) $progress['run'], (int) $progress['cursor'] );
		}
		return JobResult::success(
			array(
				'done'   => (int) $progress['done'],
				'failed' => count( (array) $progress['failed'] ),
			)
		);
	}

	/**
	 * Migrates the next sites of the running run and stores the progress.
	 *
	 * @return array<string,mixed> progress after the batch
	 */
	public static function run_batch( int $limit = self::PER_RUN ): array {
		$progress = NetworkSettings::get( 'migration' );
		if ( 'running' !== ( $progress['status'] ?? '' ) ) {
			return $progress;
		}
		$failed = (array) $progress['failed'];
		$ids    = Network::site_ids( $limit, 0, (int) $progress['cursor'] );
		foreach ( $ids as $site_id ) {
			$code = self::migrate_site( $site_id );
			if ( null === $code ) {
				unset( $failed[ $site_id ] );
			} else {
				$failed[ $site_id ] = $code;
			}
			++$progress['done'];
			$progress['cursor'] = $site_id;
		}
		$progress['failed'] = $failed;
		if ( count( $ids ) < $limit ) {
			$progress['status']       = 'done';
			$progress['finished_gmt'] = Clock::now();
		}
		// A newer run may have started meanwhile: only the run that is still current stores.
		if ( (string) NetworkSettings::get( 'migration' )['run'] === (string) $progress['run'] ) {
			NetworkSettings::update( array( 'migration' => $progress ) );
		}
		return $progress;
	}

	/** Migrates one site; null on success, else the error code. */
	public static function migrate_site( int $site_id ): ?string {
		return Network::in_site(
			$site_id,
			static function (): void {
				$code = Activator::migrate_site();
				if ( null !== $code ) {
					throw new NetworkSiteException( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- short error code, never printed.
				}
			}
		);
	}
}
