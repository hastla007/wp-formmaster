<?php
/**
 * Load and abuse limits (P6-05): large forms, many entries, streaming CSV export, index usage,
 * bursts, spam waves and a queue without cron. Results are documented in docs/handbuch/leistung.md.
 *
 * Thresholds are deliberately generous (CI machines vary); they catch regressions by orders of
 * magnitude, not by percent. Set FC_LOAD_REPORT=1 to print the measured values.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions

namespace FormCaptain\Tests\Integration\Load;

use FormCaptain\Database\Tables;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Jobs\JobResult;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Service\FormService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Limits;
use FormCaptain\Tests\Integration\Frontend\FrontendTestCase;

require_once dirname( __DIR__ ) . '/Frontend/FrontendTestCase.php';

final class LoadTest extends FrontendTestCase {

	/** @var array<string,string> metric => value */
	private static array $report = array();

	public static function tear_down_after_class(): void {
		if ( getenv( 'FC_LOAD_REPORT' ) && self::$report ) {
			$out = "\nForm Captain load report\n";
			foreach ( self::$report as $k => $v ) {
				$out .= sprintf( "  %-40s %s\n", $k, $v );
			}
			fwrite( STDERR, $out );
		}
		parent::tear_down_after_class();
	}

	private static function measure( string $label, callable $fn ): mixed {
		$start  = microtime( true );
		$result = $fn();
		$ms     = ( microtime( true ) - $start ) * 1000;
		self::$report[ $label ] = sprintf( '%.1f ms', $ms );
		return array( $result, $ms );
	}

	/** @return array<int,array<string,mixed>> */
	private static function hundred_fields(): array {
		$types  = array( 'text', 'email', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'tel', 'text' );
		$fields = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$type  = $types[ $i % count( $types ) ];
			$field = array(
				'id'       => sprintf( 'f_load%03d', $i ),
				'type'     => $type,
				'key'      => sprintf( 'field_%03d', $i ),
				'label'    => sprintf( 'Feld %d', $i ),
				'required' => 0 === $i % 3,
			);
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$field['options'] = array(
					array( 'value' => 'a', 'label' => 'A' ),
					array( 'value' => 'b', 'label' => 'B' ),
					array( 'value' => 'c', 'label' => 'C' ),
				);
			}
			$fields[] = $field;
		}
		return $fields;
	}

	/** @return string|array<int,string> */
	private static function sample( string $type, int $i ): string|array {
		return match ( $type ) {
			'email' => "user{$i}@example.org",
			'number' => (string) $i,
			'select', 'radio' => 'b',
			'checkbox' => array( 'a', 'c' ),
			'date' => '2026-01-15',
			'tel' => '+49 30 1234' . $i,
			'url' => 'https://example.org/' . $i,
			'textarea' => str_repeat( "Zeile {$i}\n", 5 ),
			default => 'Wert ' . $i,
		};
	}

	public function test_form_with_100_fields_saves_renders_and_submits(): void {
		$this->as_admin();
		$service = $this->plugin()->get( FormService::class );
		$schema  = array(
			'schema_version' => 1,
			'fields'         => self::hundred_fields(),
		);
		list( $id, $create_ms ) = self::measure( '100 fields: create', static fn() => $service->create( 'Groß', $schema, array() ) );
		$this->assertIsInt( $id );
		$form = $this->plugin()->get( FormRepository::class )->find( $id );
		list( $saved, $save_ms ) = self::measure( '100 fields: save', static fn() => $service->save( $id, 'Groß', $schema, $form['settings'], (int) $form['revision'] ) );
		$this->assertIsArray( $saved );
		list( $published, $publish_ms ) = self::measure( '100 fields: publish', static fn() => $service->publish( $id ) );
		$this->assertNotWPError( $published );
		$form = $this->plugin()->get( FormRepository::class )->find( $id );
		$this->assertSame( 'published', $form['status'] );
		$this->assertCount( 100, $form['schema']['fields'] );
		wp_set_current_user( 0 );

		$renderer = $this->plugin()->get( Renderer::class );
		list( $html, $render_ms ) = self::measure( '100 fields: render', static fn() => $renderer->render( $form ) );
		preg_match_all( '/name="fc\[(f_load\d{3})\]/', $html, $m );
		$this->assertCount( 100, array_unique( $m[1] ), 'Every field is rendered' );
		self::$report['100 fields: html size'] = sprintf( '%.1f KB', strlen( $html ) / 1024 );

		$values = array();
		foreach ( self::hundred_fields() as $i => $f ) {
			$values[ $f['id'] ] = self::sample( (string) $f['type'], $i );
		}
		$payload = $this->payload( $form, $values );
		self::$report['100 fields: payload size'] = sprintf( '%.1f KB', strlen( (string) wp_json_encode( $payload ) ) / 1024 );
		$this->assertLessThan( Limits::get( 'request_bytes' ), strlen( (string) wp_json_encode( $payload ) ), 'A full 100-field submission fits the request budget' );
		$submission = $this->plugin()->get( SubmissionService::class );
		list( $res, $submit_ms ) = self::measure( '100 fields: submit', static fn() => $submission->submit( $form, $payload, array( 'ip' => '198.51.100.20' ) ) );
		$this->assertTrue( $res['success'], (string) wp_json_encode( $res['error_codes'] ?? $res['code'] ) );
		$this->assertCount( 100, $this->plugin()->get( EntryRepository::class )->values_for( (int) $res['entry_id'] ) );

		foreach ( array( $create_ms, $save_ms, $publish_ms, $render_ms, $submit_ms ) as $ms ) {
			$this->assertLessThan( 2000, $ms );
		}

		// One field above the budget is refused before validation (too_many_fields).
		$values['f_extra01'] = 'x';
		$this->assertSame( 'too_many_fields', $submission->submit( $form, $this->payload( $form, $values ), array( 'ip' => '198.51.100.21' ) )['code'] );
	}

	/** @return array{0:array<string,mixed>,1:int} form + first entry id */
	private function bulk_entries( int $count ): array {
		$form  = $this->create_published_form();
		$other = $this->create_published_form();
		$repo  = $this->plugin()->get( EntryRepository::class );
		$first = 0;
		list( , $ms ) = self::measure(
			sprintf( '%d entries: insert via repository', $count ),
			static function () use ( $repo, $form, $other, $count, &$first ): void {
				for ( $i = 0; $i < $count; $i++ ) {
					// Every 4th entry belongs to another form so that the form index is selective.
					$target = 0 === $i % 4 ? $other : $form;
					$id     = $repo->insert(
						array(
							'form_id'         => (int) $target['id'],
							'form_version_id' => (int) $target['published_version_id'],
							'status'          => 0 === $i % 10 ? 'spam' : ( 0 === $i % 3 ? 'read' : 'new' ),
							'request_key'     => hash( 'sha256', 'load-' . $i . '-' . wp_rand() ),
						)
					);
					$first  = $first ?: $id;
					$repo->insert_values(
						$id,
						array(
							'f_name01' => 'Person ' . $i,
							'f_mail01' => "person{$i}@example.org",
							'f_msg001' => str_repeat( 'Lorem ipsum dolor sit amet. ', 8 ),
						)
					);
				}
			}
		);
		$this->assertLessThan( 60000, $ms );
		return array( $form, $first );
	}

	public function test_2000_entries_export_streams_and_queries_use_indexes(): void {
		list( $form ) = $this->bulk_entries( 2000 );
		$this->as_admin();

		// CSV export into a temp file: memory must not grow with the number of rows.
		$exporter = $this->plugin()->get( CsvExporter::class );
		$stream   = tmpfile();
		$this->assertIsResource( $stream );
		$before = memory_get_usage();
		memory_reset_peak_usage();
		list( $rows, $export_ms ) = self::measure( '2000 entries: CSV export', static fn() => $exporter->export( array( 'form_id' => (int) $form['id'] ), $stream ) );
		$peak_delta               = memory_get_peak_usage() - $before;
		$size                     = (int) fstat( $stream )['size'];
		fclose( $stream );
		self::$report['2000 entries: CSV size']             = sprintf( '%.1f KB', $size / 1024 );
		self::$report['2000 entries: CSV peak memory delta'] = sprintf( '%.1f MB', $peak_delta / 1048576 );
		$this->assertSame( 1400, $rows, 'Active entries of the form (spam excluded)' );
		$this->assertLessThan( 16 * 1048576, $peak_delta, 'Export streams in batches (csv_batch) instead of loading everything' );
		$this->assertLessThan( 30000, $export_ms );

		// List / pagination.
		$entries = $this->plugin()->get( EntryRepository::class );
		list( $page1, $p1_ms )  = self::measure( '2000 entries: list page 1', static fn() => $entries->list( array( 'form_id' => (int) $form['id'], 'per_page' => 20 ) ) );
		list( $page50, $p50_ms ) = self::measure( '2000 entries: list page 50', static fn() => $entries->list( array( 'form_id' => (int) $form['id'], 'per_page' => 20, 'page' => 50 ) ) );
		$this->assertSame( 1400, $page1['total'] );
		$this->assertCount( 20, $page1['items'] );
		$this->assertCount( 20, $page50['items'] );
		$this->assertLessThan( 1000, $p1_ms );
		$this->assertLessThan( 1000, $p50_ms );

		// EXPLAIN on the main queries.
		global $wpdb;
		$e   = Tables::name( Tables::ENTRIES );
		$v   = Tables::name( Tables::ENTRY_VALUES );
		$j   = Tables::name( Tables::JOBS );
		$fid = (int) $form['id'];
		$ids = implode( ',', array_map( static fn( $i ) => (int) $i['id'], $page1['items'] ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$checks = array(
			'list (form + status, sorted)' => array( "SELECT e.* FROM {$e} e WHERE 1=1 AND e.form_id = {$fid} AND e.status = 'new' ORDER BY e.created_gmt DESC, e.id DESC LIMIT 20 OFFSET 0", array( 'form_status_created' ) ),
			'count (form + status)'        => array( "SELECT COUNT(*) FROM {$e} e WHERE 1=1 AND e.form_id = {$fid} AND e.status = 'new'", array( 'form_status_created' ) ),
			'export batch (keyset)'        => array( "SELECT e.* FROM {$e} e WHERE 1=1 AND e.form_id = {$fid} AND e.status NOT IN ('trash','spam') AND e.id > 0 ORDER BY e.id ASC LIMIT 500", array( 'PRIMARY', 'form_status_created' ) ),
			'values for page'              => array( "SELECT entry_id, field_id, value, is_multi FROM {$v} WHERE entry_id IN ({$ids})", array( 'entry_field' ) ),
			'runnable jobs'                => array( "SELECT id FROM {$j} WHERE status IN ('pending','failed') AND run_after_gmt <= '{$now}' AND (locked_until_gmt IS NULL OR locked_until_gmt < '{$now}') ORDER BY run_after_gmt ASC, id ASC LIMIT 20", array( 'runnable' ) ),
			'retention expiry'             => array( "SELECT id FROM {$e} WHERE expires_gmt IS NOT NULL AND expires_gmt <= '{$now}' LIMIT 200", array( 'expires' ) ),
		);
		foreach ( $checks as $label => list( $sql, $keys ) ) {
			$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
			$this->assertNotEmpty( $plan, $label );
			$row      = $plan[0];
			$possible = array_filter( explode( ',', (string) ( $row['possible_keys'] ?? '' ) ) );
			self::$report[ 'EXPLAIN ' . $label ] = sprintf( 'type=%s key=%s rows=%s', $row['type'] ?? '', $row['key'] ?? 'NULL', $row['rows'] ?? '' );
			$this->assertNotEmpty( array_intersect( $keys, $possible ), $label . ': expected index among possible keys, got ' . (string) wp_json_encode( $row ) );
			$this->assertNotSame( 'ALL', $row['type'] ?? '', $label . ': full table scan ' . (string) wp_json_encode( $row ) );
		}
	}

	public function test_burst_of_50_submissions_hits_rate_limit(): void {
		$form       = $this->create_published_form();
		$submission = $this->plugin()->get( SubmissionService::class );
		$codes      = array();
		list( , $ms ) = self::measure(
			'50 submissions burst (one IP)',
			function () use ( $form, $submission, &$codes ): void {
				for ( $i = 0; $i < 50; $i++ ) {
					$codes[] = $submission->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'ip' => '203.0.113.77' ) )['code'];
				}
			}
		);
		$counts = array_count_values( $codes );
		self::$report['50 submissions burst: accepted'] = (string) ( $counts['created'] ?? 0 );
		$this->assertSame( 5, $counts['created'] ?? 0, 'Per-form IP limit (spam.rate_limit = 5)' );
		$this->assertSame( 45, $counts['rate_limited'] ?? 0 );
		$this->assertSame( 5, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
		$this->assertLessThan( 20000, $ms );

		// Distributed burst: many IPs on one form → global per-IP limit irrelevant, form total still caps.
		$jobs_before = $this->count_rows( 'fc_jobs' );
		$ok          = 0;
		for ( $i = 0; $i < 20; $i++ ) {
			$ok += (int) $submission->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'ip' => '198.51.100.' . ( 100 + $i ) ) )['success'];
		}
		$this->assertSame( 20, $ok );
		$this->assertSame( 20, $this->count_rows( 'fc_jobs' ) - $jobs_before, 'One admin mail job per accepted entry' );
	}

	public function test_spam_wave_creates_no_jobs_or_events(): void {
		$form   = $this->create_published_form( null, array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => 'core.set_status', 'enabled' => true, 'settings' => array( 'status' => 'archived' ) ) ) ) );
		$events = 0;
		add_action(
			'form_captain_entry_created_v1',
			static function () use ( &$events ): void {
				++$events;
			}
		);
		$submission = $this->plugin()->get( SubmissionService::class );
		$jobs       = $this->count_rows( 'fc_jobs' );
		list( , $ms ) = self::measure(
			'spam wave: 100 honeypot submissions',
			function () use ( $form, $submission ): void {
				for ( $i = 0; $i < 100; $i++ ) {
					$raw = $this->payload( $form, $this->valid_fields() );
					$raw[ SpamGuard::honeypot_name( (string) $form['public_id'] ) ] = 'https://spam.example/' . $i;
					$res = $submission->submit( $form, $raw, array( 'ip' => '192.0.2.' . ( $i % 250 ) ) );
					$this->assertTrue( $res['success'], 'Bots get a neutral confirmation' );
				}
			}
		);
		$this->assertSame( 0, $this->count_rows( 'fc_jobs' ) - $jobs, 'Spam never creates outbox jobs' );
		$this->assertSame( 0, $events, 'Spam never fires entry_created_v1' );
		$this->assertSame( 100, $this->count_rows( 'fc_entries', "form_id = " . (int) $form['id'] . " AND status = 'spam' AND spam_reason = 'honeypot'" ) );
		$this->assertLessThan( 30000, $ms );
	}

	public function test_queue_of_500_jobs_without_cron_respects_batches(): void {
		$repo = $this->plugin()->get( JobRepository::class );
		for ( $i = 0; $i < 500; $i++ ) {
			$this->assertGreaterThan( 0, $repo->enqueue( 'test.noop', 'form-captain:load:' . $i, array( 'n' => $i ) ) );
		}
		$calls  = 0;
		$runner = $this->plugin()->get( JobRunner::class );
		$runner->set_handler(
			'test.noop',
			static function () use ( &$calls ): JobResult {
				++$calls;
				return JobResult::success();
			}
		);

		list( $stats, $ms ) = self::measure( 'queue: one worker batch', static fn() => $runner->run() );
		$this->assertSame( Limits::get( 'job_batch' ), $stats['processed'], 'Default batch size 20' );
		$this->assertSame( 20, $calls );
		$this->assertLessThan( Limits::get( 'job_batch_seconds' ) * 1000, $ms );

		// Admin fallback without cron processes at most five jobs per page view.
		$stats = $runner->run( 5 );
		$this->assertSame( 5, $stats['processed'] );
		$this->assertSame( 475, $this->count_rows( 'fc_jobs', "type = 'test.noop' AND status = 'pending'" ) );

		// Lowered limits via filter are respected (filter can only lower).
		add_filter( 'form_captain_limits_v1', static fn( array $l ): array => array_merge( $l, array( 'job_batch' => 7, 'job_batch_seconds' => 999 ) ) );
		Limits::reset();
		$this->assertSame( 7, $runner->run()['processed'] );
		$this->assertSame( 20, Limits::get( 'job_batch_seconds' ), 'Raising the time budget is ignored' );

		// Draining the whole queue needs ceil(468 / 7) runs, never more than one batch per run.
		$runs = 0;
		list( , $drain_ms ) = self::measure(
			'queue: drain 468 jobs (batch 7)',
			static function () use ( $runner, &$runs ): void {
				while ( $runner->run()['processed'] > 0 ) {
					++$runs;
				}
			}
		);
		$this->assertSame( (int) ceil( 468 / 7 ), $runs );
		$this->assertSame( 500, $calls );
		$this->assertLessThan( 60000, $drain_ms );
		remove_all_filters( 'form_captain_limits_v1' );
		Limits::reset();
	}
}
