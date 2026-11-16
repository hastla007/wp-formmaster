<?php
/**
 * Public REST routes and No-JS fallback (P3-06, P3-13, P3-14, P3-15, ADR 0003).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Frontend\Renderer;
use FormCaptain\Http\AdminPostController;
use FormCaptain\Repository\FormRepository;

require_once __DIR__ . '/FrontendTestCase.php';

final class RestSubmissionTest extends FrontendTestCase {

	/**
	 * @param array<string,mixed> $body
	 */
	private function post( string $public_id, array $body, string $type = 'application/json', array $headers = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/form-captain/v1/forms/' . $public_id . '/submissions' );
		$req->set_header( 'content-type', $type );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		if ( 'application/json' === $type ) {
			$req->set_body( (string) wp_json_encode( $body ) );
		} else {
			$req->set_body_params( $body );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_token_route_is_public_and_not_cacheable(): void {
		$form = $this->create_published_form();
		$res  = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/token' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['token'] );
		$this->assertSame( $data['issued_at'] + 3, $data['min_submit_at'] );
		$this->assertStringContainsString( 'no-store', $res->get_headers()['Cache-Control'] );

		$draft = $this->plugin()->get( FormRepository::class )->create( 'Draft', array( 'schema_version' => 1, 'fields' => array() ), array() );
		$draft = $this->plugin()->get( FormRepository::class )->find( $draft );
		$res   = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $draft['public_id'] . '/token' ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'form_not_found', $res->get_data()['code'] );
	}

	public function test_json_submission_success_without_entry_id(): void {
		$form = $this->create_published_form();
		$res  = $this->post( (string) $form['public_id'], $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 201, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'created', $data['code'] );
		$this->assertArrayNotHasKey( 'entry_id', $data );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $data['request_id'] );
		$this->assertStringNotContainsString( 'example.org', wp_json_encode( $data ) );
		$this->assertStringContainsString( 'no-store', $res->get_headers()['Cache-Control'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $res->get_headers() );
	}

	public function test_urlencoded_submission_and_duplicate(): void {
		$form = $this->create_published_form();
		$body = $this->payload( $form, array() );
		unset( $body['fields'] );
		$body['fc'] = $this->valid_fields();
		$first      = $this->post( (string) $form['public_id'], $body, 'application/x-www-form-urlencoded' );
		$this->assertSame( 201, $first->get_status() );
		$again = $this->post( (string) $form['public_id'], $body, 'application/x-www-form-urlencoded' );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( 'duplicate_request', $again->get_data()['code'] );
	}

	public function test_validation_error_response(): void {
		$form = $this->create_published_form();
		$res  = $this->post( (string) $form['public_id'], $this->payload( $form, array( 'f_name01' => '' ) ) );
		$this->assertSame( 400, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'validation_failed', $data['code'] );
		$this->assertArrayHasKey( 'f_name01', $data['errors'] );
		$this->assertSame( 'required', $data['error_codes']['f_name01'] );
	}

	public function test_limits_media_type_and_methods(): void {
		$form = $this->create_published_form();
		$big  = $this->payload( $form, array( 'f_msg001' => str_repeat( 'x', 70000 ) ) );
		$this->assertSame( 413, $this->post( (string) $form['public_id'], $big )->get_status() );
		$this->assertSame( 413, $this->post( (string) $form['public_id'], $this->payload( $form, $this->valid_fields() ), 'application/json', array( 'content-length' => '300000' ) )->get_status() );
		$this->assertSame( 415, $this->post( (string) $form['public_id'], $this->payload( $form, $this->valid_fields() ), 'text/plain' )->get_status() );

		$get = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/submissions' ) );
		$this->assertSame( 405, $get->get_status() );
	}

	public function test_rate_limit_returns_429_with_retry_after(): void {
		$form = $this->create_published_form();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->post( (string) $form['public_id'], $this->payload( $form, $this->valid_fields() ) );
		}
		$res = $this->post( (string) $form['public_id'], $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'rate_limited', $res->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $res->get_headers()['Retry-After'] );
	}

	public function test_paused_form_returns_409(): void {
		$form = $this->create_published_form();
		$this->plugin()->get( FormRepository::class )->set_status( (int) $form['id'], 'paused' );
		$res = $this->post( (string) $form['public_id'], $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'form_not_accepting', $res->get_data()['code'] );
	}

	public function test_redirect_url_only_internal(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$form = $this->create_published_form( null, array( 'confirmation' => array( 'type' => 'page', 'page_id' => $page ) ) );
		$body = $this->payload( $form, $this->valid_fields() ) + array( 'redirect_url' => 'https://evil.example/' );
		$data = $this->post( (string) $form['public_id'], $body )->get_data();
		$this->assertSame( get_permalink( $page ), $data['redirect_url'] );
	}

	public function test_nojs_success_redirects_back_with_result_key(): void {
		$form = $this->create_published_form();
		$post = $this->payload( $form, array() );
		unset( $post['fields'] );
		$post['fc']     = $this->valid_fields();
		$post['action'] = 'form_captain_submit';
		$src            = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$target         = $this->plugin()->get( AdminPostController::class )->process( $post, array( 'HTTP_REFERER' => get_permalink( $src ) . '?fc_r=old' ) );

		$this->assertSame( 303, $target['status'] );
		$this->assertStringStartsWith( home_url(), $target['location'] );
		$this->assertMatchesRegularExpression( '/[?&]fc_r=[a-f0-9]{32}#fc-' . $form['public_id'] . '$/', $target['location'] );
		$this->assertStringNotContainsString( 'fc_r=old', $target['location'] );

		parse_str( (string) wp_parse_url( strtok( $target['location'], '#' ), PHP_URL_QUERY ), $query );
		$_GET = $query;
		$html = $this->plugin()->get( Renderer::class )->render( $this->plugin()->get( FormRepository::class )->find( (int) $form['id'] ) );
		$this->assertStringContainsString( 'Danke', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_nojs_error_keeps_values_and_never_redirects_externally(): void {
		$form   = $this->create_published_form();
		$post   = array(
			'_fc_form'  => $form['public_id'],
			'_fc_token' => $this->token( $form ),
			'fc'        => array( 'f_name01' => 'Erika', 'f_mail01' => 'kaputt' ),
		);
		$target = $this->plugin()->get( AdminPostController::class )->process( $post, array( 'HTTP_REFERER' => 'https://evil.example/phish' ) );
		$this->assertStringStartsWith( home_url( '/' ), $target['location'] );
		$this->assertStringNotContainsString( 'evil.example', $target['location'] );

		parse_str( (string) wp_parse_url( strtok( $target['location'], '#' ), PHP_URL_QUERY ), $query );
		$_GET = $query;
		$html = $this->plugin()->get( Renderer::class )->render( $this->plugin()->get( FormRepository::class )->find( (int) $form['id'] ) );
		$this->assertStringContainsString( 'value="Erika"', $html );
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertMatchesRegularExpression( '/<div class="fc-summary" role="alert" tabindex="-1" data-fc-focus="1">/', $html );
		$this->assertStringContainsString( 'href="#fc1-f_mail01"', $html );
	}

	public function test_nojs_expired_token_rerenders_with_values(): void {
		$form   = $this->create_published_form();
		$post   = array(
			'_fc_form'  => $form['public_id'],
			'_fc_token' => $this->token( $form, 7300 ),
			'fc'        => $this->valid_fields(),
		);
		$target = $this->plugin()->get( AdminPostController::class )->process( $post, array() );
		$this->assertSame( 'invalid_token', $target['result']['code'] );
		parse_str( (string) wp_parse_url( strtok( $target['location'], '#' ), PHP_URL_QUERY ), $query );
		$_GET = $query;
		$html = $this->plugin()->get( Renderer::class )->render( $this->plugin()->get( FormRepository::class )->find( (int) $form['id'] ) );
		$this->assertStringContainsString( 'value="Erika Muster"', $html );
		$this->assertStringContainsString( 'name="_fc_token" value="', $html );
	}
}
