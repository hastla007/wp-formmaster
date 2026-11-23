<?php
/**
 * Double opt-in per form (SET-FC-10, owner decision E2: free).
 *
 * Flow:
 * 1. A public submission of a form with double opt-in is stored with confirmation_status
 *    "pending". Instead of notifications and connector jobs only the job `mail.double_opt_in`
 *    is planned; the public event form_captain_entry_created_v1 is not fired yet.
 * 2. The job creates a random link token, stores only its SHA-256 hash and mails the link.
 *    A repeated delivery attempt creates a new token (older links of failed attempts expire).
 * 3. GET on the link shows a page with a button; only the POST confirms, so link scanners of
 *    mail providers cannot confirm by opening the link.
 * 4. Confirming is single use: status "confirmed", confirmed_gmt (no IP); only the holder of the
 *    last link learns "already confirmed" afterwards (its hash is kept). Then
 *    the outbox is planned (notifications, actions) and the event is fired, as for a normal entry.
 * 5. Unconfirmed entries are deleted by the retention cleanup after `delete_after_days`.
 *
 * Trusted submissions (contract API, imports) never need a confirmation: the calling plugin
 * is responsible for the person's consent.
 *
 * Filter `form_captain_double_opt_in_v1` ( array $settings, array $form ) wins over the form
 * settings (enabled, link_hours, delete_after_days, texts).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Jobs\Scheduler;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Settings\FormMessagingSettings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;
use FormCaptain\Support\Secret;

final class DoubleOptIn {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_CONFIRMED = 'confirmed';
	public const JOB_TYPE         = 'mail.double_opt_in';
	public const ACTION           = 'form_captain_confirm';

	public function __construct(
		private EntryRepository $entries,
		private FormRepository $forms,
		private OutboxPlanner $planner,
		private FormSchemaLoader $loader,
		private SubmissionService $submissions
	) {}

	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Effective settings of a form (defaults, stored values, then the filter).
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	public static function settings( array $form ): array {
		$stored   = is_array( $form['settings']['double_opt_in'] ?? null ) ? $form['settings']['double_opt_in'] : array();
		$settings = array_merge( FormMessagingSettings::double_opt_in_defaults(), $stored );
		$filtered = apply_filters( 'form_captain_double_opt_in_v1', $settings, $form );
		if ( is_array( $filtered ) ) {
			$settings = array_merge( $settings, $filtered );
		}
		$settings['enabled']           = ! empty( $settings['enabled'] );
		$settings['email_field']       = is_string( $settings['email_field'] ) ? $settings['email_field'] : '';
		$settings['link_hours']        = max( 1, min( 720, (int) $settings['link_hours'] ) );
		$settings['delete_after_days'] = max( (int) ceil( $settings['link_hours'] / 24 ), min( 90, (int) $settings['delete_after_days'] ) );
		foreach ( array( 'subject', 'body', 'pending_message' ) as $text ) {
			$settings[ $text ] = is_string( $settings[ $text ] ) ? $settings[ $text ] : '';
		}
		return $settings;
	}

	/**
	 * Settings when a public submission of this form has to be confirmed, else null.
	 *
	 * @param array<string,mixed>            $form
	 * @param array<int,array<string,mixed>> $fields published fields of the submitted version
	 * @return array<string,mixed>|null
	 */
	public static function required_for( array $form, array $fields ): ?array {
		$settings = self::settings( $form );
		if ( ! $settings['enabled'] ) {
			return null;
		}
		foreach ( $fields as $field ) {
			if ( ( $field['id'] ?? '' ) === $settings['email_field'] && 'email' === ( $field['type'] ?? '' ) ) {
				return $settings;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $form */
	public static function pending_message( array $form ): string {
		$message = trim( wp_strip_all_tags( (string) self::settings( $form )['pending_message'] ) );
		return '' !== $message ? $message : __( 'Almost done: we have sent you an e-mail. Please confirm your submission with the link in this e-mail.', 'form-captain' );
	}

	/** Deadline after which an unconfirmed entry is deleted. */
	public static function unconfirmed_until( int $days, string $created_gmt ): string {
		return gmdate( 'Y-m-d H:i:s', (int) strtotime( $created_gmt . ' UTC' ) + max( 1, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Expiry of the confirmation link (UTC timestamp).
	 *
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $form
	 */
	public static function link_expires( array $entry, array $form ): int {
		return (int) strtotime( (string) $entry['created_gmt'] . ' UTC' ) + (int) self::settings( $form )['link_hours'] * HOUR_IN_SECONDS;
	}

	/**
	 * New single-use link for a pending entry; only the hash is stored.
	 *
	 * @return string|null URL, null when the entry is no longer pending
	 */
	public static function issue_link( EntryRepository $entries, int $entry_id ): ?string {
		$raw = Secret::base64url_encode( random_bytes( 24 ) );
		if ( ! $entries->set_confirmation_token( $entry_id, self::hash( $raw ) ) ) {
			return null;
		}
		return self::url( $entry_id . '.' . $raw );
	}

	public static function url( string $token ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				't'      => rawurlencode( $token ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private static function hash( string $raw ): string {
		return hash( 'sha256', 'form-captain/doi/v1|' . $raw );
	}

	/**
	 * Checks a link token.
	 *
	 * @return array{ok:bool,code:string,entry:array<string,mixed>|null,form:array<string,mixed>|null}
	 */
	public function check( string $token ): array {
		$fail = static fn( string $code, ?array $entry = null, ?array $form = null ): array => array(
			'ok'    => false,
			'code'  => $code,
			'entry' => $entry,
			'form'  => $form,
		);
		if ( ! preg_match( '/^(\d{1,20})\.([A-Za-z0-9_-]{32})$/', $token, $m ) ) {
			return $fail( 'invalid' );
		}
		$entry = $this->entries->find( (int) $m[1] );
		$form  = $entry ? $this->forms->find( (int) $entry['form_id'] ) : null;
		if ( ! $entry || ! $form ) {
			return $fail( 'invalid' );
		}
		// The token is checked first: without it nobody learns whether an entry ID exists or was
		// confirmed (SET-FC-P4-05).
		if ( ! $this->entries->confirmation_token_matches( (int) $entry['id'], self::hash( $m[2] ), false ) ) {
			return $fail( 'invalid' );
		}
		if ( self::STATUS_CONFIRMED === ( $entry['confirmation_status'] ?? '' ) ) {
			return $fail( 'already_confirmed', $entry, $form );
		}
		if ( self::STATUS_PENDING !== ( $entry['confirmation_status'] ?? '' ) ) {
			return $fail( 'invalid' );
		}
		if ( in_array( $entry['status'], array( 'spam', 'trash' ), true ) || ! empty( $entry['anonymized_gmt'] ) || ! empty( $entry['consent_withdrawn_gmt'] ) ) {
			return $fail( 'invalid' );
		}
		if ( Clock::timestamp() > self::link_expires( $entry, $form ) ) {
			return $fail( 'expired', $entry, $form );
		}
		return array(
			'ok'    => true,
			'code'  => 'ok',
			'entry' => $entry,
			'form'  => $form,
		);
	}

	/**
	 * Confirms the entry behind a valid token: single use, then outbox and public event.
	 *
	 * @return array{ok:bool,code:string,entry:array<string,mixed>|null,form:array<string,mixed>|null}
	 */
	public function confirm( string $token ): array {
		$checked = $this->check( $token );
		if ( ! $checked['ok'] || null === $checked['entry'] || null === $checked['form'] ) {
			return $checked;
		}
		$entry    = $checked['entry'];
		$form     = $checked['form'];
		$entry_id = (int) $entry['id'];
		$now      = Clock::now();
		$tx       = $this->entries->begin();
		try {
			if ( ! $this->entries->mark_confirmed( $entry_id, $now ) ) {
				if ( $tx ) {
					$this->entries->rollback();
				}
				$checked['ok']   = false;
				$checked['code'] = 'already_confirmed';
				return $checked;
			}
			$this->planner->plan( $form, $entry_id );
			if ( $tx ) {
				$this->entries->commit();
			}
		} catch ( \Throwable $e ) {
			if ( $tx ) {
				$this->entries->rollback();
			}
			Logger::log( 'error', 'double_opt_in_failed', get_class( $e ), array( 'entry_id' => $entry_id ) );
			$checked['ok']   = false;
			$checked['code'] = 'error';
			return $checked;
		}
		Logger::log( 'info', 'double_opt_in_confirmed', '', array( 'form_id' => (int) $form['id'], 'entry_id' => $entry_id ) );
		$version = $this->forms->find_version( (int) $entry['form_version_id'] );
		$fields  = $version ? $this->loader->normalize( (array) $version['schema'] ) : array();
		$this->submissions->dispatch_event( $form, $fields, $entry_id, $version ? (int) $version['version_no'] : 0, $this->entries->values_for( $entry_id ), is_array( $entry['context'] ) ? $entry['context'] : null, Logger::correlation_id(), (string) $entry['created_gmt'] );
		Scheduler::nudge();
		$checked['entry'] = $this->entries->find( $entry_id );
		return $checked;
	}

	/**
	 * Page for the link: GET shows a confirmation button, POST confirms (testable without exit).
	 *
	 * @return array{status:int,title:string,html:string,redirect:string}
	 */
	public function page( string $token, bool $post ): array {
		$result = $post ? $this->confirm( $token ) : $this->check( $token );
		$locale = (string) ( $result['entry']['locale'] ?? '' );
		$switch = '' !== $locale && function_exists( 'switch_to_locale' ) && $locale !== determine_locale() && switch_to_locale( $locale );
		try {
			return $this->page_for( $token, $post, $result );
		} finally {
			if ( $switch ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * @param array{ok:bool,code:string,entry:array<string,mixed>|null,form:array<string,mixed>|null} $result
	 * @return array{status:int,title:string,html:string,redirect:string}
	 */
	private function page_for( string $token, bool $post, array $result ): array {
		$title = __( 'Confirm your submission', 'form-captain' );
		$page  = static fn( int $status, string $html, string $redirect = '' ): array => array(
			'status'   => $status,
			'title'    => $title,
			'html'     => '<h1>' . esc_html( $title ) . '</h1>' . $html,
			'redirect' => $redirect,
		);
		if ( 'already_confirmed' === $result['code'] ) {
			return $page( 200, '<p role="status">' . esc_html__( 'Your submission has already been confirmed. Thank you!', 'form-captain' ) . '</p>' );
		}
		if ( 'expired' === $result['code'] ) {
			return $page( 410, '<p role="alert">' . esc_html__( 'This confirmation link has expired. Please submit the form again.', 'form-captain' ) . '</p>' );
		}
		if ( ! $result['ok'] ) {
			return $page( 'error' === $result['code'] ? 500 : 404, '<p role="alert">' . esc_html__( 'This confirmation link is invalid or was already used.', 'form-captain' ) . '</p>' );
		}
		if ( ! $post ) {
			$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$form .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="t" value="' . esc_attr( $token ) . '">';
			$form .= '<p>' . esc_html__( 'Please confirm that you sent this submission.', 'form-captain' ) . '</p>';
			$form .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Confirm submission', 'form-captain' ) . '</button></p></form>';
			return $page( 200, $form );
		}
		$entry_id = (int) ( $result['entry']['id'] ?? 0 );
		$conf     = SubmissionService::confirmation( (array) $result['form'], $entry_id, $this->entries->values_for( $entry_id ) );
		return $page( 200, '<p role="status">' . esc_html( $conf['message'] ) . '</p>', (string) ( $conf['redirect_url'] ?? '' ) );
	}

	/** admin-post handler for GET (button page) and POST (confirmation). */
	public function handle(): void {
		$post   = 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		$source = $post ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- The single-use link token is the credential; logged-out visitors have no nonce.
		$token  = isset( $source['t'] ) && is_string( $source['t'] ) ? sanitize_text_field( wp_unslash( $source['t'] ) ) : '';
		$page   = $this->page( rawurldecode( $token ), $post );
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( '' !== $page['redirect'] ) {
			wp_safe_redirect( $page['redirect'], 303 );
			exit;
		}
		wp_die( $page['html'], esc_html( $page['title'] ), array( 'response' => $page['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in page_for().
	}
}
