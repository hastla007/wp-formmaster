<?php
/**
 * Network option `form_captain_network` (SET-FC-44, SET-FC-35, docs/planung/multisite.md).
 *
 * Holds network state only, never payload data: activation mode, progress of the mass migration,
 * the super admin switch for entries and the defaults for sites that install Form Captain for the
 * first time. Lives in the site meta of the current network (`get_site_option()`).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Network;

use FormCaptain\Settings\SettingsSchema;

final class NetworkSettings {

	public const OPTION = 'form_captain_network';

	/**
	 * Settings a super admin may preset for new sites (SET-FC-35). Existing sites are never
	 * changed: the values are only used when a site stores its Form Captain settings for the
	 * first time.
	 */
	public const DEFAULT_PATHS = array(
		'privacy.retention',
		'privacy.trash_days',
		'privacy.erasure_mode',
		'privacy.log_days',
	);

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'activation'               => '',
			'activated_gmt'            => '',
			'super_admin_entries'      => false,
			'allow_site_data_deletion' => true,
			'site_defaults'            => array(),
			'migration'                => self::idle_migration(),
		);
	}

	/** @return array<string,mixed> */
	public static function idle_migration(): array {
		return array(
			'status'       => 'idle',
			'run'          => '',
			'target'       => 0,
			'total'        => 0,
			'done'         => 0,
			'failed'       => array(),
			'cursor'       => 0,
			'started_gmt'  => '',
			'finished_gmt' => '',
		);
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		if ( ! is_multisite() ) {
			return self::defaults();
		}
		$stored = get_site_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
		if ( ! is_array( $out['site_defaults'] ) ) {
			$out['site_defaults'] = array();
		}
		$out['migration'] = array_merge( self::idle_migration(), is_array( $out['migration'] ) ? $out['migration'] : array() );
		return $out;
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Merges the given keys into the network option (unknown keys are dropped).
	 *
	 * @param array<string,mixed> $values
	 */
	public static function update( array $values ): void {
		if ( ! is_multisite() ) {
			return;
		}
		$all = array_merge( self::all(), array_intersect_key( $values, self::defaults() ) );
		update_site_option( self::OPTION, $all );
	}

	/** Super admins without a role on a site may read, export and delete its entries (decision 23: default off). */
	public static function super_admin_entries(): bool {
		return is_multisite() && ! empty( self::get( 'super_admin_entries' ) );
	}

	/** Sites may enable "delete all data on uninstall" themselves (default: yes). */
	public static function site_data_deletion_allowed(): bool {
		return ! is_multisite() || ! empty( self::get( 'allow_site_data_deletion' ) );
	}

	/**
	 * Valid presets for new sites as settings path => value.
	 *
	 * @return array<string,mixed>
	 */
	public static function site_defaults(): array {
		if ( ! is_multisite() ) {
			return array();
		}
		$out = array();
		foreach ( (array) self::get( 'site_defaults' ) as $path => $value ) {
			if ( in_array( $path, self::DEFAULT_PATHS, true ) ) {
				$out[ (string) $path ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Sanitizes the network settings form. Empty preset fields mean "no preset" (the plugin
	 * default applies).
	 *
	 * @param array<string,mixed> $input raw input
	 * @return array{0:array<string,mixed>,1:array<string,string>} [values, errors path => message]
	 */
	public static function sanitize( array $input ): array {
		$errors = array();
		$values = array(
			'super_admin_entries'      => ! empty( $input['super_admin_entries'] ),
			'allow_site_data_deletion' => ! empty( $input['allow_site_data_deletion'] ),
			'site_defaults'            => array(),
		);
		$defs   = SettingsSchema::definitions();
		$raw    = is_array( $input['site_defaults'] ?? null ) ? $input['site_defaults'] : array();
		foreach ( self::DEFAULT_PATHS as $path ) {
			$field = str_replace( '.', '__', $path );
			if ( ! isset( $raw[ $field ] ) || ! is_scalar( $raw[ $field ] ) || '' === trim( (string) $raw[ $field ] ) || ! isset( $defs[ $path ] ) ) {
				continue;
			}
			list( $value, $error ) = SettingsSchema::sanitize_value( $defs[ $path ], $raw[ $field ] );
			if ( null !== $error ) {
				$errors[ $path ] = $error;
				continue;
			}
			$values['site_defaults'][ $path ] = $value;
		}
		return array( $values, $errors );
	}
}
