<?php
/**
 * Phase 1 acceptance: schema, capabilities, deactivation keeps data.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration;

use FormCaptain\Database\Migrator;
use FormCaptain\Database\Tables;
use FormCaptain\Install\Capabilities;
use FormCaptain\Install\Deactivator;
use FormCaptain\Jobs\Scheduler;

final class InstallTest extends TestCase {

	public function test_all_tables_exist_with_innodb(): void {
		$report = ( new Migrator() )->integrity_report();
		$this->assertSame( Migrator::TARGET_VERSION, $report['installed_version'] );
		foreach ( Tables::ALL as $table ) {
			$this->assertSame( 'InnoDB', $report['engines'][ $table ], $table );
		}
	}

	public function test_migration_is_repeatable(): void {
		$migrator = new Migrator();
		update_option( Migrator::OPTION_VERSION, 0 );
		$this->assertTrue( $migrator->migrate() );
		$this->assertTrue( $migrator->migrate() );
		$this->assertSame( Migrator::TARGET_VERSION, $migrator->installed_version() );
	}

	public function test_downgrade_does_not_touch_schema(): void {
		update_option( Migrator::OPTION_VERSION, 99 );
		$this->assertFalse( ( new Migrator() )->migrate() );
		$state = get_option( Migrator::OPTION_STATE );
		$this->assertSame( 'schema_newer_than_plugin', $state['error'] );
		update_option( Migrator::OPTION_VERSION, Migrator::TARGET_VERSION );
		delete_option( Migrator::OPTION_STATE );
	}

	public function test_admin_has_caps_editor_has_none(): void {
		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );
		foreach ( Capabilities::ALL as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), $cap );
			$this->assertFalse( $editor->has_cap( $cap ), $cap );
		}
	}

	public function test_deactivation_keeps_data_and_clears_cron(): void {
		$form = $this->create_published_form();
		Scheduler::schedule();
		Deactivator::deactivate();
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_JOBS ) );
		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::FORMS ) . ' WHERE id = ' . (int) $form['id'] );
		$this->assertSame( 1, $count );
	}

	public function test_running_migration_blocks_submissions_and_interrupted_one_resumes(): void {
		$form = $this->create_published_form();
		update_option( Migrator::OPTION_STATE, array( 'target' => 1, 'started_gmt' => gmdate( 'Y-m-d H:i:s' ), 'error' => null ), false );
		$migrator = new Migrator();
		$this->assertTrue( $migrator->is_running() );
		$result = $this->plugin()->get( \FormCaptain\Service\SubmissionService::class )->submit( $form, array( 'name' => 'A', 'email' => 'a@example.org' ), array( 'trusted' => true, 'plugin' => 'test', 'idempotency_key' => 'mig-1' ) );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'storage_failed', $result['code'] );
		$this->assertFalse( $migrator->migrate(), 'second migrator waits while one is running' );

		update_option( Migrator::OPTION_STATE, array( 'target' => 1, 'started_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'error' => null ), false );
		$this->assertTrue( $migrator->was_interrupted() );
		update_option( Migrator::OPTION_VERSION, 0 );
		$this->assertTrue( $migrator->migrate() );
		$this->assertFalse( get_option( Migrator::OPTION_STATE ) );
		$this->assertSame( Migrator::TARGET_VERSION, $migrator->installed_version() );
	}
}
