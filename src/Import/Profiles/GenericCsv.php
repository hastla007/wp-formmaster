<?php
/**
 * Format profile v1 "Generic CSV" for entry imports (docs/planung/import.md, section 7).
 *
 * Documented format: UTF-8 (with or without BOM), header row, one row per submission,
 * delimiter "," or ";" (detected from the header row), RFC 4180 quoting, submission time
 * as ISO 8601 or "YYYY-MM-DD HH:MM:SS" in the chosen time zone, multiple values separated
 * by "|". The admin UI (EntryImportAdmin) additionally detects the families in TIME_FORMATS.
 * Covered by tests/integration/Import/ImportServiceTest.php and EntryImportUiTest.php.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Import\Profiles;

final class GenericCsv {

	public const ID              = 'generic_csv';
	public const VERSION         = 1;
	public const DELIMITERS      = array( ',', ';' );
	public const MULTI_SEPARATOR = '|';
	public const MAX_BYTES       = 20971520;
	public const MAX_ROWS        = 50000;
	public const BATCH           = 500;

	/** Detects the delimiter from the header line. */
	public static function delimiter( string $header_line ): string {
		return substr_count( $header_line, ';' ) > substr_count( $header_line, ',' ) ? ';' : ',';
	}

	/**
	 * Supported submission time formats (families). "iso" is the documented default
	 * (ISO 8601 or "YYYY-MM-DD HH:MM:SS"); the others accept a date with optional
	 * "HH:MM" or "HH:MM:SS". "unix" are seconds since 1970-01-01 UTC.
	 */
	public const TIME_FORMATS = array( 'iso', 'ymd', 'dmy_dot', 'dmy_slash', 'mdy_slash', 'unix' );

	/** Date patterns of the non-ISO families: [regex, order of Y/M/D groups]. */
	private const DATE_PATTERNS = array(
		'ymd'       => array( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', 'ymd' ),
		'dmy_dot'   => array( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:,? (\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', 'dmy' ),
		'dmy_slash' => array( '#^(\d{1,2})/(\d{1,2})/(\d{4})(?:,? (\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', 'dmy' ),
		'mdy_slash' => array( '#^(\d{1,2})/(\d{1,2})/(\d{4})(?:,? (\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', 'mdy' ),
	);

	/**
	 * Detects the time format family from sample values; null if no family fits all of them.
	 *
	 * Day/month order for "/" is decided by the samples (a part > 12); ambiguous samples
	 * default to day/month (dmy_slash).
	 *
	 * @param array<int,string> $samples
	 */
	public static function detect_time_format( array $samples ): ?string {
		$samples = array_values( array_filter( array_map( 'trim', $samples ), static fn( string $s ): bool => '' !== $s ) );
		if ( ! $samples ) {
			return null;
		}
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( self::TIME_FORMATS as $format ) {
			$ok = true;
			foreach ( $samples as $sample ) {
				if ( null === self::to_gmt( $sample, $utc, $format ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return $format;
			}
		}
		return null;
	}

	/**
	 * Parses a submission time into UTC "Y-m-d H:i:s"; null if invalid. Times without
	 * offset are read in $tz (site time zone).
	 */
	public static function to_gmt( string $value, \DateTimeZone $tz, string $format = 'iso' ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$utc = new \DateTimeZone( 'UTC' );
		if ( 'unix' === $format ) {
			return preg_match( '/^\d{9,11}$/', $value ) ? gmdate( 'Y-m-d H:i:s', (int) $value ) : null;
		}
		if ( isset( self::DATE_PATTERNS[ $format ] ) ) {
			return self::parse_family( $value, $tz, self::DATE_PATTERNS[ $format ] );
		}
		if ( 'iso' !== $format ) {
			return null;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $tz );
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+\-]\d{2}:?\d{2})?$/', $value ) ) {
			try {
				$dt = new \DateTimeImmutable( $value, $tz );
			} catch ( \Exception $e ) {
				return null;
			}
		} else {
			return null;
		}
		if ( ! $dt ) {
			return null;
		}
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) {
			return null;
		}
		return $dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array{0:string,1:string} $pattern
	 */
	private static function parse_family( string $value, \DateTimeZone $tz, array $pattern ): ?string {
		if ( ! preg_match( $pattern[0], $value, $m ) ) {
			return null;
		}
		$parts = array_combine( str_split( $pattern[1] ), array( (int) $m[1], (int) $m[2], (int) $m[3] ) );
		$hour  = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0;
		$min   = isset( $m[5] ) && '' !== $m[5] ? (int) $m[5] : 0;
		$sec   = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
		if ( ! checkdate( $parts['m'], $parts['d'], $parts['y'] ) || $hour > 23 || $min > 59 || $sec > 59 ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $parts['y'], $parts['m'], $parts['d'], $hour, $min, $sec ), $tz );
		return $dt ? $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
	}
}
