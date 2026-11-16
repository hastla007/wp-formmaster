<?php
/**
 * Asset URL/version helper: prefers built files (assets/build) and falls back to sources.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

final class Asset {

	/** @var array<string,string>|null */
	private static ?array $manifest = null;

	public static function url( string $file ): string {
		$built = FORM_CAPTAIN_DIR . 'assets/build/' . $file;
		$path  = is_readable( $built ) ? 'assets/build/' . $file : 'assets/src/' . $file;
		return plugins_url( $path, FORM_CAPTAIN_FILE );
	}

	public static function version( string $file ): string {
		if ( null === self::$manifest ) {
			$m              = FORM_CAPTAIN_DIR . 'assets/build/manifest.json';
			self::$manifest = is_readable( $m ) ? (array) json_decode( (string) file_get_contents( $m ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( isset( self::$manifest[ $file ] ) ) {
			return (string) self::$manifest[ $file ];
		}
		$src = FORM_CAPTAIN_DIR . 'assets/src/' . $file;
		return is_readable( $src ) ? substr( (string) md5_file( $src ), 0, 10 ) : FORM_CAPTAIN_VERSION;
	}
}
