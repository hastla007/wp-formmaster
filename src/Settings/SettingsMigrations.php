<?php
/**
 * One-time data migrations of settings values (independent of the database schema version).
 *
 * Steps are named; finished steps are recorded in OPTION so every step runs once and steps of
 * different features never collide on a number. Steps must be idempotent.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Database\Tables;

final class SettingsMigrations {

	public const OPTION = 'form_captain_settings_migrations';

	/** @return array<string,callable> step name => callable */
	private static function steps(): array {
		return array(
			'retention_periods'        => array( self::class, 'retention_periods' ),
			'retention_legacy_default' => array( self::class, 'retention_legacy_default' ),
		);
	}

	/** Runs every step that has not run yet. Cheap when nothing is pending (one autoloaded option). */
	public static function run(): void {
		$done = get_option( self::OPTION, array() );
		$done = is_array( $done ) ? $done : array();
		foreach ( self::steps() as $name => $step ) {
			if ( isset( $done[ $name ] ) ) {
				continue;
			}
			$step();
			$done[ $name ] = gmdate( 'Y-m-d H:i:s' );
			update_option( self::OPTION, $done, true );
		}
	}

	/**
	 * S9 / SET-FC-P3-6: an existing installation that never stored a retention value keeps the
	 * earlier default of 12 months, also when the update brings no database migration (then
	 * Settings::ensure_defaults() does not run). New installations store `manual` before this
	 * step runs, so they are not touched.
	 */
	public static function retention_legacy_default(): void {
		$stored = get_option( Settings::OPTION, null );
		if ( is_array( $stored ) && ! isset( $stored['privacy']['retention'] ) ) {
			$stored['privacy']              = is_array( $stored['privacy'] ?? null ) ? $stored['privacy'] : array();
			$stored['privacy']['retention'] = Settings::LEGACY_RETENTION;
			update_option( Settings::OPTION, $stored, true );
		}
	}

	/**
	 * SET-FC-01: retention periods instead of three fixed modes.
	 *
	 * The earlier values 90d, 12m and manual are valid periods with the same meaning and stay as
	 * they are. Spec aliases (days_90, months_12) and spelling variants are rewritten to the
	 * canonical form; unknown values fall back to what they effectively meant before: 12 months
	 * globally, "global" for a form.
	 */
	public static function retention_periods(): void {
		global $wpdb;
		$stored = get_option( Settings::OPTION, null );
		if ( is_array( $stored ) && isset( $stored['privacy']['retention'] ) ) {
			$old = (string) $stored['privacy']['retention'];
			$new = RetentionPeriod::normalize( $old ) ?? Settings::LEGACY_RETENTION;
			if ( $new !== $old ) {
				$stored['privacy']['retention'] = $new;
				update_option( Settings::OPTION, $stored, true );
			}
		}
		$table = Tables::name( Tables::FORMS );
		$rows  = $wpdb->get_results( "SELECT id, settings_json FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows ?: array() as $row ) {
			$settings = json_decode( (string) $row['settings_json'], true );
			if ( ! is_array( $settings ) || ! isset( $settings['retention']['mode'] ) || ! is_scalar( $settings['retention']['mode'] ) ) {
				continue;
			}
			$old = (string) $settings['retention']['mode'];
			$new = in_array( $old, array( '', 'global', 'inherit' ), true ) ? 'global' : ( RetentionPeriod::normalize( $old ) ?? 'global' );
			if ( $new === $old ) {
				continue;
			}
			$settings['retention']['mode'] = $new;
			$wpdb->update( $table, array( 'settings_json' => wp_json_encode( $settings ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
}
