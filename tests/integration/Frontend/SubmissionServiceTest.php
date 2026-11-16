<?php
/**
 * Submission flow: storage, outbox, idempotency, validation, spam, limits (P4-01, P4-03, P3-05/07/08/09/17).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\SubmissionService;

require_once __DIR__ . '/FrontendTestCase.php';

final class SubmissionServiceTest extends FrontendTestCase {

	private function service(): SubmissionService {
		return $this->plugin()->get( SubmissionService::class );
	}

	public function test_success_stores_entry_values_and_jobs(): void {
		$form = $this->create_published_form(
			null,
			array(
				'actions' => array(
					array( 'instance_id' => 'a_0123456789', 'action_key' => 'reference.create_ticket', 'enabled' => true, 'settings' => array(), 'mapping' => array( 'email' => 'f_mail01' ) ),
					array( 'instance_id' => 'a_abcdefabcd', 'action_key' => 'reference.other', 'enabled' => false ),
				),
			)
		);
		$events = array();
		add_action( 'form_captain_entry_created_v1', static function ( array $e ) use ( &$events ): void {
			$events[] = $e;
		} );
		$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'ip' => '198.51.100.1' ) );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'created', $res['code'] );
		$this->assertSame( 'Danke', $res['message'] );
		$entry = $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] );
		$this->assertSame( 'new', $entry['status'] );
		$this->assertSame( (int) $form['published_version_id'], $entry['form_version_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $entry['request_key'] );
		$this->assertNotNull( $entry['expires_gmt'] );

		$values = $this->plugin()->get( EntryRepository::class )->values_for( (int) $res['entry_id'] );
		$this->assertSame( 'erika@example.org', $values['f_mail01'] );
		$this->assertSame( "Hallo\nWelt", $values['f_msg001'] );

		$jobs = $this->plugin()->get( JobRepository::class )->for_entry( (int) $res['entry_id'] );
		$this->assertSame( array( 'mail.admin', 'connector.action' ), array_column( $jobs, 'type' ) );
		$blog = get_current_blog_id();
		$this->assertSame( "form-captain:{$blog}:{$res['entry_id']}:mail.admin", $jobs[0]['idempotency_key'] );
		$this->assertSame( "form-captain:{$blog}:{$res['entry_id']}:reference.create_ticket:a_0123456789", $jobs[1]['idempotency_key'] );
		$this->assertSame( array( 'action_key' => 'reference.create_ticket', 'instance_id' => 'a_0123456789' ), $jobs[1]['payload'] );
		foreach ( $jobs as $job ) {
			$this->assertStringNotContainsString( 'example.org', wp_json_encode( $job['payload'] ) );
		}

		$this->assertCount( 1, $events );
		$this->assertSame( array(), $events[0]['values'], 'No values without allowlist.' );
		$this->assertSame( (int) $res['entry_id'], $events[0]['entry_id'] );
		$this->assertSame( 1, $events[0]['form_version'] );
	}

	public function test_event_values_only_from_allowlist_and_never_sensitive(): void {
		$fields = array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
			array( 'id' => 'f_secr01', 'type' => 'text', 'key' => 'secret', 'label' => 'Secret', 'sensitive' => true ),
		);
		$form   = $this->create_published_form( $fields );
		add_filter( 'form_captain_event_value_allowlist_v1', static fn() => array( 'name', 'secret' ) );
		$event = null;
		add_action( 'form_captain_entry_created_v1', static function ( array $e ) use ( &$event ): void {
			$event = $e;
		} );
		$this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'Max', 'f_secr01' => 'geheim' ) ) );
		$this->assertSame( array( 'name' => 'Max' ), $event['values'] );
	}

	public function test_duplicate_token_is_idempotent(): void {
		$form  = $this->create_published_form();
		$token = $this->token( $form );
		$first = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields(), $token ) );
		$jobs  = $this->count_rows( 'fc_jobs' );
		$again = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields(), $token ) );

		$this->assertTrue( $again['success'] );
		$this->assertSame( 'duplicate_request', $again['code'] );
		$this->assertSame( $first['entry_id'], $again['entry_id'] );
		$this->assertSame( $first['message'], $again['message'] );
		$this->assertSame( 1, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
		$this->assertSame( $jobs, $this->count_rows( 'fc_jobs' ) );
	}

	public function test_validation_errors_are_translated_and_nothing_is_stored(): void {
		$form = $this->create_published_form();
		$res  = $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => '', 'f_mail01' => 'a@b' ) ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'validation_failed', $res['code'] );
		$this->assertSame( 'required', $res['error_codes']['f_name01'] );
		$this->assertSame( 'invalid_email', $res['error_codes']['f_mail01'] );
		$this->assertNotEmpty( $res['errors']['f_name01'] );
		$this->assertSame( 0, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
	}

	/** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
	public function manipulated_payloads(): array {
		return array(
			'unknown field'      => array( array( 'f_zzzzzz' => 'x' ), 'f_zzzzzz', 'unknown_field' ),
			'array for text'     => array( array( 'f_name01' => array( 'a', 'b' ) ), 'f_name01', 'invalid_value' ),
			'nested array'       => array( array( 'f_choice' => array( array( 'a' ) ) ), 'f_choice', 'invalid_value' ),
			'option not in list' => array( array( 'f_select' => 'evil' ), 'f_select', 'invalid_option' ),
			'checkbox unknown'   => array( array( 'f_choice' => array( 'a', 'zzz' ) ), 'f_choice', 'invalid_option' ),
			'too long'           => array( array( 'f_name01' => str_repeat( 'x', 191 ) ), 'f_name01', 'too_long' ),
			'bad date'           => array( array( 'f_date01' => '2026-02-30' ), 'f_date01', 'invalid_date' ),
			'number out of range' => array( array( 'f_numb01' => '11' ), 'f_numb01', 'out_of_range' ),
			'radio array'        => array( array( 'f_radio1' => array( 'a', 'b' ) ), 'f_radio1', 'invalid_value' ),
		);
	}

	/**
	 * @dataProvider manipulated_payloads
	 * @param array<string,mixed> $extra
	 */
	public function test_manipulated_payloads_are_rejected( array $extra, string $field, string $code ): void {
		$form = $this->create_published_form( $this->rich_fields() );
		$res  = $this->service()->submit( $form, $this->payload( $form, array_merge( array( 'f_name01' => 'Max' ), $extra ) ) );
		$this->assertSame( 'validation_failed', $res['code'] );
		$this->assertSame( $code, $res['error_codes'][ $field ] ?? null );
		$this->assertSame( 0, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
	}

	public function test_valid_rich_values_are_normalized(): void {
		$form = $this->create_published_form( $this->rich_fields() );
		$res  = $this->service()->submit(
			$form,
			$this->payload(
				$form,
				array(
					'f_name01' => 'Max',
					'f_choice' => array( 'a', 'a', 'b' ),
					'f_select' => 'x',
					'f_numb01' => '2,5',
					'f_date01' => '2026-02-28',
					'f_hidden' => 'manipulated',
					'f_html01' => 'ignored',
				)
			)
		);
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$values = $this->plugin()->get( EntryRepository::class )->values_for( (int) $res['entry_id'] );
		$this->assertSame( array( 'a', 'b' ), $values['f_choice'] );
		$this->assertSame( '2.5', $values['f_numb01'] );
		$this->assertSame( 'schema-default', $values['f_hidden'], 'Hidden values come from the schema (T-SUB-04).' );
		$this->assertArrayNotHasKey( 'f_html01', $values );
	}

	public function test_too_many_fields_and_body_size(): void {
		$form   = $this->create_published_form();
		$fields = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$fields[ 'f_x' . str_pad( (string) $i, 5, '0', STR_PAD_LEFT ) ] = 'x';
		}
		$this->assertSame( 'too_many_fields', $this->service()->submit( $form, $this->payload( $form, $fields ) )['code'] );
		$this->assertSame( 'payload_too_large', $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'body_bytes' => 70000 ) )['code'] );
	}

	public function test_conditionally_hidden_values_are_discarded(): void {
		$fields = array(
			array( 'id' => 'f_topic1', 'type' => 'radio', 'key' => 'topic', 'label' => 'Topic', 'options' => array( array( 'value' => 'quote', 'label' => 'Quote' ), array( 'value' => 'other', 'label' => 'Other' ) ) ),
			array( 'id' => 'f_budget', 'type' => 'text', 'key' => 'budget', 'label' => 'Budget', 'required' => true, 'condition' => array( 'action' => 'show', 'field' => 'f_topic1', 'operator' => 'equals', 'value' => 'quote' ) ),
		);
		$form = $this->create_published_form( $fields );
		$res  = $this->service()->submit( $form, $this->payload( $form, array( 'f_topic1' => 'other', 'f_budget' => '1000' ) ) );
		$this->assertTrue( $res['success'], 'Hidden required field may be empty.' );
		$values = $this->plugin()->get( EntryRepository::class )->values_for( (int) $res['entry_id'] );
		$this->assertSame( array( 'f_topic1' => 'other' ), $values );

		$res = $this->service()->submit( $form, $this->payload( $form, array( 'f_topic1' => 'quote' ) ) );
		$this->assertSame( 'required', $res['error_codes']['f_budget'] );
	}

	public function test_honeypot_marks_spam_without_jobs_or_event(): void {
		$form  = $this->create_published_form();
		$fired = 0;
		add_action( 'form_captain_entry_created_v1', static function () use ( &$fired ): void {
			++$fired;
		} );
		$raw = $this->payload( $form, $this->valid_fields() );
		$raw[ \FormCaptain\Security\SpamGuard::honeypot_name( (string) $form['public_id'] ) ] = 'http://spam.example';
		$res = $this->service()->submit( $form, $raw );

		$this->assertTrue( $res['success'], 'Bots get the regular success answer.' );
		$this->assertSame( 'created', $res['code'] );
		$entry = $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] );
		$this->assertSame( 'spam', $entry['status'] );
		$this->assertSame( 'honeypot', $entry['spam_reason'] );
		$this->assertSame( array(), $this->plugin()->get( JobRepository::class )->for_entry( (int) $res['entry_id'] ) );
		$this->assertSame( 0, $fired );
	}

	public function test_too_fast_and_blocklist_are_spam(): void {
		$form = $this->create_published_form();
		$res  = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields(), $this->token( $form, 1 ) ) );
		$this->assertSame( 'too_fast', $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] )['spam_reason'] );

		$this->set_spam_setting( 'blocklist', "viagra\n@spam.test" );
		$res = $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'Max', 'f_mail01' => 'x@mail.spam.test' ) ) );
		$this->assertSame( 'blocklist', $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] )['spam_reason'] );
		$res = $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'Max', 'f_mail01' => 'x@ok.test', 'f_msg001' => 'Buy VIAGRA now' ) ) );
		$this->assertSame( 'blocklist', $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] )['spam_reason'] );
		$this->assertSame( 0, $this->count_rows( 'fc_jobs', 'form_id = ' . (int) $form['id'] ) );
	}

	public function test_invalid_tokens(): void {
		$form  = $this->create_published_form();
		$other = $this->create_published_form();
		$token = $this->token( $form );
		$cases = array(
			'missing'  => '',
			'tampered' => substr( $token, 0, -2 ) . ( str_ends_with( $token, 'AA' ) ? 'BB' : 'AA' ),
			'other'    => $this->token( $other ),
			'expired'  => $this->token( $form, 7201 ),
		);
		foreach ( $cases as $label => $t ) {
			$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields(), $t ) );
			$this->assertSame( 'invalid_token', $res['code'], $label );
		}
		$this->assertSame( 0, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
	}

	public function test_paused_and_draft_forms(): void {
		$form             = $this->create_published_form( null, array( 'paused_message' => 'Pause!' ) );
		$form['status']   = 'paused';
		$res              = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 'form_not_accepting', $res['code'] );
		$this->assertSame( 'Pause!', $res['message'] );
		$form['status'] = 'draft';
		$this->assertSame( 'form_not_found', $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) )['code'] );
	}

	public function test_rate_limit_after_five_submissions(): void {
		$form = $this->create_published_form();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'ip' => '192.0.2.7' ) )['success'] );
		}
		$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'ip' => '192.0.2.7' ) );
		$this->assertSame( 'rate_limited', $res['code'] );
		$this->assertGreaterThan( 0, $res['retry_after'] );
		// Other IP is not affected.
		$this->assertTrue( $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ), array( 'ip' => '192.0.2.8' ) )['success'] );

		global $wpdb;
		$buckets = $wpdb->get_col( "SELECT bucket FROM {$wpdb->prefix}fc_rate_limits" ); // phpcs:ignore WordPress.DB
		foreach ( $buckets as $bucket ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $bucket );
			$this->assertStringNotContainsString( '192.0.2', $bucket );
		}
	}

	public function test_redirect_only_to_internal_published_page(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$form = $this->create_published_form( null, array( 'confirmation' => array( 'type' => 'page', 'page_id' => $page, 'message' => 'Danke' ) ) );
		$raw  = $this->payload( $form, $this->valid_fields() ) + array( 'redirect_to' => 'https://evil.example/' );
		$res  = $this->service()->submit( $form, $raw );
		$this->assertSame( get_permalink( $page ), $res['redirect_url'] );

		$draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$form2 = $this->create_published_form( null, array( 'confirmation' => array( 'type' => 'page', 'page_id' => $draft, 'message' => 'Danke' ) ) );
		$res   = $this->service()->submit( $form2, $this->payload( $form2, $this->valid_fields() ) );
		$this->assertArrayNotHasKey( 'redirect_url', $res );
		$this->assertSame( 'Danke', $res['message'] );
	}

	public function test_consent_proof_is_stored(): void {
		$fields = array(
			array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Einwilligung', 'required' => true, 'consent_text' => 'Ich stimme der <strong>Verarbeitung</strong> zu.', 'consent_revision' => 3 ),
		);
		$form = $this->create_published_form( $fields );
		$res  = $this->service()->submit( $form, $this->payload( $form, array() ) );
		$this->assertSame( 'consent_required', $res['error_codes']['f_cons01'] );

		$res   = $this->service()->submit( $form, $this->payload( $form, array( 'f_cons01' => '1' ) ) );
		$entry = $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] );
		$proof = $entry['consent']['f_cons01'];
		$this->assertTrue( $proof['given'] );
		$this->assertSame( 3, $proof['revision'] );
		$this->assertSame( 1, $proof['form_version'] );
		$this->assertSame( 'sha256:' . hash( 'sha256', 'Ich stimme der Verarbeitung zu.' ), $proof['text_hash'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $proof['given_at'] );
		$this->assertSame( '1', $this->plugin()->get( EntryRepository::class )->values_for( (int) $res['entry_id'] )['f_cons01'] );
	}

	public function test_storage_failure_rolls_back(): void {
		$form   = $this->create_published_form();
		$break  = static function ( string $q ): string {
			return str_contains( $q, 'fc_entry_values' ) && str_starts_with( ltrim( $q ), 'INSERT' ) ? 'INSERT INTO fc_table_that_does_not_exist VALUES (1)' : $q;
		};
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		remove_filter( 'query', $break );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 'storage_failed', $res['code'] );
		$this->assertStringNotContainsString( 'fc_table', $res['message'] );
		$this->assertSame( 0, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
		$this->assertSame( 0, $this->count_rows( 'fc_jobs', 'form_id = ' . (int) $form['id'] ) );
	}

	public function test_trusted_submission_without_token_uses_idempotency_key(): void {
		$form = $this->create_published_form();
		$meta = array( 'trusted' => true, 'plugin' => 'pass', 'idempotency_key' => 'abc-1' );
		$res  = $this->service()->submit( $form, array( 'name' => 'Max', 'email' => 'max@example.org' ), $meta );
		$this->assertSame( 'created', $res['code'], wp_json_encode( $res ) );
		$again = $this->service()->submit( $form, array( 'name' => 'Max', 'email' => 'max@example.org' ), $meta );
		$this->assertSame( 'duplicate_request', $again['code'] );
		$this->assertSame( $res['entry_id'], $again['entry_id'] );
	}

	/** @return array<int,array<string,mixed>> */
	private function rich_fields(): array {
		return array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			array( 'id' => 'f_choice', 'type' => 'checkbox', 'key' => 'choice', 'label' => 'Choice', 'options' => array( array( 'value' => 'a', 'label' => 'A' ), array( 'value' => 'b', 'label' => 'B' ) ) ),
			array( 'id' => 'f_select', 'type' => 'select', 'key' => 'sel', 'label' => 'Select', 'options' => array( array( 'value' => 'x', 'label' => 'X' ) ) ),
			array( 'id' => 'f_radio1', 'type' => 'radio', 'key' => 'rad', 'label' => 'Radio', 'options' => array( array( 'value' => 'a', 'label' => 'A' ), array( 'value' => 'b', 'label' => 'B' ) ) ),
			array( 'id' => 'f_numb01', 'type' => 'number', 'key' => 'num', 'label' => 'Number', 'validation' => array( 'min' => 0, 'max' => 10, 'step' => '0.5' ) ),
			array( 'id' => 'f_date01', 'type' => 'date', 'key' => 'date', 'label' => 'Date' ),
			array( 'id' => 'f_hidden', 'type' => 'hidden', 'key' => 'hid', 'label' => '', 'default' => 'schema-default' ),
			array( 'id' => 'f_html01', 'type' => 'html', 'key' => 'note', 'label' => '', 'content' => '<p>Hinweis</p>' ),
		);
	}
}
