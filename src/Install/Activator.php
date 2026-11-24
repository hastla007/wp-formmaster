<?php
/**
 * Activation: run migrations, register capabilities and schedules. Never deletes data.
 *
 * Network activation (SET-FC-44) only records the activation mode: every site installs itself
 * lazily on its first request (`maybe_upgrade()`), so a large network never times out.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Install;

use FormCaptain\Database\Migrator;
use FormCaptain\Jobs\Scheduler;
use FormCaptain\Network\Network;
use FormCaptain\Network\NetworkSettings;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsMigrations;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Secret;

final class Activator {

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			NetworkSettings::update(
				array(
					'activation'    => 'network',
					'activated_gmt' => Clock::now(),
				)
			);
			return;
		}
		( new Migrator() )->migrate();
		Capabilities::install();
		Secret::key( 'init' );
		Settings::ensure_defaults();
		SettingsMigrations::run();
		Scheduler::schedule();
		delete_option( Network::OPTION_PENDING );
		add_option( 'form_captain_activation_redirect', 1, '', false );
	}

	/**
	 * Runs on every load when versions differ (upgrade path without re-activation). On a site of
	 * a network-active installation this is also the lazy first installation.
	 */
	public static function maybe_upgrade(): void {
		$migrator = new Migrator();
		if ( $migrator->needs_migration() ) {
			if ( $migrator->migrate() ) {
				delete_option( Network::OPTION_PENDING );
			}
			Capabilities::install();
			Settings::ensure_defaults();
			SettingsMigrations::run();
			return;
		}
		SettingsMigrations::run();
		$state = get_option( Migrator::OPTION_STATE, null );
		$error = is_array( $state ) ? (string) ( $state['error'] ?? '' ) : '';
		if ( $migrator->installed_version() > Migrator::TARGET_VERSION ) {
			// Downgrade: record it once so the system status can explain it (data stays untouched).
			if ( 'schema_newer_than_plugin' !== $error ) {
				$migrator->migrate();
			}
		} elseif ( 'schema_newer_than_plugin' === $error ) {
			// The newer version is back: the downgrade notice no longer applies.
			delete_option( Migrator::OPTION_STATE );
		}
	}

	/**
	 * Migrates the current site now (network migration, `wp form-captain migrate`).
	 * Returns null on success or a short error code without payload data.
	 */
	public static function migrate_site(): ?string {
		$migrator = new Migrator();
		if ( $migrator->installed_version() > Migrator::TARGET_VERSION ) {
			$migrator->migrate();
			return 'schema_newer_than_plugin';
		}
		if ( ! $migrator->needs_migration() ) {
			SettingsMigrations::run();
			return null;
		}
		if ( $migrator->is_running() ) {
			return 'migration_running';
		}
		if ( ! $migrator->migrate() ) {
			$state = get_option( Migrator::OPTION_STATE, null );
			$error = is_array( $state ) ? (string) ( $state['error'] ?? '' ) : '';
			return '' !== $error ? $error : 'migration_failed';
		}
		Capabilities::install();
		Secret::key( 'init' );
		Settings::ensure_defaults();
		SettingsMigrations::run();
		Scheduler::schedule();
		delete_option( Network::OPTION_PENDING );
		return null;
	}
}
