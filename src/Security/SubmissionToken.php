<?php
/**
 * Cache-safe submission token (docs/adr/0003-rest-und-submission.md, T-SUB-01).
 *
 * Token = B64U( public_id "|" issued_at "|" B64U(random16) ) "." B64U( HMAC-SHA256 )
 * The key is derived from the plugin secret with the label `form-captain/submit-token/v1`,
 * so a token can never be valid as a signed suite context and vice versa.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Security;

use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Secret;

final class SubmissionToken {

	public const LABEL = 'form-captain/submit-token/v1';

	/** Allowed clock skew for issued_at in the future (seconds). */
	private const SKEW = 60;

	/**
	 * Issues a fresh token bound to a form's public ID.
	 *
	 * @return array{token:string,issued_at:int,expires_at:int}
	 */
	public function issue( string $public_id ): array {
		$issued  = Clock::timestamp();
		$random  = Secret::base64url_encode( random_bytes( 16 ) );
		$payload = Secret::base64url_encode( $public_id . '|' . $issued . '|' . $random );
		return array(
			'token'      => $payload . '.' . $this->signature( $payload ),
			'issued_at'  => $issued,
			'expires_at' => $issued + Limits::get( 'token_ttl' ),
		);
	}

	/**
	 * Verifies a token for the given form.
	 *
	 * @return array{ok:bool,code:string,issued_at?:int,random?:string}
	 *         code: ok | missing | malformed | signature | form_mismatch | expired | future
	 */
	public function verify( mixed $token, string $public_id ): array {
		if ( ! is_string( $token ) || '' === $token ) {
			return array( 'ok' => false, 'code' => 'missing' );
		}
		if ( strlen( $token ) > 200 || ! preg_match( '/^([A-Za-z0-9_-]{20,150})\.([A-Za-z0-9_-]{43})$/', $token, $m ) ) {
			return array( 'ok' => false, 'code' => 'malformed' );
		}
		$expected = $this->signature( $m[1] );
		if ( ! hash_equals( $expected, $m[2] ) ) {
			return array( 'ok' => false, 'code' => 'signature' );
		}
		$decoded = Secret::base64url_decode( $m[1] );
		$parts   = is_string( $decoded ) ? explode( '|', $decoded ) : array();
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[1] ) || ! preg_match( '/^[A-Za-z0-9_-]{22}$/', $parts[2] ) ) {
			return array( 'ok' => false, 'code' => 'malformed' );
		}
		if ( ! hash_equals( $public_id, $parts[0] ) ) {
			return array( 'ok' => false, 'code' => 'form_mismatch' );
		}
		$issued = (int) $parts[1];
		$now    = Clock::timestamp();
		if ( $issued > $now + self::SKEW ) {
			return array( 'ok' => false, 'code' => 'future' );
		}
		if ( $now - $issued > Limits::get( 'token_ttl' ) ) {
			return array( 'ok' => false, 'code' => 'expired' );
		}
		return array(
			'ok'        => true,
			'code'      => 'ok',
			'issued_at' => $issued,
			'random'    => $parts[2],
		);
	}

	/** Idempotency reference: sha256( form_id | token random ). */
	public static function request_key( int $form_id, string $random ): string {
		return hash( 'sha256', $form_id . '|' . $random );
	}

	private function signature( string $payload ): string {
		return Secret::base64url_encode( hash_hmac( 'sha256', $payload, Secret::key( self::LABEL ), true ) );
	}
}
