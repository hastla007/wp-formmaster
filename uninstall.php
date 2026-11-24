<?php
/**
 * Uninstall (P5-06): keeps all data unless the administrator explicitly enabled
 * "Delete all Form Captain data on uninstall" and confirmed it in the settings.
 * In a network each site follows its own setting (SET-FC-44); see Install\Uninstaller.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';
\FormCaptain\Autoloader::register();

\FormCaptain\Install\Uninstaller::run();
