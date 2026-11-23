<?php
/**
 * Technical budgets (docs/spec/budgets.md). Filter can only lower values, except for the
 * business limits in SETTABLE (SET-FC-34): their base value comes from the settings and the
 * filter `form_captain_limits_v1` may raise or lower them up to the hard ceiling (filter wins).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

final class Limits {

	private const DEFAULTS = array(
		'request_bytes'       => 65536,
		'fields_per_form'     => 100,
		'options_per_field'   => 200,
		'schema_bytes'        => 262144,
		'text_max'            => 1000,
		'textarea_max'        => 10000,
		'token_ttl'           => 7200,
		'job_batch'           => 20,
		'job_batch_seconds'   => 20,
		'job_lock_seconds'    => 300,
		'job_max_attempts'    => 8,
		'remote_timeout'      => 10,
		'cleanup_batch'       => 200,
		'csv_batch'           => 500,
		'rate_window_seconds' => 600,
		'rate_global_per_ip'  => 20,
	);

	private const MAX = array(
		'request_bytes'    => 262144,
		'token_ttl'        => 86400,
		'job_max_attempts' => 20,
		'remote_timeout'   => 30,
	);

	/** Settable limits: key => [settings path, multiplier to the limit unit, hard ceiling]. */
	public const SETTABLE = array(
		'text_max'           => array( 'general.text_max', 1, 5000 ),
		'textarea_max'       => array( 'general.textarea_max', 1, 50000 ),
		'token_ttl'          => array( 'spam.token_minutes', 60, 86400 ),
		'rate_global_per_ip' => array( 'spam.global_per_ip', 1, 1000 ),
	);

	/** @var array<string,int>|null */
	private static ?array $cache = null;

	public static function get( string $key ): int {
		if ( null === self::$cache ) {
			$base     = self::base();
			$filtered = (array) apply_filters( 'form_captain_limits_v1', $base );
			$out      = array();
			foreach ( $base as $k => $default ) {
				$value     = isset( $filtered[ $k ] ) ? (int) $filtered[ $k ] : $default;
				$ceiling   = isset( self::SETTABLE[ $k ] ) ? self::SETTABLE[ $k ][2] : max( $default, self::MAX[ $k ] ?? $default );
				$out[ $k ] = max( 1, min( $value, $ceiling ) );
			}
			self::$cache = $out;
		}
		return self::$cache[ $key ] ?? 0;
	}

	/**
	 * Defaults with the settable values from the settings. The request size follows a raised
	 * paragraph limit (4 bytes per character plus room for the other fields), up to its maximum.
	 *
	 * @return array<string,int>
	 */
	private static function base(): array {
		$base = self::DEFAULTS;
		foreach ( self::SETTABLE as $k => $def ) {
			$value = \FormCaptain\Settings\Settings::get( $def[0] );
			if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				$base[ $k ] = max( 1, min( (int) $value * $def[1], $def[2] ) );
			}
		}
		$base['request_bytes'] = max( $base['request_bytes'], min( self::MAX['request_bytes'], $base['textarea_max'] * 4 + 16384 ) );
		return $base;
	}

	public static function reset(): void {
		self::$cache = null;
	}
}
