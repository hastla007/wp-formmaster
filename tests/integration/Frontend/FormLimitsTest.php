<?php
/**
 * Limits per form (SET-FC-18): submission period in the site time zone, maximum number of
 * entries (spam and trash do not count) and the text shown while the form is closed.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Frontend\Renderer;
use FormCaptain\Http\RestController;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Service\FormLimits;
use FormCaptain\Service\FormService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;

require_once __DIR__ . '/FrontendTestCase.php';

final class FormLimitsTest extends FrontendTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Berlin' );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	private function service(): SubmissionService {
		return $this->plugin()->get( SubmissionService::class );
	}

	/** @param array<string,mixed> $limits */
	private function limited_form( array $limits ): array {
		return $this->create_published_form( null, array( 'limits' => array_merge( FormLimits::defaults(), $limits ) ) );
	}

	public function test_sanitize_checks_dates_order_and_maximum(): void {
		$errors = array();
		$clean  = FormLimits::sanitize(
			array(
				'opens_at'       => '2026-12-01 09:00',
				'closes_at'      => '2026-12-24T18:30',
				'max_entries'    => '250',
				'closed_message' => " Anmeldung beendet.\n<b>Danke</b> ",
			),
			$errors
		);
		$this->assertSame( array(), $errors );
		$this->assertSame( '2026-12-01T09:00', $clean['opens_at'] );
		$this->assertSame( '2026-12-24T18:30', $clean['closes_at'] );
		$this->assertSame( 250, $clean['max_entries'] );
		$this->assertSame( "Anmeldung beendet.\nDanke", $clean['closed_message'] );

		$errors = array();
		$clean  = FormLimits::sanitize(
			array(
				'opens_at'    => '2026-02-30T10:00',
				'closes_at'   => 'tomorrow',
				'max_entries' => '-5',
			),
			$errors
		);
		$this->assertSame( 'invalid_datetime', $errors['limits.opens_at'] );
		$this->assertSame( 'invalid_datetime', $errors['limits.closes_at'] );
		$this->assertSame( 'invalid_max_entries', $errors['limits.max_entries'] );
		$this->assertSame( FormLimits::defaults(), $clean );

		$errors = array();
		FormLimits::sanitize( array( 'opens_at' => '2026-12-24T18:00', 'closes_at' => '2026-12-24T18:00' ), $errors );
		$this->assertSame( 'closes_before_opens', $errors['limits.closes_at'] );

		$errors = array();
		FormLimits::sanitize( array( 'max_entries' => (string) ( FormLimits::MAX_ENTRIES + 1 ) ), $errors );
		$this->assertSame( 'invalid_max_entries', $errors['limits.max_entries'] );
	}

	public function test_form_service_stores_limits_with_defaults(): void {
		$service = $this->plugin()->get( FormService::class );
		$this->assertSame( FormLimits::defaults(), $service->default_settings()['limits'] );
		$clean = $service->sanitize_settings( array( 'limits' => array( 'max_entries' => 3 ) ), array( 'fields' => array() ) );
		$this->assertSame( 3, $clean['settings']['limits']['max_entries'] );
	}

	public function test_period_uses_the_site_time_zone(): void {
		// 09:00 in Berlin (winter) is 08:00 UTC.
		$form = $this->limited_form(
			array(
				'opens_at'  => '2026-12-01T09:00',
				'closes_at' => '2026-12-02T09:00',
			)
		);
		$this->assertSame( 'not_open_yet', FormLimits::state( $form, null, (int) strtotime( '2026-12-01 07:59:59 UTC' ) )['reason'] );
		$this->assertTrue( FormLimits::state( $form, null, (int) strtotime( '2026-12-01 08:00:00 UTC' ) )['open'] );
		$this->assertTrue( FormLimits::state( $form, null, (int) strtotime( '2026-12-02 07:59:59 UTC' ) )['open'] );
		$closed = FormLimits::state( $form, null, (int) strtotime( '2026-12-02 08:00:00 UTC' ) );
		$this->assertSame( 'closed', $closed['reason'] );
		$this->assertSame( 'This form is closed and no longer accepts submissions.', $closed['message'] );
		$this->assertStringContainsString( 'This form opens on', FormLimits::state( $form, null, (int) strtotime( '2026-11-30 12:00 UTC' ) )['message'] );
	}

	public function test_closed_form_is_not_rendered_and_refuses_submissions(): void {
		$form = $this->limited_form(
			array(
				'closes_at'      => '2020-01-01T00:00',
				'closed_message' => 'Die Anmeldung ist beendet.',
			)
		);
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( '<p class="fc-closed" role="status">Die Anmeldung ist beendet.</p>', $html );
		$this->assertStringNotContainsString( '<form', $html );

		$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( Errors::FORM_NOT_ACCEPTING, $res['code'] );
		$this->assertSame( 'Die Anmeldung ist beendet.', $res['message'] );
		$this->assertSame( 0, $this->count_rows( 'fc_entries', 'form_id = ' . (int) $form['id'] ) );

		$this->as_admin();
		$this->assertStringContainsString( 'fc-notice--editor', $this->plugin()->get( Renderer::class )->render( $form ) );
		$this->assertStringContainsString( '<form', $this->plugin()->get( Renderer::class )->render( $form, array( 'preview' => true ) ), 'preview still shows the form' );
	}

	public function test_token_endpoint_refuses_a_closed_form(): void {
		$form    = $this->limited_form( array( 'opens_at' => '2099-01-01T00:00' ) );
		$request = new \WP_REST_Request( 'POST', '/form-captain/v1/forms/' . $form['public_id'] . '/token' );
		$request->set_url_params( array( 'public_id' => $form['public_id'] ) );
		$res = $this->plugin()->get( RestController::class )->token( $request );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( Errors::FORM_NOT_ACCEPTING, $res->get_data()['code'] );
		$this->assertStringContainsString( 'This form opens on', $res->get_data()['message'] );
	}

	public function test_maximum_counts_only_real_entries_and_a_retry_keeps_its_answer(): void {
		$form  = $this->limited_form( array( 'max_entries' => 2 ) );
		$repo  = $this->plugin()->get( EntryRepository::class );
		$first = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertTrue( $first['success'] );
		$repo->set_status( (int) $first['entry_id'], 'trash' );

		$token  = $this->token( $form );
		$second = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields(), $token ) );
		$this->assertTrue( $second['success'], 'trashed entry does not count' );
		$third = $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'Max', 'f_mail01' => 'max@example.org' ) ) );
		$this->assertTrue( $third['success'] );
		$this->assertSame( 2, $repo->count_accepted( (int) $form['id'] ) );

		$full = $this->service()->submit( $form, $this->payload( $form, array( 'f_name01' => 'Zu spät', 'f_mail01' => 'late@example.org' ) ) );
		$this->assertSame( Errors::FORM_NOT_ACCEPTING, $full['code'] );
		$this->assertSame( 'This form has reached the maximum number of submissions and is closed.', $full['message'] );

		$retry = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields(), $token ) );
		$this->assertTrue( $retry['success'], 'idempotent retry is answered before the limit check' );
		$this->assertSame( $second['entry_id'], $retry['entry_id'] );

		$this->assertStringContainsString( 'fc-closed', $this->plugin()->get( Renderer::class )->render( $form ) );
	}

	public function test_filter_can_change_the_result(): void {
		$form = $this->limited_form( array() );
		$deny = static fn( array $state ): array => array(
			'open'    => false,
			'reason'  => 'custom',
			'message' => 'Nur für Mitglieder.',
		);
		add_filter( 'form_captain_form_limits_v1', $deny );
		try {
			$res = $this->service()->submit( $form, $this->payload( $form, $this->valid_fields() ) );
			$this->assertSame( 'Nur für Mitglieder.', $res['message'] );
		} finally {
			remove_filter( 'form_captain_form_limits_v1', $deny );
		}
		Clock::freeze( null );
		$this->assertTrue( FormLimits::state( $form )['open'] );
	}
}
