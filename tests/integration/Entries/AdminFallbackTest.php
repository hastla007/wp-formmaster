<?php
/**
 * P4-15: jobs are processed even when WP-Cron does not run.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Jobs\JobResult;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Jobs\Scheduler;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

final class AdminFallbackTest extends TestCase {

	public function tear_down(): void {
		unset( $_GET['page'] );
		parent::tear_down();
	}

	public function test_fallback_only_on_plugin_pages_for_authorized_users(): void {
		$_GET['page'] = 'form-captain-entries';
		$this->as_role( 'editor' );
		$this->assertFalse( Scheduler::should_run_admin_fallback() );

		$this->as_admin();
		$_GET['page'] = 'other-plugin';
		$this->assertFalse( Scheduler::should_run_admin_fallback() );
	}

	public function test_overdue_job_triggers_fallback_and_is_processed(): void {
		$this->as_admin();
		$_GET['page'] = 'form-captain';
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \FormCaptain\Database\Tables::name( \FormCaptain\Database\Tables::JOBS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$jobs         = $this->plugin()->get( JobRepository::class );
		$id           = $jobs->enqueue( 'test.fallback', 'test-fallback-' . wp_generate_password( 8, false ), array() );
		// The WordPress test suite defines DISABLE_WP_CRON, so the fallback must be active.
		$this->assertTrue( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$this->assertTrue( Scheduler::should_run_admin_fallback() );
		Clock::freeze( time() + 600 );
		$this->assertGreaterThan( 300, $jobs->health()['oldest_due_age_s'], 'overdue jobs are reported for system status' );

		$runner = $this->plugin()->get( JobRunner::class );
		$runner->set_handler( 'test.fallback', static fn() => JobResult::success() );
		$runner->run( 5 );
		$this->assertSame( 'succeeded', $jobs->find( $id )['status'] );
	}
}
