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

	public const TARGET_VERSION = 3;
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
			2 => array( $this, 'migrate_v2' ),
			3 => array( $this, 'migrate_v3' ),
		);
	}

	public function installed_version(): int {
		return (int) get_option( self::OPTION_VERSION, 0 );
	}

	public function needs_migration(): bool {
		return $this->installed_version() < self::TARGET_VERSION;
	}

	/** Seconds after which a migration without result counts as interrupted. */
	public const STALE_AFTER = 600;

	/**
	 * True while a migration step is running (state recorded, no error, not stale).
	 * Submissions are refused meanwhile (P6-11) so no entry is written to a half-migrated schema.
	 */
	public function is_running(): bool {
		$state = get_option( self::OPTION_STATE, null );
		if ( ! is_array( $state ) || ! empty( $state['error'] ) || empty( $state['started_gmt'] ) ) {
			return false;
		}
		return ( Clock::timestamp() - (int) strtotime( $state['started_gmt'] . ' UTC' ) ) < self::STALE_AFTER;
	}

	/** True when a migration started but neither finished nor recorded an error in time (crash/timeout). */
	public function was_interrupted(): bool {
		$state = get_option( self::OPTION_STATE, null );
		if ( ! is_array( $state ) || ! empty( $state['error'] ) || empty( $state['started_gmt'] ) ) {
			return false;
		}
		return ! $this->is_running();
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
		if ( $this->is_running() ) {
			return false; // Another request is migrating right now.
		}
		if ( $this->was_interrupted() ) {
			// Steps are idempotent (dbDelta), so an interrupted step is simply repeated.
			\FormCaptain\Support\Logger::log( 'warning', 'migration_resumed', 'Interrupted migration is resumed.' );
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
	 * v2: fc_entries.consent_withdrawn_gmt + index (consent withdrawal workflow).
	 * dbDelta() only adds what is missing, so the step is idempotent.
	 */
	public function migrate_v2(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::definitions()[ Tables::ENTRIES ] );
	}

	/**
	 * v3: fc_entries.entry_number + index (reference numbers, SET-FC-29). Idempotent like v2.
	 */
	public function migrate_v3(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::definitions()[ Tables::ENTRIES ] );
	}

	/**
	 * Throws if a table or column expected after $version is missing.
	 */
	private function verify( int $version ): void {
		global $wpdb;
		if ( $version >= 3 ) {
			$entries = Tables::name( Tables::ENTRIES );
			$column  = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$entries} LIKE %s", 'entry_number' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 'entry_number' !== $column ) {
				throw new \RuntimeException( 'missing_column:entries.entry_number' );
			}
		}
		if ( $version >= 2 ) {
			$entries = Tables::name( Tables::ENTRIES );
			$column  = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$entries} LIKE %s", 'consent_withdrawn_gmt' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 'consent_withdrawn_gmt' !== $column ) {
				throw new \RuntimeException( 'missing_column:entries.consent_withdrawn_gmt' );
			}
		}
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
