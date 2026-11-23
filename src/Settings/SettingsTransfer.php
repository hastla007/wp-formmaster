<?php
/**
 * Export and import of the global settings as JSON (SET-FC-13).
 *
 * File: `{format, format_version, plugin_version, exported_gmt, keys[], settings{group{key}}}`.
 * Exported are all user-editable values of SettingsSchema except secrets, internal values and
 * values marked `exportable => false` (deleting data on uninstall needs its own confirmation).
 * Values of add-ons (form_captain_settings_fields_v1) are not part of the file.
 *
 * Import always runs through SettingsSchema::sanitize_group(): invalid values are reported and
 * not applied, unknown keys are reported instead of stored, a retention change and an external
 * captcha still need their confirmation. preview() shows the result without saving.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Support\Clock;

final class SettingsTransfer {

	public const FORMAT = 'form-captain/settings';

	public const FORMAT_VERSION = 1;

	public const MAX_BYTES = 65536;

	/** True when a value can be exported and imported. */
	public static function is_exportable( string $path ): bool {
		$def = SettingsSchema::definitions()[ $path ] ?? null;
		return is_array( $def ) && empty( $def['internal'] ) && ! SettingsSchema::is_secret( $path ) && ( $def['exportable'] ?? true );
	}

	/** @return array<string,mixed> */
	public static function export(): array {
		$all      = Settings::all();
		$settings = array();
		$keys     = array();
		foreach ( SettingsSchema::definitions() as $path => $def ) {
			if ( ! self::is_exportable( $path ) ) {
				continue;
			}
			$settings[ $def['group'] ][ $def['key'] ] = $all[ $def['group'] ][ $def['key'] ] ?? $def['default'];
			$keys[]                                   = $path;
		}
		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => FORM_CAPTAIN_VERSION,
			'exported_gmt'   => Clock::now(),
			'keys'           => $keys,
			'settings'       => $settings,
		);
	}

	public static function export_json(): string {
		return (string) wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Parses and checks a file without saving.
	 *
	 * @param array{retention_confirm?:bool,captcha_confirm?:bool} $confirm
	 * @return array{ok:bool,error:string,changes:array<string,array{0:mixed,1:mixed}>,unknown:string[],errors:array<string,string>,skipped:string[],values:array<string,array<string,mixed>>,meta:array<string,mixed>}
	 */
	public static function preview( string $json, array $confirm = array() ): array {
		$out = array(
			'ok'      => false,
			'error'   => '',
			'changes' => array(),
			'unknown' => array(),
			'errors'  => array(),
			'skipped' => array(),
			'values'  => array(),
			'meta'    => array(),
		);
		if ( strlen( $json ) > self::MAX_BYTES ) {
			$out['error'] = __( 'The file is too large for a settings export.', 'form-captain' );
			return $out;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || ! is_array( $data['settings'] ?? null ) ) {
			$out['error'] = __( 'This is not a Form Captain settings file.', 'form-captain' );
			return $out;
		}
		if ( (int) ( $data['format_version'] ?? 0 ) < 1 || (int) $data['format_version'] > self::FORMAT_VERSION ) {
			$out['error'] = __( 'The settings file was created by a newer version of Form Captain. Please update the plugin first.', 'form-captain' );
			return $out;
		}
		$out['meta'] = array(
			'plugin_version' => is_scalar( $data['plugin_version'] ?? null ) ? sanitize_text_field( (string) $data['plugin_version'] ) : '',
			'exported_gmt'   => is_scalar( $data['exported_gmt'] ?? null ) ? sanitize_text_field( (string) $data['exported_gmt'] ) : '',
		);
		$current     = Settings::all();
		$defs        = SettingsSchema::definitions();
		foreach ( $data['settings'] as $group => $values ) {
			$group = (string) $group;
			if ( ! in_array( $group, SettingsSchema::GROUPS, true ) || ! is_array( $values ) ) {
				$out['unknown'][] = sanitize_key( $group );
				continue;
			}
			// Start from the stored values so that keys missing in the file stay unchanged.
			$input = array();
			foreach ( SettingsSchema::group( $group ) as $key => $def ) {
				if ( empty( $def['internal'] ) ) {
					$value         = $current[ $group ][ $key ] ?? $def['default'];
					$input[ $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : $value;
				}
			}
			foreach ( $values as $key => $value ) {
				$path = $group . '.' . $key;
				if ( ! isset( $defs[ $path ] ) ) {
					$out['unknown'][] = sanitize_text_field( $path );
					continue;
				}
				if ( ! self::is_exportable( $path ) ) {
					$out['skipped'][] = $path;
					continue;
				}
				$input[ (string) $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : $value;
			}
			$input['retention_confirm'] = ! empty( $confirm['retention_confirm'] );
			$input['captcha_confirm']   = ! empty( $confirm['captcha_confirm'] );
			list( $sanitized, $errors ) = SettingsSchema::sanitize_group( $group, $input );
			$out['errors']             += $errors;
			$out['values'][ $group ]    = $sanitized;
			foreach ( $sanitized as $key => $value ) {
				$before = $current[ $group ][ $key ] ?? null;
				if ( $before !== $value ) {
					$out['changes'][ $group . '.' . $key ] = array( $before, $value );
				}
			}
		}
		$out['ok'] = true;
		return $out;
	}

	/**
	 * Imports a file. Valid values are saved; invalid ones, unknown keys and unconfirmed
	 * retention/captcha changes are reported and left unchanged.
	 *
	 * @param array{retention_confirm?:bool,captcha_confirm?:bool} $confirm
	 * @return array{ok:bool,error:string,changes:array<string,array{0:mixed,1:mixed}>,unknown:string[],errors:array<string,string>,skipped:string[],values:array<string,array<string,mixed>>,meta:array<string,mixed>}
	 */
	public static function import( string $json, array $confirm = array(), string $source = 'import' ): array {
		$preview = self::preview( $json, $confirm );
		if ( ! $preview['ok'] ) {
			return $preview;
		}
		ChangeLog::with_source(
			$source,
			static function () use ( $preview ): void {
				foreach ( $preview['values'] as $group => $values ) {
					Settings::set_group( $group, $values );
				}
			}
		);
		return $preview;
	}
}
