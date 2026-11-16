<?php
/**
 * Shared helpers for the security suite (P5-07, P5-08, P5-09, P5-11, P5-15).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.Security.NonceVerification

namespace FormCaptain\Tests\Integration\Security;

require_once __DIR__ . '/RedirectIntercepted.php';

use FormCaptain\Database\Tables;
use FormCaptain\Install\Capabilities;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Secret;

trait SecurityFixtures {

	/** Roles below administrator that must never reach Form Captain data by default (rechte.md). */
	protected static function low_roles(): array {
		return array( 'anonymous', 'subscriber', 'author', 'editor' );
	}

	protected function login_as( string $role ): int {
		if ( 'anonymous' === $role ) {
			wp_set_current_user( 0 );
			return 0;
		}
		return $this->as_role( $role );
	}

	/** Stops wp_safe_redirect()/wp_redirect() before their caller can exit. */
	protected function intercept_redirects(): void {
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new RedirectIntercepted( (string) $location, (int) $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test control flow, never output.
			},
			PHP_INT_MAX,
			2
		);
	}

	/**
	 * Fingerprint of everything an admin action could change: plugin tables (without log and
	 * rate-limit buckets), plugin options, role capabilities and plugin user meta.
	 */
	protected function state(): string {
		global $wpdb;
		Settings::all();
		Secret::key( 'warmup' );
		$parts = array();
		foreach ( Tables::ALL as $table ) {
			if ( in_array( $table, array( Tables::LOG, Tables::RATE_LIMITS ), true ) ) {
				continue;
			}
			$parts[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . Tables::name( $table ) . ' ORDER BY id', ARRAY_A );
		}
		$parts['options'] = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'form\\_captain\\_%' AND option_name NOT LIKE '%transient%' ORDER BY option_name", ARRAY_A );
		$caps             = array();
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			foreach ( Capabilities::ALL as $cap ) {
				$caps[ $slug ][ $cap ] = $role->has_cap( $cap );
			}
		}
		$parts['caps']  = $caps;
		$parts['umeta'] = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'form\\_captain\\_%' ORDER BY umeta_id", ARRAY_A );
		$parts['pages'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" );
		return md5( (string) wp_json_encode( $parts ) );
	}

	/** @return array<int,array<string,mixed>> all fc_log rows */
	protected function log_rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Tables::name( Tables::LOG ) . ' ORDER BY id', ARRAY_A ) ?: array();
	}

	protected function clear_log(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::name( Tables::LOG ) );
	}

	protected function fresh_rest_server(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}

	protected function drop_rest_server(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	}
}
