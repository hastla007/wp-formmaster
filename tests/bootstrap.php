<?php
/**
 * Integration test bootstrap (WordPress test suite + MariaDB/MySQL).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$form_captain_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ?: dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $form_captain_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/form-captain.php';
		// Optional second plugin for two-plugin contract tests (P8-37).
		$connector = dirname( __DIR__ ) . '/connectors/form-captain-reference-connector/form-captain-reference-connector.php';
		if ( getenv( 'FC_LOAD_REFERENCE_CONNECTOR' ) && is_readable( $connector ) ) {
			require $connector;
		}
	}
);
tests_add_filter(
	'init',
	static function (): void {
		\FormCaptain\Install\Activator::activate();
	},
	2
);

require $form_captain_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/integration/TestCase.php';
