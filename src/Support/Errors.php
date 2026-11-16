<?php
/**
 * Stable public error codes and translated visitor/admin messages (P1-09, P3-12).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

final class Errors {

	public const FORBIDDEN               = 'forbidden';
	public const INVALID_NONCE           = 'invalid_nonce';
	public const INVALID_TOKEN           = 'invalid_token';
	public const FORM_NOT_FOUND          = 'form_not_found';
	public const FORM_NOT_ACCEPTING      = 'form_not_accepting';
	public const VALIDATION_FAILED       = 'validation_failed';
	public const RATE_LIMITED            = 'rate_limited';
	public const SPAM_SUSPECTED          = 'spam_suspected';
	public const PAYLOAD_TOO_LARGE       = 'payload_too_large';
	public const TOO_MANY_FIELDS         = 'too_many_fields';
	public const STORAGE_FAILED          = 'storage_failed';
	public const SCHEMA_INVALID          = 'schema_invalid';
	public const SCHEMA_UNSUPPORTED      = 'schema_unsupported';
	public const REVISION_CONFLICT       = 'revision_conflict';
	public const INCOMPATIBLE_CONTRACT   = 'incompatible_contract';
	public const CONNECTOR_DISABLED      = 'connector_disabled';
	public const CONNECTOR_NOT_FOUND     = 'connector_not_found';
	public const INVALID_CONTEXT         = 'invalid_context';
	public const CONTEXT_EXPIRED         = 'context_expired';
	public const CONTEXT_REPLAYED        = 'context_replayed';
	public const JOB_NOT_FOUND           = 'job_not_found';
	public const ENTRY_NOT_FOUND         = 'entry_not_found';
	public const NOT_READY               = 'not_ready';
	public const CONTEXT_PURPOSE_UNKNOWN = 'context_purpose_unknown';
	public const INVALID_SCOPE           = 'invalid_scope';
	public const INVALID_REQUEST         = 'invalid_request';
	public const INTERNAL_ERROR          = 'internal_error';

	/** HTTP status for each code. */
	public static function status( string $code ): int {
		return match ( $code ) {
			self::FORBIDDEN, self::INVALID_NONCE => 403,
			self::INVALID_TOKEN, self::VALIDATION_FAILED, self::INVALID_SCOPE, self::INVALID_REQUEST, self::CONTEXT_PURPOSE_UNKNOWN, self::SCHEMA_INVALID, self::SCHEMA_UNSUPPORTED, self::INVALID_CONTEXT, self::CONTEXT_EXPIRED, self::CONTEXT_REPLAYED, self::TOO_MANY_FIELDS => 400,
			self::FORM_NOT_FOUND, self::JOB_NOT_FOUND, self::ENTRY_NOT_FOUND, self::CONNECTOR_NOT_FOUND => 404,
			self::FORM_NOT_ACCEPTING, self::REVISION_CONFLICT, self::CONNECTOR_DISABLED, self::INCOMPATIBLE_CONTRACT => 409,
			self::PAYLOAD_TOO_LARGE => 413,
			self::RATE_LIMITED => 429,
			self::STORAGE_FAILED, self::NOT_READY => 503,
			default => 500,
		};
	}

	/** Neutral, translated message safe to show to public visitors. */
	public static function public_message( string $code ): string {
		return match ( $code ) {
			self::VALIDATION_FAILED => __( 'Please check the highlighted fields.', 'form-captain' ),
			self::RATE_LIMITED => __( 'Too many submissions in a short time. Please try again in a few minutes.', 'form-captain' ),
			self::INVALID_TOKEN => __( 'This form has expired. Please reload the page and submit again – your input is kept.', 'form-captain' ),
			self::FORM_NOT_ACCEPTING => __( 'This form is currently not accepting submissions.', 'form-captain' ),
			self::FORM_NOT_FOUND => __( 'This form is not available.', 'form-captain' ),
			self::PAYLOAD_TOO_LARGE, self::TOO_MANY_FIELDS => __( 'Your submission is too large. Please shorten your input.', 'form-captain' ),
			self::SPAM_SUSPECTED => __( 'Thank you! Your submission has been received.', 'form-captain' ),
			self::STORAGE_FAILED => __( 'Your submission could not be saved right now. Please try again shortly.', 'form-captain' ),
			default => __( 'Something went wrong. Please try again later.', 'form-captain' ),
		};
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function error( string $code, string $message = '', array $data = array() ): \WP_Error {
		return new \WP_Error( $code, '' !== $message ? $message : self::public_message( $code ), array_merge( array( 'status' => self::status( $code ) ), $data ) );
	}
}
