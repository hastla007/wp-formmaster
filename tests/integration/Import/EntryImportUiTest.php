<?php
/**
 * Admin UI for the CSV entry import (upload → mapping → dry run → batch import → revert):
 * rights and nonce per step, separators/BOM/umlauts, date formats, limits, malicious
 * values, idempotency, abort + resume, revert and temp file deletion (IMP-11…IMP-15).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

namespace FormCaptain\Tests\Integration\Import;

use FormCaptain\Admin\FormsPage;
use FormCaptain\Database\Tables;
use FormCaptain\Import\EntryImportAdmin;
use FormCaptain\Import\EntryImportService;
use FormCaptain\Import\Profiles\GenericCsv;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Service\RetentionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

final class EntryImportUiTest extends TestCase {

	private EntryImportService $service;

	private EntryRepository $entries;

	/** @var array<string,mixed> */
	private array $form;

	public function set_up(): void {
		parent::set_up();
		$this->as_admin();
		$this->service = $this->plugin()->get( EntryImportService::class );
		$this->entries = $this->plugin()->get( EntryRepository::class );
		$this->form    = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
				array( 'id' => 'f_topic1', 'type' => 'select', 'key' => 'topic', 'label' => 'Anliegen', 'required' => false, 'options' => array( array( 'label' => 'Support', 'value' => 'support' ), array( 'label' => 'Angebot', 'value' => 'angebot' ) ) ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht', 'required' => false ),
			)
		);
	}

	public function tear_down(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$_FILES   = array();
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	// Helpers.

	private function form_id(): int {
		return (int) $this->form['id'];
	}

	/**
	 * @param array<int,array<int,string>> $rows
	 */
	private static function csv( array $rows, string $delim = ';', bool $bom = false ): string {
		$out = $bom ? "\xEF\xBB\xBF" : '';
		foreach ( $rows as $cells ) {
			$out .= implode(
				$delim,
				array_map( static fn( string $c ): string => preg_match( '/["\r\n;,]/', $c ) ? '"' . str_replace( '"', '""', $c ) . '"' : $c, $cells )
			) . "\r\n";
		}
		return $out;
	}

	private static function recent( int $days_ago ): string {
		return gmdate( 'Y-m-d H:i:s', Clock::timestamp() - $days_ago * DAY_IN_SECONDS );
	}

	/** A file with $n valid rows. */
	private static function valid_csv( int $n, string $salt = '' ): string {
		$rows = array( array( 'Zeit', 'Name', 'E-Mail', 'Anliegen', 'Nachricht' ) );
		for ( $i = 1; $i <= $n; $i++ ) {
			$rows[] = array( self::recent( 1 + $i % 30 ), "Person $i$salt", "p$i@example.org", 'Support', "Text $i" );
		}
		return self::csv( $rows );
	}

	/** Column mapping for valid_csv(). */
	private static function columns(): array {
		return array( 1 => 'f_name01', 2 => 'f_mail01', 3 => 'f_topic1', 4 => 'f_msg001' );
	}

	/** @param array<string,mixed> $post */
	private function step( string $action, array $post = array() ): string {
		$_POST                = $post + array( 'form' => (string) $this->form_id() );
		$_REQUEST             = $_POST;
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( $action, $this->form_id() ) );
		return (string) $this->plugin()->get( FormsPage::class )->dispatch( $action )['redirect'];
	}

	private function stage( string $csv ): string {
		$token = $this->service->stage( $this->form_id(), $csv );
		$this->assertIsString( $token, is_wp_error( $token ) ? $token->get_error_code() : '' );
		return $token;
	}

	/** Upload + mapping (dry run) through the handlers; returns the token. */
	private function prepare( string $csv, string $format = 'auto' ): string {
		$token = $this->stage( $csv );
		$url   = $this->step(
			EntryImportAdmin::ACTION_MAP,
			array(
				'fc_token'       => $token,
				'fc_col'         => array_map( 'strval', self::columns() ) + array( 0 => '' ),
				'fc_time_col'    => '0',
				'fc_time_format' => $format,
			)
		);
		$this->assertStringNotContainsString( 'fc_ei_error', $url, (string) get_transient( 'form_captain_eimp_msg_' . get_current_user_id() ) );
		return $token;
	}

	/** Runs import steps until done; returns the run ID. */
	private function import_all( string $token, int $max_steps = 50 ): string {
		for ( $i = 0; $i < $max_steps; $i++ ) {
			$url = $this->step( EntryImportAdmin::ACTION_IMPORT, array( 'fc_token' => $token ) );
			$this->assertStringNotContainsString( 'fc_ei_error', $url );
			if ( preg_match( '/fc_run=([a-f0-9]{32})/', $url, $m ) ) {
				return $m[1];
			}
		}
		$this->fail( 'Import did not finish' );
	}

	private function count_entries(): int {
		return (int) $this->entries->list( array( 'form_id' => $this->form_id(), 'any_status' => true ) )['total'];
	}

	private function file_of( string $token ): string {
		$state = $this->service->state( $token );
		return EntryImportService::directory() . '/' . $state['file'];
	}

	private function render( array $get ): string {
		$_GET = $get + array( EntryImportAdmin::QUERY => (string) $this->form_id() );
		ob_start();
		$this->plugin()->get( FormsPage::class )->render();
		return (string) ob_get_clean();
	}

	private function user_with_caps( array $caps ): int {
		$id   = $this->as_role( 'subscriber' );
		$user = wp_get_current_user();
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
		return $id;
	}

	// Rights and nonce.

	public function test_every_step_requires_nonce_and_capabilities(): void {
		$page = $this->plugin()->get( FormsPage::class );
		foreach ( EntryImportAdmin::ACTIONS as $action ) {
			$_REQUEST = array( 'form' => (string) $this->form_id() );
			try {
				$page->dispatch( $action );
				$this->fail( "$action without nonce must die" );
			} catch ( \WPDieException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
		$token = $this->stage( self::valid_csv( 3 ) );

		// Editor (no Form Captain capabilities): dies before anything happens.
		$this->as_role( 'editor' );
		foreach ( EntryImportAdmin::ACTIONS as $action ) {
			try {
				$this->step( $action, array( 'fc_token' => $token ) );
				$this->fail( "$action as editor must die" );
			} catch ( \WPDieException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}

		// manage_forms without edit_entries: every import step is refused.
		$this->user_with_caps( array( Capabilities::MANAGE_FORMS, Capabilities::READ_ENTRIES ) );
		foreach ( EntryImportAdmin::ACTIONS as $action ) {
			$this->assertStringContainsString( 'fc_ei_error=forbidden', $this->step( $action, array( 'fc_token' => $token ) ), $action );
		}
		$this->assertInstanceOf( \WP_Error::class, $this->service->stage( $this->form_id(), self::valid_csv( 1 ) ) );
		$this->assertStringNotContainsString( 'form_captain_form_entries_upload', $this->render( array() ) );

		// manage_forms + edit_entries without delete_entries: no revert.
		$this->user_with_caps( array( Capabilities::MANAGE_FORMS, Capabilities::EDIT_ENTRIES ) );
		$this->assertStringContainsString( 'fc_ei_error=forbidden', $this->step( EntryImportAdmin::ACTION_REVERT, array( 'fc_run' => str_repeat( 'a', 32 ) ) ) );
		$this->assertSame( 'forbidden', $this->service->revert_batch( $this->form_id(), str_repeat( 'a', 32 ) )->get_error_code() );
		$this->assertSame( 0, $this->count_entries() );
	}

	public function test_state_is_bound_to_user_and_form(): void {
		$token = $this->stage( self::valid_csv( 2 ) );
		$other = $this->create_published_form();
		// Same user, other form ID in the request: refused.
		$_POST                = array( 'form' => (string) $other['id'], 'fc_token' => $token );
		$_REQUEST             = $_POST;
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( EntryImportAdmin::ACTION_IMPORT, (int) $other['id'] ) );
		$url                  = $this->plugin()->get( FormsPage::class )->dispatch( EntryImportAdmin::ACTION_IMPORT )['redirect'];
		$this->assertStringContainsString( 'fc_ei_error=import_expired', $url );
		// Other administrator: token unknown.
		$this->as_admin();
		$this->assertNull( $this->service->state( $token ) );
		$this->assertStringContainsString( 'fc_ei_error=import_expired', $this->step( EntryImportAdmin::ACTION_IMPORT, array( 'fc_token' => $token ) ) );
	}

	public function test_upload_handler_accepts_only_real_uploads(): void {
		$tmp = wp_tempnam( 'fc-test' );
		file_put_contents( $tmp, self::valid_csv( 2 ) );
		$_FILES = array(
			'fc_entry_csv' => array(
				'name'     => 'export.csv',
				'tmp_name' => $tmp,
				'size'     => filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			),
		);
		$url    = $this->step( EntryImportAdmin::ACTION_UPLOAD );
		wp_delete_file( $tmp );
		$this->assertStringContainsString( 'fc_ei_error=import_no_file', $url, 'is_uploaded_file() must reject local paths' );
		$this->assertStringNotContainsString( 'fc_token', $url );
	}

	public function test_draft_form_is_refused(): void {
		$this->plugin()->get( \FormCaptain\Repository\FormRepository::class )->set_status( $this->form_id(), 'draft' );
		$this->assertSame( 'form_not_published', $this->service->stage( $this->form_id(), self::valid_csv( 1 ) )->get_error_code() );
	}

	// Format: separators, BOM, umlauts, dates, limits.

	public function test_separators_bom_and_umlauts(): void {
		foreach ( array( array( ';', true ), array( ',', false ), array( ',', true ), array( ';', false ) ) as $n => list( $delim, $bom ) ) {
			$csv   = self::csv(
				array(
					array( 'Zeit', 'Name', 'E-Mail', 'Anliegen', 'Nachricht' ),
					array( self::recent( 2 ), "Jürgen Müller-Straße $n", "j$n@example.org", 'Angebot', "Grüße; \"zitiert\", äöüß\nzweite Zeile" ),
				),
				$delim,
				$bom
			);
			$token = $this->stage( $csv );
			$state = $this->service->state( $token );
			$this->assertSame( $delim, $state['delimiter'] );
			$this->assertSame( $bom, $state['bom'] );
			$this->assertSame( 'Zeit', $state['header'][0], 'BOM is not part of the first header' );
			$this->assertSame( 1, $state['rows'] );
			$token = $this->prepare( $csv );
			$this->import_all( $token );
		}
		$this->assertSame( 4, $this->count_entries() );
		$entry  = $this->entries->list( array( 'form_id' => $this->form_id(), 'order' => 'asc' ) )['items'][0];
		$values = $this->entries->values_for( (int) $entry['id'] );
		$this->assertSame( 'Jürgen Müller-Straße 0', $values['f_name01'] );
		$this->assertSame( "Grüße; \"zitiert\", äöüß\nzweite Zeile", $values['f_msg001'] );
		$this->assertSame( 'angebot', $values['f_topic1'] );
	}

	public function test_non_utf8_and_empty_files_are_refused(): void {
		$this->assertSame( 'invalid_file', $this->service->stage( $this->form_id(), mb_convert_encoding( "Zeit;Name\n2026-01-01;Müller\n", 'ISO-8859-1', 'UTF-8' ) )->get_error_code() );
		$this->assertSame( 'invalid_file', $this->service->stage( $this->form_id(), "\xEF\xBB\xBF \n" )->get_error_code() );
		$this->assertSame( 'no_rows', $this->service->stage( $this->form_id(), "Zeit;Name\r\n" )->get_error_code() );
	}

	public function test_date_formats_are_detected_and_converted_from_site_timezone(): void {
		$berlin = new \DateTimeZone( 'Europe/Berlin' );
		$cases  = array(
			'iso'       => array( array( '2026-03-01T10:15:00+02:00', '2026-03-02 10:15:00' ), array( '2026-03-01 08:15:00', '2026-03-02 09:15:00' ) ),
			'ymd'       => array( array( '2026-07-01 10:15', '2026-07-02' ), array( '2026-07-01 08:15:00', '2026-07-01 22:00:00' ) ),
			'dmy_dot'   => array( array( '01.07.2026 10:15', '2.7.2026', '03.07.2026 23:59:59' ), array( '2026-07-01 08:15:00', '2026-07-01 22:00:00', '2026-07-03 21:59:59' ) ),
			'dmy_slash' => array( array( '13/01/2026 10:15', '02/01/2026' ), array( '2026-01-13 09:15:00', '2026-01-01 23:00:00' ) ),
			'mdy_slash' => array( array( '01/13/2026 10:15', '02/01/2026' ), array( '2026-01-13 09:15:00', '2026-01-31 23:00:00' ) ),
			'unix'      => array( array( '1767225600' ), array( '2026-01-01 00:00:00' ) ),
		);
		foreach ( $cases as $format => list( $samples, $expected ) ) {
			$this->assertSame( $format, GenericCsv::detect_time_format( $samples ), $format );
			foreach ( $samples as $i => $sample ) {
				$this->assertSame( $expected[ $i ], GenericCsv::to_gmt( $sample, $berlin, $format ), "$format $sample" );
			}
		}
		$this->assertNull( GenericCsv::detect_time_format( array( 'gestern', '2026-01-01' ) ) );
		$this->assertNull( GenericCsv::to_gmt( '31.02.2026', $berlin, 'dmy_dot' ) );
		$this->assertNull( GenericCsv::to_gmt( '2026-01-01 24:00', $berlin, 'ymd' ) );
		$this->assertNull( GenericCsv::to_gmt( '01.01.2026', $berlin, 'nope' ) );

		// Through the UI: site time zone Europe/Berlin, German format auto-detected.
		update_option( 'timezone_string', 'Europe/Berlin' );
		$day   = gmdate( 'd.m.Y', Clock::timestamp() - 10 * DAY_IN_SECONDS );
		$csv   = self::csv(
			array(
				array( 'Eingang', 'Name', 'E-Mail', 'Anliegen', 'Nachricht' ),
				array( "$day 12:00", 'A', 'a@example.org', '', '' ),
				array( $day, 'B', 'b@example.org', '', '' ),
			)
		);
		$token = $this->prepare( $csv );
		$this->assertSame( 'dmy_dot', $this->service->state( $token )['time_format'] );
		$run     = $this->import_all( $token );
		$created = array();
		foreach ( $this->entries->list( array( 'form_id' => $this->form_id() ) )['items'] as $e ) {
			$created[] = $e['created_gmt'];
			$this->assertSame( RetentionService::expires_for( RetentionService::mode_for_form( $this->form ), (string) $e['created_gmt'] ), $e['expires_gmt'], 'Retention from original time' );
			$this->assertSame( $run, $e['context']['import_run'] );
		}
		sort( $created );
		$local = \DateTimeImmutable::createFromFormat( '!d.m.Y H:i', "$day 12:00", $berlin )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$this->assertSame( $local->format( 'Y-m-d H:i:s' ), $created[1] );
		$this->assertSame( $local->setTimezone( $berlin )->setTime( 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $created[0] );
	}

	public function test_unknown_date_format_is_reported(): void {
		$token = $this->stage( self::csv( array( array( 'Zeit', 'Name' ), array( 'gestern', 'A' ) ) ) );
		$this->assertSame( 'time_format_unknown', $this->service->map( $token, array( 1 => 'f_name01' ), 0, 'auto' )->get_error_code() );
		$this->assertSame( 'invalid_mapping', $this->service->map( $token, array( 1 => 'f_name01' ), 7, 'iso' )->get_error_code() );
		$this->assertSame( 'invalid_mapping', $this->service->map( $token, array( 1 => 'f_unknown' ), 0, 'iso' )->get_error_code() );
		$this->assertSame( 'invalid_mapping', $this->service->map( $token, array( 0 => 'f_name01', 1 => 'f_name01' ), 0, 'iso' )->get_error_code() );
	}

	public function test_limits(): void {
		$this->assertSame( 5242880, EntryImportService::MAX_BYTES );
		$this->assertSame( 10000, EntryImportService::MAX_ROWS );
		$this->assertSame( 500, EntryImportService::BATCH );

		$big = "Zeit;Name\r\n" . str_repeat( self::recent( 1 ) . ';' . str_repeat( 'x', 1000 ) . "\r\n", 5300 );
		$this->assertGreaterThan( EntryImportService::MAX_BYTES, strlen( $big ) );
		$this->assertSame( 'payload_too_large', $this->service->stage( $this->form_id(), $big )->get_error_code() );

		$line = self::recent( 1 ) . ";A\r\n";
		$this->assertSame( 'too_many_rows', $this->service->stage( $this->form_id(), "Zeit;Name\r\n" . str_repeat( $line, EntryImportService::MAX_ROWS + 1 ) )->get_error_code() );
		$token = $this->stage( "Zeit;Name\r\n" . str_repeat( $line, EntryImportService::MAX_ROWS ) );
		$this->assertSame( EntryImportService::MAX_ROWS, $this->service->state( $token )['rows'] );
		$this->service->discard( $token );
	}

	// Malicious content.

	public function test_formulas_and_html_are_stored_as_entered_and_escaped_on_output(): void {
		$evil  = array( '=HYPERLINK("http://evil.example","x")', '<script>alert(1)</script>', '+SUM(1;2)', '<img src=x onerror=alert(2)>' );
		$csv   = self::csv(
			array(
				array( 'Zeit', 'Name<script>alert(3)</script>', 'E-Mail', 'Anliegen', 'Nachricht' ),
				array( self::recent( 1 ), $evil[0], 'x@example.org', '', $evil[1] ),
				array( self::recent( 2 ), $evil[2], 'y@example.org', '', $evil[3] ),
			)
		);
		$token = $this->stage( $csv );
		$html  = $this->render( array( 'fc_token' => $token ) );
		$this->assertStringContainsString( 'form_captain_form_entries_map', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $html );
		$this->assertStringContainsString( '=HYPERLINK(', $html );
		$this->assertStringNotContainsString( 'evil.example","x")', $html, 'Preview values are shortened' );

		$token = $this->prepare( $csv );
		$this->import_all( $token );
		$stored = array();
		foreach ( $this->entries->list( array( 'form_id' => $this->form_id(), 'order' => 'asc' ) )['items'] as $e ) {
			$values   = $this->entries->values_for( (int) $e['id'] );
			$stored[] = $values['f_name01'];
			$stored[] = $values['f_msg001'];
		}
		$this->assertEqualsCanonicalizing( $evil, $stored );
		$this->assertSame( 4, count( array_intersect( $evil, $stored ) ) );
	}

	// Full flow, report, silence, idempotency.

	public function test_full_flow_reports_without_values_and_is_silent(): void {
		global $wpdb;
		$csv     = self::csv(
			array(
				array( 'Zeit', 'Name', 'E-Mail', 'Anliegen', 'Nachricht' ),
				array( self::recent( 1 ), 'Anna', 'anna@example.org', 'support', 'ok' ),
				array( self::recent( 2 ), 'Ben', 'keine-mail-geheim', 'support', 'x' ),
				array( 'kaputt', 'Carl', 'c@example.org', '', '' ),
				array( gmdate( 'Y-m-d H:i:s', Clock::timestamp() - 3 * YEAR_IN_SECONDS ), 'Alt', 'alt@example.org', '', '' ),
				array( self::recent( 3 ), 'Dora', 'dora@example.org', 'Unbekannt-Option', '' ),
			)
		);
		$jobs    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::JOBS ) );
		$created = did_action( 'form_captain_entry_created_v1' );
		reset_phpmailer_instance();

		$token = $this->prepare( $csv, 'iso' );
		$check = $this->service->state( $token )['check'];
		$this->assertSame( array( 5, 1, 0, 3, 1 ), array( $check['rows'], $check['valid'], $check['duplicates'], $check['invalid'], $check['expired'] ) );
		$this->assertSame( array( 3, 4, 6 ), array_column( $check['findings'], 'row' ) );
		$this->assertSame( array( 'invalid_email', 'invalid_time', 'invalid_option' ), array_column( $check['findings'], 'code' ) );
		$this->assertSame( 0, $this->count_entries(), 'Dry run writes nothing' );
		$html = $this->render( array( 'fc_token' => $token ) );
		$this->assertStringContainsString( 'invalid_email', $html );
		$report = substr( $html, (int) strpos( $html, 'fc-entry-counts' ), (int) strpos( $html, 'Data protection' ) - (int) strpos( $html, 'fc-entry-counts' ) );
		$this->assertStringContainsString( 'invalid_email', $report );
		$this->assertStringNotContainsString( 'keine-mail-geheim', $report, 'Report shows no values' );
		$this->assertStringNotContainsString( 'kaputt', $report );
		$this->assertStringContainsString( 'form_captain_form_entries_import', $html );

		$run = $this->import_all( $token );
		$this->assertSame( 1, $this->count_entries() );
		$log = $this->service->run( $run );
		$this->assertSame( array( 'done', 1, 3, 1 ), array( $log['status'], $log['imported'], $log['invalid'], $log['expired'] ) );
		$this->assertStringNotContainsString( 'keine-mail-geheim', (string) wp_json_encode( get_option( EntryImportService::RUNS_OPTION ) ) );
		$entry = $this->entries->list( array( 'form_id' => $this->form_id() ) )['items'][0];
		$this->assertSame( 'read', $entry['status'] );
		$this->assertNull( $entry['user_id'] );
		$this->assertSame( $jobs, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::JOBS ) ) );
		$this->assertSame( $created, did_action( 'form_captain_entry_created_v1' ) );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );

		$html = $this->render( array( 'fc_run' => $run, 'fc_ei_notice' => 'imported' ) );
		$this->assertStringContainsString( 'page=form-captain-entries&#038;form_id=' . $this->form_id(), $html );
		$this->assertStringContainsString( 'form_captain_form_entries_revert', $html );
		$this->assertStringNotContainsString( 'form-captain-private', $html );
	}

	public function test_repeated_import_creates_no_duplicates(): void {
		$csv = self::valid_csv( 7 );
		$this->import_all( $this->prepare( $csv ) );
		$this->assertSame( 7, $this->count_entries() );
		$token = $this->prepare( $csv );
		$check = $this->service->state( $token )['check'];
		$this->assertSame( 0, $check['valid'] );
		$this->assertSame( 7, $check['duplicates'] );
		$html = $this->render( array( 'fc_token' => $token ) );
		$this->assertStringNotContainsString( 'form_captain_form_entries_import"', $html, 'No import button without valid rows' );
		// Forced anyway (e.g. a stale browser tab): still no duplicates.
		$run = $this->import_all( $token );
		$this->assertSame( 7, $this->count_entries() );
		$this->assertSame( 7, $this->service->run( $run )['duplicates'] );
		$this->assertSame( 0, $this->service->run( $run )['imported'] );
	}

	public function test_abort_and_resume_in_batches(): void {
		$csv   = self::valid_csv( 1200 );
		$token = $this->prepare( $csv );
		$url   = $this->step( EntryImportAdmin::ACTION_IMPORT, array( 'fc_token' => $token ) );
		$this->assertStringContainsString( 'fc_token=' . $token, $url, 'More batches follow' );
		$this->assertSame( 500, $this->count_entries() );
		$state = $this->service->state( $token );
		$this->assertSame( 500, $state['offset'] );
		$html = $this->render( array( 'fc_token' => $token ) );
		$this->assertStringContainsString( 'data-fc-autosubmit', $html );
		$this->assertStringContainsString( '<progress max="1200" value="500">', $html );

		// Abort inside a batch: the stored offset lags behind (rows 1–500 are repeated).
		$state['offset'] = 0;
		set_transient( 'form_captain_eimp_' . get_current_user_id() . '_' . $token, $state, 600 );
		$run = $this->import_all( $token );
		$this->assertSame( 1200, $this->count_entries() );
		$log = $this->service->run( $run );
		$this->assertSame( 1200, $log['imported'] );
		$this->assertSame( 0, $log['duplicates'] );

		// Upload lost (transient expired) in a second, interrupted run: re-upload continues.
		$csv2   = self::valid_csv( 600, 'b' );
		$token2 = $this->prepare( $csv2 );
		$this->step( EntryImportAdmin::ACTION_IMPORT, array( 'fc_token' => $token2 ) );
		$this->service->discard( $token2 );
		$this->assertStringContainsString( 'fc_ei_error=import_expired', $this->step( EntryImportAdmin::ACTION_IMPORT, array( 'fc_token' => $token2 ) ) );
		$token3 = $this->prepare( $csv2 );
		$check  = $this->service->state( $token3 )['check'];
		$this->assertSame( array( 100, 500 ), array( $check['valid'], $check['duplicates'] ) );
		$this->import_all( $token3 );
		$this->assertSame( 1800, $this->count_entries() );
		$this->assertCount( 3, $this->service->runs_for_form( $this->form_id() ) );
	}

	// Revert.

	public function test_revert_deletes_exactly_this_run_within_seven_days(): void {
		$manual = $this->entries->insert(
			array(
				'form_id'         => $this->form_id(),
				'form_version_id' => (int) $this->form['published_version_id'],
				'status'          => 'new',
				'request_key'     => hash( 'sha256', 'manual' ),
			)
		);
		$run_a  = $this->import_all( $this->prepare( self::valid_csv( 3, 'a' ) ) );
		$run_b  = $this->import_all( $this->prepare( self::valid_csv( 4, 'b' ) ) );
		$this->assertSame( 8, $this->count_entries() );
		$ids_a = $this->entries->ids_for_import_run( $this->form_id(), $run_a, 100 );
		$this->entries->set_status( $ids_a[0], 'trash' ); // Changed afterwards: still part of the run.

		$reasons = array();
		$spy     = static function ( $id, $form, $reason ) use ( &$reasons ): void {
			$reasons[] = $reason;
		};
		add_action( 'form_captain_entry_deleted_v1', $spy, 10, 3 );
		$url = $this->step( EntryImportAdmin::ACTION_REVERT, array( 'fc_run' => $run_a ) );
		remove_action( 'form_captain_entry_deleted_v1', $spy, 10 );
		$this->assertStringContainsString( 'fc_ei_notice=reverted', $url );
		$this->assertSame( array( 'import_reverted', 'import_reverted', 'import_reverted' ), $reasons );
		$this->assertSame( 5, $this->count_entries() );
		$this->assertNotNull( $this->entries->find( $manual ) );
		$this->assertCount( 4, $this->entries->ids_for_import_run( $this->form_id(), $run_b, 100 ) );
		$this->assertSame( 'reverted', $this->service->run( $run_a )['status'] );
		$this->assertSame( 3, $this->service->run( $run_a )['reverted'] );
		$this->assertStringContainsString( 'fc_ei_error=revert_expired', $this->step( EntryImportAdmin::ACTION_REVERT, array( 'fc_run' => $run_a ) ) );

		// Unknown run, foreign form.
		$this->assertSame( 'import_not_found', $this->service->revert_batch( $this->form_id(), str_repeat( 'f', 32 ) )->get_error_code() );
		$this->assertSame( 'import_not_found', $this->service->revert_batch( (int) $this->create_published_form()['id'], $run_b )->get_error_code() );

		// After 7 days: no revert.
		Clock::freeze( Clock::timestamp() + 8 * DAY_IN_SECONDS );
		$this->assertSame( 'revert_expired', $this->service->revert_batch( $this->form_id(), $run_b )->get_error_code() );
		$this->assertStringNotContainsString( 'name="fc_run" value="' . $run_b, $this->render( array() ) );
		$this->assertCount( 4, $this->entries->ids_for_import_run( $this->form_id(), $run_b, 100 ) );
	}

	public function test_revert_runs_in_batches(): void {
		$run = $this->import_all( $this->prepare( self::valid_csv( 650 ) ) );
		$url = $this->step( EntryImportAdmin::ACTION_REVERT, array( 'fc_run' => $run ) );
		$this->assertStringContainsString( 'fc_revert=' . $run, $url );
		$this->assertSame( 150, $this->count_entries() );
		$this->assertStringContainsString( 'data-fc-autosubmit', $this->render( array( 'fc_revert' => $run ) ) );
		$this->assertStringContainsString( 'fc_ei_notice=reverted', $this->step( EntryImportAdmin::ACTION_REVERT, array( 'fc_run' => $run ) ) );
		$this->assertSame( 0, $this->count_entries() );
		$this->assertSame( 650, $this->service->run( $run )['reverted'] );
	}

	// Temporary file.

	public function test_temp_file_is_private_and_deleted(): void {
		$dir = EntryImportService::directory();
		$this->assertIsString( $dir );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertFileExists( dirname( $dir ) . '/.htaccess' );

		// Finished import: file and state are gone, the URL never contains the path.
		$token = $this->stage( self::valid_csv( 2 ) );
		$file  = $this->file_of( $token );
		$this->assertFileExists( $file );
		$this->assertNotFalse( wp_next_scheduled( EntryImportService::CLEANUP_HOOK, array( basename( $file ) ) ) );
		$this->step( EntryImportAdmin::ACTION_MAP, array( 'fc_token' => $token, 'fc_col' => array_map( 'strval', self::columns() ), 'fc_time_col' => '0', 'fc_time_format' => 'auto' ) );
		$url = $this->step( EntryImportAdmin::ACTION_IMPORT, array( 'fc_token' => $token ) );
		$this->assertStringNotContainsString( basename( $file ), $url );
		$this->assertStringNotContainsString( 'form-captain-private', $url );
		$this->assertFileDoesNotExist( $file );
		$this->assertNull( $this->service->state( $token ) );

		// Cancel.
		$token = $this->stage( self::valid_csv( 2 ) );
		$file  = $this->file_of( $token );
		$this->assertStringContainsString( 'fc_ei_notice=cancelled', $this->step( EntryImportAdmin::ACTION_CANCEL, array( 'fc_token' => $token ) ) );
		$this->assertFileDoesNotExist( $file );

		// Scheduled single event and hourly sweep (files older than 1 h).
		$token = $this->stage( self::valid_csv( 2 ) );
		$file  = $this->file_of( $token );
		do_action( EntryImportService::CLEANUP_HOOK, basename( $file ) );
		$this->assertFileDoesNotExist( $file );
		do_action( EntryImportService::CLEANUP_HOOK, '../../wp-config.php' ); // Only own random names are accepted.

		$token = $this->stage( self::valid_csv( 2 ) );
		$old   = $this->file_of( $token );
		$fresh = $this->file_of( $this->stage( self::valid_csv( 3 ) ) );
		touch( $old, time() - 2 * HOUR_IN_SECONDS );
		do_action( 'form_captain_hourly_cleanup' );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $fresh );
		$this->assertStringContainsString( 'fc_ei_error=import_expired', $this->step( EntryImportAdmin::ACTION_MAP, array( 'fc_token' => $token, 'fc_col' => array( 1 => 'f_name01' ), 'fc_time_col' => '0' ) ) );
		wp_delete_file( $fresh );
	}

	// Entry points.

	public function test_entry_points_and_privacy_notice(): void {
		$_GET = array();
		ob_start();
		$this->plugin()->get( FormsPage::class )->render();
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( 'fc_entry_import=' . $this->form_id(), $list, 'Row action for published forms' );
		$this->assertStringContainsString( 'name="fc_entry_import"', $list, 'Box in the import area' );

		$html = $this->render( array() );
		$this->assertStringContainsString( 'form_captain_form_entries_upload', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'legal basis', $html );
		$this->assertStringContainsString( 'Retention period of this form', $html );
	}
}
