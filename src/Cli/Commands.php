<?php
/**
 * WP-CLI commands (ADR 0004): `wp form-captain jobs run|list|retry`, `cleanup`, `status`.
 *
 * Logic lives in public methods returning data so it can be tested without WP-CLI.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Cli;

use FormCaptain\Admin\SystemStatus;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Plugin;
use FormCaptain\Repository\JobRepository;

final class Commands {

	public function __construct( private Plugin $plugin, private JobRepository $jobs ) {}

	public function register( Plugin $plugin ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command(
			'form-captain jobs run',
			array( $this, 'cli_jobs_run' ),
			array(
				'shortdesc' => 'Processes due Form Captain jobs (mails, integrations, cleanup).',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'limit',
						'optional' => true,
						'default'  => 20,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain jobs list',
			array( $this, 'cli_jobs_list' ),
			array(
				'shortdesc' => 'Lists jobs without payload values.',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'status',
						'optional' => true,
						'options'  => JobRepository::STATUSES,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'type',
						'optional' => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain jobs retry',
			array( $this, 'cli_jobs_retry' ),
			array(
				'shortdesc' => 'Re-queues a failed, dead or cancelled job.',
				'synopsis'  => array(
					array(
						'type' => 'positional',
						'name' => 'id',
					),
				),
			)
		);
		\WP_CLI::add_command( 'form-captain cleanup', array( $this, 'cli_cleanup' ), array( 'shortdesc' => 'Runs retention, trash and log cleanup now.' ) );
		\WP_CLI::add_command( 'form-captain status', array( $this, 'cli_status' ), array( 'shortdesc' => 'Prints the diagnostics report (no personal data).' ) );
	}

	/** @return array{processed:int,succeeded:int,failed:int,dead:int} */
	public function run_jobs( int $limit = 20 ): array {
		return $this->plugin->get( JobRunner::class )->run( max( 1, min( 500, $limit ) ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_jobs( string $status = '', string $type = '', int $limit = 50 ): array {
		$list = $this->jobs->list(
			array(
				'status'      => $status,
				'type_prefix' => $type,
				'per_page'    => $limit,
			)
		);
		return array_map(
			static fn( array $j ): array => array(
				'id'           => (int) $j['id'],
				'type'         => (string) $j['type'],
				'status'       => (string) $j['status'],
				'attempts'     => $j['attempts'] . '/' . $j['max_attempts'],
				'entry_id'     => (string) ( $j['entry_id'] ?? '' ),
				'error'        => (string) ( $j['last_error_code'] ?? '' ),
				'run_after'    => (string) $j['run_after_gmt'],
				'updated'      => (string) $j['updated_gmt'],
			),
			$list['items']
		);
	}

	public function retry_job( int $id ): bool|\WP_Error {
		if ( ! $this->jobs->find( $id ) ) {
			return new \WP_Error( 'job_not_found', 'Job not found.' );
		}
		return $this->jobs->retry( $id ) ? true : new \WP_Error( 'job_not_retryable', 'Only failed, dead or cancelled jobs can be retried.' );
	}

	public function cleanup(): void {
		do_action( 'form_captain_cleanup' );
	}

	public function status_report(): string {
		return $this->plugin->get( SystemStatus::class )->report_text();
	}

	/* --- WP-CLI wrappers ------------------------------------------------ */

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_jobs_run( array $args, array $assoc ): void {
		$stats = $this->run_jobs( (int) ( $assoc['limit'] ?? 20 ) );
		\WP_CLI::success( sprintf( 'Processed %d jobs: %d succeeded, %d failed, %d dead.', $stats['processed'], $stats['succeeded'], $stats['failed'], $stats['dead'] ) );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_jobs_list( array $args, array $assoc ): void {
		$rows = $this->list_jobs( (string) ( $assoc['status'] ?? '' ), (string) ( $assoc['type'] ?? '' ) );
		if ( ! $rows ) {
			\WP_CLI::log( 'No jobs found.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_jobs_retry( array $args, array $assoc ): void {
		$res = $this->retry_job( (int) ( $args[0] ?? 0 ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_code() . ': ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Job re-queued.' );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_cleanup( array $args, array $assoc ): void {
		$this->cleanup();
		\WP_CLI::success( 'Cleanup finished.' );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_status( array $args, array $assoc ): void {
		\WP_CLI::log( $this->status_report() );
	}
}
