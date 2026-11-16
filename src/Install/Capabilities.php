<?php
/**
 * Capability registry (docs/spec/rechte.md).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Install;

final class Capabilities {

	public const MANAGE_FORMS        = 'form_captain_manage_forms';
	public const READ_ENTRIES        = 'form_captain_read_entries';
	public const EDIT_ENTRIES        = 'form_captain_edit_entries';
	public const EXPORT_ENTRIES      = 'form_captain_export_entries';
	public const DELETE_ENTRIES      = 'form_captain_delete_entries';
	public const MANAGE_SETTINGS     = 'form_captain_manage_settings';
	public const MANAGE_INTEGRATIONS = 'form_captain_manage_integrations';

	public const ALL = array(
		self::MANAGE_FORMS,
		self::READ_ENTRIES,
		self::EDIT_ENTRIES,
		self::EXPORT_ENTRIES,
		self::DELETE_ENTRIES,
		self::MANAGE_SETTINGS,
		self::MANAGE_INTEGRATIONS,
	);

	/** Grants all capabilities to administrators only. Idempotent. */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		foreach ( self::ALL as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Labels for the access settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::MANAGE_FORMS        => __( 'Manage forms', 'form-captain' ),
			self::READ_ENTRIES        => __( 'Read entries', 'form-captain' ),
			self::EDIT_ENTRIES        => __( 'Edit entry status and notes', 'form-captain' ),
			self::EXPORT_ENTRIES      => __( 'Export entries', 'form-captain' ),
			self::DELETE_ENTRIES      => __( 'Delete entries', 'form-captain' ),
			self::MANAGE_SETTINGS     => __( 'Manage settings', 'form-captain' ),
			self::MANAGE_INTEGRATIONS => __( 'Manage integrations', 'form-captain' ),
		);
	}

	public static function current_user_has_any(): bool {
		foreach ( self::ALL as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}
