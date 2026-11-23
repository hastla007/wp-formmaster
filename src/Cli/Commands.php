<?php
/**
 * WP-CLI commands (ADR 0004): `wp form-captain jobs run|list|retry`, `cleanup`, `status`,
 * `settings list|get|set|reset|export|import` (SET-FC-14).
 *
 * Logic lives in public methods returning data so it can be tested without WP-CLI.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Cli;

use FormCaptain\Admin\SystemStatus;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Plugin;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\ChangeLog;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Settings\SettingsTransfer;

final class Commands {

	public function __construct( private Plugin $plugin, private JobRepository $jobs ) {}

	public function register( Plugin $plugin ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command(
			'form-captain jobs run',
			array( $this, 'cli_jobs_run' ),
			array(
				'shortdesc' => 'Processes due Form Captain jobs (mails, integrations, cleanup).',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'limit',
						'optional' => true,
						'default'  => 20,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain jobs list',
			array( $this, 'cli_jobs_list' ),
			array(
				'shortdesc' => 'Lists jobs without payload values.',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'status',
						'optional' => true,
						'options'  => JobRepository::STATUSES,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'type',
						'optional' => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain jobs retry',
			array( $this, 'cli_jobs_retry' ),
			array(
				'shortdesc' => 'Re-queues a failed, dead or cancelled job.',
				'synopsis'  => array(
					array(
						'type' => 'positional',
						'name' => 'id',
					),
				),
			)
		);
		\WP_CLI::add_command( 'form-captain cleanup', array( $this, 'cli_cleanup' ), array( 'shortdesc' => 'Runs retention, trash and log cleanup now.' ) );
		\WP_CLI::add_command( 'form-captain status', array( $this, 'cli_status' ), array( 'shortdesc' => 'Prints the diagnostics report (no personal data).' ) );
		$this->register_settings_commands();
	}

	private function register_settings_commands(): void {
		$yes    = array(
			'type'        => 'flag',
			'name'        => 'yes',
			'optional'    => true,
			'description' => 'Confirms changes that need a confirmation (retention period, external captcha, data deletion on uninstall, reset).',
		);
		$format = array(
			'type'     => 'assoc',
			'name'     => 'format',
			'optional' => true,
			'default'  => 'table',
			'options'  => array( 'table', 'json', 'csv', 'yaml' ),
		);
		\WP_CLI::add_command(
			'form-captain settings list',
			array( $this, 'cli_settings_list' ),
			array(
				'shortdesc' => 'Lists all global settings with value and default (secrets masked).',
				'synopsis'  => array( $format ),
			)
		);
		\WP_CLI::add_command(
			'form-captain settings get',
			array( $this, 'cli_settings_get' ),
			array(
				'shortdesc' => 'Prints one setting, e.g. privacy.retention.',
				'synopsis'  => array(
					array(
						'type' => 'positional',
						'name' => 'key',
					),
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain settings set',
			array( $this, 'cli_settings_set' ),
			array(
				'shortdesc' => 'Changes one setting with the same checks as the settings screen.',
				'synopsis'  => array(
					array(
						'type' => 'positional',
						'name' => 'key',
					),
					array(
						'type' => 'positional',
						'name' => 'value',
					),
					$yes,
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain settings reset',
			array( $this, 'cli_settings_reset' ),
			array(
				'shortdesc' => 'Resets one section (general, appearance, email, privacy, spam) or all sections to the defaults.',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'section',
						'optional' => true,
					),
					array(
						'type'     => 'flag',
						'name'     => 'all',
						'optional' => true,
					),
					$yes,
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain settings export',
			array( $this, 'cli_settings_export' ),
			array(
				'shortdesc' => 'Writes the settings as JSON (without secrets) to STDOUT or a file.',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'file',
						'optional' => true,
					),
				),
			)
		);
		\WP_CLI::add_command(
			'form-captain settings import',
			array( $this, 'cli_settings_import' ),
			array(
				'shortdesc' => 'Imports a settings JSON file; shows the changes first.',
				'synopsis'  => array(
					array(
						'type' => 'positional',
						'name' => 'file',
					),
					array(
						'type'     => 'flag',
						'name'     => 'dry-run',
						'optional' => true,
					),
					$yes,
				),
			)
		);
	}

	/** @return array{processed:int,succeeded:int,failed:int,dead:int} */
	public function run_jobs( int $limit = 20 ): array {
		return $this->plugin->get( JobRunner::class )->run( max( 1, min( 500, $limit ) ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_jobs( string $status = '', string $type = '', int $limit = 50 ): array {
		$list = $this->jobs->list(
			array(
				'status'      => $status,
				'type_prefix' => $type,
				'per_page'    => $limit,
			)
		);
		return array_map(
			static fn( array $j ): array => array(
				'id'        => (int) $j['id'],
				'type'      => (string) $j['type'],
				'status'    => (string) $j['status'],
				'attempts'  => $j['attempts'] . '/' . $j['max_attempts'],
				'entry_id'  => (string) ( $j['entry_id'] ?? '' ),
				'error'     => (string) ( $j['last_error_code'] ?? '' ),
				'run_after' => (string) $j['run_after_gmt'],
				'updated'   => (string) $j['updated_gmt'],
			),
			$list['items']
		);
	}

	public function retry_job( int $id ): bool|\WP_Error {
		if ( ! $this->jobs->find( $id ) ) {
			return new \WP_Error( 'job_not_found', 'Job not found.' );
		}
		return $this->jobs->retry( $id ) ? true : new \WP_Error( 'job_not_retryable', 'Only failed, dead or cancelled jobs can be retried.' );
	}

	public function cleanup(): void {
		do_action( 'form_captain_cleanup' );
	}

	public function status_report(): string {
		return $this->plugin->get( SystemStatus::class )->report_text();
	}

	/* --- Settings (SET-FC-14) ------------------------------------------ */

	/** @return array<int,array{key:string,value:string,default:string,type:string}> */
	public function settings_list(): array {
		$all  = Settings::all();
		$rows = array();
		foreach ( SettingsSchema::definitions() as $path => $def ) {
			if ( ! empty( $def['internal'] ) ) {
				continue;
			}
			$secret = SettingsSchema::is_secret( $path );
			$rows[] = array(
				'key'     => $path,
				'value'   => $secret ? '••••••' : self::cli_value( $all[ $def['group'] ][ $def['key'] ] ?? null ),
				'default' => $secret ? '' : self::cli_value( $def['default'] ),
				'type'    => (string) $def['type'],
			);
		}
		return $rows;
	}

	public function settings_get( string $path ): string|\WP_Error {
		$def = SettingsSchema::definitions()[ $path ] ?? null;
		if ( ! $def || ! empty( $def['internal'] ) ) {
			return new \WP_Error( 'unknown_setting', 'Unknown setting. See: wp form-captain settings list' );
		}
		return SettingsSchema::is_secret( $path ) ? '••••••' : self::cli_value( Settings::get( $path ) );
	}

	/**
	 * Sets one value through SettingsSchema::sanitize_group(), like the settings screen.
	 *
	 * @return array{0:mixed,1:mixed}|\WP_Error [old, new]
	 */
	public function settings_set( string $path, string $value, bool $yes = false ): array|\WP_Error {
		$def = SettingsSchema::definitions()[ $path ] ?? null;
		if ( ! $def || ! empty( $def['internal'] ) ) {
			return new \WP_Error( 'unknown_setting', 'Unknown setting. See: wp form-captain settings list' );
		}
		$group = (string) $def['group'];
		$key   = (string) $def['key'];
		$input = array();
		foreach ( Settings::all()[ $group ] as $k => $v ) {
			$input[ $k ] = is_bool( $v ) ? ( $v ? '1' : '' ) : $v;
		}
		if ( 'bool' === $def['type'] ) {
			$value = in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ) ? '1' : ( in_array( strtolower( $value ), array( '0', 'false', 'no', 'off', '' ), true ) ? '' : $value );
			if ( ! in_array( $value, array( '1', '' ), true ) ) {
				return new \WP_Error( 'invalid_value', 'Please use true or false.' );
			}
		}
		$input[ $key ] = $value;
		if ( $yes ) {
			$input['retention_confirm']      = '1';
			$input['captcha_confirm']        = '1';
			$input['uninstall_confirm_word'] = SettingsSchema::CONFIRM_WORDS[0];
		}
		$old                     = Settings::get( $path );
		list( $values, $errors ) = SettingsSchema::sanitize_group( $group, $input );
		if ( isset( $errors[ $path ] ) ) {
			$needs_yes = in_array( $path, array( 'privacy.retention', 'spam.captcha_provider', 'privacy.delete_data_on_uninstall' ), true ) && ! $yes;
			return new \WP_Error( 'invalid_value', wp_strip_all_tags( $errors[ $path ] ) . ( $needs_yes ? ' Add --yes to confirm.' : '' ) );
		}
		ChangeLog::with_source( 'cli', static fn() => Settings::set_group( $group, $values ) );
		return array( $old, Settings::get( $path ) );
	}

	/** @return true|\WP_Error */
	public function settings_reset( string $section, bool $all, bool $yes ): bool|\WP_Error {
		if ( ! $yes ) {
			return new \WP_Error( 'confirmation_required', 'Resetting changes stored settings (a different retention period reschedules the deletion of submissions). Add --yes to confirm.' );
		}
		$groups = $all ? SettingsSchema::GROUPS : array( $section );
		foreach ( $groups as $group ) {
			if ( ! in_array( $group, SettingsSchema::GROUPS, true ) ) {
				return new \WP_Error( 'unknown_section', 'Unknown section. Use one of: ' . implode( ', ', SettingsSchema::GROUPS ) . ' or --all.' );
			}
		}
		ChangeLog::with_source(
			'cli',
			static function () use ( $groups ): void {
				foreach ( $groups as $group ) {
					Settings::reset_group( $group );
				}
			}
		);
		return true;
	}

	/**
	 * @return array{ok:bool,error:string,changes:array<string,array{0:mixed,1:mixed}>,unknown:string[],errors:array<string,string>,skipped:string[],values:array<string,array<string,mixed>>,meta:array<string,mixed>}
	 */
	public function settings_import( string $json, bool $dry_run, bool $yes ): array {
		$confirm = array(
			'retention_confirm' => $yes,
			'captcha_confirm'   => $yes,
		);
		return $dry_run ? SettingsTransfer::preview( $json, $confirm ) : SettingsTransfer::import( $json, $confirm, 'cli' );
	}

	private static function cli_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_settings_list( array $args, array $assoc ): void {
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $this->settings_list(), array( 'key', 'value', 'default', 'type' ) );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_settings_get( array $args, array $assoc ): void {
		$res = $this->settings_get( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::line( $res );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_settings_set( array $args, array $assoc ): void {
		$res = $this->settings_set( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ), ! empty( $assoc['yes'] ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::success( sprintf( '%s: %s -> %s', (string) $args[0], self::cli_value( $res[0] ), self::cli_value( $res[1] ) ) );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_settings_reset( array $args, array $assoc ): void {
		$res = $this->settings_reset( (string) ( $args[0] ?? '' ), ! empty( $assoc['all'] ), ! empty( $assoc['yes'] ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::success( 'Settings reset to the defaults.' );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_settings_export( array $args, array $assoc ): void {
		$json = SettingsTransfer::export_json();
		if ( empty( $assoc['file'] ) ) {
			\WP_CLI::line( $json );
			return;
		}
		if ( false === file_put_contents( (string) $assoc['file'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			\WP_CLI::error( 'Could not write the file.' );
		}
		\WP_CLI::success( 'Settings exported to ' . $assoc['file'] );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_settings_import( array $args, array $assoc ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( 'File not readable.' );
		}
		$json = (string) file_get_contents( $file, false, null, 0, SettingsTransfer::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$dry  = ! empty( $assoc['dry-run'] );
		$res  = $this->settings_import( $json, $dry, ! empty( $assoc['yes'] ) );
		if ( ! $res['ok'] ) {
			\WP_CLI::error( $res['error'] );
		}
		foreach ( $res['changes'] as $path => $pair ) {
			\WP_CLI::log( sprintf( '%s: %s -> %s', $path, self::cli_value( $pair[0] ), self::cli_value( $pair[1] ) ) );
		}
		foreach ( $res['errors'] as $path => $msg ) {
			\WP_CLI::warning( $path . ': ' . wp_strip_all_tags( $msg ) . ( in_array( $path, array( 'privacy.retention', 'spam.captcha_provider' ), true ) && empty( $assoc['yes'] ) ? ' Add --yes to confirm.' : '' ) );
		}
		if ( $res['unknown'] ) {
			\WP_CLI::warning( 'Unknown keys ignored: ' . implode( ', ', $res['unknown'] ) );
		}
		if ( $dry ) {
			\WP_CLI::success( sprintf( 'Dry run: %d settings would change.', count( $res['changes'] ) ) );
			return;
		}
		\WP_CLI::success( sprintf( '%d settings imported.', count( $res['changes'] ) ) );
	}

	/* --- WP-CLI wrappers ------------------------------------------------ */

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_jobs_run( array $args, array $assoc ): void {
		$stats = $this->run_jobs( (int) ( $assoc['limit'] ?? 20 ) );
		\WP_CLI::success( sprintf( 'Processed %d jobs: %d succeeded, %d failed, %d dead, %d paused.', $stats['processed'], $stats['succeeded'], $stats['failed'], $stats['dead'], $stats['paused'] ?? 0 ) );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_jobs_list( array $args, array $assoc ): void {
		$rows = $this->list_jobs( (string) ( $assoc['status'] ?? '' ), (string) ( $assoc['type'] ?? '' ) );
		if ( ! $rows ) {
			\WP_CLI::log( 'No jobs found.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_jobs_retry( array $args, array $assoc ): void {
		$res = $this->retry_job( (int) ( $args[0] ?? 0 ) );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_code() . ': ' . $res->get_error_message() );
		}
		\WP_CLI::success( 'Job re-queued.' );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_cleanup( array $args, array $assoc ): void {
		$this->cleanup();
		\WP_CLI::success( 'Cleanup finished.' );
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function cli_status( array $args, array $assoc ): void {
		\WP_CLI::log( $this->status_report() );
	}
}
