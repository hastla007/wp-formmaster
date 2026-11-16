<?php
/**
 * Read-only system diagnostics (P1-11, P5-10, P5-22, P6-19, P9-14).
 *
 * Contains versions, schema/integrity, queue health, aggregated error codes and correlation IDs.
 * Never contains field values, e-mail addresses, IP addresses, tokens or secrets.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Database\Migrator;
use FormCaptain\Database\Tables;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Jobs\Scheduler;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Support\Clock;

final class SystemStatus {

	public const QUEUE_WARN_SECONDS = 900;

	public function __construct( private JobRepository $jobs, private ConnectorRegistry $registry ) {}

	/** @return array<string,mixed> */
	public function collect(): array {
		global $wpdb;
		$migrator  = new Migrator();
		$integrity = $migrator->integrity_report();
		$health    = $this->jobs->health();
		$cron_off  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$since     = Clock::plus( -DAY_IN_SECONDS );
		$state     = $integrity['state'];

		$connectors = array();
		foreach ( $this->registry->all() as $key => $c ) {
			$connectors[ $key ] = array(
				'version'  => (string) $c['version'],
				'contract' => (string) $c['contract'],
				'state'    => (string) $c['state'],
				'code'     => (string) $c['code'],
			);
		}

		return array(
			'environment'   => array(
				'form_captain' => FORM_CAPTAIN_VERSION,
				'contract'     => FORM_CAPTAIN_CONTRACT_VERSION,
				'wordpress'    => (string) get_bloginfo( 'version' ),
				'php'          => PHP_VERSION,
				'database'     => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version(),
				'multisite'    => is_multisite(),
				'intl'         => class_exists( 'Normalizer' ),
				'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
			),
			'schema'        => array(
				'installed'     => (int) $integrity['installed_version'],
				'target'        => (int) $integrity['target_version'],
				'ok'            => (int) $integrity['installed_version'] === (int) $integrity['target_version'] && empty( $state['error'] ),
				'error'         => is_array( $state ) ? (string) ( $state['error'] ?? '' ) : '',
				'engines'       => (array) $integrity['engines'],
				'orphan_values' => (int) $integrity['orphan_values'],
			),
			'queue'         => array(
				'counts'            => (array) $health['counts'],
				'oldest_due_gmt'    => $health['oldest_due_gmt'],
				'oldest_due_age_s'  => (int) $health['oldest_due_age_s'],
				'delayed'           => (int) $health['oldest_due_age_s'] > self::QUEUE_WARN_SECONDS,
				'wp_cron_disabled'  => $cron_off,
				'next_worker_run'   => self::next_run( Scheduler::HOOK_JOBS ),
				'next_cleanup_run'  => self::next_run( Scheduler::HOOK_CLEANUP ),
				'last_cleanup'      => self::option_time( 'form_captain_last_cleanup_at' ),
				'last_cleanup_run'  => self::cleanup_stats(),
				'cleanup_failures'  => (int) $this->retention( 'persistent_failures', 0 ),
				'restore_suspected' => (bool) $this->retention( 'restore_suspected', false ),
				'cli_command'       => 'wp form-captain jobs run',
			),
			'mail'          => array(
				'failed_24h'   => $this->jobs->error_summary( 'mail.', $since ),
				'log_24h'      => $this->log_codes( 'mail', $since ),
				'domain_match' => ! SettingsSchema::from_domain_mismatch(),
				'format'       => (string) Settings::get( 'email.format' ),
			),
			'integrations'  => array(
				'connectors' => $connectors,
				'failed_24h' => $this->jobs->error_summary( 'connector.', $since ),
				'suite'      => self::suite_plugins(),
				'pro_active' => (bool) apply_filters( 'form_captain_pro_active', false ),
			),
			'settings'      => array(
				'retention'        => (string) Settings::get( 'privacy.retention' ),
				'trash_days'       => (int) Settings::get( 'privacy.trash_days' ),
				'erasure_mode'     => (string) Settings::get( 'privacy.erasure_mode' ),
				'delete_on_uninst' => (bool) Settings::get( 'privacy.delete_data_on_uninstall' ),
				'appearance'       => (string) Settings::get( 'appearance.mode' ),
				'honeypot'         => (bool) Settings::get( 'spam.honeypot' ),
				'min_seconds'      => (int) Settings::get( 'spam.min_seconds' ),
				'rate_limit'       => (int) Settings::get( 'spam.rate_limit' ),
				'blocklist_lines'  => count( array_filter( explode( "\n", (string) Settings::get( 'spam.blocklist' ) ) ) ),
				'captcha'          => (string) Settings::get( 'spam.captcha_provider' ),
				'secret_present'   => is_string( get_option( 'form_captain_secret' ) ),
			),
			'recent_errors' => $this->recent_errors(),
		);
	}

	/** Calls a RetentionService method (agent C) when available. */
	private function retention( string $method, mixed $fallback ): mixed {
		$class = 'FormCaptain\\Service\\RetentionService';
		if ( ! class_exists( $class ) ) {
			return $fallback;
		}
		try {
			$service = \FormCaptain\Plugin::instance()->get( $class );
			return method_exists( $service, $method ) ? $service->{$method}() : $fallback;
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	/** Numbers of the last cleanup run (counts only). */
	private static function cleanup_stats(): array {
		$stats = get_option( 'form_captain_last_cleanup', array() );
		$out   = array();
		foreach ( is_array( $stats ) ? $stats : array() as $k => $v ) {
			if ( is_string( $k ) && ( is_int( $v ) || is_numeric( $v ) ) ) {
				$out[ $k ] = (int) $v;
			}
		}
		return $out;
	}

	private static function next_run( string $hook ): ?string {
		$ts = wp_next_scheduled( $hook );
		return $ts ? gmdate( 'Y-m-d H:i:s', (int) $ts ) : null;
	}

	private static function option_time( string $option ): ?string {
		$v = get_option( $option );
		if ( is_numeric( $v ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $v );
		}
		return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $v ) ? substr( $v, 0, 19 ) : null;
	}

	/**
	 * Aggregated log codes (warnings/errors) since $since whose code contains $needle.
	 *
	 * @return array<string,int>
	 */
	private function log_codes( string $needle, string $since ): array {
		global $wpdb;
		$table = Tables::name( Tables::LOG );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT code, COUNT(*) c FROM {$table} WHERE level IN ('warning','error') AND created_gmt >= %s AND code LIKE %s GROUP BY code ORDER BY c DESC LIMIT 20", $since, '%' . $wpdb->esc_like( $needle ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (string) $r['code'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Last warnings/errors: time, level, code, correlation ID and object IDs – never the message.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function recent_errors( int $limit = 20 ): array {
		global $wpdb;
		$table = Tables::name( Tables::LOG );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT created_gmt, level, code, correlation_id, form_id, entry_id, job_id FROM {$table} WHERE level IN ('warning','error') ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows ?: array() as $r ) {
			$out[] = array(
				'time'        => (string) $r['created_gmt'],
				'level'       => (string) $r['level'],
				'code'        => (string) $r['code'],
				'correlation' => (string) $r['correlation_id'],
				'form_id'     => $r['form_id'] ? (int) $r['form_id'] : null,
				'entry_id'    => $r['entry_id'] ? (int) $r['entry_id'] : null,
				'job_id'      => $r['job_id'] ? (int) $r['job_id'] : null,
			);
		}
		return $out;
	}

	/**
	 * Active plugins that belong to the Captain Suite (slug and version only).
	 *
	 * @return array<string,string>
	 */
	public static function suite_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		$out = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$slug = dirname( (string) $file );
			if ( str_contains( $slug, 'captain' ) && 'form-captain' !== $slug ) {
				$out[ $slug ] = (string) ( $all[ $file ]['Version'] ?? '' );
			}
		}
		return $out;
	}

	/** Problems for notices, Site Health and CLI: code => translated message. */
	public function problems( ?array $data = null ): array {
		$d   = $data ?? $this->collect();
		$out = array();
		if ( ! $d['schema']['ok'] ) {
			$out['schema'] = __( 'The database schema is not up to date. Deactivate and activate Form Captain or check the error log.', 'form-captain' );
		}
		foreach ( $d['schema']['engines'] as $table => $engine ) {
			if ( 'missing' === $engine ) {
				$out['schema_missing'] = __( 'A Form Captain database table is missing.', 'form-captain' );
			} elseif ( 'InnoDB' !== $engine ) {
				$out['schema_engine'] = __( 'Some Form Captain tables do not use InnoDB. Transactions are not available; integrity is checked here instead.', 'form-captain' );
			}
		}
		if ( $d['schema']['orphan_values'] > 0 ) {
			$out['orphans'] = __( 'There are stored values without a submission. Run the cleanup.', 'form-captain' );
		}
		if ( $d['queue']['delayed'] ) {
			$out['queue'] = $d['queue']['wp_cron_disabled']
				? __( 'Jobs are waiting longer than 15 minutes and WP-Cron is disabled. Run "wp form-captain jobs run" every minute from a system cron.', 'form-captain' )
				: __( 'Jobs are waiting longer than 15 minutes. WP-Cron may not run reliably on this site; consider a system cron with "wp form-captain jobs run".', 'form-captain' );
		}
		if ( (int) ( $d['queue']['counts']['dead'] ?? 0 ) > 0 ) {
			$out['dead'] = __( 'Some jobs failed permanently (dead-letter). Review them under Integrations or Entries and retry them.', 'form-captain' );
		}
		if ( ! empty( $d['queue']['restore_suspected'] ) ) {
			$out['restore'] = __( 'It looks like older data was restored. Expired submissions will be cleaned up now. Please repeat erasure requests completed after the backup date.', 'form-captain' );
		}
		if ( ! empty( $d['queue']['cleanup_failures'] ) ) {
			$out['cleanup'] = __( 'Some submissions could not be deleted by the cleanup three times in a row. Check the error log and delete them manually if needed.', 'form-captain' );
		}
		if ( array_sum( $d['mail']['failed_24h'] ) > 0 ) {
			$out['mail'] = __( 'E-mails failed in the last 24 hours. Send a test e-mail and check your mail configuration (e.g. an SMTP plugin).', 'form-captain' );
		}
		return $out;
	}

	/**
	 * Copyable support report without personal data (P5-22, P6-19).
	 */
	public function report_text( ?array $data = null ): string {
		$d     = $data ?? $this->collect();
		$lines = array( '### Form Captain diagnostics ###', 'generated_gmt: ' . Clock::now() );
		foreach ( array( 'environment', 'schema', 'queue', 'mail', 'integrations', 'settings' ) as $section ) {
			$lines[] = '';
			$lines[] = '## ' . $section;
			foreach ( $d[ $section ] as $k => $v ) {
				$lines[] = $k . ': ' . self::scalar( $v );
			}
		}
		$lines[] = '';
		$lines[] = '## recent_errors (time | level | code | correlation | form/entry/job)';
		foreach ( $d['recent_errors'] as $e ) {
			$lines[] = sprintf( '%s | %s | %s | %s | %s/%s/%s', $e['time'], $e['level'], $e['code'], $e['correlation'], $e['form_id'] ?? '-', $e['entry_id'] ?? '-', $e['job_id'] ?? '-' );
		}
		$lines[] = '';
		$lines[] = '## problems';
		foreach ( array_keys( $this->problems( $d ) ) as $code ) {
			$lines[] = '- ' . $code;
		}
		return self::scrub( implode( "\n", $lines ) );
	}

	private static function scalar( mixed $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'yes' : 'no';
		}
		if ( null === $v ) {
			return '-';
		}
		if ( is_array( $v ) ) {
			$parts = array();
			foreach ( $v as $k => $item ) {
				$parts[] = ( is_int( $k ) ? '' : $k . '=' ) . ( is_array( $item ) ? '{' . self::scalar( $item ) . '}' : self::scalar( $item ) );
			}
			return $parts ? implode( ', ', $parts ) : '-';
		}
		return (string) $v;
	}

	/** Final safety net: removes anything that looks like an e-mail or IP address. */
	public static function scrub( string $text ): string {
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text );
		$text = (string) preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $text );
		return (string) preg_replace( '/\b(?:[0-9a-f]{1,4}:){4,7}[0-9a-f]{1,4}\b/i', '[ip]', $text );
	}
}
