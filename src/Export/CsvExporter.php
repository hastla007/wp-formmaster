<?php
/**
 * Streaming CSV export of entries (P4-11, T-CSV-01…06, DS-B-05).
 *
 * - POST only, capability `form_captain_export_entries` (+ read) and nonce.
 * - Form filter is required (columns are form-specific); status/date filters optional.
 * - Columns: metadata + fields of the current schema + fields of older versions.
 *   Sensitive fields are only exported when selected explicitly.
 * - Batches via keyset (`id > last_id`), streamed to php://output, never stored on disk.
 * - Formula injection protection, RFC 4180 quoting, CRLF line endings, optional UTF-8 BOM.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Export;

use FormCaptain\Install\Capabilities;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;

final class CsvExporter {

	public const ACTION       = 'form_captain_export_entries';
	public const NONCE_ACTION = 'form_captain_export_entries';
	public const MAX_ROWS     = 100000;
	public const META_COLUMNS = array( 'meta:id', 'meta:created', 'meta:status', 'meta:form_version', 'meta:source_url', 'meta:consent_withdrawn' );

	public function __construct( private EntryRepository $entries, private FormRepository $forms ) {}

	/**
	 * Available columns for a form: key => [label, sensitive, field, removed].
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,array{label:string,sensitive:bool,field:array<string,mixed>|null,removed:bool}>
	 */
	public function columns( array $form ): array {
		$cols    = array(
			'meta:id'           => array( 'label' => __( 'Submission ID', 'form-captain' ), 'sensitive' => false, 'field' => null, 'removed' => false ),
			'meta:created'      => array( 'label' => __( 'Submitted at', 'form-captain' ), 'sensitive' => false, 'field' => null, 'removed' => false ),
			'meta:status'       => array( 'label' => __( 'Status', 'form-captain' ), 'sensitive' => false, 'field' => null, 'removed' => false ),
			'meta:form_version' => array( 'label' => __( 'Form version', 'form-captain' ), 'sensitive' => false, 'field' => null, 'removed' => false ),
			'meta:source_url'   => array( 'label' => __( 'Source page', 'form-captain' ), 'sensitive' => false, 'field' => null, 'removed' => false ),
			// Selected by default so withdrawn consents are marked in every export; can be deselected.
			'meta:consent_withdrawn' => array( 'label' => __( 'Consent withdrawn', 'form-captain' ), 'sensitive' => false, 'field' => null, 'removed' => false ),
		);
		$current = array();
		foreach ( MailBuilder::fields( (array) ( $form['schema'] ?? array() ) ) as $field ) {
			if ( ! self::exportable( $field ) ) {
				continue;
			}
			$current[ $field['id'] ]         = true;
			$cols[ 'field:' . $field['id'] ] = array(
				'label'     => (string) ( $field['label'] ?? $field['key'] ?? $field['id'] ),
				'sensitive' => ! empty( $field['sensitive'] ),
				'field'     => $field,
				'removed'   => false,
			);
		}
		foreach ( $this->forms->versions_for( (int) $form['id'] ) as $version ) {
			foreach ( MailBuilder::fields( $version['schema'] ) as $field ) {
				if ( ! self::exportable( $field ) || isset( $cols[ 'field:' . $field['id'] ] ) ) {
					continue;
				}
				$cols[ 'field:' . $field['id'] ] = array(
					/* translators: %s: field label */
					'label'     => sprintf( __( '%s (removed)', 'form-captain' ), (string) ( $field['label'] ?? $field['key'] ?? $field['id'] ) ),
					'sensitive' => ! empty( $field['sensitive'] ),
					'field'     => $field,
					'removed'   => true,
				);
			}
		}
		$extra = (array) apply_filters( 'form_captain_csv_columns_v1', array(), $form );
		foreach ( $extra as $key => $label ) {
			if ( is_string( $key ) && preg_match( '/^[a-z0-9_]{1,40}$/', $key ) ) {
				$cols[ 'extra:' . $key ] = array( 'label' => (string) $label, 'sensitive' => false, 'field' => null, 'removed' => false );
			}
		}
		return $cols;
	}

	/** @param array<string,mixed> $field */
	private static function exportable( array $field ): bool {
		return isset( $field['id'] ) && 'html' !== ( $field['type'] ?? '' );
	}

	/**
	 * Default selection: metadata and all non-sensitive fields.
	 *
	 * @param array<string,array<string,mixed>> $columns
	 * @return string[]
	 */
	public static function default_columns( array $columns ): array {
		return array_keys( array_filter( $columns, static fn( array $c ): bool => ! $c['sensitive'] ) );
	}

	/**
	 * Formula injection guard (T-CSV-01).
	 */
	public static function escape_cell( string $value, bool $numeric_field = false ): string {
		if ( $numeric_field && preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			return $value;
		}
		$trimmed = ltrim( $value, ' ' );
		if ( '' === $trimmed ) {
			return $value;
		}
		$first = mb_substr( $trimmed, 0, 1 );
		if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r", '＝', '＋', '－', '＠' ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/** RFC 4180 line with CRLF ending. */
	public static function line( array $cells, string $delimiter = ',' ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && ( str_contains( $cell, $delimiter ) || str_contains( $cell, '"' ) || str_contains( $cell, "\n" ) || str_contains( $cell, "\r" ) ) ) {
				$cell = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$out[] = $cell;
		}
		return implode( $delimiter, $out ) . "\r\n";
	}

	/**
	 * Normalises request arguments.
	 *
	 * @param array<string,mixed> $raw
	 * @return array{form_id:int,statuses:string[],date_from:string,date_to:string,columns:string[],bom:bool,delimiter:string}
	 */
	public static function parse_args( array $raw ): array {
		$statuses = array_values( array_intersect( array_map( 'strval', (array) ( $raw['status'] ?? array() ) ), EntryRepository::STATUSES ) );
		$date     = static function ( $v ): string {
			$v = is_string( $v ) ? trim( $v ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		return array(
			'form_id'   => (int) ( $raw['form_id'] ?? 0 ),
			'statuses'  => $statuses,
			'date_from' => $date( $raw['date_from'] ?? '' ),
			'date_to'   => $date( $raw['date_to'] ?? '' ),
			'columns'   => array_values( array_filter( array_map( 'strval', (array) ( $raw['columns'] ?? array() ) ) ) ),
			'bom'       => ! empty( $raw['bom'] ),
			'delimiter' => ';' === ( $raw['delimiter'] ?? ',' ) ? ';' : ',',
		);
	}

	/**
	 * Accepts raw request-style args (status, columns, …) or already parsed ones.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private static function normalize( array $args ): array {
		return isset( $args['statuses'] ) ? array_merge( self::parse_args( array() ), $args ) : self::parse_args( $args );
	}

	/**
	 * Writes the CSV to $stream. Caller must have checked permissions.
	 *
	 * @param array<string,mixed> $args see parse_args()
	 * @param resource            $stream
	 * @return int|\WP_Error number of data rows
	 */
	public function export( array $args, $stream ): int|\WP_Error {
		$args = self::normalize( $args );
		$plan = $this->prepare( $args );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		list( $form, $all, $selected, $query ) = $plan;

		if ( $args['bom'] ) {
			fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		$header = array();
		foreach ( $selected as $key ) {
			$header[] = self::escape_cell( $all[ $key ]['label'] );
		}
		fwrite( $stream, self::line( $header, $args['delimiter'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$versions = array();
		$rows     = 0;
		$after    = 0;
		$batch    = Limits::get( 'csv_batch' );
		do {
			$items = $this->entries->batch_after( $after, $batch, $query );
			if ( ! $items ) {
				break;
			}
			$after  = (int) end( $items )['id'];
			$items  = array_filter( $items, static fn( array $e ): bool => ! $args['statuses'] || in_array( $e['status'], $args['statuses'], true ) );
			$values = $this->entries->values_for_many( array_map( static fn( array $e ): int => (int) $e['id'], $items ) );
			foreach ( $items as $entry ) {
				$vid = (int) $entry['form_version_id'];
				if ( ! array_key_exists( $vid, $versions ) ) {
					$versions[ $vid ] = $this->forms->find_version( $vid );
				}
				$extra = (array) apply_filters( 'form_captain_csv_row', array(), $entry );
				$cells = array();
				foreach ( $selected as $key ) {
					$cells[] = $this->cell( $key, $all[ $key ], $entry, $values[ (int) $entry['id'] ] ?? array(), $versions[ $vid ], $extra );
				}
				fwrite( $stream, self::line( $cells, $args['delimiter'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				++$rows;
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		} while ( true );

		Logger::log(
			'info',
			'export_created',
			sprintf( 'user=%d rows=%d columns=%d statuses=%s from=%s to=%s', get_current_user_id(), $rows, count( $selected ), implode( '|', $args['statuses'] ) ?: 'active', $args['date_from'] ?: '-', $args['date_to'] ?: '-' ),
			array( 'form_id' => (int) $form['id'] )
		);
		return $rows;
	}

	/**
	 * Validates arguments before any output is sent.
	 *
	 * @param array<string,mixed> $args see parse_args()
	 * @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>,2:string[],3:array<string,mixed>}|\WP_Error
	 */
	public function prepare( array $args ): array|\WP_Error {
		$args = self::normalize( $args );
		$form = $args['form_id'] ? $this->forms->find( (int) $args['form_id'] ) : null;
		if ( ! $form ) {
			return new \WP_Error( 'invalid_request', __( 'Please choose a form to export.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$all      = $this->columns( $form );
		$selected = $args['columns'] ? array_values( array_intersect( $args['columns'], array_keys( $all ) ) ) : self::default_columns( $all );
		if ( ! $selected ) {
			return new \WP_Error( 'invalid_request', __( 'Please choose at least one column.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$query = $this->query_args( $form, $args );
		$total = (int) $this->entries->list( array_merge( $query, array( 'per_page' => 1 ) ) )['total'];
		if ( $total > self::MAX_ROWS ) {
			return new \WP_Error( 'export_too_large', __( 'This export is too large. Please narrow the date range.', 'form-captain' ), array( 'status' => 422 ) );
		}
		return array( $form, $all, $selected, $query );
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	private function query_args( array $form, array $args ): array {
		$query = array( 'form_id' => (int) $form['id'] );
		if ( 1 === count( $args['statuses'] ) ) {
			$query['status'] = $args['statuses'][0];
		} elseif ( count( $args['statuses'] ) > 1 ) {
			$query['any_status'] = true;
		}
		if ( $args['date_from'] ) {
			$query['date_from'] = get_gmt_from_date( $args['date_from'] . ' 00:00:00' );
		}
		if ( $args['date_to'] ) {
			$query['date_to'] = get_gmt_from_date( $args['date_to'] . ' 23:59:59' );
		}
		return $query;
	}

	/**
	 * @param array<string,mixed>                     $col
	 * @param array<string,mixed>                     $entry
	 * @param array<string,string|array<int,string>> $values
	 * @param array<string,mixed>|null                $version
	 * @param array<string,mixed>                     $extra
	 */
	private function cell( string $key, array $col, array $entry, array $values, ?array $version, array $extra ): string {
		if ( str_starts_with( $key, 'extra:' ) ) {
			$v = $extra[ substr( $key, 6 ) ] ?? '';
			return self::escape_cell( is_scalar( $v ) ? (string) $v : '' );
		}
		switch ( $key ) {
			case 'meta:id':
				return (string) (int) $entry['id'];
			case 'meta:created':
				return get_date_from_gmt( (string) $entry['created_gmt'], 'Y-m-d H:i:s' );
			case 'meta:status':
				return self::status_label( $entry );
			case 'meta:form_version':
				return $version ? (string) $version['version_no'] : '';
			case 'meta:source_url':
				return self::escape_cell( (string) ( $entry['source_url'] ?? '' ) );
			case 'meta:consent_withdrawn':
				return ! empty( $entry['consent_withdrawn_gmt'] ) ? get_date_from_gmt( (string) $entry['consent_withdrawn_gmt'], 'Y-m-d H:i:s' ) : '';
		}
		$field_id = substr( $key, 6 );
		if ( ! array_key_exists( $field_id, $values ) ) {
			return '';
		}
		$field = (array) $col['field'];
		if ( $version ) {
			foreach ( MailBuilder::fields( $version['schema'] ) as $vf ) {
				if ( $vf['id'] === $field_id ) {
					$field = $vf;
					break;
				}
			}
		}
		$value = MailBuilder::display_value( $field, $values[ $field_id ] );
		return self::escape_cell( $value, 'number' === ( $field['type'] ?? '' ) );
	}

	/** @param array<string,mixed> $entry */
	public static function status_label( array $entry ): string {
		if ( ! empty( $entry['anonymized_gmt'] ) ) {
			return __( 'Anonymized', 'form-captain' );
		}
		return match ( (string) $entry['status'] ) {
			'new' => __( 'New', 'form-captain' ),
			'read' => __( 'Read', 'form-captain' ),
			'archived' => __( 'Archived', 'form-captain' ),
			'spam' => __( 'Spam', 'form-captain' ),
			'trash' => __( 'Trash', 'form-captain' ),
			default => (string) $entry['status'],
		};
	}

	/**
	 * Permission/method/nonce checks for the admin-post handler.
	 */
	public function check_request( string $method, array $request ): true|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::EXPORT_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to export submissions.', 'form-captain' ) );
		}
		if ( 'POST' !== strtoupper( $method ) ) {
			return new \WP_Error( 'method_not_allowed', __( 'Exports must be requested via POST.', 'form-captain' ), array( 'status' => 405 ) );
		}
		$nonce = isset( $request['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $request['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return Errors::error( Errors::INVALID_NONCE, __( 'Your session has expired. Please reload the page.', 'form-captain' ) );
		}
		// RL-08: one export at a time and at most 10 per hour per user.
		$user  = get_current_user_id();
		$count = (int) get_transient( 'fc_export_count_' . $user );
		if ( $count >= 10 || get_transient( 'fc_export_running_' . $user ) ) {
			return Errors::error( Errors::RATE_LIMITED, __( 'Too many exports. Please wait a moment.', 'form-captain' ) );
		}
		return true;
	}

	/** admin-post handler: streams the file and exits. */
	public function handle_request(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$check  = $this->check_request( $method, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_request().
		if ( is_wp_error( $check ) ) {
			wp_die( esc_html( $check->get_error_message() ), '', array( 'response' => (int) ( $check->get_error_data()['status'] ?? 403 ) ) );
		}
		$args = self::parse_args( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$plan = $this->prepare( $args );
		if ( is_wp_error( $plan ) ) {
			wp_die( esc_html( $plan->get_error_message() ), '', array( 'response' => (int) ( $plan->get_error_data()['status'] ?? 400 ) ) );
		}
		$form = $plan[0];
		$user = get_current_user_id();
		set_transient( 'fc_export_running_' . $user, 1, 10 * MINUTE_IN_SECONDS );
		set_transient( 'fc_export_count_' . $user, (int) get_transient( 'fc_export_count_' . $user ) + 1, HOUR_IN_SECONDS );

		$slug     = sanitize_title( (string) $form['title'] ) ?: 'form-' . (int) $form['id'];
		$filename = 'form-captain-' . $slug . '-' . wp_date( 'Y-m-d' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		try {
			$this->export( $args, $out );
		} finally {
			delete_transient( 'fc_export_running_' . $user );
			if ( is_resource( $out ) ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
		exit;
	}
}
