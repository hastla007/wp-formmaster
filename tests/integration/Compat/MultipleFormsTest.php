<?php
/**
 * Compatibility and conflict cases (P6-04): several forms on one page, Classic Editor shortcode
 * (the_content), block editor content (do_blocks) and full-page caching with stale tokens.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Compat;

use FormCaptain\Frontend\Renderer;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Tests\Integration\Frontend\FrontendTestCase;

require_once dirname( __DIR__ ) . '/Frontend/FrontendTestCase.php';

final class MultipleFormsTest extends FrontendTestCase {

	/**
	 * Renders $content like a front-end page view $age seconds ago (passes the minimum fill time).
	 */
	private function page_html( string $content, int $age = 30 ): string {
		Renderer::reset_counters();
		Clock::freeze( time() - $age );
		$html = (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		Clock::freeze( null );
		return $html;
	}

	/** @return array<int,array{form:string,token:string,id:string}> forms in document order */
	private static function forms_in( string $html ): array {
		preg_match_all( '/<form[^>]*\sid="([^"]+)"[^>]*data-fc-form="([^"]+)"[^>]*>.*?name="_fc_token" value="([^"]*)"/s', $html, $m, PREG_SET_ORDER );
		return array_map(
			static fn( array $row ): array => array(
				'id'    => $row[1],
				'form'  => $row[2],
				'token' => $row[3],
			),
			$m
		);
	}

	private function submit( string $public_id, string $token, string $ip, array $fields = array() ): \WP_REST_Response {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$req                    = new \WP_REST_Request( 'POST', '/form-captain/v1/forms/' . $public_id . '/submissions' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body(
			(string) wp_json_encode(
				array(
					'_fc_form'                             => $public_id,
					'_fc_token'                            => $token,
					'fields'                               => $fields ?: $this->valid_fields(),
					SpamGuard::honeypot_name( $public_id ) => '',
				)
			)
		);
		return rest_get_server()->dispatch( $req );
	}

	private function entries_of( array $form, string $status = 'new' ): int {
		return $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] . " AND status = '" . esc_sql( $status ) . "'" );
	}

	public function test_three_forms_on_one_page_have_unique_ids_tokens_and_are_submittable(): void {
		$a = $this->create_published_form();
		$b = $this->create_published_form();
		$c = $this->create_published_form();
		// Mixed page: Classic shortcode, block, and the same form twice.
		$content = '<p>Intro</p>[form_captain id="' . $a['public_id'] . '"]'
			. "\n<!-- wp:form-captain/form {\"formId\":\"" . $b['public_id'] . "\"} /-->\n"
			. '[form_captain id="' . $c['id'] . '"]'
			. '[form_captain id="' . $a['public_id'] . '"]';
		$html    = $this->page_html( $content );
		$forms   = self::forms_in( $html );

		$this->assertSame( array( $a['public_id'], $b['public_id'], $c['public_id'], $a['public_id'] ), array_column( $forms, 'form' ) );
		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		$this->assertSame( count( $ids[1] ), count( array_unique( $ids[1] ) ), 'All element IDs on the page are unique' );
		preg_match_all( '/\sfor="([^"]+)"/', $html, $fors );
		foreach ( $fors[1] as $for ) {
			$this->assertContains( $for, $ids[1], 'Every label points to an existing control' );
		}
		$tokens = array_column( $forms, 'token' );
		$this->assertCount( 4, array_unique( $tokens ), 'Every form instance gets its own token' );
		$this->assertStringContainsString( 'id="fc-' . $a['public_id'] . '-2"', $html, 'Second instance of the same form gets a suffixed wrapper ID' );

		foreach ( $forms as $i => $instance ) {
			$res = $this->submit( $instance['form'], $instance['token'], '198.51.100.' . ( 10 + $i ) );
			$this->assertSame( 201, $res->get_status(), 'Form instance ' . $i . ': ' . (string) wp_json_encode( $res->get_data() ) );
		}
		$this->assertSame( 2, $this->entries_of( $a ) );
		$this->assertSame( 1, $this->entries_of( $b ) );
		$this->assertSame( 1, $this->entries_of( $c ) );

		// A token is bound to its form.
		$res = $this->submit( $b['public_id'], $forms[0]['token'], '198.51.100.40' );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_token', $res->get_data()['code'] );
	}

	public function test_classic_editor_shortcode_in_post_content(): void {
		$form = $this->create_published_form();
		$post = self::factory()->post->create(
			array(
				'post_content' => "Absatz eins.\n\n[form_captain id=\"" . $form['public_id'] . "\"]\n\nAbsatz zwei.",
				'post_status'  => 'publish',
			)
		);
		$this->go_to( get_permalink( $post ) );
		$html = $this->page_html( (string) get_post_field( 'post_content', $post ) );
		$this->assertCount( 1, self::forms_in( $html ) );
		$this->assertStringNotContainsString( '[form_captain', $html );
		$this->assertStringNotContainsString( '<p><form', $html, 'wpautop does not wrap the form in a paragraph' );
		$this->assertStringNotContainsString( '<br />' . "\n" . '<input', $html, 'wpautop does not break hidden inputs' );
		$this->assertSame( 201, $this->submit( (string) $form['public_id'], self::forms_in( $html )[0]['token'], '198.51.100.50' )->get_status() );
	}

	public function test_block_in_block_editor_content(): void {
		$form    = $this->create_published_form();
		$content = "<!-- wp:paragraph -->\n<p>Vor dem Formular</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:form-captain/form {\"formId\":\"" . $form['public_id'] . "\"} /-->\n\n<!-- wp:paragraph -->\n<p>Danach</p>\n<!-- /wp:paragraph -->";
		$this->assertTrue( has_block( 'form-captain/form', $content ) );
		Renderer::reset_counters();
		Clock::freeze( time() - 30 );
		$html = do_blocks( $content );
		Clock::freeze( null );
		$forms = self::forms_in( $html );
		$this->assertCount( 1, $forms );
		$this->assertStringContainsString( 'Vor dem Formular', $html );
		$this->assertStringNotContainsString( '<!-- wp:form-captain', $html );
		$this->assertSame( 201, $this->submit( (string) $form['public_id'], $forms[0]['token'], '198.51.100.60' )->get_status() );
	}

	public function test_page_cache_with_stale_token_recovers_via_token_endpoint(): void {
		$form = $this->create_published_form();
		// Page HTML cached long ago (older than token_ttl) and served to every visitor.
		$cached = $this->page_html( '[form_captain id="' . $form['public_id'] . '"]', Limits::get( 'token_ttl' ) + 600 );
		$stale  = self::forms_in( $cached )[0]['token'];

		$res = $this->submit( (string) $form['public_id'], $stale, '198.51.100.70' );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_token', $res->get_data()['code'], 'Expired cached token is rejected' );
		$this->assertSame( 0, $this->entries_of( $form ) );

		// frontend.js fetches a fresh token (not cacheable) before submitting.
		$token_res = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/token' ) );
		$this->assertSame( 200, $token_res->get_status() );
		$this->assertStringContainsString( 'no-store', (string) $token_res->get_headers()['Cache-Control'] );
		$fresh = (string) $token_res->get_data()['token'];
		$this->assertNotSame( $stale, $fresh );

		Clock::freeze( time() + 30 ); // Visitor fills the form.
		$res = $this->submit( (string) $form['public_id'], $fresh, '198.51.100.70' );
		Clock::freeze( null );
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 1, $this->entries_of( $form ) );
	}

	public function test_page_cache_shared_valid_token_is_not_merged_across_visitors(): void {
		$form   = $this->create_published_form();
		$cached = $this->page_html( '[form_captain id="' . $form['public_id'] . '"]', 120 );
		$token  = self::forms_in( $cached )[0]['token'];

		// Visitor 1 submits with the cached token.
		$this->assertSame( 201, $this->submit( (string) $form['public_id'], $token, '198.51.100.80' )->get_status() );
		// Visitor 2 got the same cached HTML: the request key covers the submitted values,
		// so a different submission with the shared token is stored as its own entry.
		$res = $this->submit( (string) $form['public_id'], $token, '198.51.100.81', array( 'f_name01' => 'Zweite Person', 'f_mail01' => 'zwei@example.org' ) );
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 2, $this->entries_of( $form ) );
		// An identical repeat of visitor 2 is deduplicated: no third entry, no new jobs.
		$jobs = $this->count_rows( 'fc_jobs' );
		$rep  = $this->submit( (string) $form['public_id'], $token, '198.51.100.81', array( 'f_name01' => 'Zweite Person', 'f_mail01' => 'zwei@example.org' ) );
		$this->assertSame( 200, $rep->get_status() );
		$this->assertSame( 'duplicate_request', $rep->get_data()['code'] );
		$this->assertSame( $jobs, $this->count_rows( 'fc_jobs' ) );
		$this->assertSame( 2, $this->entries_of( $form ) );

		// With the token refresh (frontend.js) visitor 2 gets a fresh token and is stored separately.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.81';
		$fresh                  = (string) rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/token' ) )->get_data()['token'];
		Clock::freeze( time() + 30 );
		$res = $this->submit( (string) $form['public_id'], $fresh, '198.51.100.81', array( 'f_name01' => 'Zweite Person', 'f_mail01' => 'zwei@example.org' ) );
		Clock::freeze( null );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 3, $this->entries_of( $form ) );
	}
}
