<?php
/**
 * Retention period value object (SET-FC-01).
 *
 * A period is stored as a compact string: `manual` or an amount plus unit, e.g. `30d`, `6m`,
 * `10y`. The values of earlier versions (`90d`, `12m`, `manual`) are valid periods with the same
 * meaning; the spec aliases `days_90` and `months_12` are normalised on update.
 * Limits: 1 day to 30 years. Expiry dates use calendar arithmetic (DateInterval), so `12m` and
 * `1y` end on the same calendar day.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

final class RetentionPeriod {

	public const MANUAL = 'manual';

	/** Highest amount per unit (30 years). */
	public const MAX = array(
		'd' => 10950,
		'm' => 360,
		'y' => 30,
	);

	/** @return array<string,string> unit => label */
	public static function unit_labels(): array {
		return array(
			'd' => __( 'days', 'form-captain' ),
			'm' => __( 'months', 'form-captain' ),
			'y' => __( 'years', 'form-captain' ),
		);
	}

	/**
	 * Canonical form of a period or null when invalid.
	 */
	public static function normalize( mixed $raw ): ?string {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$raw = strtolower( trim( (string) $raw ) );
		$raw = match ( $raw ) {
			'days_90' => '90d',
			'months_12' => '12m',
			default => $raw,
		};
		if ( self::MANUAL === $raw ) {
			return self::MANUAL;
		}
		if ( ! preg_match( '/^(\d{1,5})\s*([dmy])$/', $raw, $m ) ) {
			return null;
		}
		$amount = (int) $m[1];
		return $amount >= 1 && $amount <= self::MAX[ $m[2] ] ? $amount . $m[2] : null;
	}

	/**
	 * Builds a period from form inputs (amount + unit or manual).
	 */
	public static function from_parts( mixed $kind, mixed $amount, mixed $unit ): string {
		if ( self::MANUAL === $kind ) {
			return self::MANUAL;
		}
		$amount = is_scalar( $amount ) ? trim( (string) $amount ) : '';
		$unit   = is_scalar( $unit ) ? (string) $unit : '';
		return $amount . $unit;
	}

	/**
	 * @return array{amount:int,unit:string}|null null for manual or invalid periods
	 */
	public static function parts( string $period ): ?array {
		$period = self::normalize( $period );
		if ( null === $period || self::MANUAL === $period ) {
			return null;
		}
		return array(
			'amount' => (int) substr( $period, 0, -1 ),
			'unit'   => substr( $period, -1 ),
		);
	}

	public static function interval( string $period ): ?\DateInterval {
		$p = self::parts( $period );
		return null === $p ? null : new \DateInterval( 'P' . $p['amount'] . strtoupper( $p['unit'] ) );
	}

	/** Readable label, e.g. "90 days", "12 months", "Until deleted manually". */
	public static function label( string $period ): string {
		$normalized = self::normalize( $period );
		if ( self::MANUAL === $normalized ) {
			return __( 'Until deleted manually', 'form-captain' );
		}
		$p = self::parts( $period );
		if ( null === $p ) {
			return $period;
		}
		$n = $p['amount'];
		return match ( $p['unit'] ) {
			/* translators: %s: number of days */
			'd' => sprintf( _n( '%s day', '%s days', $n, 'form-captain' ), number_format_i18n( $n ) ),
			/* translators: %s: number of months */
			'm' => sprintf( _n( '%s month', '%s months', $n, 'form-captain' ), number_format_i18n( $n ) ),
			/* translators: %s: number of years */
			default => sprintf( _n( '%s year', '%s years', $n, 'form-captain' ), number_format_i18n( $n ) ),
		};
	}

	/** Error text for invalid periods (also used by the form editor REST errors). */
	public static function error_message(): string {
		return __( 'Please enter a period between 1 day and 30 years, or choose manual deletion.', 'form-captain' );
	}
}
