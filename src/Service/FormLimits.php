<?php
/**
 * Limits per form (SET-FC-18): submission period (opens/closes, site time zone) and a maximum
 * number of entries. Spam and trash do not count. Used by the renderer, the token endpoint and
 * the submission flow, so a closed form is refused everywhere in the same way.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Repository\EntryRepository;
use FormCaptain\Support\Clock;

final class FormLimits {

	/** Highest configurable maximum number of entries. */
	public const MAX_ENTRIES = 1000000;

	/** Stored date-time format (site time zone, as delivered by <input type="datetime-local">). */
	public const DATETIME_FORMAT = 'Y-m-d\TH:i';

	/**
	 * Default limit settings: empty period, no maximum, translated default closed text.
	 *
	 * @return array{opens_at:string,closes_at:string,max_entries:int,closed_message:string}
	 */
	public static function defaults(): array {
		return array(
			'opens_at'       => '',
			'closes_at'      => '',
			'max_entries'    => 0,
			'closed_message' => '',
		);
	}

	/**
	 * Normalizes settings.limits. Errors are reported as path => code; invalid values are reset.
	 *
	 * @param mixed                $in
	 * @param array<string,string> $errors
	 * @return array{opens_at:string,closes_at:string,max_entries:int,closed_message:string}
	 */
	public static function sanitize( mixed $in, array &$errors ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = self::defaults();
		foreach ( array( 'opens_at', 'closes_at' ) as $k ) {
			$raw = is_scalar( $in[ $k ] ?? null ) ? trim( (string) $in[ $k ] ) : '';
			if ( '' === $raw ) {
				continue;
			}
			$raw = substr( str_replace( ' ', 'T', $raw ), 0, 16 );
			if ( null === self::timestamp( $raw ) ) {
				$errors[ "limits.$k" ] = 'invalid_datetime';
				continue;
			}
			$out[ $k ] = $raw;
		}
		if ( '' !== $out['opens_at'] && '' !== $out['closes_at'] && (int) self::timestamp( $out['closes_at'] ) <= (int) self::timestamp( $out['opens_at'] ) ) {
			$errors['limits.closes_at'] = 'closes_before_opens';
			$out['closes_at']           = '';
		}
		$max = $in['max_entries'] ?? 0;
		if ( '' !== $max && null !== $max ) {
			if ( ! is_numeric( $max ) || (int) $max < 0 || (int) $max > self::MAX_ENTRIES || (string) (int) $max !== trim( (string) $max ) ) {
				$errors['limits.max_entries'] = 'invalid_max_entries';
			} else {
				$out['max_entries'] = (int) $max;
			}
		}
		$msg                   = is_scalar( $in['closed_message'] ?? null ) ? (string) $in['closed_message'] : '';
		$out['closed_message'] = trim( mb_substr( sanitize_textarea_field( $msg ), 0, 500 ) );
		return $out;
	}

	/** Unix timestamp of a stored date-time in the site time zone, or null if invalid. */
	public static function timestamp( string $value ): ?int {
		$dt = \DateTimeImmutable::createFromFormat( '!' . self::DATETIME_FORMAT, $value, wp_timezone() );
		if ( ! $dt || $dt->format( self::DATETIME_FORMAT ) !== $value ) {
			return null;
		}
		return $dt->getTimestamp();
	}

	/**
	 * Whether the form accepts submissions now. `reason`: '' (open), not_open_yet, closed, full.
	 * The filter `form_captain_form_limits_v1` may change the result (e.g. an extension that adds
	 * further rules); it receives and must return the same shape.
	 *
	 * @param array<string,mixed> $form form row
	 * @return array{open:bool,reason:string,message:string}
	 */
	public static function state( array $form, ?EntryRepository $entries = null, ?int $now = null ): array {
		$limits = is_array( $form['settings']['limits'] ?? null ) ? $form['settings']['limits'] : array();
		$now    = $now ?? Clock::timestamp();
		$reason = '';
		$opens  = self::timestamp( (string) ( $limits['opens_at'] ?? '' ) );
		$closes = self::timestamp( (string) ( $limits['closes_at'] ?? '' ) );
		$max    = (int) ( $limits['max_entries'] ?? 0 );
		if ( null !== $opens && $now < $opens ) {
			$reason = 'not_open_yet';
		} elseif ( null !== $closes && $now >= $closes ) {
			$reason = 'closed';
		} elseif ( $max > 0 && (int) ( $form['id'] ?? 0 ) > 0 && ( $entries ?? new EntryRepository() )->count_accepted( (int) $form['id'] ) >= $max ) {
			$reason = 'full';
		}
		$state    = array(
			'open'    => '' === $reason,
			'reason'  => $reason,
			'message' => '' === $reason ? '' : self::message( $form, $reason, $opens ),
		);
		$filtered = apply_filters( 'form_captain_form_limits_v1', $state, $form );
		if ( is_array( $filtered ) && isset( $filtered['open'] ) ) {
			return array(
				'open'    => (bool) $filtered['open'],
				'reason'  => (string) ( $filtered['reason'] ?? '' ),
				'message' => wp_strip_all_tags( (string) ( $filtered['message'] ?? '' ) ),
			);
		}
		return $state;
	}

	/**
	 * Text shown instead of the form: the form's own closed text or a translated default.
	 *
	 * @param array<string,mixed> $form
	 */
	private static function message( array $form, string $reason, ?int $opens ): string {
		$own = trim( (string) ( $form['settings']['limits']['closed_message'] ?? '' ) );
		if ( '' !== $own ) {
			return $own;
		}
		if ( 'not_open_yet' === $reason && null !== $opens ) {
			/* translators: %s: date and time when the form opens */
			return sprintf( __( 'This form opens on %s.', 'form-captain' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $opens ) );
		}
		if ( 'full' === $reason ) {
			return __( 'This form has reached the maximum number of submissions and is closed.', 'form-captain' );
		}
		return __( 'This form is closed and no longer accepts submissions.', 'form-captain' );
	}
}
