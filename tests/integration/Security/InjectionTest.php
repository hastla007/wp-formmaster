<?php
/**
 * P5-08 / P6-16: SQL, HTML and script payloads in form titles, labels, options, field values
 * and search parameters are neutralised in every output channel (admin list, detail, frontend,
 * mail, CSV, JSON import); manipulated payloads, limits and replays are rejected.
 *
 * Payloads are written straight into the database (bypassing the save-time sanitizer) so
 * that the output escaping itself is tested, not only the input filter.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.Security.NonceVerification

namespace FormCaptain\Tests\Integration\Security;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Admin\FormsPage;
use FormCaptain\Database\Tables;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Http\AdminPostController;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\FormSchemaLoader;
use FormCaptain\Service\FormService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/SecurityFixtures.php';
require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';

final class InjectionTest extends TestCase {

	use SecurityFixtures;
	use EntryFixtures;

	private const XSS = '<script>alert(1)</script><img src=x onerror=alert(2)>"\'><svg onload=alert(3)>';
	private const SQL = "' OR '1'='1'; DROP TABLE wp_fc_entries; -- ";

	/** Raw markers that must never appear unescaped in any HTML output. */
	private const RAW = array( '<script>alert(1)', '<img src=x', '<svg onload', '"\'><svg' );

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'toplevel_page_form-captain' );
		Limits::reset();
		Renderer::reset_counters();
		$this->plugin()->get( FormSchemaLoader::class )->flush();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';
		update_option( 'admin_email', 'site-admin@example.org' );
		$this->reset_mailer();
		$this->fresh_rest_server();
	}

	public function tear_down(): void {
		$_GET = array();
		$this->drop_rest_server();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function assert_no_raw( string $html, string $where ): void {
		foreach ( self::RAW as $raw ) {
			$this->assertStringNotContainsString( $raw, $html, "$where contains unescaped payload $raw" );
		}
	}

	/** Form whose stored schema contains payloads everywhere (as if manipulated in the DB). */
	private function evil_form(): array {
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name ' . self::XSS, 'help' => 'Help ' . self::XSS, 'placeholder' => self::XSS ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
				array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => '=cmd|' . self::XSS ),
				array(
					'id'      => 'f_sel001',
					'type'    => 'select',
					'key'     => 'topic',
					'label'   => 'Topic',
					'options' => array( array( 'value' => 'a', 'label' => 'Opt ' . self::XSS ) ),
				),
				array( 'id' => 'f_html01', 'type' => 'html', 'content' => '<p>ok</p>' . self::XSS . '<a href="javascript:alert(4)">x</a>' ),
				array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Consent', 'consent_text' => 'I agree ' . self::XSS ),
			),
			array(
				'notification' => array( 'enabled' => true, 'to' => array( 'admin@example.org' ), 'subject' => 'New {form_title}', 'fields' => 'all_non_sensitive', 'reply_to_field' => 'f_mail01' ),
				'email'        => array( 'from_name' => 'Team', 'from_address' => 'noreply@example.org' ),
			)
		);
		global $wpdb;
		$wpdb->update( Tables::name( Tables::FORMS ), array( 'title' => 'Title ' . self::XSS . self::SQL ), array( 'id' => (int) $form['id'] ) );
		return $this->forms()->find( (int) $form['id'] );
	}

	private function evil_entry( array $form ): int {
		return $this->make_entry(
			$form,
			array(
				'f_name01' => 'Val ' . self::XSS,
				'f_mail01' => 'eve@example.org',
				'f_msg001' => "=HYPERLINK(\"http://evil\")\n" . self::XSS . self::SQL,
				'f_sel001' => 'a',
			)
		);
	}

	private function render_page( object $page, array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			$page->render();
		} finally {
			$out  = (string) ob_get_clean();
			$_GET = array();
		}
		return $out;
	}

	/* -------------------------------------------------------------- output */

	public function test_frontend_render_escapes_schema_payloads(): void {
		$form = $this->evil_form();
		wp_set_current_user( 0 );
		$html = $this->plugin()->get( Renderer::class )->render( $form, array( 'title' => true ) );
		$this->assertStringContainsString( 'fc-form', $html );
		$this->assert_no_raw( $html, 'frontend' );
		$this->assertStringNotContainsString( 'javascript:', $html, 'kses strips javascript: links in html fields' );
		$this->assertStringNotContainsString( 'onerror=alert(2)>', $html );
	}

	public function test_admin_lists_and_detail_escape_payloads(): void {
		$this->as_admin();
		$form  = $this->evil_form();
		$entry = $this->evil_entry( $form );

		$this->assert_no_raw( $this->render_page( $this->plugin()->get( FormsPage::class ), array( 's' => self::XSS ) ), 'forms list' );
		$this->assert_no_raw( $this->render_page( $this->plugin()->get( FormsPage::class ), array( 's' => 'Title' ) ), 'forms list with hit' );
		$this->assert_no_raw( $this->render_page( $this->plugin()->get( EntriesPage::class ), array( 'page' => EntriesPage::SLUG ) ), 'entries list' );
		$search = $this->render_page( $this->plugin()->get( EntriesPage::class ), array( 'page' => EntriesPage::SLUG, 's' => self::XSS . self::SQL ) );
		$this->assert_no_raw( $search, 'entries search' );
		$detail = $this->render_page( $this->plugin()->get( EntriesPage::class ), array( 'page' => EntriesPage::SLUG, 'view' => (string) $entry ) );
		$this->assertStringContainsString( 'Val &lt;script&gt;', $detail );
		$this->assert_no_raw( $detail, 'entry detail' );
		$export = $this->render_page( $this->plugin()->get( EntriesPage::class ), array( 'page' => EntriesPage::SLUG, 'export' => '1', 'form_id' => (string) $form['id'] ) );
		$this->assert_no_raw( $export, 'export screen' );
	}

	public function test_mail_html_and_headers_are_neutralised(): void {
		$this->as_admin();
		$form  = $this->evil_form();
		$entry = $this->evil_entry( $form );
		$this->entries()->insert_values( $this->make_entry( $form, array( 'f_name01' => 'x', 'f_mail01' => "eve@example.org\r\nBcc: victim@example.net" ) ), array() );
		$mail = $this->plugin()->get( MailBuilder::class )->admin( $form, $this->entries()->find( $entry ), $this->entries()->values_for( $entry ) );
		$this->assert_no_raw( (string) $mail['html'], 'mail html' );
		$this->assertStringNotContainsString( "\n", (string) $mail['subject'] );
		$this->assertStringNotContainsString( '<script', (string) $mail['subject'] );

		$bad  = $this->make_entry( $form, array( 'f_name01' => 'x', 'f_mail01' => "eve@example.org\r\nBcc: victim@example.net" ) );
		$mail = $this->plugin()->get( MailBuilder::class )->admin( $form, $this->entries()->find( $bad ), $this->entries()->values_for( $bad ) );
		$this->assertEmpty( $mail['reply_to'], 'CRLF in reply-to value drops the header' );
		$this->assertContains( 'reply_to', $mail['rejected'] );
		$this->assertSame( array( 'admin@example.org' ), $mail['to'], 'Field values never become recipients' );
	}

	public function test_csv_neutralises_formulas_in_values_and_labels(): void {
		$this->as_admin();
		$form = $this->evil_form();
		$this->evil_entry( $form );
		$stream = fopen( 'php://memory', 'w+b' );
		$this->assertIsInt( $this->plugin()->get( CsvExporter::class )->export( array( 'form_id' => (int) $form['id'] ), $stream ) );
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://evil\"\")", $csv );
		$this->assertStringContainsString( "'=cmd|", $csv, 'Header label is escaped too' );
		foreach ( preg_split( '/\r\n/', $csv ) as $line ) {
			foreach ( str_getcsv( $line, ',', '"', '' ) as $cell ) {
				$this->assertDoesNotMatchRegularExpression( '/^\s*[=+\-@\t\r]/', (string) $cell, 'Cell starts with a formula trigger: ' . $cell );
			}
		}
	}

	public function test_json_import_strips_markup_and_dangerous_links(): void {
		$this->as_admin();
		$json   = (string) wp_json_encode(
			array(
				'format'   => 'form-captain-form',
				'title'    => 'Imported ' . self::XSS,
				'schema'   => array(
					'schema_version' => 1,
					'fields'         => array(
						array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name ' . self::XSS ),
						array( 'id' => 'f_html01', 'type' => 'html', 'key' => 'note', 'content' => '<p onclick="x()">Hi</p><a href="javascript:alert(1)">l</a><iframe src="//evil"></iframe>' ),
					),
				),
				'settings' => array(
					'confirmation' => array( 'type' => 'page', 'page_id' => 987654, 'message' => self::XSS ),
					'actions'      => array( array( 'action_key' => 'evil.action', 'enabled' => true, 'settings' => array( 'api_key' => 'x' ) ) ),
				),
			)
		);
		$result = $this->plugin()->get( FormService::class )->import( $json );
		$this->assertIsArray( $result );
		$form   = $this->forms()->find( $result['form_id'] );
		$stored = (string) wp_json_encode( $form );
		$this->assertSame( 'draft', $form['status'] );
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringNotContainsString( 'javascript:', $stored );
		$this->assertStringNotContainsString( 'onclick', $stored );
		$this->assertStringNotContainsString( '<iframe', $stored );
		$this->assertSame( 'message', $form['settings']['confirmation']['type'], 'Invalid redirect page removed' );
		$this->assertFalse( (bool) $form['settings']['actions'][0]['enabled'], 'Imported actions are disabled' );

		$this->assertSame( 'schema_invalid', $this->plugin()->get( FormService::class )->import( 'O:8:"stdClass":0:{}' )->get_error_code(), 'Serialized PHP is never unserialized' );
		$deep = str_repeat( '[', 40 ) . str_repeat( ']', 40 );
		$this->assertSame( 'schema_invalid', $this->plugin()->get( FormService::class )->import( '{"schema":' . $deep . '}' )->get_error_code(), 'Depth limit 32' );
		$this->assertSame( 'payload_too_large', $this->plugin()->get( FormService::class )->import( str_repeat( ' ', FormService::IMPORT_MAX_BYTES + 1 ) )->get_error_code() );
	}

	/* ----------------------------------------------------------------- SQL */

	public function test_sql_payloads_in_search_filters_and_ordering(): void {
		global $wpdb;
		$this->as_admin();
		$form = $this->create_published_form();
		$this->make_entry( $form );
		$entries = $this->plugin()->get( EntryRepository::class );
		$forms   = $this->plugin()->get( FormRepository::class );

		$wpdb->last_error = '';
		$this->assertSame( 0, $entries->list( array( 'search' => self::SQL ) )['total'] );
		$this->assertSame( 0, $entries->list( array( 'search' => '%' ) )['total'], 'LIKE wildcards are escaped' );
		$this->assertSame( 0, $entries->list( array( 'search' => '_' ) )['total'] );
		$this->assertSame( 1, $entries->list( array( 'form_id' => (int) $form['id'], 'status' => self::SQL ) )['total'], 'Unknown status ignored' );
		// Malformed dates never reach the database (strict SQL modes would raise an error) and are ignored.
		$this->assertSame( $entries->list( array() )['total'], $entries->list( array( 'date_from' => self::SQL, 'date_to' => self::SQL ) )['total'], 'Invalid dates are ignored' );
		$this->assertSame( '', $wpdb->last_error, 'No SQL error from date payloads' );
		$this->assertNull( EntryRepository::valid_datetime( '2026-02-30' ), 'Impossible calendar dates rejected' );
		$this->assertSame( '2026-11-16 00:00:00', EntryRepository::valid_datetime( '2026-11-16' ) );
		$res = $forms->list( array( 'search' => self::SQL, 'orderby' => 'id; DROP TABLE x', 'order' => 'DESC; --' ) );
		$this->assertSame( 0, $res['total'] );
		$res = $forms->list( array( 'orderby' => '(SELECT SLEEP(5))', 'order' => 'asc' ) );
		$this->assertGreaterThanOrEqual( 1, $res['total'] );
		$this->assertSame( '', $wpdb->last_error, 'No SQL error from payloads' );
		$this->assertNotNull( $wpdb->get_var( 'SHOW TABLES LIKE \'' . Tables::name( Tables::ENTRIES ) . '\'' ) );

		$req = new \WP_REST_Request( 'GET', '/form-captain/v1/admin/forms' );
		$req->set_query_params( array( 'search' => self::SQL, 'status' => self::SQL ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $req )->get_status(), 'Status enum validated by the REST schema' );
		$req->set_query_params( array( 'search' => self::SQL, 'per_page' => 100000 ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $req )->get_status(), 'per_page is capped at 100' );
		$req->set_query_params( array( 'search' => self::SQL ) );
		$this->assertSame( 0, rest_get_server()->dispatch( $req )->get_data()['total'] );
	}

	/* ----------------------------------------------- manipulated payloads */

	private function token( array $form, int $age = 30 ): string {
		Clock::freeze( time() - $age );
		$t = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] )['token'];
		Clock::freeze( null );
		return $t;
	}

	private int $ip = 0;

	/** Each call uses a fresh documentation-range IP unless $same_ip (rate-limit test). */
	private function submit( array $form, array $fields, ?string $token = null, array $extra = array(), bool $same_ip = false ): array {
		$ip = $same_ip ? '203.0.113.30' : '198.51.100.' . ( ++$this->ip % 250 + 1 );
		return $this->plugin()->get( SubmissionService::class )->submit(
			$form,
			array_merge(
				array(
					'_fc_token' => $token ?? $this->token( $form ),
					'fields'    => $fields,
					SpamGuard::honeypot_name( (string) $form['public_id'] ) => '',
				),
				$extra
			),
			array( 'ip' => $ip )
		);
	}

	public function test_manipulated_payloads_are_rejected(): void {
		$form = $this->create_published_form();
		$ok   = array( 'f_name01' => 'Eve', 'f_mail01' => 'eve@example.org' );

		$this->assertSame( 'validation_failed', $this->submit( $form, $ok + array( 'f_evil01' => 'x' ) )['code'], 'Unknown field' );
		$this->assertSame( 'validation_failed', $this->submit( $form, array( 'f_name01' => array( 'a', 'b' ), 'f_mail01' => 'eve@example.org' ) )['code'], 'Array for text field' );
		$this->assertSame( 'validation_failed', $this->submit( $form, array( 'f_name01' => array( array( 'deep' ) ), 'f_mail01' => 'eve@example.org' ) )['code'], 'Nested arrays' );
		$this->assertSame( 'validation_failed', $this->submit( $form, array( 'f_name01' => "Eve\xC3\x28", 'f_mail01' => 'eve@example.org' ) )['code'], 'Invalid UTF-8' );
		$this->assertSame( 'validation_failed', $this->submit( $form, array( 'f_name01' => str_repeat( 'a', 191 ), 'f_mail01' => 'eve@example.org' ) )['code'], 'Text limit 190' );
		$this->assertSame( 'validation_failed', $this->submit( $form, array( 'f_name01' => 'Eve', 'f_mail01' => "eve@example.org\r\nBcc: x@example.net" ) )['code'], 'CRLF in email' );

		$res = $this->submit( $form, array( 'f_name01' => "Eve\0", 'f_mail01' => 'eve@example.org' ) );
		if ( 'created' === $res['code'] ) {
			$this->assertStringNotContainsString( "\0", (string) wp_json_encode( $this->entries()->values_for( (int) $res['entry_id'] ) ), 'NUL removed' );
		}

		$many = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$many[ sprintf( 'f_x%05d', $i ) ] = 'x';
		}
		$this->assertSame( 'too_many_fields', $this->submit( $form, $many )['code'] );

		$this->assertSame( 'invalid_token', $this->submit( $form, $ok, 'garbage' )['code'] );
		$token = $this->token( $form );
		$this->assertSame( 'invalid_token', $this->submit( $form, $ok, substr( $token, 0, -2 ) . ( str_ends_with( $token, 'AA' ) ? 'BB' : 'AA' ) )['code'], 'Tampered signature' );
		$this->assertSame( 'invalid_token', $this->submit( $form, $ok, $this->token( $form, 3 * HOUR_IN_SECONDS ) )['code'], 'Expired token' );
		$this->assertSame( 'invalid_token', $this->submit( $form, $ok, $this->token( $form, -3600 ) )['code'], 'Token from the future' );
		$this->assertSame( 'invalid_token', $this->submit( $form, $ok, $token, array( '_fc_token' => array( $token ) ) )['code'], 'Token as array' );
		$this->assertSame( 'created' === $res['code'] ? 1 : 0, (int) $this->entries()->list( array( 'form_id' => (int) $form['id'], 'any_status' => true ) )['total'], 'Rejected payloads store nothing' );
	}

	public function test_hidden_field_value_comes_from_schema_not_from_browser(): void {
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
				array( 'id' => 'f_hid001', 'type' => 'hidden', 'key' => 'campaign', 'default' => 'spring' ),
			)
		);
		$res  = $this->submit( $form, array( 'f_name01' => 'Eve', 'f_hid001' => self::XSS ) );
		$this->assertSame( 'created', $res['code'] );
		$stored = $this->entries()->values_for( (int) $res['entry_id'] );
		$this->assertStringNotContainsString( '<script', (string) wp_json_encode( $stored ), 'Browser value of a hidden field is ignored' );
		if ( isset( $stored['f_hid001'] ) ) {
			$this->assertSame( 'spring', $stored['f_hid001'] );
		}
	}

	public function test_oversized_body_and_media_type_on_rest(): void {
		$form = $this->create_published_form();
		$req  = new \WP_REST_Request( 'POST', '/form-captain/v1/forms/' . $form['public_id'] . '/submissions' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( array( 'fields' => array( 'f_name01' => str_repeat( 'a', Limits::get( 'request_bytes' ) ) ) ) ) );
		$this->assertSame( 413, rest_get_server()->dispatch( $req )->get_status() );
		$req->set_header( 'content-length', (string) ( Limits::get( 'request_bytes' ) + 1 ) );
		$req->set_body( '{}' );
		$this->assertSame( 413, rest_get_server()->dispatch( $req )->get_status(), 'Content-Length checked before parsing' );
		$req = new \WP_REST_Request( 'POST', '/form-captain/v1/forms/' . $form['public_id'] . '/submissions' );
		$req->set_header( 'content-type', 'text/xml' );
		$req->set_body( '<x/>' );
		$this->assertSame( 415, rest_get_server()->dispatch( $req )->get_status() );
	}

	public function test_replay_and_repeated_parallel_submissions_create_one_entry(): void {
		$form  = $this->create_published_form();
		$token = $this->token( $form );
		$ok    = array( 'f_name01' => 'Eve', 'f_mail01' => 'eve@example.org' );
		$codes = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$codes[] = $this->submit( $form, $ok, $token )['code'];
		}
		$this->assertSame( array( 'created', 'duplicate_request', 'duplicate_request', 'duplicate_request' ), $codes );
		$this->assertSame( 1, (int) $this->entries()->list( array( 'form_id' => (int) $form['id'] ) )['total'] );

		// Race: a second request that slipped past the duplicate check hits the UNIQUE request_key.
		$key = SubmissionToken::request_key( (int) $form['id'], 'race-random-0000000000' );
		$row = array(
			'form_id'         => (int) $form['id'],
			'form_version_id' => (int) $form['published_version_id'],
			'status'          => 'new',
			'request_key'     => $key,
		);
		global $wpdb;
		$this->assertGreaterThan( 0, $this->entries()->insert( $row ) );
		$suppress = $wpdb->suppress_errors( true );
		$second   = (int) $this->entries()->insert( $row );
		$wpdb->suppress_errors( $suppress );
		$this->assertLessThanOrEqual( 0, $second, 'UNIQUE request_key blocks the parallel duplicate' );
	}

	public function test_rate_limit_stops_floods(): void {
		$form  = $this->create_published_form();
		$codes = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$codes[] = $this->submit( $form, array( 'f_name01' => 'Eve ' . $i, 'f_mail01' => 'eve@example.org' ), null, array(), true )['code'];
		}
		$this->assertContains( 'rate_limited', $codes );
		$this->assertLessThanOrEqual( 5, count( array_keys( $codes, 'created', true ) ) );
	}

	public function test_no_js_fallback_redirect_cannot_be_steered(): void {
		$form = $this->create_published_form();
		$ctrl = $this->plugin()->get( AdminPostController::class );
		foreach ( array( 'https://evil.example/x', '//evil.example/x', 'javascript:alert(1)', "https://example.org\r\nLocation: https://evil.example" ) as $ref ) {
			$out  = $ctrl->process(
				array(
					'_fc_form'     => (string) $form['public_id'],
					'_fc_token'    => $this->token( $form ),
					'fc'           => array( 'f_name01' => 'Eve', 'f_mail01' => 'eve@example.org' ),
					'redirect_to'  => 'https://evil.example/',
					'redirect_url' => 'https://evil.example/',
					SpamGuard::honeypot_name( (string) $form['public_id'] ) => '',
				),
				array( 'HTTP_REFERER' => $ref )
			);
			$host = wp_parse_url( $out['location'], PHP_URL_HOST );
			$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $host, $ref );
			$this->assertStringNotContainsString( "\n", $out['location'] );
		}
	}
}
