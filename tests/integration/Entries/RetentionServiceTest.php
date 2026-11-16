<?php
/**
 * Retention calculation, cleanup run and recalculation (P5-03, P5-04, AC-089, AC-112…116).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Database\Tables;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\RetentionService;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class RetentionServiceTest extends TestCase {

	use EntryFixtures;

	private function retention(): RetentionService {
		return $this->plugin()->get( RetentionService::class );
	}

	public function tear_down(): void {
		remove_all_filters( 'form_captain_limits_v1' );
		Limits::reset();
		parent::tear_down();
	}

	public function test_expiry_calculation(): void {
		$this->assertSame( '2027-01-31 10:00:00', RetentionService::expires_for( '12m', '2026-01-31 10:00:00' ) );
		$this->assertSame( '2025-03-01 00:00:00', RetentionService::expires_for( '12m', '2024-02-29 00:00:00' ) );
		$this->assertSame( strtotime( '2026-03-20 12:00:00 UTC' ) + 90 * 86400, strtotime( RetentionService::expires_for( '90d', '2026-03-20 12:00:00' ) . ' UTC' ) );
		$this->assertSame( RetentionService::expires_for( '90d', '2026-03-20 12:00:00' ), RetentionService::expires_for( 'days_90', '2026-03-20 12:00:00' ) );
		$this->assertNull( RetentionService::expires_for( 'manual', '2026-01-01 00:00:00' ) );
	}

	public function test_cleanup_deletes_expired_and_old_trash_with_reason_retention(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form    = $this->create_published_form();
		$expired = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-05-31 00:00:00' ) );
		$future  = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-07-01 00:00:00' ) );
		$manual  = $this->make_entry( $form );
		$old_tr  = $this->make_entry( $form );
		$new_tr  = $this->make_entry( $form );
		$spam    = $this->make_entry( $form, array(), array( 'status' => 'spam' ) );
		$this->entries()->update( $old_tr, array( 'status' => 'trash', 'trashed_gmt' => '2026-04-30 12:00:00' ) );
		$this->entries()->update( $new_tr, array( 'status' => 'trash', 'trashed_gmt' => '2026-05-20 12:00:00' ) );
		$this->entries()->update( $spam, array( 'trashed_gmt' => '2026-04-01 00:00:00' ) );
		$job = $this->enqueue_mail( $old_tr, (int) $form['id'] );

		$reasons = array();
		$cb      = static function ( $id, $form_id, $reason ) use ( &$reasons ): void {
			$reasons[ $id ] = $reason;
		};
		add_action( 'form_captain_entry_deleted_v1', $cb, 10, 3 );
		do_action( 'form_captain_cleanup' );
		remove_action( 'form_captain_entry_deleted_v1', $cb, 10 );

		$this->assertNull( $this->entries()->find( $expired ) );
		$this->assertNull( $this->entries()->find( $old_tr ) );
		$this->assertNull( $this->entries()->find( $spam ) );
		$this->assertNotNull( $this->entries()->find( $future ) );
		$this->assertNotNull( $this->entries()->find( $manual ) );
		$this->assertNotNull( $this->entries()->find( $new_tr ) );
		$this->assertSame( array( $expired => 'retention', $old_tr => 'retention', $spam => 'retention' ), $reasons );
		$this->assertSame( 'cancelled', $this->jobs()->find( $job )['status'] );
		$this->assertSame( array(), $this->entries()->values_for( $old_tr ) );
		$this->assertSame( Clock::now(), get_option( RetentionService::OPTION_LAST_RUN ) );
		$this->assertSame( 3, get_option( RetentionService::OPTION_LAST_STATS )['deleted'] );
	}

	public function test_cleanup_is_batched_and_resumable(): void {
		add_filter( 'form_captain_limits_v1', static fn( array $l ): array => array_merge( $l, array( 'cleanup_batch' => 2 ) ) );
		Limits::reset();
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		$ids  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-05-01 00:00:00' ) );
		}
		// Budget exhausted immediately: nothing lost, continuation job queued.
		$stats = $this->retention()->run( 0 );
		$this->assertFalse( $stats['done'] );
		$this->assertSame( 0, $stats['expired'] );
		$cont = $this->plugin()->get( JobRepository::class )->list( array( 'type_prefix' => 'cleanup.retention' ) );
		$this->assertSame( 1, $cont['total'] );

		// Normal run processes all batches; repeat run is a no-op.
		$stats = $this->retention()->run();
		$this->assertTrue( $stats['done'] );
		$this->assertSame( 5, $stats['expired'] );
		$this->assertSame( 0, $this->retention()->run()['expired'] );
		foreach ( $ids as $id ) {
			$this->assertNull( $this->entries()->find( $id ) );
		}
	}

	public function test_failing_entry_does_not_stop_batch(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		$bad  = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-05-01 00:00:00' ) );
		$good = $this->make_entry( $form, array(), array( 'expires_gmt' => '2026-05-01 00:00:00' ) );
		$boom = static function ( $entry_id ) use ( $bad ) {
			if ( (int) $entry_id === $bad ) {
				throw new \RuntimeException( 'storage unavailable' );
			}
			return $entry_id;
		};
		add_filter( 'form_captain_delete_entry_files_v1', $boom );
		$stats = $this->retention()->run();
		remove_filter( 'form_captain_delete_entry_files_v1', $boom );
		$this->assertSame( 1, $stats['failed'] );
		$this->assertSame( 1, $stats['expired'] );
		$this->assertNull( $this->entries()->find( $good ) );
		$this->assertNotNull( $this->entries()->find( $bad ), 'stays due and is retried next run' );
		$this->assertSame( 1, get_option( RetentionService::OPTION_FAILURES )[ $bad ] );
		$this->assertSame( 1, $this->retention()->run()['expired'] );
		$this->assertNull( $this->entries()->find( $bad ) );
	}

	public function test_shortening_retention_applies_grace_period_and_is_idempotent(): void {
		$now = strtotime( '2026-06-01 12:00:00 UTC' );
		Clock::freeze( $now );
		$form  = $this->create_published_form();
		$old   = $this->make_entry( $form, array(), array( 'expires_gmt' => '2027-02-20 00:00:00' ) );
		$fresh = $this->make_entry( $form, array(), array( 'expires_gmt' => '2027-05-30 00:00:00' ) );
		$this->entries()->update( $old, array( 'created_gmt' => '2026-02-20 00:00:00' ) ); // 100 days old.
		$this->entries()->update( $fresh, array( 'created_gmt' => '2026-05-30 00:00:00' ) );

		$preview = $this->retention()->preview( '90d' );
		$this->assertSame( 2, $preview['affected'] );
		$this->assertSame( Clock::plus( 14 * DAY_IN_SECONDS ), $preview['earliest'] );

		Settings::set_group( 'privacy', array( 'retention' => '90d' ) ); // Triggers recalculation.
		$grace = Clock::plus( 14 * DAY_IN_SECONDS );
		$this->assertSame( $grace, $this->entries()->find( $old )['expires_gmt'] );
		$this->assertSame( '2026-08-28 00:00:00', $this->entries()->find( $fresh )['expires_gmt'] );
		$this->assertSame( 0, $this->retention()->run()['expired'], 'nothing deleted immediately' );

		Clock::freeze( $now + 5 * DAY_IN_SECONDS );
		$this->assertSame( 0, $this->retention()->recalculate( '90d' )['updated'], 'repeat does not extend grace' );
		$this->assertSame( $grace, $this->entries()->find( $old )['expires_gmt'] );

		Settings::set_group( 'privacy', array( 'retention' => 'manual' ) );
		$this->assertNull( $this->entries()->find( $old )['expires_gmt'] );
		$this->assertSame( 0, $this->retention()->run()['expired'] );

		Settings::set_group( 'privacy', array( 'retention' => '12m' ) );
		$this->assertSame( '2027-02-20 00:00:00', $this->entries()->find( $old )['expires_gmt'] );
	}

	public function test_global_change_skips_forms_with_own_retention(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$own = $this->create_published_form( null, array( 'retention' => array( 'mode' => 'manual' ) ) );
		$id  = $this->make_entry( $own );
		$this->assertSame( 'manual', RetentionService::mode_for_form( $own ) );
		Settings::set_group( 'privacy', array( 'retention' => '90d' ) );
		$this->assertNull( $this->entries()->find( $id )['expires_gmt'] );
	}

	public function test_form_override_change_queues_recalculation(): void {
		$form = $this->create_published_form();
		do_action( 'form_captain_form_updated_v1', $form, 'saved' );
		$this->assertSame( 0, $this->jobs()->list( array( 'type_prefix' => 'cleanup.retention' ) )['total'], 'first sighting only records the mode' );
		$form['settings']['retention'] = array( 'mode' => '90d' );
		do_action( 'form_captain_form_updated_v1', $form, 'saved' );
		$this->assertSame( 1, $this->jobs()->list( array( 'type_prefix' => 'cleanup.retention' ) )['total'] );
		do_action( 'form_captain_form_updated_v1', $form, 'saved' );
		$this->assertSame( 1, $this->jobs()->list( array( 'type_prefix' => 'cleanup.retention' ) )['total'], 'unchanged mode queues nothing' );
	}

	public function test_entry_created_safety_net_sets_expiry(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		Settings::set_group( 'privacy', array( 'retention' => '90d' ) );
		$form = $this->create_published_form();
		$id   = $this->make_entry( $form );
		do_action( 'form_captain_entry_created_v1', array( 'entry_id' => $id ) );
		$this->assertSame( '2026-08-30 12:00:00', $this->entries()->find( $id )['expires_gmt'] );
	}

	public function test_upcoming_counts_active_entries_only(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		$this->make_entry( $form, array(), array( 'expires_gmt' => '2026-06-05 00:00:00' ) );
		$this->make_entry( $form, array(), array( 'expires_gmt' => '2026-06-10 00:00:00' ) );
		$this->make_entry( $form, array(), array( 'expires_gmt' => '2026-09-10 00:00:00' ) );
		$this->make_entry( $form, array(), array( 'expires_gmt' => '2026-06-03 00:00:00', 'status' => 'spam' ) );
		$up = $this->retention()->upcoming( 14 );
		$this->assertSame( 2, $up['count'] );
		$this->assertSame( '2026-06-05 00:00:00', $up['earliest'] );
	}

	public function test_purges_old_logs_and_finished_jobs(): void {
		global $wpdb;
		$now = strtotime( '2026-06-01 12:00:00 UTC' );
		Clock::freeze( $now - 100 * DAY_IN_SECONDS );
		\FormCaptain\Support\Logger::log( 'info', 'old_entry', 'x' );
		$job   = $this->jobs()->enqueue( 'test.old', 'test.old:1', array() );
		$token = $this->jobs()->claim( $job );
		$this->jobs()->complete( $job, $token );
		Clock::freeze( $now );
		\FormCaptain\Support\Logger::log( 'info', 'new_entry', 'x' );
		$this->retention()->run();
		$codes = $wpdb->get_col( 'SELECT code FROM ' . Tables::name( Tables::LOG ) );
		$this->assertNotContains( 'old_entry', $codes );
		$this->assertContains( 'new_entry', $codes );
		$this->assertNull( $this->jobs()->find( $job ) );
	}

	public function test_restore_detection(): void {
		Clock::freeze( strtotime( '2026-06-01 12:00:00 UTC' ) );
		$form = $this->create_published_form();
		update_option( RetentionService::OPTION_LAST_RUN, '2026-05-31 12:00:00' );
		$this->assertFalse( $this->retention()->restore_suspected() );
		$this->make_entry( $form, array(), array( 'expires_gmt' => '2026-05-01 00:00:00' ) ); // "restored" from backup.
		$this->assertTrue( $this->retention()->restore_suspected() );
		$this->retention()->run();
		$this->assertFalse( $this->retention()->restore_suspected() );
	}
}
