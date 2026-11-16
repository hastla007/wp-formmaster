<?php
/**
 * PSR-4 fallback autoloader (release builds ship without vendor/).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain;

final class Autoloader {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		spl_autoload_register(
			static function ( string $class ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( ! str_starts_with( $class, $prefix ) ) {
					return;
				}
				$relative = substr( $class, strlen( $prefix ) );
				$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
