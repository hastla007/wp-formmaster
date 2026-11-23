<?php
/**
 * Uninstall (P5-06): keeps all data unless the administrator explicitly enabled
 * "Delete all Form Captain data on uninstall" and confirmed it in the settings.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$form_captain_settings = get_option( 'form_captain_settings', array() );
$form_captain_delete   = is_array( $form_captain_settings )
	&& ! empty( $form_captain_settings['privacy']['delete_data_on_uninstall'] )
	&& ! empty( $form_captain_settings['privacy']['delete_data_confirmed_gmt'] );

wp_clear_scheduled_hook( 'form_captain_process_jobs' );
wp_clear_scheduled_hook( 'form_captain_daily_cleanup' );
wp_clear_scheduled_hook( 'form_captain_hourly_cleanup' );
wp_unschedule_hook( 'form_captain_entry_import_cleanup' );

// Temporary entry import uploads are always removed (never kept data).
$form_captain_uploads = wp_upload_dir( null, false );
if ( ! empty( $form_captain_uploads['basedir'] ) ) {
	foreach ( (array) glob( trailingslashit( $form_captain_uploads['basedir'] ) . 'form-captain-private/imports/*.csv.tmp' ) as $form_captain_file ) {
		wp_delete_file( (string) $form_captain_file );
	}
}

if ( ! $form_captain_delete ) {
	return;
}

require_once __DIR__ . '/src/Autoloader.php';
\FormCaptain\Autoloader::register();

global $wpdb;
foreach ( \FormCaptain\Database\Tables::ALL as $form_captain_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . $form_captain_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}
$form_captain_options = array(
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
	'form_captain_cleanup_failures',
	'form_captain_last_cleanup',
	'form_captain_last_cleanup_at',
	'form_captain_entry_import_runs',
);
foreach ( $form_captain_options as $form_captain_option ) {
	delete_option( $form_captain_option );
}
// Short-lived transients (No-JS results with field values, preview tokens, notices, counters).
foreach ( array( 'fc\\_', 'form\\_captain\\_' ) as $form_captain_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\\_transient\\_' . $form_captain_prefix . '%', '\\_transient\\_timeout\\_' . $form_captain_prefix . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
// Per-form records with an ID suffix (import reports).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'form\\_captain\\_import\\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// Reference number counters (one per year).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'form\\_captain\\_entry\\_seq\\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_metadata( 'user', 0, 'form_captain_pro_hints_dismissed', '', true );
\FormCaptain\Install\Capabilities::uninstall();
