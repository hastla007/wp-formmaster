<?php
/**
 * "Send test to me" in the form editor (SET-FC-19): the real form mail with sample values,
 * always to the current user's own address, never to the configured recipients.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Mail;

use FormCaptain\Service\DoubleOptIn;
use FormCaptain\Service\FormService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;

final class TestMail {

	public const KINDS       = array( 'admin', 'confirmation', 'double_opt_in' );
	public const PER_WINDOW  = 3;
	private const WINDOW_SEC = 10 * MINUTE_IN_SECONDS;

	public function __construct(
		private MailBuilder $builder,
		private MailJobHandler $handler,
		private FormService $service
	) {}

	/**
	 * @param array<string,mixed>      $form     form row (working copy schema)
	 * @param array<string,mixed>|null $settings unsaved editor settings, checked like a save
	 * @return array{to:string}|\WP_Error
	 */
	public function send( array $form, string $kind, ?array $settings, \WP_User $user ): array|\WP_Error {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'invalid_kind', __( 'Unknown email type.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$to = (string) $user->user_email;
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'no_user_email', __( 'Your user account has no valid email address.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$key  = 'form_captain_formtest_' . (int) $user->ID;
		$hits = (int) get_transient( $key );
		if ( $hits >= self::PER_WINDOW ) {
			return new \WP_Error( 'rate_limited', __( 'Too many test emails. Please wait ten minutes.', 'form-captain' ), array( 'status' => 429 ) );
		}
		$schema = (array) ( $form['schema'] ?? array() );
		if ( null !== $settings ) {
			$form['settings'] = $this->service->sanitize_settings( $settings, $schema )['settings'];
		}
		$fields = MailBuilder::fields( $schema );
		$values = self::sample_values( $fields, $to );
		$entry  = array(
			'id'              => 0,
			'form_id'         => (int) $form['id'],
			'form_version_id' => 0,
			'created_gmt'     => Clock::now(),
		);
		if ( 'confirmation' === $kind ) {
			$mail = $this->builder->confirmation( $form, $entry, $values );
		} elseif ( 'double_opt_in' === $kind ) {
			$doi = DoubleOptIn::settings( $form );
			if ( '' === $doi['email_field'] ) {
				return new \WP_Error( 'doi_email_field_required', __( 'Choose the email field that receives the confirmation link.', 'form-captain' ), array( 'status' => 400 ) );
			}
			$values[ $doi['email_field'] ] = $to;
			$mail                          = $this->builder->double_opt_in( $form, $entry, $values, DoubleOptIn::url( '0.' . str_repeat( 'x', 32 ) ) );
		} else {
			$mail = $this->builder->admin( $form, $entry, $values );
		}
		if ( is_wp_error( $mail ) ) {
			$message = 'invalid_recipient' === $mail->get_error_code()
				? __( 'Mark an email field as recipient of the confirmation email.', 'form-captain' )
				: __( 'The test email could not be built. Please check the settings.', 'form-captain' );
			return new \WP_Error( $mail->get_error_code(), $message, array( 'status' => 400 ) );
		}
		set_transient( $key, $hits + 1, self::WINDOW_SEC );
		$mail['to'] = array( $to );
		/* translators: %s: subject of the email */
		$mail['subject'] = sprintf( __( '[Test] %s', 'form-captain' ), (string) $mail['subject'] );
		$error           = '';
		if ( ! $this->handler->send( $mail, $error ) ) {
			Logger::log( 'warning', 'mail_form_test_failed', Logger::redact( $error ), array( 'form_id' => (int) $form['id'] ) );
			return new \WP_Error( 'mail_failed', __( 'The test email could not be sent. WordPress reported an error; an SMTP plugin usually solves delivery problems.', 'form-captain' ), array( 'status' => 502 ) );
		}
		Logger::log( 'info', 'mail_form_test_sent', $kind, array( 'form_id' => (int) $form['id'] ) );
		return array( 'to' => $to );
	}

	/**
	 * Sample values per field type; e-mail fields get the tester's address.
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<string,string|array<int,string>>
	 */
	public static function sample_values( array $fields, string $email ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$type    = (string) ( $field['type'] ?? '' );
			$options = array_values( array_filter( (array) ( $field['options'] ?? array() ), 'is_array' ) );
			$first   = (string) ( $options[0]['value'] ?? '' );
			$value   = match ( $type ) {
				'html' => null,
				'email' => $email,
				'tel' => '+49 30 1234567',
				'number' => '42',
				'date' => wp_date( 'Y-m-d' ),
				'consent' => '1',
				'checkbox' => '' !== $first ? array( $first ) : array(),
				'select', 'radio' => $first,
				'textarea' => __( 'Sample text', 'form-captain' ) . "\n" . __( 'Second line', 'form-captain' ),
				'hidden' => (string) ( $field['default'] ?? '' ),
				default => __( 'Sample text', 'form-captain' ),
			};
			if ( null !== $value ) {
				$out[ (string) $field['id'] ] = $value;
			}
		}
		return $out;
	}
}
