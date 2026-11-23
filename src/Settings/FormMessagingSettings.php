<?php
/**
 * Per-form values for e-mail format, spam protection and double opt-in
 * (SET-FC-10, SET-FC-17, SET-FC-19).
 *
 * Kept apart from FormService so the form settings list (SET-FC-28) can take the
 * definitions over unchanged: every value has a default, a check and an error code.
 *
 * Form settings handled here:
 * - email.format              global|html|text (global = Settings email.format)
 * - spam.mode                 global|strict|off
 * - double_opt_in.enabled     bool, default false
 * - double_opt_in.email_field ID of a required email field
 * - double_opt_in.subject     ≤ 190, empty = translated default
 * - double_opt_in.body        ≤ 5000, plain text or allowlisted HTML, empty = translated default
 * - double_opt_in.pending_message ≤ 1000, empty = translated default
 * - double_opt_in.link_hours  1–720, default 48
 * - double_opt_in.delete_after_days 1–90, default 7, at least as long as the link is valid
 * - confirmation_mail.body    ≤ 5000, plain text or allowlisted HTML
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Domain\HtmlSanitizer;

final class FormMessagingSettings {

	public const EMAIL_FORMATS = array( 'global', 'html', 'text' );
	public const SPAM_MODES    = array( 'global', 'strict', 'off' );
	public const BODY_MAX      = 5000;

	/** @return array<string,mixed> */
	public static function double_opt_in_defaults(): array {
		return array(
			'enabled'           => false,
			'email_field'       => '',
			'subject'           => '',
			'body'              => '',
			'pending_message'   => '',
			'link_hours'        => 48,
			'delete_after_days' => 7,
		);
	}

	public static function sanitize_email_format( mixed $value ): string {
		return in_array( $value, self::EMAIL_FORMATS, true ) ? (string) $value : 'global';
	}

	/**
	 * @param array<string,string> $errors
	 * @return array{mode:string}
	 */
	public static function sanitize_spam( mixed $in, array &$errors ): array {
		$mode = is_array( $in ) ? ( $in['mode'] ?? 'global' ) : 'global';
		if ( ! in_array( $mode, self::SPAM_MODES, true ) ) {
			$errors['spam.mode'] = 'invalid_spam_mode';
			$mode                = 'global';
		}
		return array( 'mode' => (string) $mode );
	}

	/**
	 * E-mail body: plain text stays plain text (as before); a body with tags keeps the HTML
	 * allowlist of html fields (HtmlSanitizer), everything else is removed.
	 */
	public static function sanitize_body( mixed $raw ): string {
		$raw = is_scalar( $raw ) ? (string) $raw : '';
		if ( self::is_html( $raw ) ) {
			return mb_substr( trim( HtmlSanitizer::clean( $raw ) ), 0, self::BODY_MAX );
		}
		return mb_substr( sanitize_textarea_field( $raw ), 0, self::BODY_MAX );
	}

	/** True when a body template contains markup (then it is treated as HTML). */
	public static function is_html( string $text ): bool {
		return 1 === preg_match( '#<(/?[a-z][a-z0-9]*)(\s[^>]*)?/?>#i', $text );
	}

	/**
	 * @param array<string,array<string,mixed>> $fields field_id => field of the schema
	 * @param array<string,string>              $errors path => error code
	 * @return array<string,mixed>
	 */
	public static function sanitize_double_opt_in( mixed $in, array $fields, array &$errors ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = self::double_opt_in_defaults();

		$out['enabled'] = ! empty( $in['enabled'] );
		$field          = is_string( $in['email_field'] ?? null ) ? $in['email_field'] : '';
		if ( '' !== $field && ( ! isset( $fields[ $field ] ) || 'email' !== ( $fields[ $field ]['type'] ?? '' ) ) ) {
			$errors['double_opt_in.email_field'] = 'invalid_doi_email_field';
			$field                               = '';
		}
		if ( $out['enabled'] && '' === $field && ! isset( $errors['double_opt_in.email_field'] ) ) {
			$errors['double_opt_in.email_field'] = 'doi_email_field_required';
		} elseif ( $out['enabled'] && '' !== $field && empty( $fields[ $field ]['required'] ) ) {
			// Without an address the submission could never be confirmed.
			$errors['double_opt_in.email_field'] = 'doi_email_field_not_required';
		}
		$out['email_field'] = $field;

		$subject = is_scalar( $in['subject'] ?? '' ) ? sanitize_text_field( (string) ( $in['subject'] ?? '' ) ) : '';
		if ( mb_strlen( $subject ) > 190 ) {
			$errors['double_opt_in.subject'] = 'text_too_long';
			$subject                         = mb_substr( $subject, 0, 190 );
		}
		$out['subject'] = $subject;
		$out['body']    = self::sanitize_body( $in['body'] ?? '' );
		$pending        = is_scalar( $in['pending_message'] ?? '' ) ? sanitize_textarea_field( (string) ( $in['pending_message'] ?? '' ) ) : '';
		if ( mb_strlen( $pending ) > 1000 ) {
			$errors['double_opt_in.pending_message'] = 'text_too_long';
			$pending                                 = mb_substr( $pending, 0, 1000 );
		}
		$out['pending_message'] = $pending;

		$hours = self::int_in_range( $in['link_hours'] ?? 48, 1, 720 );
		if ( null === $hours ) {
			$errors['double_opt_in.link_hours'] = 'doi_link_hours_range';
			$hours                              = 48;
		}
		$days = self::int_in_range( $in['delete_after_days'] ?? 7, 1, 90 );
		if ( null === $days ) {
			$errors['double_opt_in.delete_after_days'] = 'doi_delete_days_range';
			$days                                      = max( 7, (int) ceil( $hours / 24 ) );
		} elseif ( $days * 24 < $hours ) {
			$errors['double_opt_in.delete_after_days'] = 'doi_delete_before_expiry';
			$days                                      = (int) ceil( $hours / 24 );
		}
		$out['link_hours']        = $hours;
		$out['delete_after_days'] = $days;
		return $out;
	}

	private static function int_in_range( mixed $value, int $min, int $max ): ?int {
		if ( is_int( $value ) ) {
			$int = $value;
		} elseif ( is_string( $value ) && preg_match( '/^\s*\d+\s*$/', $value ) ) {
			$int = (int) $value;
		} else {
			return null;
		}
		return $int >= $min && $int <= $max ? $int : null;
	}

	/**
	 * Editor messages for the error codes above.
	 *
	 * @return array<string,string>
	 */
	public static function error_messages(): array {
		return array(
			'invalid_spam_mode'              => __( 'Please choose one of the offered spam protection options.', 'form-captain' ),
			'invalid_doi_email_field'        => __( 'Double opt-in needs an email field of this form.', 'form-captain' ),
			'doi_email_field_required'       => __( 'Choose the email field that receives the confirmation link.', 'form-captain' ),
			'doi_email_field_not_required'   => __( 'The email field for double opt-in must be required.', 'form-captain' ),
			'text_too_long'                  => __( 'This text is too long.', 'form-captain' ),
			'doi_link_hours_range'           => __( 'Enter a link validity between 1 and 720 hours.', 'form-captain' ),
			'doi_link_longer_than_retention' => __( 'The confirmation link may not stay valid longer than submissions of this form are kept (retention period), otherwise the submission could be deleted before it is confirmed. Shorten the link validity or keep submissions longer.', 'form-captain' ),
			'doi_delete_days_range'          => __( 'Enter a number of days between 1 and 90.', 'form-captain' ),
			'doi_delete_before_expiry'       => __( 'Unconfirmed submissions may only be deleted after the link has expired.', 'form-captain' ),
		);
	}
}
