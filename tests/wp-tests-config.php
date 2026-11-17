<?php
/**
 * WordPress test-suite configuration, read by wp-phpunit.
 *
 * @package FormCaptain
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride

$form_captain_wp_version = getenv( 'WP_VERSION' ) ?: '7.0.8';
$form_captain_core       = getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__ ) . '/vendor/wordpress/' . $form_captain_wp_version;

define( 'ABSPATH', rtrim( $form_captain_core, '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wp_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'wp' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'wp' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'fc-test' );
define( 'SECURE_AUTH_KEY', 'fc-test' );
define( 'LOGGED_IN_KEY', 'fc-test' );
define( 'NONCE_KEY', 'fc-test' );
define( 'AUTH_SALT', 'fc-test' );
define( 'SECURE_AUTH_SALT', 'fc-test' );
define( 'LOGGED_IN_SALT', 'fc-test' );
define( 'NONCE_SALT', 'fc-test' );
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Form Captain Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
