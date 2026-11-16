<?php
/**
 * Site secret for HMACs (tokens, rate-limit buckets, signed context). Never output.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

final class Secret {

	public const OPTION = 'form_captain_secret';

	public static function key( string $purpose ): string {
		$secret = get_option( self::OPTION );
		if ( ! is_string( $secret ) || strlen( $secret ) < 64 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::OPTION, $secret, false );
		}
		return hash_hmac( 'sha256', $purpose, $secret . wp_salt( 'auth' ) );
	}

	public static function sign( string $purpose, string $data ): string {
		return hash_hmac( 'sha256', $data, self::key( $purpose ) );
	}

	public static function rotate(): void {
		update_option( self::OPTION, bin2hex( random_bytes( 32 ) ), false );
	}

	public static function random_id( int $length = 16 ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, 35 ) ];
		}
		return $out;
	}

	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function base64url_decode( string $data ): string|false {
		return base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
