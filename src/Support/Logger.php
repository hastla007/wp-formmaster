<?php
/**
 * Structured, privacy-preserving log (P1-17, P5-09).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

use FormCaptain\Database\Tables;

final class Logger {

	private static ?string $correlation = null;

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
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'               => '[email]',
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/'                              => '[ip]',
			'/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i'                => '[ip]',
			'/\b(?:bearer|token|secret|key|password)\s*[=:]\s*\S+/i'     => '[secret]',
			'/\b[A-Za-z0-9_\-]{32,}\b/'                                  => '[redacted]',
			'/\b\d{7,}\b/'                                               => '[number]',
		);
		$out = preg_replace( array_keys( $patterns ), array_values( $patterns ), $message );
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
		if ( 'debug' === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
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

	public static function purge_old(): int {
		global $wpdb;
		$table = Tables::name( Tables::LOG );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s LIMIT 5000", Clock::plus( -86400 * Limits::get( 'log_days' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
