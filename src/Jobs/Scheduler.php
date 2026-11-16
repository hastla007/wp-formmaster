<?php
/**
 * WP-Cron schedules for the job worker and daily cleanup (ADR 0004).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Jobs;

use FormCaptain\Plugin;

final class Scheduler {

	public const HOOK_JOBS    = 'form_captain_process_jobs';
	public const HOOK_CLEANUP = 'form_captain_daily_cleanup';

	public function register( Plugin $plugin ): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK_JOBS, static fn() => $plugin->get( JobRunner::class )->run() );
		add_action( self::HOOK_CLEANUP, static fn() => do_action( 'form_captain_cleanup' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action(
			'admin_init',
			static function () use ( $plugin ): void {
				if ( self::should_run_admin_fallback() ) {
					$plugin->get( JobRunner::class )->run( 5 );
				}
			},
			99
		);
	}

	/**
	 * Fallback for sites without working WP-Cron (ADR 0004): a visit to a Form Captain
	 * admin page processes up to five due jobs when the oldest due job waits > 5 minutes
	 * or DISABLE_WP_CRON is set. Never runs on AJAX/REST/cron requests.
	 */
	public static function should_run_admin_fallback(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! str_starts_with( $page, 'form-captain' ) || ! \FormCaptain\Install\Capabilities::current_user_has_any() ) {
			return false;
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return true;
		}
		$health = Plugin::instance()->get( \FormCaptain\Repository\JobRepository::class )->health();
		return (int) $health['oldest_due_age_s'] > 300;
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( array $schedules ): array {
		$schedules['form_captain_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Form Captain jobs)', 'form-captain' ),
		);
		return $schedules;
	}

	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! wp_next_scheduled( self::HOOK_JOBS ) ) {
			wp_schedule_event( time() + 60, 'form_captain_minute', self::HOOK_JOBS );
		}
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK_CLEANUP );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_JOBS );
		wp_clear_scheduled_hook( self::HOOK_CLEANUP );
	}

	/** Non-blocking nudge after a submission so mails go out quickly. */
	public static function nudge(): void {
		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}
