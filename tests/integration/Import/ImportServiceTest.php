<?php
/**
 * Type A import service and admin flow (P9-06, IMP-05/06, IMP-09, IMP-11, IMP-12, IMP-15).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Import;

use FormCaptain\Admin\FormsPage;
use FormCaptain\Database\Tables;
use FormCaptain\Import\ImportAdmin;
use FormCaptain\Import\ImportService;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

final class ImportServiceTest extends TestCase {

	private const SOURCE = <<<'SRC'
<label> Ihr Name [text* your-name] </label>
<label> E-Mail [email* your-email] </label>
<label> Anliegen [select anliegen "Angebot|angebot" "Support|support"] </label>
<label> Themen [checkbox themen "Web|web" "App|app"] </label>
<label> Nachricht [textarea message] </label>
[file anhang]
[acceptance dsgvo] Ich stimme zu. [/acceptance]
[submit "Senden"]
SRC;

	private ImportService $service;

	public function set_up(): void {
		parent::set_up();
		$this->as_admin();
		$this->service = $this->plugin()->get( ImportService::class );
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_POST['fc_type_a_source'], $_POST['fc_type_a_title'], $_POST['fc_type_a_token'], $_GET['fc_type_a'] );
		Clock::freeze( null );
		parent::tear_down();
	}

	private function form_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::FORMS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_preview_is_read_only_and_reports_elements(): void {
		$before  = $this->form_count();
		$preview = $this->service->preview( self::SOURCE, 'Kontakt' );
		$this->assertSame( $before, $this->form_count() );
		$this->assertSame( 'Kontakt (Import)', $preview['title'] );
		$this->assertSame( 'lossy', $preview['status'] );
		$this->assertTrue( $preview['importable'] );
		$this->assertSame( array( 'text', 'email', 'select', 'checkbox', 'textarea', 'consent' ), array_column( $preview['schema']['fields'], 'type' ) );
		$this->assertSame( 1, $preview['counts']['dropped'] );
		$this->assertNotEmpty( $preview['warnings'] );
		$outcomes = array_column( $preview['elements'], 'outcome', 'source_type' );
		$this->assertSame( 'dropped', $outcomes['file'] );
		$this->assertSame( 'taken', $outcomes['text'] );
	}

	public function test_import_creates_valid_draft_and_record(): void {
		$result = $this->service->import( self::SOURCE, 'Kontakt' );
		$this->assertIsArray( $result );
		$form = $this->plugin()->get( FormRepository::class )->find( $result['form_id'] );
		$this->assertSame( 'draft', $form['status'] );
		$this->assertNull( $form['published_version_id'] );
		$this->assertSame( 'Kontakt (Import)', $form['title'] );
		$this->assertSame( array(), $this->plugin()->get( FormService::class )->errors_for( $form ) );
		$this->assertSame( array(), $form['settings']['actions'] );

		$record = $this->service->record( $result['form_id'] );
		$this->assertSame( 'type_a', $record['source_type'] );
		$this->assertSame( 32, strlen( $record['import_id'] ) );
		$this->assertSame( get_current_user_id(), $record['user_id'] );
		$ids = array_column( $form['schema']['fields'], 'id', 'key' );
		$this->assertSame( $ids['your_name'], $record['field_map']['your-name'] );
		$this->assertArrayNotHasKey( 'anhang', $record['field_map'] );
	}

	public function test_import_options_exclude_and_relabel(): void {
		$result = $this->service->import( self::SOURCE, 'X', array( 'exclude' => array( 'themen' ), 'labels' => array( 'message' => 'Ihre Nachricht' ) ) );
		$form   = $this->plugin()->get( FormRepository::class )->find( $result['form_id'] );
		$labels = array_column( $form['schema']['fields'], 'label', 'key' );
		$this->assertArrayNotHasKey( 'themen', $labels );
		$this->assertSame( 'Ihre Nachricht', $labels['message'] );
	}

	public function test_blocked_and_too_large_sources_are_not_imported(): void {
		$before = $this->form_count();
		$this->assertSame( 'import_blocked', $this->service->import( '[unknown x]', 'X' )->get_error_code() );
		$src = '';
		for ( $i = 1; $i <= 101; $i++ ) {
			$src .= "F$i [text f$i]\n";
		}
		$this->assertSame( 'import_limit_exceeded', $this->service->import( $src, 'X' )->get_error_code() );
		$this->assertSame( 'payload_too_large', $this->service->import( str_repeat( 'x', ImportService::MAX_SOURCE_BYTES + 1 ), 'X' )->get_error_code() );
		$this->assertSame( $before, $this->form_count() );
	}

	public function test_import_requires_capability(): void {
		$this->as_role( 'editor' );
		$this->assertSame( 'forbidden', $this->service->import( self::SOURCE, 'X' )->get_error_code() );
		$this->assertSame( 'forbidden', $this->service->import_entries( 1, "a\n1", array( 'a' => 'b' ) )->get_error_code() );
	}

	public function test_admin_flow_requires_nonce_and_capability(): void {
		$page = $this->plugin()->get( FormsPage::class );
		foreach ( array( ImportAdmin::ACTION_PREVIEW, ImportAdmin::ACTION_IMPORT ) as $action ) {
			try {
				$page->dispatch( $action );
				$this->fail( "$action without nonce must die" );
			} catch ( \WPDieException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
		$this->as_role( 'editor' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( ImportAdmin::ACTION_PREVIEW ) );
		$this->expectException( \WPDieException::class );
		$page->dispatch( ImportAdmin::ACTION_PREVIEW );
	}

	public function test_admin_flow_preview_then_confirm(): void {
		$page                      = $this->plugin()->get( FormsPage::class );
		$before                    = $this->form_count();
		$_POST['fc_type_a_source'] = wp_slash( self::SOURCE . '<script>alert("x")</script>' );
		$_POST['fc_type_a_title']  = 'Kontakt <b>alt</b>';
		$_REQUEST['_wpnonce']      = wp_create_nonce( FormsPage::nonce_action( ImportAdmin::ACTION_PREVIEW ) );
		$res                       = $page->dispatch( ImportAdmin::ACTION_PREVIEW );
		$this->assertSame( $before, $this->form_count() );
		$this->assertMatchesRegularExpression( '/fc_type_a=([a-f0-9]{24})/', $res['redirect'] );
		preg_match( '/fc_type_a=([a-f0-9]{24})/', $res['redirect'], $m );

		$_GET['fc_type_a'] = $m[1];
		ob_start();
		$this->plugin()->get( ImportAdmin::class )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'form_captain_form_type_a_import', $html );
		$this->assertStringContainsString( '[file anhang]', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<b>alt</b>', $html );

		$_POST['fc_type_a_token'] = $m[1];
		$_REQUEST['_wpnonce']     = wp_create_nonce( FormsPage::nonce_action( ImportAdmin::ACTION_IMPORT ) );
		$res                      = $page->dispatch( ImportAdmin::ACTION_IMPORT );
		$this->assertStringContainsString( 'fc_notice=imported', $res['redirect'] );
		$this->assertSame( $before + 1, $this->form_count() );

		// Token is single-use.
		$res = $page->dispatch( ImportAdmin::ACTION_IMPORT );
		$this->assertStringContainsString( 'fc_error=import_expired', $res['redirect'] );
		$this->assertSame( $before + 1, $this->form_count() );
	}

	public function test_admin_preview_rejects_too_large_source(): void {
		$_POST['fc_type_a_source'] = str_repeat( 'a', ImportService::MAX_SOURCE_BYTES + 1 );
		$_REQUEST['_wpnonce']      = wp_create_nonce( FormsPage::nonce_action( ImportAdmin::ACTION_PREVIEW ) );
		$res                       = $this->plugin()->get( FormsPage::class )->dispatch( ImportAdmin::ACTION_PREVIEW );
		$this->assertStringContainsString( 'fc_error=payload_too_large', $res['redirect'] );
	}

	/** @return array{form_id:int,ids:array<string,string>} */
	private function imported_published_form(): array {
		$result = $this->service->import( self::SOURCE, 'Kontakt' );
		$pub    = $this->plugin()->get( FormService::class )->publish( $result['form_id'] );
		$this->assertIsArray( $pub );
		$form = $this->plugin()->get( FormRepository::class )->find( $result['form_id'] );
		return array(
			'form_id' => $result['form_id'],
			'ids'     => array_column( $form['schema']['fields'], 'id', 'key' ),
		);
	}

	private function csv(): string {
		$now    = Clock::timestamp();
		$rows   = array( "\xEF\xBB\xBFZeit;Name;Mail;Anliegen;Themen;Nachricht" );
		$rows[] = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) . ';Anna;anna@example.org;Support;Web|app;"Hallo; ""Welt"""';
		$rows[] = gmdate( 'Y-m-d\TH:i:s\Z', $now - 2 * DAY_IN_SECONDS ) . ';Ben;BEN@example.org;angebot;;=SUMME(A1)';
		$rows[] = gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS ) . ';Carl;keine-mail;Support;;x';
		$rows[] = 'gestern;Dora;dora@example.org;Support;;x';
		$rows[] = gmdate( 'Y-m-d H:i:s', $now - 3 * YEAR_IN_SECONDS ) . ';Alt;alt@example.org;Support;;x';
		$rows[] = gmdate( 'Y-m-d H:i:s', $now - 4 * DAY_IN_SECONDS ) . ';;;Unbekannt;;';
		return implode( "\r\n", $rows ) . "\r\n";
	}

	public function test_entry_import_is_validated_idempotent_and_silent(): void {
		global $wpdb;
		// The CSV fixture and the assertions both derive times from "now"; freeze it so a
		// second boundary between them cannot make the comparison flaky.
		Clock::freeze( time() );
		$form    = $this->imported_published_form();
		$mapping = array( 'Name' => 'your_name', 'Mail' => 'your_email', 'Anliegen' => 'anliegen', 'Themen' => $form['ids']['themen'], 'Nachricht' => 'message' );
		$jobs    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::JOBS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$created = did_action( 'form_captain_entry_created_v1' );
		$events  = did_action( 'form_captain_entries_imported_v1' );
		reset_phpmailer_instance();

		$stats = $this->service->import_entries( $form['form_id'], $this->csv(), $mapping, array( 'time_column' => 'Zeit', 'timezone' => 'UTC' ) );
		$this->assertIsArray( $stats, is_wp_error( $stats ) ? $stats->get_error_code() : '' );
		$this->assertSame( 6, $stats['rows'] );
		$this->assertSame( 2, $stats['imported'] );
		$this->assertSame( 1, $stats['expired'] );
		$this->assertSame( 3, $stats['invalid'] );
		$this->assertSame( array( 4, 5, 7 ), array_column( $stats['findings'], 'row' ) );
		$this->assertSame( array( 'invalid_email', 'invalid_time', 'invalid_option' ), array_column( $stats['findings'], 'code' ) );
		$this->assertStringNotContainsString( 'keine-mail', (string) wp_json_encode( $stats ) );

		$entries = $this->plugin()->get( EntryRepository::class );
		$list    = $entries->list( array( 'form_id' => $form['form_id'], 'order' => 'asc' ) );
		$this->assertSame( 2, $list['total'] );
		foreach ( $list['items'] as $entry ) {
			$this->assertSame( 'read', $entry['status'] );
			$this->assertNull( $entry['user_id'] );
			$this->assertNull( $entry['source_url'] );
		}
		$anna = $entries->find_by_request_key( hash( 'sha256', $stats['import_id'] . ':2' ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', Clock::timestamp() - DAY_IN_SECONDS ), $anna['created_gmt'] );
		$values = $entries->values_for( (int) $anna['id'] );
		$this->assertSame( 'support', $values[ $form['ids']['anliegen'] ] );
		$this->assertSame( array( 'web', 'app' ), $values[ $form['ids']['themen'] ] );
		$this->assertSame( 'Hallo; "Welt"', $values[ $form['ids']['message'] ] );
		$ben = $entries->find_by_request_key( hash( 'sha256', $stats['import_id'] . ':3' ) );
		$this->assertSame( '=SUMME(A1)', $entries->values_for( (int) $ben['id'] )[ $form['ids']['message'] ] );

		$this->assertSame( $jobs, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::JOBS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( $created, did_action( 'form_captain_entry_created_v1' ) );
		$this->assertSame( $events + 1, did_action( 'form_captain_entries_imported_v1' ) );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );

		// Second run: no duplicates.
		$again = $this->service->import_entries( $form['form_id'], $this->csv(), $mapping, array( 'time_column' => 'Zeit', 'timezone' => 'UTC' ) );
		$this->assertSame( 0, $again['imported'] );
		$this->assertSame( 2, $again['duplicates'] );
		$this->assertSame( 2, $entries->list( array( 'form_id' => $form['form_id'] ) )['total'] );
	}

	public function test_entry_import_preconditions(): void {
		$plain = $this->create_published_form();
		$this->assertSame( 'import_not_found', $this->service->import_entries( (int) $plain['id'], "a\n1", array( 'a' => 'name' ), array( 'time_column' => 'a' ) )->get_error_code() );

		$draft = $this->service->import( self::SOURCE, 'Draft' );
		$this->assertSame( 'form_not_published', $this->service->import_entries( $draft['form_id'], "a\n1", array( 'a' => 'your_name' ), array( 'time_column' => 'a' ) )->get_error_code() );

		$form = $this->imported_published_form();
		$this->assertSame( 'invalid_mapping', $this->service->import_entries( $form['form_id'], "Zeit,Name\nx,y", array( 'Fehlt' => 'your_name' ), array( 'time_column' => 'Zeit' ) )->get_error_code() );
		$this->assertSame( 'invalid_mapping', $this->service->import_entries( $form['form_id'], "Zeit,Name\nx,y", array( 'Name' => 'your_name' ), array( 'time_column' => 'Nope' ) )->get_error_code() );
		$this->assertSame( 'invalid_file', $this->service->import_entries( $form['form_id'], '', array( 'Name' => 'your_name' ), array( 'time_column' => 'Zeit' ) )->get_error_code() );
	}
}
