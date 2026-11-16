<?php
/**
 * Activation: run migrations, register capabilities and schedules. Never deletes data.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Install;

use FormCaptain\Database\Migrator;
use FormCaptain\Jobs\Scheduler;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Secret;

final class Activator {

	public static function activate(): void {
		( new Migrator() )->migrate();
		Capabilities::install();
		Secret::key( 'init' );
		Settings::ensure_defaults();
		Scheduler::schedule();
		add_option( 'form_captain_activation_redirect', 1, '', false );
	}

	/** Runs on every load when versions differ (upgrade path without re-activation). */
	public static function maybe_upgrade(): void {
		$migrator = new Migrator();
		if ( $migrator->needs_migration() ) {
			$migrator->migrate();
			Capabilities::install();
			Settings::ensure_defaults();
		}
	}
}
