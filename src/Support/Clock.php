<?php
/**
 * UTC time helper (overridable in tests).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

final class Clock {

	private static ?int $frozen = null;

	public static function timestamp(): int {
		return self::$frozen ?? time();
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s', self::timestamp() );
	}

	public static function at( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	public static function plus( int $seconds ): string {
		return self::at( self::timestamp() + $seconds );
	}

	/** Test helper. */
	public static function freeze( ?int $timestamp ): void {
		self::$frozen = $timestamp;
	}
}
