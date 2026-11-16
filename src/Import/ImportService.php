<?php
/**
 * Import from "shortcode form format type A" (docs/planung/import.md, P9-06).
 *
 * preview(): read-only analysis (candidate schema + conversion report).
 * import(): creates the form as a draft via FormService – never publishes, never touches
 * the source. import_entries(): separate step for documented CSV export files (profile
 * "Generic CSV"), idempotent via request_key, without mails, jobs or entry events.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Import;

use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Domain\SubmissionValidator;
use FormCaptain\Import\Profiles\GenericCsv;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\RetentionService;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;

final class ImportService {

	public const SOURCE_TYPE = 'type_a';

	/** Maximum size of a pasted form source (bytes). */
	public const MAX_SOURCE_BYTES = 262144;

	/** Option prefix of the per-form import record (contains no submitted data). */
	public const RECORD_OPTION = 'form_captain_import_';

	/** Maximum number of row findings kept in an entry import report. */
	private const MAX_ROW_FINDINGS = 500;

	public function __construct(
		private ShortcodeFormatParser $parser,
		private SchemaValidator $validator,
		private FormService $forms,
		private FormRepository $repo,
		private EntryRepository $entries,
		private SubmissionValidator $submission
	) {}

	// Forms.

	/**
	 * Read-only analysis: candidate schema and conversion report. Writes nothing.
	 *
	 * @return array<string,mixed>
	 */
	public function preview( string $source, string $title ): array {
		$parsed   = $this->parser->parse( $source );
		$findings = $parsed['findings'];
		$schema   = array(
			'schema_version' => SchemaValidator::CURRENT_VERSION,
			'fields'         => $parsed['fields'],
		);
		$status   = $parsed['status'];
		if ( 'blocked' !== $status ) {
			$result = $this->validator->validate( $schema );
			if ( $result['errors'] ) {
				$status     = 'blocked';
				$findings[] = array(
					'severity'        => 'blocked',
					'code'            => 'schema_invalid',
					'source_tag_name' => '',
					'target_field_id' => null,
					/* translators: %s: list of error codes */
					'message'         => sprintf( __( 'The converted form is not valid (%s).', 'form-captain' ), implode( ', ', array_slice( array_map( static fn( $p, $c ) => $p . ': ' . $c, array_keys( $result['errors'] ), $result['errors'] ), 0, 10 ) ) ),
				);
			} else {
				$schema = $result['schema'];
			}
		}
		if ( 'blocked' === $status ) {
			$schema['fields'] = array();
		}
		$warnings = array();
		foreach ( $findings as $f ) {
			if ( 'info' !== $f['severity'] ) {
				$warnings[] = (string) $f['message'];
			}
		}
		return array(
			'source_type'  => self::SOURCE_TYPE,
			'title'        => self::import_title( $title ),
			'status'       => $status,
			'importable'   => 'blocked' !== $status,
			'schema'       => $schema,
			'counts'       => $parsed['counts'],
			'elements'     => $parsed['elements'],
			'findings'     => $findings,
			'warnings'     => array_values( array_unique( $warnings ) ),
			'source_map'   => $parsed['source_map'],
			'submit_label' => $parsed['submit_label'],
			'source_hash'  => hash( 'sha256', $source ),
		);
	}

	/**
	 * Imports the form as a new draft. Options: exclude (list of keys), labels (key => label).
	 *
	 * @param array{exclude?:array<int,string>,labels?:array<string,string>} $options
	 * @return array{form_id:int,import_id:string,report:array<string,mixed>}|\WP_Error
	 */
	public function import( string $source, string $title, array $options = array() ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to manage forms.', 'form-captain' ) );
		}
		if ( strlen( $source ) > self::MAX_SOURCE_BYTES ) {
			return Errors::error( Errors::PAYLOAD_TOO_LARGE, __( 'The form source is too large (maximum 256 KB).', 'form-captain' ) );
		}
		$preview = $this->preview( $source, $title );
		if ( ! $preview['importable'] ) {
			$limit = in_array( 'limit_exceeded', array_column( $preview['findings'], 'code' ), true );
			return Errors::error(
				$limit ? 'import_limit_exceeded' : 'import_blocked',
				$limit ? __( 'The form exceeds the limits (100 fields, 200 options) and was not imported.', 'form-captain' ) : __( 'The form cannot be imported. See the report for details.', 'form-captain' ),
				array(
					'status' => 422,
					'codes'  => array_values( array_unique( array_column( $preview['findings'], 'code' ) ) ),
				)
			);
		}
		$schema  = $preview['schema'];
		$exclude = array_map( 'strval', (array) ( $options['exclude'] ?? array() ) );
		$labels  = (array) ( $options['labels'] ?? array() );
		$fields  = array();
		foreach ( $schema['fields'] as $field ) {
			if ( in_array( (string) $field['key'], $exclude, true ) ) {
				continue;
			}
			if ( isset( $labels[ $field['key'] ] ) && is_string( $labels[ $field['key'] ] ) && '' !== trim( wp_strip_all_tags( $labels[ $field['key'] ] ) ) ) {
				$field['label'] = $labels[ $field['key'] ];
			}
			$fields[] = $field;
		}
		$schema['fields'] = $fields;

		$id = $this->forms->create( $preview['title'], $schema, array() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$form = $this->repo->find( $id );
		if ( $form && 'draft' !== $form['status'] ) {
			$this->repo->set_status( $id, 'draft' );
		}
		$ids       = array_column( $fields, 'id' );
		$field_map = array_filter( $preview['source_map'], static fn( string $fid ): bool => in_array( $fid, $ids, true ) );
		$import_id = bin2hex( random_bytes( 16 ) );
		$report    = array(
			'import_id'      => $import_id,
			'created_gmt'    => Clock::now(),
			'user_id'        => get_current_user_id(),
			'source_type'    => self::SOURCE_TYPE,
			'source_hash'    => $preview['source_hash'],
			'form_id'        => $id,
			'plugin_version' => defined( 'FORM_CAPTAIN_VERSION' ) ? (string) FORM_CAPTAIN_VERSION : '',
			'status'         => $preview['status'],
			'counts'         => $preview['counts'],
			'findings'       => $preview['findings'],
			'field_map'      => $field_map,
			'entry_imports'  => array(),
		);
		update_option( self::RECORD_OPTION . $id, $report, false );
		return array(
			'form_id'   => $id,
			'import_id' => $import_id,
			'report'    => $report,
		);
	}

	/** @return array<string,mixed>|null */
	public function record( int $form_id ): ?array {
		$record = get_option( self::RECORD_OPTION . $form_id );
		return is_array( $record ) ? $record : null;
	}

	public static function import_title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ) );
		if ( '' === $title ) {
			$title = __( 'Imported form', 'form-captain' );
		}
		/* translators: %s: form title */
		return mb_substr( sprintf( __( '%s (Import)', 'form-captain' ), mb_substr( $title, 0, 170 ) ), 0, 190 );
	}

	// Entries.

	/**
	 * Reads the header row of a CSV file (profile "Generic CSV").
	 *
	 * @return array<int,string>|\WP_Error
	 */
	public function csv_header( string $csv ): array|\WP_Error {
		$rows = $this->read_csv( $csv, 1 );
		return is_wp_error( $rows ) ? $rows : $rows[0];
	}

	/**
	 * Imports entries from a CSV file into a form created by import(). Separate step.
	 *
	 * @param array<string,string> $mapping column header => field ID or key
	 * @param array{time_column?:string,timezone?:string} $options
	 * @return array<string,mixed>|\WP_Error
	 */
	public function import_entries( int $form_id, string $csv, array $mapping, array $options = array() ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) || ! current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to import entries.', 'form-captain' ) );
		}
		if ( strlen( $csv ) > GenericCsv::MAX_BYTES ) {
			return Errors::error( Errors::PAYLOAD_TOO_LARGE, __( 'The file is too large (maximum 20 MB).', 'form-captain' ) );
		}
		$form   = $this->repo->find( $form_id );
		$record = $this->record( $form_id );
		if ( ! $form || ! $record ) {
			return Errors::error( 'import_not_found', __( 'Entries can only be imported into a form that was imported before.', 'form-captain' ), array( 'status' => 404 ) );
		}
		$version = ! empty( $form['published_version_id'] ) ? $this->repo->find_version( (int) $form['published_version_id'] ) : null;
		if ( ! $version ) {
			return Errors::error( 'form_not_published', __( 'Please publish the imported form before importing entries.', 'form-captain' ), array( 'status' => 409 ) );
		}
		$rows = $this->read_csv( $csv, GenericCsv::MAX_ROWS + 1 );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( count( $rows ) - 1 > GenericCsv::MAX_ROWS ) {
			return Errors::error( 'too_many_rows', __( 'The file has more than 50,000 rows.', 'form-captain' ), array( 'status' => 413 ) );
		}
		$header = array_shift( $rows );

		// Column mapping.
		$fields = array();
		foreach ( (array) ( $version['schema']['fields'] ?? array() ) as $f ) {
			$fields[ (string) $f['id'] ] = $f;
		}
		$by_key = array_column( array_values( $fields ), 'id', 'key' );
		$cols   = array();
		foreach ( $mapping as $column => $target ) {
			$idx = array_search( (string) $column, $header, true );
			$fid = isset( $fields[ (string) $target ] ) ? (string) $target : ( $by_key[ (string) $target ] ?? null );
			if ( '' === (string) $target ) {
				continue;
			}
			if ( false === $idx || null === $fid || in_array( $fields[ $fid ]['type'], array( 'html', 'hidden' ), true ) ) {
				return Errors::error( 'invalid_mapping', __( 'The column mapping refers to unknown columns or fields.', 'form-captain' ), array( 'status' => 400 ) );
			}
			$cols[ $fid ] = (int) $idx;
		}
		$time_idx = array_search( (string) ( $options['time_column'] ?? '' ), $header, true );
		if ( ! $cols || false === $time_idx ) {
			return Errors::error( 'invalid_mapping', __( 'Please map at least one field and the submission time column.', 'form-captain' ), array( 'status' => 400 ) );
		}
		try {
			$tz = new \DateTimeZone( (string) ( $options['timezone'] ?? '' ) ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			return Errors::error( 'invalid_timezone', __( 'Unknown time zone.', 'form-captain' ), array( 'status' => 400 ) );
		}

		// Import mode: only mapped fields, required fields may be missing, no conditions.
		$check = array();
		foreach ( $cols as $fid => $idx ) {
			$f              = $fields[ $fid ];
			$f['required']  = false;
			$f['condition'] = null;
			$check[]        = $f;
		}

		$import_id   = substr( hash( 'sha256', $form_id . '|' . hash( 'sha256', $csv ) ), 0, 32 );
		$mode        = RetentionService::mode_for_form( $form );
		$now         = Clock::now();
		$stats       = array(
			'import_id'  => $import_id,
			'rows'       => count( $rows ),
			'imported'   => 0,
			'duplicates' => 0,
			'invalid'    => 0,
			'expired'    => 0,
			'findings'   => array(),
		);
		$row_finding = function ( int $row, string $code, string $field = '' ) use ( &$stats ): void {
			++$stats['invalid'];
			if ( count( $stats['findings'] ) < self::MAX_ROW_FINDINGS ) {
				$stats['findings'][] = array( 'row' => $row, 'code' => $code, 'field' => $field );
			}
		};

		foreach ( array_chunk( $rows, GenericCsv::BATCH, true ) as $batch ) {
			$this->entries->begin();
			foreach ( $batch as $i => $cells ) {
				$row_no = (int) $i + 2; // 1-based incl. header row.
				$key    = hash( 'sha256', $import_id . ':' . $row_no );
				if ( $this->entries->find_by_request_key( $key ) ) {
					++$stats['duplicates'];
					continue;
				}
				$created = GenericCsv::to_gmt( (string) ( $cells[ $time_idx ] ?? '' ), $tz );
				if ( null === $created ) {
					$row_finding( $row_no, 'invalid_time' );
					continue;
				}
				$expires = RetentionService::expires_for( $mode, $created );
				if ( null !== $expires && $expires <= $now ) {
					++$stats['expired'];
					continue;
				}
				$input = array();
				foreach ( $cols as $fid => $idx ) {
					$value = (string) ( $cells[ $idx ] ?? '' );
					if ( '' === trim( $value ) ) {
						continue;
					}
					$input[ $fid ] = self::csv_value( $fields[ $fid ], $value );
				}
				$result = $this->submission->validate( $check, $input, array( 'today' => substr( $created, 0, 10 ) ) );
				if ( $result['codes'] ) {
					$fid = (string) array_key_first( $result['codes'] );
					$row_finding( $row_no, (string) $result['codes'][ $fid ], (string) ( $fields[ $fid ]['key'] ?? '' ) );
					continue;
				}
				if ( ! $result['values'] ) {
					$row_finding( $row_no, 'empty_row' );
					continue;
				}
				$entry_id = $this->entries->insert(
					array(
						'form_id'         => $form_id,
						'form_version_id' => (int) $version['id'],
						'status'          => 'read',
						'request_key'     => $key,
						'source_url'      => null,
						'user_id'         => null,
						'context'         => array( 'import' => $import_id ),
						'consent'         => $result['consent'] ? $result['consent'] : null,
						'expires_gmt'     => $expires,
					)
				);
				if ( $entry_id <= 0 || ! $this->entries->insert_values( $entry_id, $result['values'] ) ) {
					$row_finding( $row_no, 'storage_failed' );
					continue;
				}
				$this->entries->update( $entry_id, array( 'created_gmt' => $created ) );
				++$stats['imported'];
			}
			$this->entries->commit();
		}

		$record['entry_imports'][ $import_id ] = array(
			'created_gmt' => $now,
			'user_id'     => get_current_user_id(),
			'rows'        => $stats['rows'],
			'imported'    => (int) ( $record['entry_imports'][ $import_id ]['imported'] ?? 0 ) + $stats['imported'],
			'invalid'     => $stats['invalid'],
			'expired'     => $stats['expired'],
		);
		update_option( self::RECORD_OPTION . $form_id, $record, false );
		if ( $stats['imported'] > 0 ) {
			do_action( 'form_captain_entries_imported_v1', $import_id, $form_id, $stats['imported'] );
		}
		return $stats;
	}

	/**
	 * Converts a CSV cell into the raw input shape (multiple values, option labels).
	 *
	 * @param array<string,mixed> $field
	 * @return string|array<int,string>
	 */
	private static function csv_value( array $field, string $value ): string|array {
		$type  = (string) $field['type'];
		$multi = 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) );
		$parts = $multi ? array_values( array_filter( array_map( 'trim', explode( GenericCsv::MULTI_SEPARATOR, $value ) ), 'strlen' ) ) : array( $value );
		if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$labels = array();
			foreach ( (array) ( $field['options'] ?? array() ) as $o ) {
				$labels[ mb_strtolower( (string) $o['label'] ) ] = (string) $o['value'];
			}
			$parts = array_map( static fn( string $p ): string => $labels[ mb_strtolower( trim( $p ) ) ] ?? trim( $p ), $parts );
		}
		return $multi ? $parts : $parts[0];
	}

	/**
	 * Parses CSV (profile "Generic CSV"): BOM, delimiter detection, RFC 4180 quoting.
	 *
	 * @return array<int,array<int,string>>|\WP_Error first row is the header
	 */
	private function read_csv( string $csv, int $max_rows ): array|\WP_Error {
		if ( str_starts_with( $csv, "\xEF\xBB\xBF" ) ) {
			$csv = substr( $csv, 3 );
		}
		if ( '' === trim( $csv ) || ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			return Errors::error( 'invalid_file', __( 'The file is empty or not UTF-8 encoded CSV.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$first = strtok( $csv, "\n" );
		$delim = GenericCsv::delimiter( false === $first ? '' : $first );
		$fh    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return Errors::error( Errors::STORAGE_FAILED );
		}
		fwrite( $fh, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$rows  = array();
		$count = 0;
		while ( $count <= $max_rows ) {
			$cells = fgetcsv( $fh, 0, $delim, '"', '' );
			if ( false === $cells ) {
				break;
			}
			if ( array( null ) === $cells ) {
				continue; // Blank line.
			}
			$rows[] = array_map( static fn( $c ): string => (string) $c, $cells );
			++$count;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $rows ) {
			return Errors::error( 'invalid_file', __( 'The file is empty or not UTF-8 encoded CSV.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$rows[0] = array_map( 'trim', $rows[0] );
		return $rows;
	}
}
