<?php
/**
 * Plugin Name:       Form Captain
 * Plugin URI:        https://github.com/hastla007/wp-formmaster
 * Description:       Build forms visually, manage submissions securely inside WordPress and connect them to the Captain Suite.
 * Version:           1.0.0-dev
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Author:            Captain Suite
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       form-captain
 * Domain Path:       /languages
 *
 * @package FormCaptain
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FORM_CAPTAIN_VERSION', '1.0.0-dev' );
define( 'FORM_CAPTAIN_FILE', __FILE__ );
define( 'FORM_CAPTAIN_DIR', __DIR__ . '/' );
define( 'FORM_CAPTAIN_MIN_WP', '7.0' );
define( 'FORM_CAPTAIN_MIN_PHP', '8.5' );
define( 'FORM_CAPTAIN_CONTRACT_VERSION', '1.4.0' );

if ( version_compare( PHP_VERSION, FORM_CAPTAIN_MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), FORM_CAPTAIN_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: 1: WordPress version, 2: PHP version */
					__( 'Form Captain requires WordPress %1$s and PHP %2$s or newer. The plugin is inactive.', 'form-captain' ),
					FORM_CAPTAIN_MIN_WP,
					FORM_CAPTAIN_MIN_PHP
				)
			) . '</p></div>';
		}
	);
	return;
}

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) && ! class_exists( \FormCaptain\Plugin::class, false ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/src/Autoloader.php';
\FormCaptain\Autoloader::register();
require_once __DIR__ . '/includes/functions.php';

register_activation_hook( __FILE__, array( \FormCaptain\Install\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \FormCaptain\Install\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \FormCaptain\Plugin::class, 'boot' ), 5 );
