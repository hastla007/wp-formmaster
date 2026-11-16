<?php
/**
 * Versioned, repeatable schema migrations (P1-05).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Database;

use FormCaptain\Support\Clock;

final class Migrator {

	public const TARGET_VERSION = 1;
	public const OPTION_VERSION = 'form_captain_db_version';
	public const OPTION_STATE   = 'form_captain_migration_state';

	/**
	 * Map of version => callable performing the step. Steps must be idempotent.
	 *
	 * @return array<int,callable():void>
	 */
	private function steps(): array {
		return array(
			1 => array( $this, 'install_v1' ),
		);
	}

	public function installed_version(): int {
		return (int) get_option( self::OPTION_VERSION, 0 );
	}

	public function needs_migration(): bool {
		return $this->installed_version() < self::TARGET_VERSION;
	}

	/**
	 * Runs all pending migrations. Returns true on success. On failure the stored
	 * version stays at the last successful step and the error is recorded.
	 */
	public function migrate(): bool {
		$current = $this->installed_version();
		if ( $current > self::TARGET_VERSION ) {
			// Downgrade: never touch data; record state for system status.
			update_option( self::OPTION_STATE, array( 'error' => 'schema_newer_than_plugin', 'installed' => $current ), false );
			return false;
		}
		foreach ( $this->steps() as $version => $step ) {
			if ( $version <= $current ) {
				continue;
			}
			update_option(
				self::OPTION_STATE,
				array(
					'target'      => $version,
					'started_gmt' => Clock::now(),
					'error'       => null,
				),
				false
			);
			try {
				$step();
				$this->verify( $version );
			} catch ( \Throwable $e ) {
				update_option(
					self::OPTION_STATE,
					array(
						'target'      => $version,
						'started_gmt' => Clock::now(),
						'error'       => 'migration_failed',
						'detail'      => substr( $e->getMessage(), 0, 200 ),
					),
					false
				);
				return false;
			}
			update_option( self::OPTION_VERSION, $version, true );
			$current = $version;
		}
		delete_option( self::OPTION_STATE );
		return true;
	}

	public function install_v1(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Throws if a table expected after $version is missing.
	 */
	private function verify( int $version ): void {
		global $wpdb;
		if ( $version >= 1 ) {
			foreach ( Tables::ALL as $table ) {
				$name  = Tables::name( $table );
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
				if ( $found !== $name ) {
					throw new \RuntimeException( 'missing_table:' . esc_html( $table ) );
				}
			}
		}
	}

	/**
	 * Integrity report for system status (P1-16).
	 *
	 * @return array<string,mixed>
	 */
	public function integrity_report(): array {
		global $wpdb;
		$report = array(
			'installed_version' => $this->installed_version(),
			'target_version'    => self::TARGET_VERSION,
			'state'             => get_option( self::OPTION_STATE, null ),
			'engines'           => array(),
			'orphan_values'     => 0,
		);
		foreach ( Tables::ALL as $table ) {
			$name                        = Tables::name( $table );
			$engine                      = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name ) );
			$report['engines'][ $table ] = $engine ? (string) $engine : 'missing';
		}
		$values                  = Tables::name( Tables::ENTRY_VALUES );
		$entries                 = Tables::name( Tables::ENTRIES );
		$report['orphan_values'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$values} v LEFT JOIN {$entries} e ON e.id = v.entry_id WHERE e.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $report;
	}
}
