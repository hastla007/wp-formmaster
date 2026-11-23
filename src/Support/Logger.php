<?php
/**
 * Structured, privacy-preserving log (P1-17, P5-09).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

use FormCaptain\Database\Tables;
use FormCaptain\Settings\Settings;

final class Logger {

	/** Levels from most to least important (SET-FC-09). */
	public const LEVELS = array( 'error', 'warning', 'info', 'debug' );

	private static ?string $correlation = null;

	private static ?string $level = null;

	public static function correlation_id(): string {
		if ( null === self::$correlation ) {
			self::$correlation = bin2hex( random_bytes( 16 ) );
		}
		return self::$correlation;
	}

	public static function set_correlation_id( ?string $id ): void {
		self::$correlation = $id;
	}

	/**
	 * Removes e-mail addresses, IPv4/IPv6 addresses, bearer/secret-like tokens and long digit runs.
	 */
	public static function redact( string $message ): string {
		$patterns = array(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'  => '[email]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'               => '[ip]',
			'/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i' => '[ip]',
			'/\b(?:bearer|token|secret|key|password)\s*[=:]\s*\S+/i' => '[secret]',
			'/\b[A-Za-z0-9_\-]{32,}\b/'                   => '[redacted]',
			'/\b\d{7,}\b/'                                => '[number]',
		);
		$out      = preg_replace( array_keys( $patterns ), array_values( $patterns ), $message );
		return mb_substr( (string) $out, 0, 255 );
	}

	/**
	 * @param array{form_id?:int,entry_id?:int,job_id?:int} $refs
	 */
	public static function log( string $level, string $code, string $message = '', array $refs = array() ): void {
		global $wpdb;
		if ( ! in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			$level = 'info';
		}
		if ( ! self::enabled( $level ) ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Tables::name( Tables::LOG ),
			array(
				'level'          => $level,
				'code'           => substr( preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( $code ) ), 0, 60 ),
				'correlation_id' => self::correlation_id(),
				'form_id'        => $refs['form_id'] ?? null,
				'entry_id'       => $refs['entry_id'] ?? null,
				'job_id'         => $refs['job_id'] ?? null,
				'message'        => self::redact( $message ),
				'created_gmt'    => Clock::now(),
			)
		);
	}

	/**
	 * Lowest level that is written: setting `general.log_level` (default info), filter
	 * `form_captain_log_level_v1` wins. WP_DEBUG keeps writing debug entries as before.
	 */
	public static function level(): string {
		if ( null === self::$level ) {
			$level       = (string) apply_filters( 'form_captain_log_level_v1', (string) Settings::get( 'general.log_level' ) );
			self::$level = in_array( $level, self::LEVELS, true ) ? $level : 'info';
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG ? 'debug' : self::$level;
	}

	/** True when entries of $level are written with the current log level. */
	public static function enabled( string $level ): bool {
		$rank = array_search( $level, self::LEVELS, true );
		return false !== $rank && $rank <= array_search( self::level(), self::LEVELS, true );
	}

	/** True when debug entries are written because of the setting or filter (not WP_DEBUG). */
	public static function debug_by_setting(): bool {
		self::level();
		return 'debug' === self::$level;
	}

	public static function reset_level(): void {
		self::$level = null;
	}

	/** Days log entries are kept: setting `privacy.log_days` (1–365), filter `form_captain_log_days_v1` wins. */
	public static function retention_days(): int {
		$days = (int) apply_filters( 'form_captain_log_days_v1', (int) Settings::get( 'privacy.log_days' ) );
		return max( 1, min( 3650, $days ) );
	}

	public static function purge_old(): int {
		global $wpdb;
		$table = Tables::name( Tables::LOG );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s LIMIT 5000", Clock::plus( -DAY_IN_SECONDS * self::retention_days() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
