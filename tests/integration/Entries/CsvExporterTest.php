<?php
/**
 * CSV export (P4-11, T-CSV-01…06, AC-101…104).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Database\Tables;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/EntryFixtures.php';

final class CsvExporterTest extends TestCase {

	use EntryFixtures;

	private function exporter(): CsvExporter {
		return $this->plugin()->get( CsvExporter::class );
	}

	/** @param array<string,mixed> $args */
	private function csv( array $args ): string {
		$stream = fopen( 'php://memory', 'w+b' );
		$result = $this->exporter()->export( $args, $stream );
		$this->assertIsInt( $result );
		rewind( $stream );
		$out = (string) stream_get_contents( $stream );
		fclose( $stream );
		return $out;
	}

	/** @return array<int,array<int,string>> */
	private static function parse( string $csv ): array {
		$csv = str_starts_with( $csv, "\xEF\xBB\xBF" ) ? substr( $csv, 3 ) : $csv;
		$fh  = fopen( 'php://memory', 'w+b' );
		fwrite( $fh, $csv );
		rewind( $fh );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
			$rows[] = $row;
		}
		fclose( $fh );
		return $rows;
	}

	private function form_with_fields(): array {
		return $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => '=Nachricht' ),
				array( 'id' => 'f_num001', 'type' => 'number', 'key' => 'amount', 'label' => 'Betrag' ),
				array( 'id' => 'f_iban01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
				array( 'id' => 'f_html01', 'type' => 'html', 'content' => '<p>x</p>' ),
				array( 'id' => 'f_box001', 'type' => 'checkbox', 'key' => 'topics', 'label' => 'Themen', 'options' => array( array( 'value' => 'a', 'label' => 'Äpfel' ), array( 'value' => 'b', 'label' => 'Birnen' ) ) ),
			)
		);
	}

	public function test_escape_cell_formula_injection(): void {
		$cases = array( '=1+1', '+A1', '-2+3', '@SUM(A1)', "\tX", "\rX", '＝X', '＋X', '－X', '＠X', ' =X' );
		foreach ( $cases as $case ) {
			$this->assertSame( "'" . $case, CsvExporter::escape_cell( $case ), $case );
		}
		$this->assertSame( '-5', CsvExporter::escape_cell( '-5', true ) );
		$this->assertSame( '-5.25', CsvExporter::escape_cell( '-5.25', true ) );
		$this->assertSame( "'-5", CsvExporter::escape_cell( '-5' ) );
		$this->assertSame( "'-5+1", CsvExporter::escape_cell( '-5+1', true ) );
		$this->assertSame( 'Hallo', CsvExporter::escape_cell( 'Hallo' ) );
	}

	public function test_line_quotes_rfc4180(): void {
		$this->assertSame( "a,\"b,c\",\"d \"\"e\"\"\",\"f\nG\"\r\n", CsvExporter::line( array( 'a', 'b,c', 'd "e"', "f\nG" ) ) );
		$this->assertSame( "a;\"b;c\";b,c\r\n", CsvExporter::line( array( 'a', 'b;c', 'b,c' ), ';' ) );
	}

	public function test_export_default_columns_bom_umlauts_newlines_and_injection(): void {
		$form = $this->form_with_fields();
		$id   = $this->make_entry(
			$form,
			array(
				'f_name01' => 'Jürgen Groß',
				'f_mail01' => 'j@example.org',
				'f_msg001' => "=HYPERLINK(\"http://evil\")\nZeile 2",
				'f_num001' => '-5',
				'f_iban01' => 'DE89370400440532013000',
				'f_box001' => array( 'a', 'b' ),
			)
		);
		$csv  = $this->csv( array( 'form_id' => (int) $form['id'], 'bom' => true ) );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( "\r\n", $csv );
		$rows = self::parse( $csv );
		$this->assertCount( 2, $rows );
		$head = $rows[0];
		$this->assertContains( "'=Nachricht", $head, 'header labels are escaped too' );
		$this->assertNotContains( 'IBAN', $head, 'sensitive not exported by default' );
		$this->assertStringNotContainsString( 'DE89370400440532013000', $csv );
		$row = array_combine( $head, $rows[1] );
		$this->assertSame( (string) $id, $row['Submission ID'] );
		$this->assertSame( 'Jürgen Groß', $row['Name'] );
		$this->assertSame( "'=HYPERLINK(\"http://evil\")\nZeile 2", $row["'=Nachricht"] );
		$this->assertSame( '-5', $row['Betrag'], 'numeric field keeps negative number' );
		$this->assertSame( 'Äpfel, Birnen', $row['Themen'] );
		$this->assertSame( 'New', $row['Status'] );
		$this->assertArrayNotHasKey( '', $row );
	}

	public function test_explicit_column_selection_and_sensitive_opt_in(): void {
		$form = $this->form_with_fields();
		$this->make_entry( $form, array( 'f_name01' => 'A', 'f_iban01' => 'DE00123' ) );
		$rows = self::parse( $this->csv( array( 'form_id' => (int) $form['id'], 'columns' => array( 'meta:id', 'field:f_iban01', 'field:unknown' ) ) ) );
		$this->assertSame( array( 'Submission ID', 'IBAN' ), $rows[0] );
		$this->assertSame( 'DE00123', $rows[1][1] );
	}

	public function test_status_and_date_filters_and_deleted_entries(): void {
		$form  = $this->form_with_fields();
		$a     = $this->make_entry( $form, array( 'f_name01' => 'Aktiv' ) );
		$spam  = $this->make_entry( $form, array( 'f_name01' => 'Spammer' ), array( 'status' => 'spam' ) );
		$trash = $this->make_entry( $form, array( 'f_name01' => 'Weg' ), array( 'status' => 'trash' ) );
		$gone  = $this->make_entry( $form, array( 'f_name01' => 'Geloescht' ) );
		$anon  = $this->make_entry( $form, array( 'f_name01' => '[deleted]' ) );
		$old   = $this->make_entry( $form, array( 'f_name01' => 'Alt' ) );
		$this->entries()->update( $anon, array( 'anonymized_gmt' => '2026-01-01 00:00:00' ) );
		$this->entries()->update( $old, array( 'created_gmt' => '2020-01-01 10:00:00' ) );
		$this->plugin()->get( EntryDeletionService::class )->delete( $gone );

		$csv = $this->csv( array( 'form_id' => (int) $form['id'], 'columns' => array( 'meta:id', 'meta:status', 'field:f_name01' ) ) );
		$this->assertStringContainsString( 'Aktiv', $csv );
		$this->assertStringNotContainsString( 'Spammer', $csv );
		$this->assertStringNotContainsString( 'Weg', $csv );
		$this->assertStringNotContainsString( 'Geloescht', $csv );
		$this->assertStringContainsString( '[deleted]', $csv );
		$this->assertStringContainsString( 'Anonymized', $csv, 'anonymized entries are not shown as active' );

		$csv = $this->csv( array( 'form_id' => (int) $form['id'], 'status' => array( 'spam', 'trash' ), 'columns' => array( 'field:f_name01' ) ) );
		$this->assertStringContainsString( 'Spammer', $csv );
		$this->assertStringContainsString( 'Weg', $csv );
		$this->assertStringNotContainsString( 'Aktiv', $csv );

		$csv = $this->csv( array( 'form_id' => (int) $form['id'], 'date_from' => '2019-12-31', 'date_to' => '2020-01-02', 'columns' => array( 'field:f_name01' ) ) );
		$this->assertSame( array( array( 'Name' ), array( 'Alt' ) ), self::parse( $csv ) );
		unset( $a, $spam, $trash );
	}

	public function test_fields_of_older_versions_are_exportable(): void {
		$form             = $this->form_with_fields();
		$old              = $this->make_entry( $form, array( 'f_name01' => 'Alt', 'f_num001' => '3' ) );
		$schema           = $form['schema'];
		$schema['fields'] = array_values( array_filter( $schema['fields'], static fn( $f ) => 'f_num001' !== $f['id'] ) );
		$this->forms()->update( (int) $form['id'], array( 'schema' => $schema ), (int) $form['revision'] );
		$this->forms()->publish_version( (int) $form['id'], $schema );
		$columns = $this->exporter()->columns( $this->forms()->find( (int) $form['id'] ) );
		$this->assertTrue( $columns['field:f_num001']['removed'] );
		$this->assertSame( 'Betrag (removed)', $columns['field:f_num001']['label'] );
		$rows = self::parse( $this->csv( array( 'form_id' => (int) $form['id'], 'columns' => array( 'meta:id', 'field:f_num001' ) ) ) );
		$this->assertSame( array( (string) $old, '3' ), $rows[1] );
	}

	public function test_form_is_required(): void {
		$stream = fopen( 'php://memory', 'w+b' );
		$this->assertSame( 'invalid_request', $this->exporter()->export( array(), $stream )->get_error_code() );
		$this->assertSame( '', stream_get_contents( $stream, -1, 0 ) );
	}

	public function test_request_checks_capability_method_and_nonce(): void {
		$this->as_role( 'editor' );
		$this->assertSame( 'forbidden', $this->exporter()->check_request( 'POST', array( '_wpnonce' => wp_create_nonce( CsvExporter::NONCE_ACTION ) ) )->get_error_code() );
		$this->as_admin();
		$this->assertSame( 'method_not_allowed', $this->exporter()->check_request( 'GET', array( '_wpnonce' => wp_create_nonce( CsvExporter::NONCE_ACTION ) ) )->get_error_code() );
		$this->assertSame( 'invalid_nonce', $this->exporter()->check_request( 'POST', array() )->get_error_code() );
		$this->assertSame( 'invalid_nonce', $this->exporter()->check_request( 'POST', array( '_wpnonce' => wp_create_nonce( 'other' ) ) )->get_error_code() );
		$this->assertTrue( $this->exporter()->check_request( 'POST', array( '_wpnonce' => wp_create_nonce( CsvExporter::NONCE_ACTION ) ) ) );
	}

	public function test_export_is_logged_without_pii(): void {
		global $wpdb;
		$user = $this->as_admin();
		$form = $this->form_with_fields();
		$id   = $this->make_entry( $form, array( 'f_name01' => 'Geheim Name', 'f_mail01' => 'secret@example.org' ) );
		$this->csv( array( 'form_id' => (int) $form['id'] ) );
		$log = $wpdb->get_row( 'SELECT * FROM ' . Tables::name( Tables::LOG ) . " WHERE code = 'export_created' ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertSame( (int) $form['id'], (int) $log['form_id'] );
		$this->assertStringContainsString( 'user=' . $user, $log['message'] );
		$this->assertStringContainsString( 'rows=1', $log['message'] );
		$this->assertStringNotContainsString( 'Geheim', $log['message'] );
		$this->assertSame( array(), array_filter( $this->entries()->notes_for( $id ), static fn( $n ) => str_contains( (string) $n['message'], 'export' ) ), 'no per-entry audit note' );
	}
}
