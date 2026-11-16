<?php
/**
 * Technical budgets (docs/spec/budgets.md). Filter can only lower values.
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
		'log_days'            => 30,
		'rate_window_seconds' => 600,
		'rate_global_per_ip'  => 20,
	);

	private const MAX = array(
		'request_bytes'    => 262144,
		'token_ttl'        => 86400,
		'job_max_attempts' => 20,
		'remote_timeout'   => 30,
	);

	/** @var array<string,int>|null */
	private static ?array $cache = null;

	public static function get( string $key ): int {
		if ( null === self::$cache ) {
			$filtered = (array) apply_filters( 'form_captain_limits_v1', self::DEFAULTS );
			$out      = array();
			foreach ( self::DEFAULTS as $k => $default ) {
				$value     = isset( $filtered[ $k ] ) ? (int) $filtered[ $k ] : $default;
				$ceiling   = self::MAX[ $k ] ?? $default;
				$out[ $k ] = max( 1, min( $value, $ceiling ) );
			}
			self::$cache = $out;
		}
		return self::$cache[ $key ] ?? 0;
	}

	public static function reset(): void {
		self::$cache = null;
	}
}
