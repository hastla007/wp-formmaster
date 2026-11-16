<?php
/**
 * P5-15 / T-IDOR-01…05: every ID is checked against the capability first (no existence
 * oracle), nested objects must belong to their parent, public routes only take public IDs,
 * and every token is bound to exactly one object and user.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Security;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Api\ContractApi;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Install\Capabilities;
use FormCaptain\Integrations\JobGrant;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\FormService;
use FormCaptain\Service\PreviewTokens;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/SecurityFixtures.php';
require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';

final class IdorTest extends TestCase {

	use SecurityFixtures;
	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->fresh_rest_server();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
	}

	public function tear_down(): void {
		$_GET = array();
		JobGrant::close();
		$this->drop_rest_server();
		parent::tear_down();
	}

	private function rest( string $method, string $path ): \WP_REST_Response {
		return rest_get_server()->dispatch( new \WP_REST_Request( $method, '/form-captain/v1' . $path ) );
	}

	private function token( array $form ): string {
		Clock::freeze( time() - 30 );
		$token = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] )['token'];
		Clock::freeze( null );
		return $token;
	}

	private function submit( array $form, string $token, array $fields = array() ): array {
		return $this->plugin()->get( SubmissionService::class )->submit(
			$form,
			array(
				'_fc_token' => $token,
				'fields'    => $fields ?: array( 'f_name01' => 'Eve', 'f_mail01' => 'eve@example.org' ),
				SpamGuard::honeypot_name( (string) $form['public_id'] ) => '',
			),
			array( 'ip' => '203.0.113.20' )
		);
	}

	public function test_rest_capability_check_precedes_existence(): void {
		$form = $this->create_published_form();
		$this->as_role( 'editor' );
		foreach ( array( (int) $form['id'], 987654 ) as $id ) {
			foreach ( array( array( 'GET', "/admin/forms/$id" ), array( 'DELETE', "/admin/forms/$id" ), array( 'POST', "/admin/forms/$id/duplicate" ), array( 'POST', "/admin/forms/$id/preview-link" ) ) as list( $m, $p ) ) {
				$res = $this->rest( $m, $p );
				$this->assertSame( 403, $res->get_status(), "$m $p" );
				$this->assertSame( 'forbidden', $res->get_data()['code'], 'Same answer for existing and missing IDs' );
			}
		}
		$this->as_admin();
		$this->assertSame( 404, $this->rest( 'GET', '/admin/forms/987654' )->get_status() );
		$this->assertSame( 'not_found', $this->rest( 'POST', '/admin/forms/987654/preview-link' )->get_data()['code'] );
	}

	public function test_entry_services_answer_forbidden_for_existing_and_missing_ids(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$entry = $this->make_entry( $form );
		$this->role_with_caps( 'fc_forms_only', array( Capabilities::MANAGE_FORMS ) );
		$service = $this->plugin()->get( EntryService::class );
		foreach ( array( $entry, 987654 ) as $id ) {
			$this->assertSame( 'forbidden', $service->set_status( $id, 'archived' )->get_error_code() );
			$this->assertSame( 'forbidden', $service->delete( $id )->get_error_code() );
			$this->assertSame( 'forbidden', $service->notes( $id )->get_error_code() );
			$this->assertSame( 'forbidden', $service->add_note( $id, 'x' )->get_error_code() );
			$this->assertSame( 'forbidden', $service->resend_notification( $id )->get_error_code() );
			$this->assertSame( 'forbidden', $this->plugin()->get( ContractApi::class )->get_entry( $id, array( 'meta' ) )->get_error_code() );
		}
		$this->assertSame( 'new', $this->entries()->find( $entry )['status'] );
	}

	public function test_detail_view_does_not_leak_entries_to_roles_without_read_entries(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$entry = $this->make_entry( $form, array( 'f_name01' => 'Secret Person', 'f_mail01' => 'secret@example.org' ) );
		$this->role_with_caps( 'fc_forms_only2', array( Capabilities::MANAGE_FORMS ) );
		$_GET = array( 'page' => EntriesPage::SLUG, 'view' => (string) $entry );
		ob_start();
		try {
			$this->plugin()->get( EntriesPage::class )->render();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertStringNotContainsString( 'secret@example.org', $e->getMessage() );
		} finally {
			$out = (string) ob_get_clean();
		}
		$this->assertStringNotContainsString( 'Secret Person', $out );
		$this->assertSame( 'new', $this->entries()->find( $entry )['status'], 'No auto-read by unauthorized users' );
	}

	public function test_nested_job_must_belong_to_entry(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$a     = $this->make_entry( $form );
		$b     = $this->make_entry( $form );
		$job_b = $this->enqueue_mail( $b, (int) $form['id'] );
		$this->jobs()->cancel( $job_b );
		$res = $this->plugin()->get( EntryService::class )->retry_job( $a, $job_b );
		$this->assertSame( 'job_not_found', $res->get_error_code(), 'Job of entry B via entry A' );
		$this->assertSame( 'cancelled', $this->jobs()->find( $job_b )['status'] );
	}

	public function test_entry_nonce_and_bulk_ids_are_checked_individually(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$b    = $this->make_entry( $form );
		$page = $this->plugin()->get( EntriesPage::class );
		$res  = $page->process_entry_action( array( 'entry_id' => $b, 'do' => 'delete', '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_ENTRY . $a ) ) );
		$this->assertSame( 'invalid_nonce', $res->get_error_code() );
		$this->assertNotNull( $this->entries()->find( $b ) );

		$res = $page->process_bulk( array( '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_BULK ), 'fc_bulk_action' => 'archive', 'ids' => array( $a, 987654, -3, 'x' ) ) );
		$this->assertSame( array( $a ), $res['done'] );
		$this->assertSame( array( array( 'id' => 987654, 'code' => 'entry_not_found' ) ), $res['skipped'] );
		$this->assertSame( 'new', $this->entries()->find( $b )['status'] );
	}

	public function test_public_routes_only_accept_public_ids(): void {
		$form = $this->create_published_form();
		$this->assertSame( 404, $this->rest( 'GET', '/forms/' . (int) $form['id'] . '/token' )->get_status(), 'Numeric ID is not routable' );
		$this->assertSame( 'rest_no_route', $this->rest( 'POST', '/forms/' . (int) $form['id'] . '/submissions' )->get_data()['code'] );
		$this->assertSame( 404, $this->rest( 'GET', '/forms/' . strtoupper( (string) $form['public_id'] ) . '/token' )->get_status() );
		$this->assertSame( 16, strlen( (string) $form['public_id'] ) );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{16}$/', (string) $form['public_id'] );

		$res = $this->rest( 'GET', '/forms/' . $form['public_id'] . '/token' )->get_data();
		$this->assertArrayNotHasKey( 'form_id', $res );
		$this->assertStringNotContainsString( '"id":' . (int) $form['id'], (string) wp_json_encode( $res ) );
	}

	public function test_submission_token_is_bound_to_its_form(): void {
		$a     = $this->create_published_form();
		$b     = $this->create_published_form();
		$token = $this->token( $a );
		$this->assertSame( 'invalid_token', $this->submit( $b, $token )['code'], 'Token of form A on form B' );
		$this->assertSame( 'created', $this->submit( $a, $token )['code'] );
		$this->assertSame( 'duplicate_request', $this->submit( $a, $token )['code'], 'Replay yields duplicate, not a second entry' );
		$this->assertSame( 1, (int) $this->entries()->list( array( 'form_id' => (int) $a['id'] ) )['total'] );
		$this->assertSame( 0, (int) $this->entries()->list( array( 'form_id' => (int) $b['id'] ) )['total'] );
	}

	public function test_success_response_exposes_no_entry_id(): void {
		$form = $this->create_published_form();
		$req  = new \WP_REST_Request( 'POST', '/form-captain/v1/forms/' . $form['public_id'] . '/submissions' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			(string) wp_json_encode(
				array(
					'_fc_token' => $this->token( $form ),
					'fields'    => array( 'f_name01' => 'Eve', 'f_mail01' => 'eve@example.org' ),
				)
			)
		);
		$data = rest_get_server()->dispatch( $req )->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( array( 'success', 'code', 'message', 'request_id' ), array_keys( $data ) );
	}

	public function test_no_js_result_is_bound_to_its_form(): void {
		$a                              = $this->create_published_form();
		$b                              = $this->create_published_form();
		$key                            = Renderer::store_result( array( 'public_id' => $a['public_id'], 'status' => 'error', 'values' => array( 'f_name01' => 'Private' ) ) );
		$_GET[ Renderer::RESULT_PARAM ] = $key;
		$this->assertNull( Renderer::load_result( (string) $b['public_id'] ) );
		$this->assertSame( 'Private', Renderer::load_result( (string) $a['public_id'] )['values']['f_name01'] );
		$_GET[ Renderer::RESULT_PARAM ] = "../../{$key}";
		$this->assertNull( Renderer::load_result( (string) $a['public_id'] ) );
	}

	public function test_preview_token_is_bound_to_user(): void {
		$owner = $this->as_admin();
		$form  = $this->create_published_form();
		$link  = $this->plugin()->get( PreviewTokens::class )->create( (int) $form['id'], $owner );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( 'forbidden', $this->plugin()->get( PreviewTokens::class )->verify( $link['token'], $other )->get_error_code() );
		$this->assertSame( 'invalid_token', $this->plugin()->get( PreviewTokens::class )->verify( $link['token'], 0 )->get_error_code() );
		$this->assertSame( (int) $form['id'], $this->plugin()->get( PreviewTokens::class )->verify( $link['token'], $owner ) );
	}

	public function test_job_grant_covers_only_its_entry(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$a    = $this->make_entry( $form );
		$b    = $this->make_entry( $form );
		wp_set_current_user( 0 );
		JobGrant::open( $a, array( 'f_mail01' ) );
		$api = $this->plugin()->get( ContractApi::class );
		$this->assertSame( array( 'email' => 'max@example.org' ), $api->get_entry( $a, array( 'values' ) )['values'] );
		$this->assertSame( 'forbidden', $api->get_entry( $b, array( 'meta' ) )->get_error_code() );
		$this->assertSame( 'forbidden', $api->get_entry( $a, array( 'sensitive' ) )->get_error_code() );
		JobGrant::close();
		$this->assertSame( 'forbidden', $api->get_entry( $a, array( 'meta' ) )->get_error_code() );
	}

	public function test_form_export_and_duplicate_use_the_requested_form_only(): void {
		$this->as_admin();
		$a       = $this->create_published_form();
		$service = $this->plugin()->get( FormService::class );
		$this->assertSame( 'not_found', $service->export( 987654 )->get_error_code() );
		$this->assertSame( 'not_found', $service->duplicate( 987654 )->get_error_code() );
		$this->assertSame( 'not_found', $service->delete( 987654 )->get_error_code() );
		$export = $service->export( (int) $a['id'] );
		$json   = (string) wp_json_encode( $export );
		$this->assertStringNotContainsString( (string) $a['public_id'], $json, 'T-JSON-06: no public_id' );
		$this->assertArrayNotHasKey( 'id', $export );
	}
}
