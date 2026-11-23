<?php
/**
 * Admin REST routes for forms (OP-FRM-*, AC-027, AC-033).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Tests\Integration\TestCase;

final class AdminRestTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function call( string $method, string $route, array $body = array(), array $headers = array() ): \WP_REST_Response {
		$query = array();
		if ( str_contains( $route, '?' ) ) {
			list( $route, $qs ) = explode( '?', $route, 2 );
			parse_str( $qs, $query );
		}
		$request = new \WP_REST_Request( $method, '/form-captain/v1' . $route );
		$request->set_query_params( $query );
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	/** @return array<int,array{0:string,1:string}> */
	private function routes( int $id ): array {
		return array(
			array( 'GET', '/admin/forms' ),
			array( 'POST', '/admin/forms' ),
			array( 'GET', "/admin/forms/$id" ),
			array( 'PUT', "/admin/forms/$id" ),
			array( 'DELETE', "/admin/forms/$id" ),
			array( 'POST', "/admin/forms/$id/publish" ),
			array( 'POST', "/admin/forms/$id/status" ),
			array( 'POST', "/admin/forms/$id/duplicate" ),
			array( 'POST', "/admin/forms/$id/preview-link" ),
			array( 'PUT', "/admin/forms/$id/actions" ),
			array( 'GET', '/admin/templates' ),
		);
	}

	public function test_routes_refuse_anonymous_and_editor(): void {
		$form = $this->create_published_form();
		wp_set_current_user( 0 );
		foreach ( $this->routes( $form['id'] ) as $r ) {
			$this->assertSame( 401, $this->call( $r[0], $r[1] )->get_status(), implode( ' ', $r ) );
		}
		$this->as_role( 'editor' );
		foreach ( $this->routes( $form['id'] ) as $r ) {
			$res = $this->call( $r[0], $r[1] );
			$this->assertSame( 403, $res->get_status(), implode( ' ', $r ) );
			$this->assertStringNotContainsString( 'f_name01', (string) wp_json_encode( $res->get_data() ) );
		}
		$this->assertNotNull( $this->plugin()->get( FormRepository::class )->find( $form['id'] ) );
	}

	public function test_application_passwords_are_refused(): void {
		$this->as_admin();
		$res = $this->call( 'GET', '/admin/forms', array(), array( 'authorization' => 'Basic ' . base64_encode( 'a:b' ) ) ); // phpcs:ignore
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_actions_route_needs_manage_integrations(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		wp_get_current_user()->add_cap( 'form_captain_manage_integrations', false );
		$res = $this->call( 'PUT', '/admin/forms/' . $form['id'] . '/actions', array( 'revision' => 1, 'actions' => array() ) );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_crud_flow_with_revision_conflict(): void {
		$this->as_admin();
		$res = $this->call( 'POST', '/admin/forms', array( 'template' => 'contact' ) );
		$this->assertSame( 201, $res->get_status() );
		$form = $res->get_data()['form'];
		$this->assertSame( 'draft', $form['status'] );
		$id = $form['id'];

		$list = $this->call( 'GET', '/admin/forms?status=draft' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( $id, array_column( $list->get_data()['items'], 'id' ) );

		$get = $this->call( 'GET', "/admin/forms/$id" )->get_data()['form'];
		$put = $this->call(
			'PUT',
			"/admin/forms/$id",
			array(
				'title'    => 'Neu',
				'revision' => $get['revision'],
				'schema'   => $get['schema'],
				'settings' => $get['settings'],
			)
		);
		$this->assertSame( 200, $put->get_status() );
		$this->assertSame( $get['revision'] + 1, $put->get_data()['form']['revision'] );

		$stale = $this->call(
			'PUT',
			"/admin/forms/$id",
			array(
				'title'    => 'Alt',
				'revision' => $get['revision'],
				'schema'   => $get['schema'],
				'settings' => $get['settings'],
			)
		);
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'revision_conflict', $stale->get_data()['code'] );
		$this->assertFalse( $stale->get_data()['success'] );

		$this->assertSame( 400, $this->call( 'PUT', "/admin/forms/$id", array( 'title' => 'x' ) )->get_status() );

		$pub = $this->call( 'POST', "/admin/forms/$id/publish" );
		$this->assertSame( 200, $pub->get_status() );
		$this->assertSame( 'published', $pub->get_data()['form']['status'] );

		$st = $this->call( 'POST', "/admin/forms/$id/status", array( 'status' => 'paused' ) );
		$this->assertSame( 'paused', $st->get_data()['form']['status'] );
		$bad = $this->call( 'POST', "/admin/forms/$id/status", array( 'status' => 'nope' ) );
		$this->assertSame( 400, $bad->get_status() );

		$dup = $this->call( 'POST', "/admin/forms/$id/duplicate" );
		$this->assertSame( 201, $dup->get_status() );
		$this->assertNotSame( $form['public_id'], $dup->get_data()['form']['public_id'] );

		$link = $this->call( 'POST', "/admin/forms/$id/preview-link" );
		$this->assertSame( 200, $link->get_status() );
		$this->assertStringContainsString( 'action=form_captain_preview', $link->get_data()['url'] );

		$this->assertCount( 3, $this->call( 'GET', '/admin/templates' )->get_data()['items'] );

		$del = $this->call( 'DELETE', "/admin/forms/$id" );
		$this->assertSame( 200, $del->get_status() );
		$this->assertSame( 404, $this->call( 'GET', "/admin/forms/$id" )->get_status() );
	}

	public function test_save_reports_values_that_were_not_stored(): void {
		$this->as_admin();
		$form     = $this->call( 'POST', '/admin/forms', array( 'template' => 'contact' ) )->get_data()['form'];
		$settings = $form['settings'];

		$settings['limits'] = array(
			'opens_at'  => '2027-05-01T10:00',
			'closes_at' => '2027-04-01T10:00',
		);
		$put                = $this->call(
			'PUT',
			'/admin/forms/' . $form['id'],
			array(
				'title'    => $form['title'],
				'revision' => $form['revision'],
				'schema'   => $form['schema'],
				'settings' => $settings,
			)
		);
		$this->assertSame( 200, $put->get_status() );
		$saved = $put->get_data()['form'];
		$this->assertSame( '', $saved['settings']['limits']['closes_at'], 'the invalid end is not stored' );
		$this->assertSame( 'closes_before_opens', ( (array) $saved['errors'] )['limits.closes_at'] ?? null, 'and the editor is told why' );
	}

	public function test_publishing_invalid_form_is_rejected_with_errors(): void {
		$this->as_admin();
		$id  = $this->plugin()->get( FormService::class )->create_blank();
		$res = $this->call( 'POST', "/admin/forms/$id/publish" );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'schema_invalid', $res->get_data()['code'] );
		$this->assertArrayHasKey( 'fields', (array) $res->get_data()['errors'] );
	}

	public function test_delete_with_entries_conflicts(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$this->plugin()->get( \FormCaptain\Repository\EntryRepository::class )->insert(
			array(
				'form_id'         => $form['id'],
				'form_version_id' => $form['published_version_id'],
				'request_key'     => hash( 'sha256', 'rest' ),
			)
		);
		$res = $this->call( 'DELETE', '/admin/forms/' . $form['id'] );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'conflict', $res->get_data()['code'] );
	}

	public function test_save_actions_filters_mapping(): void {
		$this->as_admin();
		$form    = $this->create_published_form(
			array(
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
				array( 'id' => 'f_secr01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
			)
		);
		$service = $this->plugin()->get( FormService::class );
		$errors  = array();
		$out     = $service->sanitize_actions(
			array(
				array( 'instance_id' => 'a_0123456789', 'action_key' => 'ref.ticket', 'enabled' => true, 'mapping' => array( 'email' => 'f_mail01', 'iban' => 'f_secr01', 'x' => 'f_missing' ) ),
				array( 'action_key' => 'invalid key' ),
			),
			$form['schema'],
			$errors,
			false
		);
		$this->assertCount( 1, $out );
		$this->assertSame( array( 'email' => 'f_mail01' ), $out[0]['mapping'] );
		$this->assertSame( 'sensitive_field', $errors['actions.0.mapping.iban'] );
		$this->assertSame( 'invalid_mapping', $errors['actions.0.mapping.x'] );
		$this->assertSame( 'invalid_action_key', $errors['actions.1.action_key'] );
	}
}
