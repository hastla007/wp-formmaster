<?php
/**
 * Role × capability matrix for "Access & roles" (docs/spec/rechte.md, P5-20).
 *
 * Capabilities live in WP_Roles, not in the settings option. The administrator role can never lose
 * form_captain_manage_settings (lock-out protection).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Install\Capabilities;

final class AccessControl {

	public const LOCKED = array( 'administrator' => array( Capabilities::MANAGE_SETTINGS ) );

	/** @return array<string,array{label:string,users:int,caps:array<string,bool>}> role => info */
	public static function matrix(): array {
		$counts = count_users();
		$out    = array();
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			$caps = array();
			foreach ( Capabilities::ALL as $cap ) {
				$caps[ $cap ] = $role->has_cap( $cap );
			}
			$out[ $slug ] = array(
				'label' => translate_user_role( (string) ( wp_roles()->role_names[ $slug ] ?? $slug ) ),
				'users' => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ),
				'caps'  => $caps,
			);
		}
		return $out;
	}

	public static function is_locked( string $role, string $cap ): bool {
		return in_array( $cap, self::LOCKED[ $role ] ?? array(), true );
	}

	/**
	 * Applies a submitted matrix role => [cap => bool]. Roles missing from the input lose all
	 * Form Captain capabilities except locked ones. Returns changes and a short summary.
	 *
	 * @param array<string,array<string,mixed>> $input
	 * @return array{changed:int,granted:array<int,string>,revoked:array<int,string>}
	 */
	public static function apply( array $input ): array {
		$result = array(
			'changed' => 0,
			'granted' => array(),
			'revoked' => array(),
		);
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			foreach ( Capabilities::ALL as $cap ) {
				$want = ! empty( $input[ $slug ][ $cap ] ) || self::is_locked( (string) $slug, $cap );
				$has  = $role->has_cap( $cap );
				if ( $want === $has ) {
					continue;
				}
				if ( $want ) {
					$role->add_cap( $cap );
					$result['granted'][] = $slug . ':' . $cap;
				} else {
					$role->remove_cap( $cap );
					$result['revoked'][] = $slug . ':' . $cap;
				}
				++$result['changed'];
			}
		}
		return $result;
	}

	/**
	 * Restores the default: administrators get everything, all other roles nothing.
	 *
	 * @return array<string,mixed>
	 */
	public static function reset(): array {
		$input = array( 'administrator' => array_fill_keys( Capabilities::ALL, true ) );
		return self::apply( $input );
	}

	/**
	 * Number of users who hold a capability through any of their roles (super admins excluded,
	 * multisite is not supported in v1).
	 */
	public static function users_with_cap( string $cap ): int {
		$roles = array();
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( $role->has_cap( $cap ) ) {
				$roles[] = $slug;
			}
		}
		if ( ! $roles ) {
			return 0;
		}
		$query = new \WP_User_Query(
			array(
				'role__in'    => $roles,
				'fields'      => 'ID',
				'count_total' => true,
				'number'      => 1,
			)
		);
		return (int) $query->get_total();
	}
}
