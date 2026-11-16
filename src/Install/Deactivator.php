<?php
/**
 * Deactivation: stop scheduled events only. Data, jobs and capabilities stay.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Install;

use FormCaptain\Jobs\Scheduler;

final class Deactivator {

	public static function deactivate(): void {
		Scheduler::unschedule();
	}
}
