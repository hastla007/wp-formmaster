<?php
/**
 * Admin CSV entry import (docs/planung/import.md section 7, docs/handbuch/import.md):
 * staged upload → column mapping → dry run → batch import → revert.
 *
 * - The uploaded file is kept only in a private upload subdirectory (.htaccess deny +
 *   index.php, random file name); its path never leaves the server (state transient bound
 *   to the user). It is deleted after completion/cancellation, at the latest after 1 hour.
 * - Rows are processed by ImportService::process_entries() in batches of 500; request keys
 *   make every step repeatable after an abort.
 * - Every run is recorded (IDs and counters only) so it can be reverted within 7 days.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Import;

use FormCaptain\Import\Profiles\GenericCsv;
use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\EntryDeletionService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Logger;

// phpcs:disable WordPress.WP.AlternativeFunctions -- local temp file handling in a private directory.

final class EntryImportService {

	/** Upload limit of the admin import (bytes, 5 MB). */
	public const MAX_BYTES = 5242880;

	/** Row limit of the admin import (data rows without header). */
	public const MAX_ROWS = 10000;

	/** Rows per import step. */
	public const BATCH = GenericCsv::BATCH;

	/** Lifetime of an uploaded file and its state (seconds). */
	public const FILE_TTL = 3600;

	/** A run can be reverted for this many days. */
	public const REVERT_DAYS = 7;

	/** Preview: rows and characters per cell. */
	public const PREVIEW_ROWS  = 5;
	public const PREVIEW_CHARS = 40;

	/** Import log: runs with IDs and counters only (no values). */
	public const RUNS_OPTION = 'form_captain_entry_import_runs';

	/** Single cron event deleting one uploaded file after FILE_TTL. */
	public const CLEANUP_HOOK = 'form_captain_entry_import_cleanup';

	/** Private subdirectory below the uploads base directory. */
	public const DIR = 'form-captain-private/imports';

	/** Runs kept in the log (days / count). */
	private const RUNS_KEEP_DAYS = 30;
	private const RUNS_KEEP_MAX  = 50;

	/** Findings kept per run. */
	private const RUN_FINDINGS = 200;

	private const STATE_PREFIX = 'form_captain_eimp_';

	public function __construct(
		private ImportService $import,
		private EntryRepository $entries,
		private FormRepository $forms,
		private EntryDeletionService $deletion
	) {}

	/** Cron hooks (lazy: the service is only built when a hook fires). */
	public static function register_hooks( Plugin $plugin ): void {
		add_action(
			self::CLEANUP_HOOK,
			static function ( $name = '' ) use ( $plugin ): void {
				$plugin->get( self::class )->cleanup_file( (string) $name );
			}
		);
		add_action(
			'form_captain_hourly_cleanup',
			static function () use ( $plugin ): void {
				$plugin->get( self::class )->sweep();
			}
		);
	}

	public static function can_import(): bool {
		return current_user_can( Capabilities::MANAGE_FORMS ) && current_user_can( Capabilities::EDIT_ENTRIES );
	}

	public static function can_revert(): bool {
		return current_user_can( Capabilities::MANAGE_FORMS ) && current_user_can( Capabilities::DELETE_ENTRIES );
	}

	// Files.

	/** Private directory (created with .htaccess deny + index.php). */
	public static function directory(): string|\WP_Error {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return Errors::error( Errors::STORAGE_FAILED, __( 'The upload directory is not available.', 'form-captain' ) );
		}
		$dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return Errors::error( Errors::STORAGE_FAILED, __( 'The upload directory is not writable.', 'form-captain' ) );
		}
		foreach ( array( $dir, dirname( $dir ) ) as $d ) {
			if ( ! file_exists( $d . '/.htaccess' ) ) {
				file_put_contents( $d . '/.htaccess', "# Form Captain: private files.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			}
			if ( ! file_exists( $d . '/index.php' ) ) {
				file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" );
			}
		}
		return $dir;
	}

	private static function file_path( string $name ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{32}\.csv\.tmp$/', $name ) ) {
			return null;
		}
		$dir = self::directory();
		return is_wp_error( $dir ) ? null : $dir . '/' . $name;
	}

	/** Cron callback: deletes one uploaded file (name only, never a path). */
	public function cleanup_file( mixed $name = '' ): void {
		$path = self::file_path( (string) $name );
		if ( null !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** Deletes uploaded files older than FILE_TTL. Returns the number of deleted files. */
	public function sweep(): int {
		$dir = self::directory();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}
		$deleted = 0;
		foreach ( (array) glob( $dir . '/*.csv.tmp' ) as $path ) {
			$mtime = (int) @filemtime( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- file may vanish concurrently.
			if ( $mtime > 0 && $mtime < time() - self::FILE_TTL ) {
				wp_delete_file( (string) $path );
				++$deleted;
			}
		}
		return $deleted;
	}

	// State (bound to the current user; contains no path, only the random file name).

	private static function state_key( string $token ): string {
		return self::STATE_PREFIX . get_current_user_id() . '_' . $token;
	}

	/** @return array<string,mixed>|null */
	public function state( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $token ) ) {
			return null;
		}
		$state = get_transient( self::state_key( $token ) );
		if ( ! is_array( $state ) || (int) ( $state['expires'] ?? 0 ) < time() ) {
			return null;
		}
		return $state;
	}

	/** @param array<string,mixed> $state */
	private function save_state( string $token, array $state ): void {
		set_transient( self::state_key( $token ), $state, max( 60, (int) $state['expires'] - time() ) );
	}

	/** @param array<string,mixed> $state */
	private function read_file( array $state ): string|\WP_Error {
		$path = self::file_path( (string) ( $state['file'] ?? '' ) );
		$csv  = null !== $path && is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		if ( '' === $csv || ! hash_equals( (string) $state['sha'], hash( 'sha256', $csv ) ) ) {
			return Errors::error( 'import_expired', __( 'The uploaded file is no longer available. Please upload it again.', 'form-captain' ), array( 'status' => 410 ) );
		}
		return $csv;
	}

	/** Ends an upload: deletes the file and the state. */
	public function discard( string $token ): void {
		$state = $this->state( $token );
		if ( $state ) {
			$this->cleanup_file( (string) $state['file'] );
		}
		delete_transient( self::state_key( $token ) );
	}

	// Step 1: upload.

	/**
	 * Validates and stores an uploaded CSV for a published form. Returns the state token.
	 */
	public function stage( int $form_id, string $csv ): string|\WP_Error {
		if ( ! self::can_import() ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to import entries.', 'form-captain' ) );
		}
		$form = $this->forms->find( $form_id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		if ( empty( $form['published_version_id'] ) || ! in_array( $form['status'], array( 'published', 'paused' ), true ) ) {
			return Errors::error( 'form_not_published', __( 'Please publish the form before importing entries.', 'form-captain' ), array( 'status' => 409 ) );
		}
		if ( strlen( $csv ) > self::MAX_BYTES ) {
			/* translators: %s: maximum file size, e.g. "5 MB" */
			return Errors::error( Errors::PAYLOAD_TOO_LARGE, sprintf( __( 'The file is too large (maximum %s).', 'form-captain' ), size_format( self::MAX_BYTES ) ) );
		}
		$parsed = $this->import->read_entry_csv( $csv, self::MAX_ROWS + 1 );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		if ( count( $parsed['rows'] ) > self::MAX_ROWS ) {
			/* translators: %s: maximum number of rows */
			return Errors::error( 'too_many_rows', sprintf( __( 'The file has more than %s rows.', 'form-captain' ), number_format_i18n( self::MAX_ROWS ) ), array( 'status' => 413 ) );
		}
		if ( ! $parsed['rows'] ) {
			return Errors::error( 'no_rows', __( 'The file contains no data rows below the header row.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$dir = self::directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$this->sweep();
		$name = bin2hex( random_bytes( 16 ) ) . '.csv.tmp';
		if ( strlen( $csv ) !== (int) file_put_contents( $dir . '/' . $name, $csv ) ) {
			wp_delete_file( $dir . '/' . $name );
			return Errors::error( Errors::STORAGE_FAILED, __( 'The file could not be stored temporarily.', 'form-captain' ) );
		}
		wp_schedule_single_event( time() + self::FILE_TTL, self::CLEANUP_HOOK, array( $name ) );

		$preview = array();
		foreach ( array_slice( $parsed['rows'], 0, self::PREVIEW_ROWS ) as $cells ) {
			$preview[] = array_map( array( self::class, 'shorten' ), $cells );
		}
		$token = bin2hex( random_bytes( 12 ) );
		$this->save_state(
			$token,
			array(
				'form_id'   => $form_id,
				'file'      => $name,
				'sha'       => hash( 'sha256', $csv ),
				'bytes'     => strlen( $csv ),
				'rows'      => count( $parsed['rows'] ),
				'header'    => array_map( array( self::class, 'shorten' ), $parsed['header'] ),
				'delimiter' => $parsed['delimiter'],
				'bom'       => str_starts_with( $csv, "\xEF\xBB\xBF" ),
				'preview'   => $preview,
				'step'      => 'map',
				'expires'   => time() + self::FILE_TTL,
			)
		);
		return $token;
	}

	/** Shortened preview value (never stored beyond the upload transient). */
	public static function shorten( string $value ): string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return mb_strlen( $value ) > self::PREVIEW_CHARS ? mb_substr( $value, 0, self::PREVIEW_CHARS - 1 ) . '…' : $value;
	}

	/**
	 * Sample values of one column for format detection (not stored).
	 *
	 * @return array<int,string>
	 */
	private function samples( string $csv, int $index, int $count = 200 ): array {
		$parsed = $this->import->read_entry_csv( $csv, $count );
		if ( is_wp_error( $parsed ) ) {
			return array();
		}
		return array_map( static fn( array $cells ): string => (string) ( $cells[ $index ] ?? '' ), $parsed['rows'] );
	}

	/** Detected format of a column (for the mapping screen), null if none. */
	public function detect_format( string $token, int $index ): ?string {
		$state = $this->state( $token );
		$csv   = $state ? $this->read_file( $state ) : '';
		return is_string( $csv ) && '' !== $csv ? GenericCsv::detect_time_format( $this->samples( $csv, $index, 50 ) ) : null;
	}

	// Steps 2 + 3: mapping and dry run.

	/**
	 * Stores the mapping and runs the dry run over all rows.
	 *
	 * @param array<int,string> $columns column index => field ID ('' = ignore)
	 * @return array<string,mixed>|\WP_Error the dry-run report
	 */
	public function map( string $token, array $columns, int $time_index, string $time_format ): array|\WP_Error {
		if ( ! self::can_import() ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to import entries.', 'form-captain' ) );
		}
		$state = $this->state( $token );
		if ( ! $state || ( 'map' !== $state['step'] && 'check' !== $state['step'] ) ) {
			return Errors::error( 'import_expired', __( 'The upload has expired. Please upload the file again.', 'form-captain' ), array( 'status' => 410 ) );
		}
		$csv = $this->read_file( $state );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		$columns = array_filter( $columns, static fn( $fid ): bool => '' !== (string) $fid );
		if ( 'auto' === $time_format ) {
			$time_format = (string) GenericCsv::detect_time_format( $this->samples( $csv, $time_index ) );
			if ( '' === $time_format ) {
				return Errors::error( 'time_format_unknown', __( 'The date format of the submission time column was not recognized. Please choose it.', 'form-captain' ), array( 'status' => 400 ) );
			}
		}
		$report = $this->import->process_entries(
			(int) $state['form_id'],
			$csv,
			array(),
			array(
				'columns'     => $columns,
				'time_index'  => $time_index,
				'time_format' => $time_format,
				'dry_run'     => true,
				'max_bytes'   => self::MAX_BYTES,
				'max_rows'    => self::MAX_ROWS,
			)
		);
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$state['columns']     = $columns;
		$state['time_index']  = $time_index;
		$state['time_format'] = $time_format;
		$state['check']       = array_intersect_key( $report, array_flip( array( 'rows', 'valid', 'duplicates', 'invalid', 'expired', 'findings' ) ) );
		$state['step']        = 'check';
		$this->save_state( $token, $state );
		return $state['check'];
	}

	// Step 4: batch import.

	/**
	 * Imports the next batch. Returns the state (step "import" while rows remain) or, when
	 * done, the finished run.
	 *
	 * @return array{done:bool,run_id:string,offset:int,rows:int}|\WP_Error
	 */
	public function import_batch( string $token ): array|\WP_Error {
		if ( ! self::can_import() ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to import entries.', 'form-captain' ) );
		}
		$state = $this->state( $token );
		if ( ! $state || ! in_array( $state['step'], array( 'check', 'import' ), true ) ) {
			return Errors::error( 'import_expired', __( 'The upload has expired. Please upload the file again – rows imported before are recognized and skipped.', 'form-captain' ), array( 'status' => 410 ) );
		}
		$csv = $this->read_file( $state );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		$form_id = (int) $state['form_id'];
		if ( 'check' === $state['step'] ) {
			$state['run_id'] = bin2hex( random_bytes( 16 ) );
			$state['offset'] = 0;
			$state['step']   = 'import';
			$this->save_run(
				(string) $state['run_id'],
				array(
					'form_id'     => $form_id,
					'import_id'   => ImportService::entry_import_id( $form_id, $csv ),
					'user_id'     => get_current_user_id(),
					'created_gmt' => Clock::now(),
					'status'      => 'running',
					'rows'        => (int) $state['rows'],
					'imported'    => 0,
					'duplicates'  => 0,
					'invalid'     => 0,
					'expired'     => 0,
					'findings'    => array(),
				)
			);
			$this->save_state( $token, $state );
		}
		$run_id = (string) $state['run_id'];
		$stats  = $this->import->process_entries(
			$form_id,
			$csv,
			array(),
			array(
				'columns'     => (array) $state['columns'],
				'time_index'  => (int) $state['time_index'],
				'time_format' => (string) $state['time_format'],
				'run_id'      => $run_id,
				'offset'      => (int) $state['offset'],
				'limit'       => self::BATCH,
				'max_bytes'   => self::MAX_BYTES,
				'max_rows'    => self::MAX_ROWS,
			)
		);
		if ( is_wp_error( $stats ) ) {
			return $stats;
		}
		$run = $this->run( $run_id );
		if ( $run ) {
			foreach ( array( 'duplicates', 'invalid', 'expired' ) as $k ) {
				$run[ $k ] = (int) $run[ $k ] + (int) $stats[ $k ];
			}
			$run['findings'] = array_slice( array_merge( (array) $run['findings'], (array) $stats['findings'] ), 0, self::RUN_FINDINGS );
			$run['imported'] = count( $this->entries->ids_for_import_run( $form_id, $run_id, self::MAX_ROWS + 1 ) );
			if ( $stats['done'] ) {
				$run['status']       = 'done';
				$run['finished_gmt'] = Clock::now();
			}
			$this->save_run( $run_id, $run );
		}
		if ( $stats['done'] ) {
			$this->discard( $token );
			Logger::log( 'info', 'entry_import_finished', sprintf( 'imported=%d duplicates=%d invalid=%d expired=%d', (int) ( $run['imported'] ?? 0 ), (int) ( $run['duplicates'] ?? 0 ), (int) ( $run['invalid'] ?? 0 ), (int) ( $run['expired'] ?? 0 ) ), array( 'form_id' => $form_id ) );
		} else {
			$state['offset'] = (int) $stats['next_offset'];
			$this->save_state( $token, $state );
		}
		return array(
			'done'   => (bool) $stats['done'],
			'run_id' => $run_id,
			'offset' => (int) $stats['next_offset'],
			'rows'   => (int) $stats['rows'],
		);
	}

	// Import log and revert.

	/** @return array<string,array<string,mixed>> */
	private function all_runs(): array {
		$runs = get_option( self::RUNS_OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/** @param array<string,mixed> $run */
	private function save_run( string $run_id, array $run ): void {
		$runs            = $this->all_runs();
		$runs[ $run_id ] = $run;
		$cutoff          = Clock::plus( -self::RUNS_KEEP_DAYS * DAY_IN_SECONDS );
		$runs            = array_filter( $runs, static fn( $r ): bool => is_array( $r ) && (string) ( $r['created_gmt'] ?? '' ) >= $cutoff );
		uasort( $runs, static fn( array $a, array $b ): int => strcmp( (string) $b['created_gmt'], (string) $a['created_gmt'] ) );
		update_option( self::RUNS_OPTION, array_slice( $runs, 0, self::RUNS_KEEP_MAX, true ), false );
	}

	/** @return array<string,mixed>|null */
	public function run( string $run_id ): ?array {
		$runs = $this->all_runs();
		return isset( $runs[ $run_id ] ) && is_array( $runs[ $run_id ] ) ? $runs[ $run_id ] : null;
	}

	/** @return array<string,array<string,mixed>> runs of one form, newest first */
	public function runs_for_form( int $form_id ): array {
		return array_filter( $this->all_runs(), static fn( $r ): bool => is_array( $r ) && (int) $r['form_id'] === $form_id );
	}

	/** @param array<string,mixed> $run */
	public static function revertable( array $run ): bool {
		return 'reverted' !== ( $run['status'] ?? '' ) && (string) $run['created_gmt'] >= Clock::plus( -self::REVERT_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Permanently deletes the entries of one run (next batch). Returns deleted/remaining.
	 *
	 * @return array{deleted:int,remaining:int}|\WP_Error
	 */
	public function revert_batch( int $form_id, string $run_id ): array|\WP_Error {
		if ( ! self::can_revert() ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to delete entries.', 'form-captain' ) );
		}
		$run = preg_match( '/^[a-f0-9]{32}$/', $run_id ) ? $this->run( $run_id ) : null;
		if ( ! $run || (int) $run['form_id'] !== $form_id ) {
			return Errors::error( 'import_not_found', __( 'Import run not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		if ( ! self::revertable( $run ) ) {
			/* translators: %d: number of days */
			return Errors::error( 'revert_expired', sprintf( __( 'An import can only be reverted within %d days.', 'form-captain' ), self::REVERT_DAYS ), array( 'status' => 409 ) );
		}
		$deleted = 0;
		foreach ( $this->entries->ids_for_import_run( $form_id, $run_id, self::BATCH ) as $id ) {
			if ( $this->deletion->delete( $id, 'import_reverted' ) ) {
				++$deleted;
			}
		}
		$remaining           = count( $this->entries->ids_for_import_run( $form_id, $run_id, self::MAX_ROWS + 1 ) );
		$run['reverted']     = (int) ( $run['reverted'] ?? 0 ) + $deleted;
		$run['reverted_by']  = get_current_user_id();
		$run['reverted_gmt'] = Clock::now();
		if ( 0 === $remaining ) {
			$run['status'] = 'reverted';
			Logger::log( 'info', 'entry_import_reverted', sprintf( 'deleted=%d', (int) $run['reverted'] ), array( 'form_id' => $form_id ) );
		}
		$this->save_run( $run_id, $run );
		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
		);
	}
}
