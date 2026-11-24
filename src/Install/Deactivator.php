<?php
/**
 * Deactivation: stop scheduled events only. Data, jobs and capabilities stay.
 *
 * Network deactivation (SET-FC-44) removes the cron hooks of every site in batches; a site that
 * still has Form Captain activated on its own keeps its schedules.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Install;

use FormCaptain\Jobs\Scheduler;
use FormCaptain\Network\Network;
use FormCaptain\Network\NetworkSettings;

final class Deactivator {

	public static function deactivate( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			Scheduler::unschedule();
			return;
		}
		NetworkSettings::update(
			array(
				'activation'    => '',
				'activated_gmt' => '',
			)
		);
		$basename = plugin_basename( FORM_CAPTAIN_FILE );
		Network::each_site(
			static function () use ( $basename ): void {
				if ( in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) {
					return; // Activated on this site itself: keeps running.
				}
				Scheduler::unschedule();
				wp_clear_scheduled_hook( Network::HOOK_INSTALL );
			}
		);
	}
}
