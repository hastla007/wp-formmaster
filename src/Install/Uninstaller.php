<?php
/**
 * Uninstall (P5-06, SET-FC-42): keeps all data unless the administrator explicitly enabled
 * "Delete all Form Captain data on uninstall" and confirmed it in the settings.
 *
 * In a network every site follows its own rule: data of a site is deleted only when this site
 * confirmed the deletion (and the network allows sites to do so). Sites are processed in batches;
 * an error on one site never stops the others. For very large networks run the uninstall with
 * WP-CLI (`wp plugin uninstall form-captain --network`) so no web timeout applies.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Install;

use FormCaptain\Database\Tables;
use FormCaptain\Network\Network;
use FormCaptain\Network\NetworkSettings;

final class Uninstaller {

	/** Every option Form Captain stores per site. */
	public const OPTIONS = array(
		'form_captain_settings',
		'form_captain_db_version',
		'form_captain_migration_state',
		'form_captain_secret',
		'form_captain_secret_previous',
		'form_captain_secret_previous_until',
		'form_captain_rl_salt',
		'form_captain_connectors',
		'form_captain_activation_redirect',
		'form_captain_worker_lock',
		'form_captain_setup_steps',
		'form_captain_retention_modes',
		'form_captain_settings_migrations',
		'form_captain_cleanup_failures',
		'form_captain_last_cleanup',
		'form_captain_last_cleanup_at',
		'form_captain_entry_import_runs',
		'form_captain_settings_changelog',
		'form_captain_install_pending',
	);

	/** Cron hooks of a site. */
	private const HOOKS = array(
		'form_captain_process_jobs',
		'form_captain_daily_cleanup',
		'form_captain_hourly_cleanup',
		'form_captain_install_site',
	);

	/**
	 * Entry point of uninstall.php.
	 *
	 * @return array<int,string> site ID => error code of sites that failed (multisite only)
	 */
	public static function run(): array {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return array();
		}
		$allowed = NetworkSettings::site_data_deletion_allowed();
		$errors  = Network::each_site(
			static function () use ( $allowed ): void {
				self::uninstall_site( $allowed );
			}
		);
		if ( ! $errors ) {
			delete_site_option( 'form_captain_network' );
		}
		return $errors;
	}

	/** True when the current site enabled and confirmed the deletion of its data. */
	public static function site_confirmed_deletion(): bool {
		$settings = get_option( 'form_captain_settings', array() );
		return is_array( $settings )
			&& ! empty( $settings['privacy']['delete_data_on_uninstall'] )
			&& ! empty( $settings['privacy']['delete_data_confirmed_gmt'] );
	}

	/** Uninstalls the current site: schedules always, data only after the site's own confirmation. */
	public static function uninstall_site( bool $deletion_allowed = true ): void {
		global $wpdb;
		$delete = $deletion_allowed && self::site_confirmed_deletion();

		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_unschedule_hook( 'form_captain_entry_import_cleanup' );

		// Temporary entry import uploads are always removed (never kept data).
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . 'form-captain-private/imports/*.csv.tmp' ) as $file ) {
				wp_delete_file( (string) $file );
			}
		}

		if ( ! $delete ) {
			return;
		}

		foreach ( Tables::ALL as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		// Short-lived transients (No-JS results with field values, preview tokens, notices, counters).
		foreach ( array( 'fc\\_', 'form\\_captain\\_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\\_transient\\_' . $prefix . '%', '\\_transient\\_timeout\\_' . $prefix . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		// Per-form records with an ID suffix (import reports).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'form\\_captain\\_import\\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Reference number counters (one per year).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'form\\_captain\\_entry\\_seq\\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_multisite() ) {
			// User meta is shared by all sites of a network; there it stays.
			delete_metadata( 'user', 0, 'form_captain_pro_hints_dismissed', '', true );
		}
		Capabilities::uninstall();
	}
}
