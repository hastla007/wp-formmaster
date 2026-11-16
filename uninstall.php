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

if ( ! $form_captain_delete ) {
	return;
}

require_once __DIR__ . '/src/Autoloader.php';
\FormCaptain\Autoloader::register();

global $wpdb;
foreach ( \FormCaptain\Database\Tables::ALL as $form_captain_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . $form_captain_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
}
foreach ( array( 'form_captain_settings', 'form_captain_db_version', 'form_captain_migration_state', 'form_captain_secret', 'form_captain_connectors', 'form_captain_activation_redirect', 'form_captain_worker_lock' ) as $form_captain_option ) {
	delete_option( $form_captain_option );
}
\FormCaptain\Install\Capabilities::uninstall();
