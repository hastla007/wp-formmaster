<?php
/**
 * Format profile v1 "Generic CSV" for entry imports (docs/planung/import.md, section 7).
 *
 * Documented format: UTF-8 (with or without BOM), header row, one row per submission,
 * delimiter "," or ";" (detected from the header row), RFC 4180 quoting, submission time
 * as ISO 8601 or "YYYY-MM-DD HH:MM:SS" in the chosen time zone, multiple values separated
 * by "|". Covered by tests/integration/Import/ImportServiceTest.php.
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
	 * Parses a submission time into UTC "Y-m-d H:i:s"; null if invalid.
	 */
	public static function to_gmt( string $value, \DateTimeZone $tz ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$utc = new \DateTimeZone( 'UTC' );
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
}
