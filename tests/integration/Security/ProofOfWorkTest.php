<?php
/**
 * Built-in proof-of-work captcha and sending limits as settings (SET-FC-05).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Security;

use FormCaptain\Http\AdminPostController;
use FormCaptain\Http\RestController;
use FormCaptain\Mail\MailJobHandler;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Security\PowCaptcha;
use FormCaptain\Security\RateLimiter;
use FormCaptain\Security\SpamLimits;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Spam\ProofOfWork\ProofOfWork;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Tests\Integration\Frontend\FrontendTestCase;

require_once dirname( __DIR__ ) . '/Frontend/FrontendTestCase.php';

final class ProofOfWorkTest extends FrontendTestCase {

	public function tear_down(): void {
		remove_all_filters( 'form_captain_pow_max_number_v1' );
		remove_all_filters( 'form_captain_spam_limits_v1' );
		remove_all_filters( 'form_captain_captcha_providers_v1' );
		remove_all_filters( 'form_captain_spam_checks_v1' );
		parent::tear_down();
	}

	private function engine( int $now = 1700000000, ?array &$used = null ): ProofOfWork {
		$used = array();
		return new ProofOfWork(
			str_repeat( 'k', 32 ),
			static function ( string $challenge ) use ( &$used ): bool {
				if ( isset( $used[ $challenge ] ) ) {
					return false;
				}
				$used[ $challenge ] = true;
				return true;
			},
			1000,
			600,
			static fn(): int => $now
		);
	}

	public function test_core_creates_and_checks_challenges(): void {
		$pow       = $this->engine();
		$challenge = $pow->create( 'form-a' );
		$this->assertSame( 'SHA-256', $challenge['algorithm'] );
		$this->assertSame( 1000, $challenge['maxnumber'] );
		$this->assertSame( 1700000600, $challenge['expires'] );
		$number = ProofOfWork::solve( $challenge );
		$this->assertNotNull( $number );

		$this->assertSame( ProofOfWork::MISSING, $pow->verify( '', 'form-a' ) );
		$this->assertSame( ProofOfWork::MALFORMED, $pow->verify( 'not base64 json', 'form-a' ) );
		$this->assertSame( ProofOfWork::WRONG_SCOPE, $pow->verify( ProofOfWork::encode( $challenge, $number ), 'form-b' ) );
		$this->assertSame( ProofOfWork::WRONG_SOLUTION, $pow->verify( ProofOfWork::encode( $challenge, $number + 1 ), 'form-a' ) );
		$forged              = $challenge;
		$forged['challenge'] = hash( 'sha256', $challenge['salt'] . '0' );
		$this->assertSame( ProofOfWork::BAD_SIGNATURE, $pow->verify( ProofOfWork::encode( $forged, 0 ), 'form-a' ) );
		$this->assertSame( ProofOfWork::OK, $pow->verify( ProofOfWork::encode( $challenge, $number ), 'form-a' ) );
		$this->assertSame( ProofOfWork::REPLAYED, $pow->verify( ProofOfWork::encode( $challenge, $number ), 'form-a' ), 'single use' );

		$late = $this->engine( 1700000601 );
		$this->assertSame( ProofOfWork::EXPIRED, $late->verify( ProofOfWork::encode( $challenge, $number ), 'form-a' ) );
	}

	public function test_core_file_has_no_host_dependencies(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Spam/ProofOfWork/ProofOfWork.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertDoesNotMatchRegularExpression( '/\b(apply_filters|get_option|wp_[a-z_]+|__|esc_[a-z_]+)\s*\(/', $code );
		$this->assertStringNotContainsString( 'use FormCaptain\\', $code );
	}

	public function test_provider_choice_and_form_modes(): void {
		$this->assertArrayHasKey( 'pow', SettingsSchema::captcha_options() );
		list( $values, $errors ) = SettingsSchema::sanitize_group( 'spam', array( 'captcha_provider' => 'pow' ) + Settings::all()['spam'] );
		$this->assertSame( array(), $errors, 'built-in provider needs no third-party confirmation' );
		$this->assertSame( 'pow', $values['captcha_provider'] );

		$form = array( 'settings' => array( 'spam' => array( 'mode' => 'global' ) ) );
		$this->assertSame( 'none', PowCaptcha::provider_for( $form ) );
		$this->assertSame( 'pow', PowCaptcha::provider_for( array( 'settings' => array( 'spam' => array( 'mode' => 'strict' ) ) ) ) );
		$this->set_spam_setting( 'captcha_provider', 'pow' );
		$this->assertSame( 'pow', PowCaptcha::provider_for( $form ) );
		$this->assertSame( 'none', PowCaptcha::provider_for( array( 'settings' => array( 'spam' => array( 'mode' => 'off' ) ) ) ) );
	}

	/**
	 * An external provider registers through its own filter and checks through form_captain_spam_checks_v1
	 * (SET-FC-P6): a typed check callback is never called with the provider list, so the settings
	 * screen and the submission both work.
	 */
	public function test_external_provider_registers_and_checks_through_separate_filters(): void {
		add_filter(
			'form_captain_captcha_providers_v1',
			static fn( array $providers ): array => $providers + array(
				'extcap' => array(
					'type'        => 'captcha',
					'label'       => 'External captcha',
					'data'        => 'IP address',
					'purpose'     => 'Bot check',
					'privacy_url' => 'https://example.org/privacy',
				),
			)
		);
		$calls = 0;
		add_filter(
			'form_captain_spam_checks_v1',
			static function ( ?string $reason, array $context, array $values ) use ( &$calls ): ?string {
				unset( $values );
				++$calls;
				return 'extcap' === $context['captcha'] && empty( $context['raw']['extcap_token'] ) ? 'extcap_missing' : $reason;
			},
			10,
			3
		);
		$this->assertSame( 'External captcha', SettingsSchema::captcha_options()['extcap'] );
		$this->assertArrayHasKey( 'extcap', SettingsSchema::captcha_providers() );
		$this->assertSame( 0, $calls, 'the provider list does not run spam checks' );

		$this->set_spam_setting( 'captcha_provider', 'extcap' );
		$form    = $this->create_published_form();
		$service = $this->plugin()->get( SubmissionService::class );
		$entries = $this->plugin()->get( EntryRepository::class );
		$res     = $service->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertSame( 'extcap_missing', $entries->find( (int) $res['entry_id'] )['spam_reason'] );
		$res = $service->submit( $form, array( 'extcap_token' => 'ok' ) + $this->payload( $form, array( 'f_name01' => 'E', 'f_mail01' => 'e@example.org' ) ) );
		$this->assertSame( 'new', $entries->find( (int) $res['entry_id'] )['status'] );
		$this->assertSame( 2, $calls );
	}

	public function test_submissions_with_and_without_solution(): void {
		$this->set_spam_setting( 'captcha_provider', 'pow' );
		add_filter( 'form_captain_pow_max_number_v1', static fn(): int => 1000 );
		$form    = $this->create_published_form();
		$service = $this->plugin()->get( SubmissionService::class );
		$entries = $this->plugin()->get( EntryRepository::class );

		// Token route hands out a challenge bound to the form.
		$challenge = PowCaptcha::challenge_for( $form );
		$this->assertNotNull( $challenge );
		$payload = ProofOfWork::encode( $challenge, (int) ProofOfWork::solve( $challenge ) );

		$ok = $service->submit( $form, array( '_fc_pow' => $payload ) + $this->payload( $form, $this->valid_fields() ), array( 'js' => true ) );
		$this->assertSame( 'new', $entries->find( (int) $ok['entry_id'] )['status'] );

		$replay = $service->submit( $form, array( '_fc_pow' => $payload ) + $this->payload( $form, array( 'f_name01' => 'B', 'f_mail01' => 'b@example.org' ) ), array( 'js' => true ) );
		$this->assertSame( PowCaptcha::REASON_REPLAYED, $entries->find( (int) $replay['entry_id'] )['spam_reason'] );

		$missing = $service->submit( $form, $this->payload( $form, array( 'f_name01' => 'C', 'f_mail01' => 'c@example.org' ) ), array( 'js' => true ) );
		$this->assertSame( PowCaptcha::REASON_MISSING, $entries->find( (int) $missing['entry_id'] )['spam_reason'], 'the JS route needs a solution' );

		// No-JS fallback (admin-post): the existing checks decide.
		$nojs = $this->plugin()->get( AdminPostController::class )->process( $this->payload( $form, array( 'f_name01' => 'D', 'f_mail01' => 'd@example.org' ) ) + array( '_fc_form' => $form['public_id'] ), array() );
		$this->assertTrue( $nojs['result']['success'] );
		$this->assertSame( 'new', $entries->find( (int) $nojs['result']['entry_id'] )['status'] );

		// Strict forms require the solution also without JavaScript.
		$strict = $this->create_published_form( null, array( 'spam' => array( 'mode' => 'strict' ) ) );
		$res    = $service->submit( $strict, $this->payload( $strict, $this->valid_fields() ) );
		$this->assertSame( PowCaptcha::REASON_MISSING, $entries->find( (int) $res['entry_id'] )['spam_reason'] );

		// Forms that switched the captcha off are not affected.
		$off = $this->create_published_form( null, array( 'spam' => array( 'mode' => 'off' ) ) );
		$res = $service->submit( $off, $this->payload( $off, $this->valid_fields() ), array( 'js' => true ) );
		$this->assertSame( 'new', $entries->find( (int) $res['entry_id'] )['status'] );
		$this->assertNull( PowCaptcha::challenge_for( $off ) );
	}

	/**
	 * SET-FC-P4-01: frontend.js fetches token and challenge together and keeps the token for up to
	 * 115 minutes. A solution solved at the first focus must still count when the visitor submits
	 * 100 minutes later, otherwise the entry lands in spam while the visitor sees a success message.
	 */
	public function test_solution_stays_valid_as_long_as_the_token(): void {
		$this->set_spam_setting( 'captcha_provider', 'pow' );
		add_filter( 'form_captain_pow_max_number_v1', static fn(): int => 1000 );
		$form    = $this->create_published_form();
		$issued  = time() - 100 * MINUTE_IN_SECONDS;
		$entries = $this->plugin()->get( EntryRepository::class );

		Clock::freeze( $issued );
		$token     = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] )['token'];
		$challenge = PowCaptcha::challenge_for( $form );
		Clock::freeze( null );
		$this->assertGreaterThanOrEqual( $issued + Limits::get( 'token_ttl' ), $challenge['expires'], 'challenge lives as long as the token' );

		$payload = ProofOfWork::encode( $challenge, (int) ProofOfWork::solve( $challenge ) );
		$res     = $this->plugin()->get( SubmissionService::class )->submit( $form, array( '_fc_pow' => $payload ) + $this->payload( $form, $this->valid_fields(), $token ), array( 'js' => true ) );
		$this->assertTrue( $res['success'] );
		$entry = $entries->find( (int) $res['entry_id'] );
		$this->assertSame( 'new', $entry['status'] );
		$this->assertNull( $entry['spam_reason'] );
	}

	/**
	 * SET-FC-P4-06: renewing the signing key keeps the previous key valid for 26 hours (settings
	 * screen). Open forms – token and proof-of-work challenge – must keep working in that window;
	 * "discard immediately" invalidates them.
	 */
	public function test_open_forms_survive_a_key_renewal_with_grace_period(): void {
		$this->set_spam_setting( 'captcha_provider', 'pow' );
		add_filter( 'form_captain_pow_max_number_v1', static fn(): int => 1000 );
		$form    = $this->create_published_form();
		$service = $this->plugin()->get( SubmissionService::class );
		$entries = $this->plugin()->get( EntryRepository::class );
		$page    = $this->plugin()->get( \FormCaptain\Admin\SettingsPage::class );
		$open    = function () use ( $form ): array {
			$token     = $this->token( $form );
			$challenge = PowCaptcha::challenge_for( $form );
			return array( '_fc_pow' => ProofOfWork::encode( $challenge, (int) ProofOfWork::solve( $challenge ) ) ) + $this->payload( $form, $this->valid_fields(), $token );
		};

		$before = $open();
		$page->rotate_secret();
		$res = $service->submit( $form, $before, array( 'js' => true ) );
		$this->assertTrue( $res['success'], 'token of the previous key accepted' );
		$this->assertSame( 'new', $entries->find( (int) $res['entry_id'] )['status'], 'challenge of the previous key accepted' );

		$before = $open();
		$page->rotate_secret( true );
		$res = $service->submit( $form, $before, array( 'js' => true ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'invalid_token', $res['code'], 'discarded key: open forms are invalid' );
	}

	public function test_token_route_contains_the_challenge_only_when_used(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		$form = $this->create_published_form();
		$get  = static fn(): array => rest_do_request( new \WP_REST_Request( 'GET', '/' . RestController::NAMESPACE . '/forms/' . $form['public_id'] . '/token' ) )->get_data();
		$this->assertArrayNotHasKey( 'pow', $get() );
		$this->set_spam_setting( 'captcha_provider', 'pow' );
		$data = $get();
		$this->assertSame( 'SHA-256', $data['pow']['algorithm'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}\.\d+\.[0-9a-f]{16}$/', $data['pow']['salt'] );
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}

	public function test_limits_are_settings_and_the_filter_wins(): void {
		$this->assertSame( 10, Settings::get( 'spam.window_minutes' ) );
		$this->assertSame( 300, Settings::get( 'spam.form_total' ) );
		$this->assertSame( 3, Settings::get( 'spam.receipts_per_hour' ) );
		$this->assertSame( 600, SpamLimits::window_seconds() );

		list( , $errors ) = SettingsSchema::sanitize_group( 'spam', array( 'form_total' => '5', 'window_minutes' => '0', 'receipts_per_hour' => '51' ) + Settings::all()['spam'] );
		$this->assertArrayHasKey( 'spam.form_total', $errors );
		$this->assertArrayHasKey( 'spam.window_minutes', $errors );
		$this->assertArrayHasKey( 'spam.receipts_per_hour', $errors );

		$this->set_spam_setting( 'form_total', 10 );
		$limiter = $this->plugin()->get( RateLimiter::class );
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertTrue( $limiter->check_submission( 77, '198.51.100.' . $i )['allowed'] );
		}
		$this->assertFalse( $limiter->check_submission( 77, '198.51.100.11' )['allowed'], 'flood brake from the setting' );

		add_filter( 'form_captain_spam_limits_v1', static fn( array $l ): array => array( 'form_total' => 50000, 'window_minutes' => 99999 ) + $l );
		$this->assertSame( 50000, SpamLimits::get( 'form_total' ), 'filter wins over the setting' );
		$this->assertSame( 1440, SpamLimits::get( 'window_minutes' ), 'kept within bounds' );
		$this->assertSame( 3, SpamLimits::get( 'receipts_per_hour' ) );
		$this->assertSame( 3, MailJobHandler::RECEIPTS_PER_HOUR );
	}
}
