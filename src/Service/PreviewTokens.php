<?php
/**
 * Short-lived, random, user-bound preview tokens (P2-15, OP-PUB-05).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;

final class PreviewTokens {

	public const TTL = 1800;

	private const PREFIX = 'fc_preview_';

	/** @return array{token:string,url:string,expires_at:int} */
	public function create( int $form_id, int $user_id ): array {
		$token   = bin2hex( random_bytes( 16 ) );
		$expires = Clock::timestamp() + self::TTL;
		set_transient(
			self::PREFIX . $token,
			array(
				'form_id' => $form_id,
				'user_id' => $user_id,
				'expires' => $expires,
			),
			self::TTL
		);
		return array(
			'token'      => $token,
			'url'        => self::url( $token ),
			'expires_at' => $expires,
		);
	}

	public static function url( string $token ): string {
		return add_query_arg(
			array(
				'action' => 'form_captain_preview',
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** Returns the form ID for a valid token of this user. */
	public function verify( string $token, int $user_id ): int|\WP_Error {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || $user_id <= 0 ) {
			return Errors::error( Errors::INVALID_TOKEN, __( 'This preview link is invalid or has expired.', 'form-captain' ) );
		}
		$data = get_transient( self::PREFIX . $token );
		if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < Clock::timestamp() ) {
			delete_transient( self::PREFIX . $token );
			return Errors::error( Errors::INVALID_TOKEN, __( 'This preview link is invalid or has expired.', 'form-captain' ) );
		}
		if ( (int) $data['user_id'] !== $user_id ) {
			return Errors::error( Errors::FORBIDDEN, __( 'This preview link belongs to another user.', 'form-captain' ) );
		}
		return (int) $data['form_id'];
	}
}
