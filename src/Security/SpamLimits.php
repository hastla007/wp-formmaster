<?php
/**
 * Sending limits as settings (SET-FC-05 b, owner rule S2: setting with safe default, filter wins).
 *
 * spam.window_minutes    time window of all submission limits (default 10)
 * spam.form_total        submissions per form and window from all visitors (flood brake, default 300)
 * spam.receipts_per_hour e-mails to one visitor address per hour (confirmation, double opt-in; default 3)
 *
 * Filter `form_captain_spam_limits_v1` ( array{window_minutes:int,form_total:int,receipts_per_hour:int} )
 * wins over the settings; results are kept within the setting bounds. The older budget
 * `rate_window_seconds` of form_captain_limits_v1 still lowers the window when a site filters it.
 * The per-visitor limit across forms is `spam.global_per_ip` (see SET-FC-34).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Security;

use FormCaptain\Settings\Settings;
use FormCaptain\Support\Limits;

final class SpamLimits {

	/** Bounds, also used by SettingsSchema. */
	public const BOUNDS = array(
		'window_minutes'    => array( 1, 1440 ),
		'form_total'        => array( 10, 100000 ),
		'receipts_per_hour' => array( 1, 50 ),
	);

	private const LEGACY_WINDOW = 600;

	/** @return array{window_minutes:int,form_total:int,receipts_per_hour:int} */
	public static function all(): array {
		$values = array();
		foreach ( array_keys( self::BOUNDS ) as $key ) {
			$values[ $key ] = (int) Settings::get( 'spam.' . $key );
		}
		$filtered = apply_filters( 'form_captain_spam_limits_v1', $values );
		$out      = array();
		foreach ( self::BOUNDS as $key => $bounds ) {
			$value       = is_array( $filtered ) && isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ? (int) $filtered[ $key ] : $values[ $key ];
			$out[ $key ] = max( $bounds[0], min( $bounds[1], $value ) );
		}
		/** @var array{window_minutes:int,form_total:int,receipts_per_hour:int} $out */
		return $out;
	}

	public static function get( string $key ): int {
		return self::all()[ $key ] ?? 0;
	}

	/** Window of the submission limits in seconds. */
	public static function window_seconds(): int {
		$seconds = self::get( 'window_minutes' ) * MINUTE_IN_SECONDS;
		$legacy  = Limits::get( 'rate_window_seconds' );
		return $legacy < self::LEGACY_WINDOW ? min( $seconds, $legacy ) : $seconds;
	}
}
