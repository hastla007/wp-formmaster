<?php
/**
 * Plugin Name:       Form Captain Reference Connector (example)
 * Description:       EXAMPLE ONLY – shows how a connector plugin integrates with Form Captain (contract v1). Simulates a local ticket service; sends nothing anywhere.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Requires Plugins:  form-captain
 * Author:            Captain Suite
 * License:           GPL-2.0-or-later
 * Text Domain:       form-captain-reference-connector
 *
 * @package FormCaptainReferenceConnector
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FCRC_VERSION', '1.0.0' );
define( 'FCRC_DIR', __DIR__ . '/' );

/**
 * Boots the connector once Form Captain is loaded. Never touches Form Captain internals:
 * only the public filter form_captain_connectors_v1 and form_captain_register_action_v1().
 */
function fcrc_boot(): void {
	// Form Captain missing or too old: stay silent, the plugin simply does nothing.
	if ( ! function_exists( 'form_captain_contract_version' ) || ! interface_exists( \FormCaptain\Integrations\ConnectorInterface::class ) ) {
		return;
	}
	require_once FCRC_DIR . 'includes/class-reference-connector.php';
	if ( ! has_filter( 'form_captain_connectors_v1', 'fcrc_register_connector' ) ) {
		add_filter( 'form_captain_connectors_v1', 'fcrc_register_connector' );
	}
	if ( ! has_action( 'form_captain_entry_deleted_v1', 'fcrc_entry_deleted' ) ) {
		add_action( 'form_captain_entry_deleted_v1', 'fcrc_entry_deleted', 10, 3 );
	}
}

/**
 * @param array<string,object> $connectors
 * @return array<string,object>
 */
function fcrc_register_connector( array $connectors ): array {
	return $connectors + array( 'reference' => new \FormCaptainReferenceConnector\Reference_Connector() );
}

function fcrc_entry_deleted( int $entry_id, int $form_id, string $reason ): void {
	\FormCaptainReferenceConnector\Reference_Connector::on_entry_deleted( $entry_id, $form_id, $reason );
}

add_action( 'form_captain_loaded_v1', 'fcrc_boot' );
if ( did_action( 'form_captain_loaded_v1' ) ) {
	fcrc_boot();
}
