<?php
/**
 * Signed suite context (P8-13, docs/spec/suite-kontext.md test cases).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Repository\EntryRepository;
use FormCaptain\Security\SignedContext;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Secret;

require_once __DIR__ . '/FrontendTestCase.php';

final class SignedContextTest extends FrontendTestCase {

	/** @var array{issue:bool,submit:bool} */
	public static array $checks = array( 'issue' => true, 'submit' => true );

	public function set_up(): void {
		parent::set_up();
		self::$checks = array( 'issue' => true, 'submit' => true );
		$ctx          = $this->ctx();
		$ctx->register_purpose(
			'pass.event_registration',
			array(
				'owner'       => 'pass',
				'contract'    => '^1.0',
				'label'       => 'Event registration',
				'ref_types'   => array( 'event' ),
				'default_ttl' => 43200,
				'max_ttl'     => 86400,
				'single_use'  => false,
				'issuance'    => 'public_endpoint',
				'issue_check' => static fn() => self::$checks['issue'],
			)
		);
		$ctx->register_purpose(
			'woo.order_item_questionnaire',
			array(
				'owner'          => 'woo',
				'contract'       => '^1.0',
				'label'          => 'Questionnaire',
				'ref_types'      => array( 'order_item' ),
				'default_ttl'    => 7200,
				'max_ttl'        => 7200,
				'single_use'     => true,
				'issuance'       => array( 'render', 'link' ),
				'submit_check'   => static fn() => self::$checks['submit'],
				'marks_verified' => true,
			)
		);
	}

	private function ctx(): SignedContext {
		return $this->plugin()->get( SignedContext::class );
	}

	private function form( array $purposes = array( 'pass.event_registration', 'woo.order_item_questionnaire' ) ): array {
		return $this->create_published_form( null, array( 'accepted_context_purposes' => $purposes ) );
	}

	public function test_fixture_vector(): void {
		$fx  = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/context/v1-valid.json' ), true );
		$key = SignedContext::derive_key( $fx['auth_salt'], $fx['secret'] );
		$this->assertSame( $fx['k_ctx_hex'], bin2hex( $key ) );
		$this->assertSame( $fx['site'], SignedContext::site_hash( $fx['home_url'] ) );
		$this->assertSame( $fx['context'], SignedContext::encode( $fx['payload'], $key ) );
	}

	public function test_sign_and_submit_stores_verified_context(): void {
		$form   = $this->form();
		$signed = $this->ctx()->sign( 'woo.order_item_questionnaire', (int) $form['id'], 'order_item', '5531:88' );
		$this->assertTrue( $signed['success'], wp_json_encode( $signed ) );
		$this->assertLessThanOrEqual( 1024, strlen( $signed['context'] ) );

		$raw            = $this->payload( $form, $this->valid_fields() );
		$raw['_fc_ctx'] = $signed['context'];
		$res            = $this->plugin()->get( SubmissionService::class )->submit( $form, $raw );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$entry = $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] );
		$this->assertSame(
			array( 'purpose' => 'woo.order_item_questionnaire', 'ref_type' => 'order_item', 'ref_id' => '5531:88', 'verified' => true ),
			$entry['context']
		);
	}

	public function test_expired_tampered_and_malformed(): void {
		$form   = $this->form();
		$signed = $this->ctx()->sign( 'pass.event_registration', (int) $form['id'], 'event', '1187', 600 );
		$this->assertTrue( $this->ctx()->verify( $signed['context'], $form )['ok'] );

		Clock::freeze( $signed['expires_at'] + 59 );
		$this->assertTrue( $this->ctx()->verify( $signed['context'], $form )['ok'] );
		Clock::freeze( $signed['expires_at'] + 61 );
		$res = $this->ctx()->verify( $signed['context'], $form );
		$this->assertSame( 'context_expired', $res['code'] );
		$this->assertSame( 'context_expired', $res['public_code'] );
		Clock::freeze( null );

		list( $payload, $sig ) = explode( '.', $signed['context'] );
		$flip                  = static fn( string $s, int $i ): string => substr( $s, 0, $i ) . ( 'A' === $s[ $i ] ? 'B' : 'A' ) . substr( $s, $i + 1 );
		$this->assertSame( 'context_signature_invalid', $this->ctx()->verify( $flip( $payload, 5 ) . '.' . $sig, $form )['code'] );
		$this->assertSame( 'context_signature_invalid', $this->ctx()->verify( $payload . '.' . $flip( $sig, 3 ), $form )['code'] );
		$this->assertSame( 'context_malformed', $this->ctx()->verify( $payload . '.' . substr( $sig, 0, 42 ), $form )['code'] );
		$this->assertSame( 'context_malformed', $this->ctx()->verify( $payload . $sig, $form )['code'] );
		$this->assertSame( 'context_malformed', $this->ctx()->verify( $payload . '.' . $sig . '.x', $form )['code'] );
		$this->assertSame( 'context_malformed', $this->ctx()->verify( str_repeat( 'a', 1025 ), $form )['code'] );
		$this->assertSame( 'invalid_context', $this->ctx()->verify( $payload . 'x.' . $sig, $form )['public_code'] );

		// Submission token is never valid as context (domain separation).
		$token = $this->token( $form );
		$this->assertContains( $this->ctx()->verify( $token, $form )['code'], array( 'context_signature_invalid', 'context_malformed' ) );

		// Correctly signed payload with an extra key.
		$key = SignedContext::derive_key( wp_salt( 'auth' ), (string) get_option( Secret::OPTION ) );
		$bad = SignedContext::encode(
			array( 'purpose' => 'pass.event_registration', 'form_id' => (int) $form['id'], 'ref_type' => 'event', 'ref_id' => '1', 'site' => SignedContext::site_hash(), 'exp' => time() + 60, 'nonce' => str_repeat( 'a', 22 ), 'extra' => 1 ),
			$key
		);
		$this->assertSame( 'context_malformed', $this->ctx()->verify( $bad, $form )['code'] );
		$long = SignedContext::encode(
			array( 'purpose' => 'pass.event_registration', 'form_id' => (int) $form['id'], 'ref_type' => 'event', 'ref_id' => '1', 'site' => SignedContext::site_hash(), 'exp' => time() + 90000, 'nonce' => str_repeat( 'a', 22 ) ),
			$key
		);
		$this->assertSame( 'context_ttl_invalid', $this->ctx()->verify( $long, $form )['code'] );
	}

	public function test_form_mismatch_and_purpose_not_allowed(): void {
		$a      = $this->form();
		$b      = $this->form();
		$c      = $this->form( array( 'woo.order_item_questionnaire' ) );
		$signed = $this->ctx()->sign( 'pass.event_registration', (int) $a['id'], 'event', '1187' );
		$this->assertSame( 'context_form_mismatch', $this->ctx()->verify( $signed['context'], $b )['code'] );
		$this->assertSame( 'context_purpose_not_allowed', $this->ctx()->verify( $signed['context'], $c )['code'] );
		$this->assertSame( 'context_purpose_not_allowed', $this->ctx()->sign( 'pass.event_registration', (int) $c['id'], 'event', '1' )['code'] );
		$this->assertSame( 'context_purpose_unknown', $this->ctx()->sign( 'nope.unknown', (int) $a['id'], 'event', '1' )['code'] );
		$this->assertSame( 'context_ref_invalid', $this->ctx()->sign( 'pass.event_registration', (int) $a['id'], 'order', '1' )['code'] );

		global $wpdb;
		$log = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}fc_log WHERE code = 'context_form_mismatch' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $log );
		$this->assertStringNotContainsString( $signed['context'], (string) $log['message'] );
	}

	public function test_accepted_purposes_filter_lets_the_owner_accept_its_purpose(): void {
		$form = $this->form( array() );
		$this->assertSame( 'context_purpose_not_allowed', $this->ctx()->sign( 'woo.order_item_questionnaire', (int) $form['id'], 'order_item', '1:2' )['code'] );

		$filter = static function ( $list, $form_id ) use ( $form ) {
			return (int) $form['id'] === $form_id ? array_merge( (array) $list, array( 'woo.order_item_questionnaire', 'bad purpose', 42 ) ) : $list;
		};
		add_filter( 'form_captain_accepted_context_purposes_v1', $filter, 10, 2 );
		$this->assertSame( array( 'woo.order_item_questionnaire' ), SignedContext::accepted_purposes( $form ), 'invalid entries are dropped' );
		$signed = $this->ctx()->sign( 'woo.order_item_questionnaire', (int) $form['id'], 'order_item', '1:2' );
		$this->assertTrue( $signed['success'], wp_json_encode( $signed ) );

		$raw            = $this->payload( $form, $this->valid_fields() );
		$raw['_fc_ctx'] = $signed['context'];
		$res            = $this->plugin()->get( SubmissionService::class )->submit( $form, $raw );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$this->assertSame( '1:2', $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] )['context']['ref_id'] );
		remove_filter( 'form_captain_accepted_context_purposes_v1', $filter, 10 );
		$this->assertSame( array(), SignedContext::accepted_purposes( $form ) );
	}

	public function test_form_without_purposes_ignores_context(): void {
		$with           = $this->form();
		$plain          = $this->create_published_form();
		$signed         = $this->ctx()->sign( 'pass.event_registration', (int) $with['id'], 'event', '1187' );
		$raw            = $this->payload( $plain, $this->valid_fields() );
		$raw['_fc_ctx'] = $signed['context'];
		$res            = $this->plugin()->get( SubmissionService::class )->submit( $plain, $raw );
		$this->assertTrue( $res['success'] );
		$this->assertNull( $this->plugin()->get( EntryRepository::class )->find( (int) $res['entry_id'] )['context'] );
	}

	public function test_single_use_replay_and_validation_failure_keeps_nonce(): void {
		$form    = $this->form();
		$signed  = $this->ctx()->sign( 'woo.order_item_questionnaire', (int) $form['id'], 'order_item', '1:2' );
		$service = $this->plugin()->get( SubmissionService::class );

		$raw            = $this->payload( $form, array( 'f_name01' => '' ) );
		$raw['_fc_ctx'] = $signed['context'];
		$this->assertSame( 'validation_failed', $service->submit( $form, $raw )['code'] );

		$raw            = $this->payload( $form, $this->valid_fields() );
		$raw['_fc_ctx'] = $signed['context'];
		$this->assertSame( 'created', $service->submit( $form, $raw )['code'] );

		$raw            = $this->payload( $form, $this->valid_fields() );
		$raw['_fc_ctx'] = $signed['context'];
		$res            = $service->submit( $form, $raw );
		$this->assertSame( 'context_replayed', $res['code'] );
		$this->assertSame( 1, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
	}

	public function test_submit_check_rejection_and_trusted_detail_code(): void {
		$form                   = $this->form();
		$signed                 = $this->ctx()->sign( 'woo.order_item_questionnaire', (int) $form['id'], 'order_item', '1:3' );
		self::$checks['submit'] = false;
		$raw                    = $this->payload( $form, $this->valid_fields() );
		$raw['_fc_ctx']         = $signed['context'];
		$this->assertSame( 'invalid_context', $this->plugin()->get( SubmissionService::class )->submit( $form, $raw )['code'] );

		$res = $this->plugin()->get( SubmissionService::class )->submit(
			$form,
			array( 'name' => 'Max', 'email' => 'max@example.org' ),
			array( 'trusted' => true, 'plugin' => 'woo', 'idempotency_key' => 'k1', 'signed_context' => $signed['context'] )
		);
		$this->assertSame( 'invalid_context', $res['code'] );
		$this->assertSame( 'context_ref_rejected', $res['detail_code'] );
		$this->assertSame( 0, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );
	}

	public function test_context_required(): void {
		$form = $this->create_published_form( null, array( 'accepted_context_purposes' => array( 'pass.event_registration' ), 'context_required' => true ) );
		$res  = $this->plugin()->get( SubmissionService::class )->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 'invalid_context', $res['code'] );
	}

	public function test_public_endpoint_token_route(): void {
		$form = $this->form();
		$req  = new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/token' );
		$req->set_query_params( array( 'ctx_purpose' => 'pass.event_registration', 'ctx_ref_type' => 'event', 'ctx_ref_id' => '1187' ) );
		$data = rest_get_server()->dispatch( $req )->get_data();
		$this->assertTrue( $this->ctx()->verify( $data['_fc_ctx'], $form )['ok'] );

		self::$checks['issue'] = false;
		$data                  = rest_get_server()->dispatch( $req )->get_data();
		$this->assertArrayNotHasKey( '_fc_ctx', $data );
		$this->assertSame( 'context_ref_rejected', $data['context_error'] );
		$this->assertNotEmpty( $data['token'] );

		// Single-use purposes cannot be issued publicly.
		$req->set_query_params( array( 'ctx_purpose' => 'woo.order_item_questionnaire', 'ctx_ref_type' => 'order_item', 'ctx_ref_id' => '1:2' ) );
		$this->assertSame( 'context_purpose_not_allowed', rest_get_server()->dispatch( $req )->get_data()['context_error'] );
	}

	public function test_invalid_purpose_definitions(): void {
		$res = $this->ctx()->register_purpose(
			'shop.public_once',
			array( 'owner' => 'shop', 'contract' => '^1.0', 'label' => 'x', 'ref_types' => array( 'x' ), 'default_ttl' => 600, 'max_ttl' => 600, 'single_use' => true, 'issuance' => 'public_endpoint', 'issue_check' => '__return_true' )
		);
		$this->assertSame( 'context_purpose_invalid', $res->get_error_code() );
		$res = $this->ctx()->register_purpose( 'pass.event_registration', array() );
		$this->assertSame( 'context_purpose_duplicate', $res->get_error_code() );
		$res = $this->ctx()->register_purpose(
			'old.purpose',
			array( 'owner' => 'old', 'contract' => '^2.0', 'label' => 'x', 'ref_types' => array( 'x' ), 'default_ttl' => 600, 'max_ttl' => 600, 'single_use' => false, 'issuance' => 'render' )
		);
		$this->assertSame( 'incompatible_contract', $res->get_error_code() );
	}

	public function test_rendered_context_hidden_field(): void {
		$form   = $this->form();
		$signed = $this->ctx()->sign( 'woo.order_item_questionnaire', (int) $form['id'], 'order_item', '9:9' );
		$html   = $this->plugin()->get( \FormCaptain\Frontend\Renderer::class )->render( $form, array( 'context' => $signed['context'] ) );
		$this->assertStringContainsString( '<input type="hidden" name="_fc_ctx" value="' . $signed['context'] . '">', $html );

		$html = $this->plugin()->get( \FormCaptain\Frontend\Renderer::class )->render( $form, array( 'context_ref' => array( 'purpose' => 'pass.event_registration', 'ref_type' => 'event', 'ref_id' => '1187' ) ) );
		$this->assertStringContainsString( 'data-fc-ctx-purpose="pass.event_registration"', $html );
		$this->assertStringContainsString( 'name="_fc_ctx"', $html );
	}
}
