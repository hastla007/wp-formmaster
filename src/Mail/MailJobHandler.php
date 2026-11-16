<?php
/**
 * Queue handlers for `mail.admin` and `mail.confirmation` (P4-07, P4-08, P4-18).
 *
 * Entry and values are loaded fresh right before delivery; deleted, trashed, spam or
 * anonymized entries are never mailed. The core always delivers its own mails via
 * wp_mail(); there is no hand-over of mails to a workflow (owner decision F-12 / D10).
 * Duplicate deliveries are prevented by the job's idempotency key and row claim.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Mail;

use FormCaptain\Jobs\JobResult;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Support\Logger;
use FormCaptain\Support\Secret;

final class MailJobHandler {

	public const RECEIPTS_PER_HOUR = 3;

	private bool $registered = false;

	public function __construct(
		private EntryRepository $entries,
		private FormRepository $forms,
		private MailBuilder $builder
	) {}

	public function register( Plugin $plugin ): void {
		unset( $plugin );
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_filter( 'form_captain_job_handlers', array( $this, 'add_handlers' ) );
	}

	/**
	 * @param array<string,callable> $handlers
	 * @return array<string,callable>
	 */
	public function add_handlers( array $handlers ): array {
		$handlers['mail.admin']        = array( $this, 'handle_admin' );
		$handlers['mail.confirmation'] = array( $this, 'handle_confirmation' );
		return $handlers;
	}

	/** @param array<string,mixed> $job */
	public function handle_admin( array $job ): JobResult {
		return $this->handle( $job, 'admin' );
	}

	/** @param array<string,mixed> $job */
	public function handle_confirmation( array $job ): JobResult {
		return $this->handle( $job, 'confirmation' );
	}

	/** @param array<string,mixed> $job */
	private function handle( array $job, string $kind ): JobResult {
		$entry_id = (int) ( $job['entry_id'] ?? 0 );
		$entry    = $entry_id ? $this->entries->find( $entry_id ) : null;
		if ( ! $entry ) {
			return JobResult::fail( 'entry_deleted', 'Entry no longer exists' );
		}
		if ( 'spam' === $entry['status'] ) {
			return JobResult::fail( 'entry_spam', 'Entry is marked as spam' );
		}
		if ( 'trash' === $entry['status'] ) {
			return JobResult::fail( 'entry_trashed', 'Entry is in the trash' );
		}
		if ( ! empty( $entry['anonymized_gmt'] ) ) {
			return JobResult::fail( 'entry_anonymized', 'Entry was anonymized' );
		}
		if ( ! empty( $entry['consent_withdrawn_gmt'] ) ) {
			return JobResult::fail( 'consent_withdrawn', 'Consent was withdrawn' );
		}
		$form = $this->forms->find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return JobResult::fail( 'form_not_found', 'Form no longer exists' );
		}
		$section = 'admin' === $kind ? 'notification' : 'confirmation_mail';
		if ( empty( $form['settings'][ $section ]['enabled'] ) && empty( $job['payload']['manual'] ) ) {
			return JobResult::success( array( 'skipped' => 'disabled' ) );
		}
		$values = $this->entries->values_for( $entry_id );
		$mail   = 'admin' === $kind ? $this->builder->admin( $form, $entry, $values ) : $this->builder->confirmation( $form, $entry, $values );
		if ( is_wp_error( $mail ) ) {
			return JobResult::fail( $mail->get_error_code(), $mail->get_error_message() );
		}
		$refs = array(
			'entry_id' => $entry_id,
			'form_id'  => (int) $entry['form_id'],
			'job_id'   => (int) ( $job['id'] ?? 0 ),
		);
		if ( $mail['rejected'] ) {
			Logger::log( 'warning', 'mail_header_rejected', 'headers: ' . implode( ',', $mail['rejected'] ), $refs );
		}

		if ( 'confirmation' === $kind && ! $this->receipt_allowed( (string) $mail['to'][0] ) ) {
			return JobResult::fail( 'receipt_rate_limited', 'Too many confirmations to this recipient' );
		}

		$error = '';
		$sent  = $this->send( $mail, $error );
		if ( ! $sent ) {
			return JobResult::retry( 'mail_failed', '' !== $error ? Logger::redact( $error ) : 'wp_mail returned false' );
		}
		if ( 'confirmation' === $kind ) {
			$this->count_receipt( (string) $mail['to'][0] );
		}
		return JobResult::success( array( 'recipients' => count( $mail['to'] ) ) );
	}

	/**
	 * Sends via wp_mail(); HTML mails get a plain-text alternative (multipart/alternative).
	 *
	 * @param array<string,mixed> $mail
	 * @param-out string $error
	 */
	public function send( array $mail, string &$error = '' ): bool {
		$headers = array();
		$is_html = 'html' === $mail['format'];
		if ( $is_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		if ( ! empty( $mail['reply_to'] ) && ! MailBuilder::header_unsafe( (string) $mail['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $mail['reply_to'];
		}
		$from_email = static fn(): string => (string) $mail['from_email'];
		$from_name  = static fn(): string => (string) $mail['from_name'];
		$alt        = static function ( $phpmailer ) use ( $mail, $is_html ): void {
			if ( $is_html && is_object( $phpmailer ) ) {
				$phpmailer->AltBody = (string) $mail['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		};
		$failed     = static function ( $wp_error ) use ( &$error ): void {
			if ( $wp_error instanceof \WP_Error ) {
				$error = (string) $wp_error->get_error_message();
			}
		};
		add_filter( 'wp_mail_from', $from_email, 999 );
		add_filter( 'wp_mail_from_name', $from_name, 999 );
		add_action( 'phpmailer_init', $alt, 999 );
		add_action( 'wp_mail_failed', $failed );
		try {
			$ok = wp_mail( $mail['to'], (string) $mail['subject'], $is_html ? (string) $mail['html'] : (string) $mail['text'], $headers );
		} finally {
			remove_filter( 'wp_mail_from', $from_email, 999 );
			remove_filter( 'wp_mail_from_name', $from_name, 999 );
			remove_action( 'phpmailer_init', $alt, 999 );
			remove_action( 'wp_mail_failed', $failed );
		}
		return (bool) $ok;
	}

	private function receipt_key( string $email ): string {
		return 'fc_rcpt_' . substr( Secret::sign( 'receipt', strtolower( $email ) ), 0, 32 );
	}

	private function receipt_allowed( string $email ): bool {
		return (int) get_transient( $this->receipt_key( $email ) ) < self::RECEIPTS_PER_HOUR;
	}

	private function count_receipt( string $email ): void {
		$key = $this->receipt_key( $email );
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}
}
