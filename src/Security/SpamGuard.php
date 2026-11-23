<?php
/**
 * Privacy-friendly spam checks: honeypot, minimum fill time, blocklist (P3-08, P5-19, T-SUB-09/10).
 *
 * No personal data is logged. Extensions (e.g. opt-in captcha providers) hook into
 * `form_captain_spam_checks_v1` and may return a spam reason.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Security;

use FormCaptain\Settings\Settings;
use FormCaptain\Support\Secret;

final class SpamGuard {

	public const REASON_HONEYPOT  = 'honeypot';
	public const REASON_TOO_FAST  = 'too_fast';
	public const REASON_BLOCKLIST = 'blocklist';

	/** Per-form random-looking honeypot field name (stable, not guessable across sites). */
	public static function honeypot_name( string $public_id ): string {
		return 'fc_website_' . substr( Secret::sign( 'form-captain/honeypot/v1', $public_id ), 0, 8 );
	}

	/**
	 * Runs all checks. Returns the spam reason or null.
	 *
	 * @param array<string,mixed>                     $form   form row
	 * @param array<string,mixed>                     $raw    raw request (top-level keys)
	 * @param array<string,string|array<int,string>> $values validated values field_id => value
	 * @param array<int,array<string,mixed>>          $fields published fields
	 * @param array<string,mixed>                     $meta   trusted, issued_at
	 */
	public function check( array $form, array $raw, array $values, array $fields, array $meta ): ?string {
		$trusted = ! empty( $meta['trusted'] );
		$reason  = null;
		if ( ! $trusted ) {
			$reason = $this->check_honeypot( (string) ( $form['public_id'] ?? '' ), $raw )
				?? $this->check_min_time( isset( $meta['issued_at'] ) ? (int) $meta['issued_at'] : null, (int) ( $meta['now'] ?? time() ) )
				?? PowCaptcha::check( $form, $raw, ! empty( $meta['js'] ) );
		}
		$reason = $reason ?? $this->check_blocklist( $values, $fields );
		/**
		 * Additional spam checks. Return a reason string (≤ 40 chars, [a-z0-9_]) or null.
		 *
		 * @param string|null $reason current result
		 * @param array       $context form_id, public_id, trusted (no field values beyond what extensions need)
		 * @param array       $values  validated values
		 */
		$filtered = apply_filters(
			'form_captain_spam_checks_v1',
			$reason,
			array(
				'form_id'   => (int) ( $form['id'] ?? 0 ),
				'public_id' => (string) ( $form['public_id'] ?? '' ),
				'trusted'   => $trusted,
				'raw'       => $raw,
				// SET-FC-05/17: provider that applies to this form ("none" when the form switched it off).
				'captcha'   => PowCaptcha::provider_for( $form ),
				'spam_mode' => PowCaptcha::spam_mode( $form ),
			),
			$values
		);
		if ( is_string( $filtered ) && preg_match( '/^[a-z0-9_]{1,40}$/', $filtered ) ) {
			return $filtered;
		}
		return $reason;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	public function check_honeypot( string $public_id, array $raw ): ?string {
		if ( ! Settings::get( 'spam.honeypot' ) ) {
			return null;
		}
		$value = $raw[ self::honeypot_name( $public_id ) ] ?? '';
		if ( is_array( $value ) || '' !== trim( (string) $value ) ) {
			return self::REASON_HONEYPOT;
		}
		return null;
	}

	public function check_min_time( ?int $issued_at, int $now ): ?string {
		$min = SpamLimits::get( 'min_seconds' );
		if ( $min <= 0 || null === $issued_at ) {
			return null;
		}
		return ( $now - $issued_at ) < $min ? self::REASON_TOO_FAST : null;
	}

	/**
	 * Lines are terms; a line starting with "@" or looking like a domain also matches e-mail domains.
	 *
	 * @param array<string,string|array<int,string>> $values
	 * @param array<int,array<string,mixed>>          $fields
	 */
	public function check_blocklist( array $values, array $fields ): ?string {
		$terms = self::blocklist_terms( (string) Settings::get( 'spam.blocklist' ) );
		if ( ! $terms ) {
			return null;
		}
		$types = array();
		foreach ( $fields as $f ) {
			$types[ (string) ( $f['id'] ?? '' ) ] = (string) ( $f['type'] ?? '' );
		}
		foreach ( $values as $field_id => $value ) {
			foreach ( (array) $value as $v ) {
				$v = mb_strtolower( (string) $v );
				if ( '' === $v ) {
					continue;
				}
				$domain = 'email' === ( $types[ $field_id ] ?? '' ) && str_contains( $v, '@' ) ? substr( $v, strrpos( $v, '@' ) + 1 ) : null;
				foreach ( $terms as $term ) {
					if ( null !== $domain && str_starts_with( $term, '@' ) ) {
						$d = substr( $term, 1 );
						if ( $domain === $d || str_ends_with( $domain, '.' . $d ) ) {
							return self::REASON_BLOCKLIST;
						}
						continue;
					}
					if ( str_starts_with( $term, '@' ) ) {
						continue;
					}
					if ( null !== $domain && ( $domain === $term || str_ends_with( $domain, '.' . $term ) ) ) {
						return self::REASON_BLOCKLIST;
					}
					if ( false !== mb_strpos( $v, $term ) ) {
						return self::REASON_BLOCKLIST;
					}
				}
			}
		}
		return null;
	}

	/** @return string[] */
	public static function blocklist_terms( string $blocklist ): array {
		$out = array();
		foreach ( preg_split( '/\R/u', $blocklist ) ?: array() as $line ) {
			$line = mb_strtolower( trim( $line ) );
			if ( '' !== $line && mb_strlen( $line ) <= 190 ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
