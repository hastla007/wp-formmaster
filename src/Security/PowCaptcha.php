<?php
/**
 * Built-in captcha provider `pow` (SET-FC-05 a): WordPress side of Spam\ProofOfWork.
 *
 * - Key: derived from the site secret (Support\Secret), never output.
 * - Single use: a row in fc_rate_limits per solved challenge (INSERT IGNORE, like the context nonce),
 *   removed by the normal rate-limit purge after expiry.
 * - Challenges are only handed out by the token route, never in cached HTML.
 * - Without JavaScript (admin-post fallback) a missing solution is accepted with the existing checks
 *   (honeypot, minimum time, blocklist, rate limits), unless the form's spam mode is "strict". Such a
 *   submission is never dropped silently, but it counts against a much stricter sending limit
 *   (RateLimiter::check_without_pow()) and the entry is marked NOTE_WITHOUT_POW (SET-FC-P4-08,
 *   decision 22).
 *
 * Effective provider of a form: spam.mode "off" → none; "strict" → global provider, or `pow` when
 * none is chosen globally; "global" → global provider (spam.captcha_provider).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Security;

use FormCaptain\Database\Tables;
use FormCaptain\Settings\Settings;
use FormCaptain\Spam\ProofOfWork\ProofOfWork;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Secret;

final class PowCaptcha {

	public const PROVIDER = 'pow';

	public const REASON_MISSING  = 'pow_missing';
	public const REASON_INVALID  = 'pow_invalid';
	public const REASON_REPLAYED = 'pow_replayed';

	/**
	 * Value of fc_entries.spam_reason for a NON-spam entry that was sent without JavaScript and
	 * therefore without a proof-of-work solution (mode "global", SET-FC-P4-08). The entry keeps its
	 * normal status; list, detail and CSV show "spam check without proof of work".
	 */
	public const NOTE_WITHOUT_POW = 'pow_skipped';

	private const KEY_PURPOSE = 'form-captain/pow/v1';

	/**
	 * @param string|null $key     HMAC key; default the current one (previous key: rotation window).
	 * @param bool        $consume false: checks a solution without marking it as used (pre-check, SET-FC-P4-09).
	 */
	public static function engine( ?string $key = null, bool $consume = true ): ProofOfWork {
		/**
		 * Difficulty (technical value): highest number the browser may have to try. Default 50 000.
		 *
		 * @param int $max_number
		 */
		$max = (int) apply_filters( 'form_captain_pow_max_number_v1', ProofOfWork::DEFAULT_MAX_NUMBER );
		// The challenge comes with the submission token and is solved once per token; frontend.js keeps
		// a token for up to 115 minutes. A shorter validity would let a legitimate but slow visitor's
		// submission land in spam silently (SET-FC-P4-01), so it lives at least as long as the token.
		$ttl = max( ProofOfWork::DEFAULT_TTL, Limits::get( 'token_ttl' ) );
		return new ProofOfWork(
			$key ?? Secret::key( self::KEY_PURPOSE ),
			static function ( string $challenge, int $expires ) use ( $consume ): bool {
				if ( ! $consume ) {
					return true;
				}
				global $wpdb;
				$table = Tables::name( Tables::RATE_LIMITS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$affected = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (bucket, form_id, hits, window_start_gmt) VALUES (%s, 0, 1, %s)", hash( 'sha256', 'form-captain/pow-used|' . $challenge ), Clock::at( $expires + 60 ) ) );
				return 1 === (int) $affected;
			},
			$max,
			$ttl,
			static fn(): int => Clock::timestamp()
		);
	}

	/** @param array<string,mixed> $form */
	public static function spam_mode( array $form ): string {
		$mode = (string) ( $form['settings']['spam']['mode'] ?? 'global' );
		return in_array( $mode, array( 'global', 'strict', 'off' ), true ) ? $mode : 'global';
	}

	/**
	 * Captcha provider that applies to a form.
	 *
	 * @param array<string,mixed> $form
	 */
	public static function provider_for( array $form ): string {
		$mode   = self::spam_mode( $form );
		$global = (string) Settings::get( 'spam.captcha_provider' );
		if ( 'off' === $mode ) {
			return 'none';
		}
		if ( 'strict' === $mode && ( '' === $global || 'none' === $global ) ) {
			return self::PROVIDER;
		}
		return '' !== $global ? $global : 'none';
	}

	/**
	 * Challenge for the token route, or null when the form does not use `pow`.
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>|null
	 */
	public static function challenge_for( array $form ): ?array {
		return self::PROVIDER === self::provider_for( $form ) ? self::engine()->create( (string) $form['public_id'] ) : null;
	}

	/**
	 * True for a public No-JS submission (admin-post fallback) without a solution to a form whose
	 * effective provider is `pow` in mode "global": check() lets it pass, the caller applies the
	 * stricter limit and marks the entry (SET-FC-P4-08).
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $raw
	 */
	public static function unsolved_without_js( array $form, array $raw, bool $js ): bool {
		if ( $js || 'global' !== self::spam_mode( $form ) || self::PROVIDER !== self::provider_for( $form ) ) {
			return false;
		}
		$payload = $raw['_fc_pow'] ?? null;
		return null === $payload || '' === $payload || array() === $payload;
	}

	/**
	 * Spam reason for a public submission, or null.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $raw  request; the solution is in `_fc_pow`
	 * @param bool                $js   true for the REST route used by frontend.js
	 */
	public static function check( array $form, array $raw, bool $js ): ?string {
		return self::evaluate( $form, $raw, $js, true );
	}

	/**
	 * Like check(), but without marking the solution as used: runs before the field validation so an
	 * extension stores no upload for a submission that is spam anyway (SET-FC-P4-09). A replayed
	 * solution is only detected by check().
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $raw
	 */
	public static function precheck( array $form, array $raw, bool $js ): ?string {
		return self::evaluate( $form, $raw, $js, false );
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $raw
	 */
	private static function evaluate( array $form, array $raw, bool $js, bool $consume ): ?string {
		if ( self::PROVIDER !== self::provider_for( $form ) ) {
			return null;
		}
		$result = self::engine( null, $consume )->verify( $raw['_fc_pow'] ?? null, (string) ( $form['public_id'] ?? '' ) );
		$prev   = ProofOfWork::BAD_SIGNATURE === $result ? Secret::previous_key( self::KEY_PURPOSE ) : null;
		if ( null !== $prev ) {
			// Challenge handed out before a key renewal (SET-FC-P4-06).
			$result = self::engine( $prev, $consume )->verify( $raw['_fc_pow'] ?? null, (string) ( $form['public_id'] ?? '' ) );
		}
		if ( ProofOfWork::OK === $result ) {
			return null;
		}
		if ( ProofOfWork::MISSING === $result ) {
			// No-JS fallback keeps the existing checks; strict forms and the JS route need a solution.
			return $js || 'strict' === self::spam_mode( $form ) ? self::REASON_MISSING : null;
		}
		return ProofOfWork::REPLAYED === $result ? self::REASON_REPLAYED : self::REASON_INVALID;
	}
}
