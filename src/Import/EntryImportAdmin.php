<?php
/**
 * Admin UI for the CSV entry import (forms overview → "Import entries"):
 * 1 upload → 2 column mapping → 3 dry run → 4 batch import → result / revert.
 *
 * Every step is an admin-post action form_captain_form_entries_* routed through
 * FormsPage::dispatch() (manage_forms + per-form nonce); this class additionally checks
 * edit_entries (import) or delete_entries (revert). Rendered inside the forms page with
 * ?page=form-captain-forms&fc_entry_import=<form ID>. All values are escaped on output.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Import;

use FormCaptain\Admin\FormsPage;
use FormCaptain\Import\Profiles\GenericCsv;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\RetentionService;

final class EntryImportAdmin {

	public const ACTION_UPLOAD = 'entries_upload';
	public const ACTION_MAP    = 'entries_map';
	public const ACTION_IMPORT = 'entries_import';
	public const ACTION_CANCEL = 'entries_cancel';
	public const ACTION_REVERT = 'entries_revert';

	public const ACTIONS = array( self::ACTION_UPLOAD, self::ACTION_MAP, self::ACTION_IMPORT, self::ACTION_CANCEL, self::ACTION_REVERT );

	/** Query argument that opens the import screen for a form. */
	public const QUERY = 'fc_entry_import';

	public function __construct(
		private EntryImportService $service,
		private FormRepository $forms
	) {}

	/**
	 * @param array<string,mixed> $args
	 */
	public static function page_url( int $form_id, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'form-captain-forms', self::QUERY => $form_id ), $args ), admin_url( 'admin.php' ) );
	}

	private static function message_key(): string {
		return 'form_captain_eimp_msg_' . get_current_user_id();
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private static function fail( \WP_Error $error, int $form_id, array $args = array() ): string {
		set_transient( self::message_key(), $error->get_error_message(), 300 );
		return self::page_url( $form_id, $args + array( 'fc_ei_error' => $error->get_error_code() ) );
	}

	// Handlers.

	/**
	 * Handles one step (nonce + manage_forms verified by FormsPage::dispatch()). Returns the redirect URL.
	 */
	public function handle( string $action, int $form_id ): string {
		$allowed = self::ACTION_REVERT === $action ? EntryImportService::can_revert() : EntryImportService::can_import();
		if ( ! $allowed ) {
			return self::page_url( $form_id, array( 'fc_ei_error' => 'forbidden' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in FormsPage::dispatch().
		$token = isset( $_POST['fc_token'] ) ? sanitize_key( wp_unslash( $_POST['fc_token'] ) ) : '';
		if ( self::ACTION_UPLOAD !== $action && self::ACTION_REVERT !== $action ) {
			$state = $this->service->state( $token );
			if ( ! $state || (int) $state['form_id'] !== $form_id ) {
				return self::page_url( $form_id, array( 'fc_ei_error' => 'import_expired' ) );
			}
		}
		switch ( $action ) {
			case self::ACTION_UPLOAD:
				$csv = $this->uploaded_csv();
				if ( is_wp_error( $csv ) ) {
					return self::fail( $csv, $form_id );
				}
				$token = $this->service->stage( $form_id, $csv );
				return is_wp_error( $token ) ? self::fail( $token, $form_id ) : self::page_url( $form_id, array( 'fc_token' => $token ) );

			case self::ACTION_MAP:
				$columns = array();
				$raw     = isset( $_POST['fc_col'] ) && is_array( $_POST['fc_col'] ) ? map_deep( wp_unslash( $_POST['fc_col'] ), 'sanitize_key' ) : array();
				foreach ( $raw as $idx => $fid ) {
					$columns[ (int) $idx ] = is_string( $fid ) ? $fid : '';
				}
				$time_index  = isset( $_POST['fc_time_col'] ) && '' !== $_POST['fc_time_col'] ? (int) $_POST['fc_time_col'] : -1;
				$time_format = isset( $_POST['fc_time_format'] ) ? sanitize_key( wp_unslash( $_POST['fc_time_format'] ) ) : 'auto';
				if ( 'auto' !== $time_format && ! in_array( $time_format, GenericCsv::TIME_FORMATS, true ) ) {
					$time_format = 'auto';
				}
				$report = $this->service->map( $token, $columns, $time_index, $time_format );
				return is_wp_error( $report ) ? self::fail( $report, $form_id, array( 'fc_token' => $token ) ) : self::page_url( $form_id, array( 'fc_token' => $token ) );

			case self::ACTION_IMPORT:
				$result = $this->service->import_batch( $token );
				if ( is_wp_error( $result ) ) {
					return self::fail( $result, $form_id, array( 'fc_token' => $token ) );
				}
				return $result['done']
					? self::page_url( $form_id, array( 'fc_run' => $result['run_id'], 'fc_ei_notice' => 'imported' ) )
					: self::page_url( $form_id, array( 'fc_token' => $token ) );

			case self::ACTION_CANCEL:
				$this->service->discard( $token );
				return self::page_url( $form_id, array( 'fc_ei_notice' => 'cancelled' ) );

			case self::ACTION_REVERT:
				$run    = isset( $_POST['fc_run'] ) ? sanitize_key( wp_unslash( $_POST['fc_run'] ) ) : '';
				$result = $this->service->revert_batch( $form_id, $run );
				if ( is_wp_error( $result ) ) {
					return self::fail( $result, $form_id );
				}
				return $result['remaining'] > 0
					? self::page_url( $form_id, array( 'fc_revert' => $run ) )
					: self::page_url( $form_id, array( 'fc_run' => $run, 'fc_ei_notice' => 'reverted' ) );
		}
		// phpcs:enable
		return self::page_url( $form_id );
	}

	/** Reads the uploaded file of step 1 (real HTTP upload only). */
	private function uploaded_csv(): string|\WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in FormsPage::dispatch(); only tmp_name/size/error/name are read and checked.
		$file = $_FILES['fc_entry_csv'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$too_large = is_array( $file ) && in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return $too_large
				/* translators: %s: maximum file size, e.g. "5 MB" */
				? new \WP_Error( 'payload_too_large', sprintf( __( 'The file is too large (maximum %s).', 'form-captain' ), size_format( EntryImportService::MAX_BYTES ) ) )
				: new \WP_Error( 'import_no_file', __( 'Please choose a CSV file to import.', 'form-captain' ) );
		}
		if ( ! preg_match( '/\.(csv|txt)$/i', (string) ( $file['name'] ?? '' ) ) ) {
			return new \WP_Error( 'unsupported_media_type', __( 'Please upload a .csv file.', 'form-captain' ) );
		}
		if ( (int) $file['size'] > EntryImportService::MAX_BYTES ) {
			/* translators: %s: maximum file size, e.g. "5 MB" */
			return new \WP_Error( 'payload_too_large', sprintf( __( 'The file is too large (maximum %s).', 'form-captain' ), size_format( EntryImportService::MAX_BYTES ) ) );
		}
		return (string) file_get_contents( (string) $file['tmp_name'], false, null, 0, EntryImportService::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	// Rendering.

	/** Box in the forms overview: choose a published form. */
	public function render_entry_box(): void {
		if ( ! EntryImportService::can_import() ) {
			return;
		}
		$forms = array();
		foreach ( array( 'published', 'paused' ) as $status ) {
			$forms = array_merge( $forms, $this->forms->list( array( 'status' => $status, 'per_page' => 200 ) )['items'] );
		}
		echo '<details class="fc-import fc-import-entries" id="fc-entry-import"><summary>' . esc_html__( 'Import entries from a CSV file', 'form-captain' ) . '</summary>';
		if ( ! $forms ) {
			echo '<p>' . esc_html__( 'Entries can only be imported into a published form.', 'form-captain' ) . '</p></details>';
			return;
		}
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="form-captain-forms">';
		echo '<p><label for="fc-entry-import-form">' . esc_html__( 'Target form', 'form-captain' ) . '</label><br><select id="fc-entry-import-form" name="' . esc_attr( self::QUERY ) . '">';
		foreach ( $forms as $form ) {
			echo '<option value="' . (int) $form['id'] . '">' . esc_html( (string) $form['title'] ) . '</option>';
		}
		echo '</select></p>';
		submit_button( __( 'Continue', 'form-captain' ), 'secondary', '', false );
		echo '</form></details>';
	}

	/** Full import screen for one form. */
	public function render_page( int $form_id ): void {
		echo '<div class="wrap fc-admin fc-entry-import">';
		$form = $this->forms->find( $form_id );
		echo '<h1>' . esc_html__( 'Import entries', 'form-captain' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-forms' ) ) . '">' . esc_html__( '← Back to forms', 'form-captain' ) . '</a></p>';
		if ( ! $form ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Form not found.', 'form-captain' ) . '</p></div></div>';
			return;
		}
		if ( ! EntryImportService::can_import() && ! EntryImportService::can_revert() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You are not allowed to import entries.', 'form-captain' ) . '</p></div></div>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: form title */
				__( 'Target form: %s', 'form-captain' ),
				(string) $form['title']
			)
		) . '</p>';
		$this->notices();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view of the current user's own state.
		$token  = isset( $_GET['fc_token'] ) ? sanitize_key( wp_unslash( $_GET['fc_token'] ) ) : '';
		$run_id = isset( $_GET['fc_run'] ) ? sanitize_key( wp_unslash( $_GET['fc_run'] ) ) : '';
		$revert = isset( $_GET['fc_revert'] ) ? sanitize_key( wp_unslash( $_GET['fc_revert'] ) ) : '';
		// phpcs:enable
		$state = '' !== $token ? $this->service->state( $token ) : null;
		if ( $state && (int) $state['form_id'] !== $form_id ) {
			$state = null;
		}
		if ( '' !== $revert && EntryImportService::can_revert() ) {
			$this->render_revert_progress( $form_id, $revert );
		} elseif ( $state && EntryImportService::can_import() ) {
			match ( (string) $state['step'] ) {
				'check' => $this->render_check( $form, $token, $state ),
				'import' => $this->render_progress( $form_id, $token, $state ),
				default => $this->render_mapping( $form, $token, $state ),
			};
		} elseif ( '' !== $run_id && $this->service->run( $run_id ) ) {
			$this->render_result( $form_id, $run_id, (array) $this->service->run( $run_id ) );
		} elseif ( EntryImportService::can_import() ) {
			$this->render_upload( $form );
		}
		$this->render_runs( $form_id );
		echo '</div>';
	}

	private function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['fc_ei_notice'] ) ? sanitize_key( wp_unslash( $_GET['fc_ei_notice'] ) ) : '';
		$error  = isset( $_GET['fc_ei_error'] ) ? sanitize_key( wp_unslash( $_GET['fc_ei_error'] ) ) : '';
		// phpcs:enable
		$ok = array(
			'imported'  => __( 'Import finished.', 'form-captain' ),
			'cancelled' => __( 'Import cancelled. The uploaded file was deleted.', 'form-captain' ),
			'reverted'  => __( 'Import reverted. The imported entries were deleted permanently.', 'form-captain' ),
		);
		if ( isset( $ok[ $notice ] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $ok[ $notice ] ) . '</p></div>';
		}
		if ( '' === $error ) {
			return;
		}
		$message = get_transient( self::message_key() );
		delete_transient( self::message_key() );
		$fixed = array(
			'forbidden'      => __( 'You are not allowed to do this.', 'form-captain' ),
			'import_expired' => __( 'The upload has expired. Please upload the file again – rows imported before are recognised and skipped.', 'form-captain' ),
		);
		$text  = $fixed[ $error ] ?? ( is_string( $message ) && '' !== $message ? $message : __( 'The action could not be completed.', 'form-captain' ) );
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** @param array<string,mixed> $form */
	private function privacy_notice( array $form ): void {
		$mode  = RetentionService::mode_for_form( $form );
		$label = array(
			'90d'    => __( '90 days', 'form-captain' ),
			'12m'    => __( '12 months', 'form-captain' ),
			'manual' => __( 'no automatic deletion', 'form-captain' ),
		)[ $mode ] ?? $mode;
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Data protection', 'form-captain' ) . '</strong></p><ul class="ul-disc">';
		echo '<li>' . esc_html__( 'You are responsible for the legal basis of the imported data (for example consent given at the time). Only import data you are allowed to keep and process.', 'form-captain' ) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: retention period, e.g. "12 months" */
				__( 'Retention period of this form: %s. It is calculated from the original submission time – rows whose period has already expired are not imported, others are deleted automatically when it ends.', 'form-captain' ),
				$label
			)
		) . '</li>';
		echo '<li>' . esc_html__( 'The file is stored only temporarily in a protected directory and deleted after the import, at the latest after one hour. No mails or connector actions are triggered.', 'form-captain' ) . '</li>';
		echo '</ul></div>';
	}

	private static function hidden( string $action, int $form_id, string $token = '' ): void {
		echo '<input type="hidden" name="action" value="form_captain_form_' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="form" value="' . (int) $form_id . '">';
		if ( '' !== $token ) {
			echo '<input type="hidden" name="fc_token" value="' . esc_attr( $token ) . '">';
		}
		wp_nonce_field( FormsPage::nonce_action( $action, $form_id ) );
	}

	/** @param array<string,mixed> $form */
	private function render_upload( array $form ): void {
		$form_id = (int) $form['id'];
		echo '<h2>' . esc_html__( 'Step 1 of 4: Upload CSV file', 'form-captain' ) . '</h2>';
		if ( empty( $form['published_version_id'] ) || ! in_array( $form['status'], array( 'published', 'paused' ), true ) ) {
			echo '<p>' . esc_html__( 'Please publish the form before importing entries.', 'form-captain' ) . '</p>';
			return;
		}
		$this->privacy_notice( $form );
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( self::ACTION_UPLOAD, $form_id );
		echo '<input type="hidden" name="MAX_FILE_SIZE" value="' . (int) EntryImportService::MAX_BYTES . '">';
		echo '<p><label for="fc-entry-csv">' . esc_html(
			sprintf(
				/* translators: 1: maximum file size, 2: maximum number of rows */
				__( 'CSV file (UTF-8, separator comma or semicolon, header row; max. %1$s and %2$s rows)', 'form-captain' ),
				size_format( EntryImportService::MAX_BYTES ),
				number_format_i18n( EntryImportService::MAX_ROWS )
			)
		) . '</label><br>';
		echo '<input type="file" id="fc-entry-csv" name="fc_entry_csv" accept=".csv,text/csv" required></p>';
		echo '<p class="description">' . esc_html__( 'One row per submission and a column with the submission time. Multiple values (checkboxes) are separated by "|". Nothing is imported before you have checked the mapping and the dry run.', 'form-captain' ) . '</p>';
		submit_button( __( 'Upload and continue', 'form-captain' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/** @return array<string,string> */
	private static function format_labels(): array {
		return array(
			'auto'      => __( 'Detect automatically', 'form-captain' ),
			'iso'       => __( 'ISO 8601 / YYYY-MM-DD HH:MM:SS', 'form-captain' ),
			'ymd'       => __( 'YYYY-MM-DD [HH:MM[:SS]]', 'form-captain' ),
			'dmy_dot'   => __( 'DD.MM.YYYY [HH:MM[:SS]]', 'form-captain' ),
			'dmy_slash' => __( 'DD/MM/YYYY [HH:MM[:SS]]', 'form-captain' ),
			'mdy_slash' => __( 'MM/DD/YYYY [HH:MM[:SS]]', 'form-captain' ),
			'unix'      => __( 'Unix timestamp (seconds)', 'form-captain' ),
		);
	}

	/**
	 * Mappable fields of the published version.
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,array<string,mixed>>
	 */
	private function mappable_fields( array $form ): array {
		$version = $this->forms->find_version( (int) $form['published_version_id'] );
		$out     = array();
		foreach ( (array) ( $version['schema']['fields'] ?? array() ) as $f ) {
			if ( ! in_array( (string) $f['type'], array( 'html', 'hidden' ), true ) ) {
				$out[ (string) $f['id'] ] = $f;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $field
	 */
	private static function type_hint( array $field ): string {
		return match ( (string) $field['type'] ) {
			'email' => __( 'e-mail address', 'form-captain' ),
			'number' => __( 'number', 'form-captain' ),
			'date' => __( 'date YYYY-MM-DD', 'form-captain' ),
			'select', 'radio' => __( 'option label or value', 'form-captain' ),
			'checkbox' => __( 'option labels or values, separated by |', 'form-captain' ),
			'consent' => __( 'consent: 1 or empty', 'form-captain' ),
			'tel' => __( 'phone number', 'form-captain' ),
			'textarea' => __( 'multi-line text', 'form-captain' ),
			default => __( 'text', 'form-captain' ),
		};
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $state
	 */
	private function render_mapping( array $form, string $token, array $state ): void {
		$form_id = (int) $form['id'];
		$fields  = $this->mappable_fields( $form );
		$header  = (array) $state['header'];
		$current = (array) ( $state['columns'] ?? array() );
		echo '<h2>' . esc_html__( 'Step 2 of 4: Map columns', 'form-captain' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of rows, 2: separator character */
				__( '%1$s data rows, separator "%2$s".', 'form-captain' ),
				number_format_i18n( (int) $state['rows'] ),
				(string) $state['delimiter']
			)
		) . '</p>';

		// Suggestions: header equals field label or key; time column by name or format.
		$time_index = isset( $state['time_index'] ) ? (int) $state['time_index'] : -1;
		$suggest    = array();
		foreach ( $header as $idx => $name ) {
			$needle = mb_strtolower( trim( (string) $name ) );
			if ( -1 === $time_index && preg_match( '/zeit|datum|date|time|created|submitted|eingang/i', $needle ) ) {
				$time_index = (int) $idx;
				continue;
			}
			foreach ( $fields as $fid => $f ) {
				if ( in_array( $needle, array( mb_strtolower( (string) $f['label'] ), mb_strtolower( (string) $f['key'] ) ), true ) && ! in_array( $fid, $suggest, true ) ) {
					$suggest[ $idx ] = $fid;
					break;
				}
			}
		}
		$detected = $time_index >= 0 ? $this->service->detect_format( $token, $time_index ) : null;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( self::ACTION_MAP, $form_id, $token );
		echo '<table class="widefat striped fc-entry-mapping"><thead><tr><th scope="col">' . esc_html__( 'Column', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Example values (shortened)', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Import into', 'form-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $header as $idx => $name ) {
			$examples = array();
			foreach ( (array) $state['preview'] as $cells ) {
				$examples[] = (string) ( $cells[ $idx ] ?? '' );
			}
			$selected = (string) ( $current[ $idx ] ?? ( $suggest[ $idx ] ?? '' ) );
			$label_id = 'fc-col-' . (int) $idx;
			echo '<tr><th scope="row"><label for="' . esc_attr( $label_id ) . '">' . esc_html( '' !== (string) $name ? (string) $name : sprintf( /* translators: %d: column number */ __( 'Column %d', 'form-captain' ), (int) $idx + 1 ) ) . '</label></th>';
			echo '<td><code>' . implode( '</code><br><code>', array_map( 'esc_html', array_filter( $examples, static fn( string $s ): bool => '' !== $s ) ) ) . '</code></td>';
			echo '<td><select id="' . esc_attr( $label_id ) . '" name="fc_col[' . (int) $idx . ']"><option value="">' . esc_html__( '– Ignore –', 'form-captain' ) . '</option>';
			foreach ( $fields as $fid => $f ) {
				echo '<option value="' . esc_attr( $fid ) . '"' . selected( $selected, $fid, false ) . '>' . esc_html( (string) $f['label'] . ' (' . self::type_hint( $f ) . ')' ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Submission time', 'form-captain' ) . '</h3>';
		echo '<p><label for="fc-time-col">' . esc_html__( 'Column with the submission time', 'form-captain' ) . '</label><br><select id="fc-time-col" name="fc_time_col" required><option value="">' . esc_html__( '– Please choose –', 'form-captain' ) . '</option>';
		foreach ( $header as $idx => $name ) {
			echo '<option value="' . (int) $idx . '"' . selected( $time_index, (int) $idx, false ) . '>' . esc_html( '' !== (string) $name ? (string) $name : sprintf( /* translators: %d: column number */ __( 'Column %d', 'form-captain' ), (int) $idx + 1 ) ) . '</option>';
		}
		echo '</select></p>';
		$format = (string) ( $state['time_format'] ?? 'auto' );
		echo '<p><label for="fc-time-format">' . esc_html__( 'Date format', 'form-captain' ) . '</label><br><select id="fc-time-format" name="fc_time_format">';
		foreach ( self::format_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $format, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( null !== $detected ) {
			echo ' <span class="description">' . esc_html(
				sprintf(
					/* translators: %s: date format */
					__( 'Detected: %s', 'form-captain' ),
					self::format_labels()[ $detected ] ?? $detected
				)
			) . '</span>';
		}
		echo '</p><p class="description">' . esc_html(
			sprintf(
				/* translators: %s: time zone name */
				__( 'Times without an offset are read in the site time zone (%s) and stored in UTC.', 'form-captain' ),
				wp_timezone_string()
			)
		) . '</p>';
		submit_button( __( 'Check all rows (dry run)', 'form-captain' ), 'primary', 'submit', false );
		echo '</form>';
		$this->cancel_form( $form_id, $token );
	}

	private function cancel_form( int $form_id, string $token ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fc-entry-cancel">';
		self::hidden( self::ACTION_CANCEL, $form_id, $token );
		submit_button( __( 'Cancel and delete file', 'form-captain' ), 'link', 'submit', false );
		echo '</form>';
	}

	/** @param array<string,mixed> $report */
	private static function render_counts( array $report, bool $dry_run ): void {
		$rows = array(
			array( __( 'Rows in file', 'form-captain' ), (int) ( $report['rows'] ?? 0 ) ),
			$dry_run ? array( __( 'Valid (will be imported)', 'form-captain' ), (int) ( $report['valid'] ?? 0 ) ) : array( __( 'Imported', 'form-captain' ), (int) ( $report['imported'] ?? 0 ) ),
			array( __( 'Invalid (skipped)', 'form-captain' ), (int) ( $report['invalid'] ?? 0 ) ),
			array( __( 'Duplicates (already imported, skipped)', 'form-captain' ), (int) ( $report['duplicates'] ?? 0 ) ),
			array( __( 'Retention period already expired (skipped)', 'form-captain' ), (int) ( $report['expired'] ?? 0 ) ),
		);
		echo '<table class="widefat fc-entry-counts"><tbody>';
		foreach ( $rows as list( $label, $count ) ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$findings = (array) ( $report['findings'] ?? array() );
		if ( $findings ) {
			echo '<h3>' . esc_html__( 'Invalid rows', 'form-captain' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Row number in the file (header row = 1) and error code; values are not shown.', 'form-captain' ) . '</p>';
			echo '<table class="widefat striped fc-entry-findings"><thead><tr><th scope="col">' . esc_html__( 'Row', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Error code', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Field', 'form-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $findings as $f ) {
				echo '<tr><td>' . (int) $f['row'] . '</td><td><code>' . esc_html( (string) $f['code'] ) . '</code></td><td>' . esc_html( (string) ( $f['field'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( (int) ( $report['invalid'] ?? 0 ) > count( $findings ) ) {
				echo '<p class="description">' . esc_html__( 'Only the first invalid rows are listed.', 'form-captain' ) . '</p>';
			}
		}
	}

	/**
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $state
	 */
	private function render_check( array $form, string $token, array $state ): void {
		$form_id = (int) $form['id'];
		$check   = (array) $state['check'];
		echo '<h2>' . esc_html__( 'Step 3 of 4: Check (dry run)', 'form-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'All rows were checked. Nothing has been saved yet.', 'form-captain' ) . '</p>';
		self::render_counts( $check, true );
		$this->privacy_notice( $form );
		if ( (int) $check['valid'] > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::hidden( self::ACTION_IMPORT, $form_id, $token );
			submit_button(
				sprintf(
					/* translators: %s: number of entries */
					__( 'Import %s entries', 'form-captain' ),
					number_format_i18n( (int) $check['valid'] )
				),
				'primary',
				'submit',
				false
			);
			echo '</form>';
		} else {
			echo '<p><strong>' . esc_html__( 'No row can be imported. Please check the mapping or the file.', 'form-captain' ) . '</strong></p>';
		}
		echo '<details><summary>' . esc_html__( 'Change mapping', 'form-captain' ) . '</summary>';
		$this->render_mapping( $form, $token, $state );
		echo '</details>';
	}

	/** @param array<string,mixed> $state */
	private function render_progress( int $form_id, string $token, array $state ): void {
		$rows = max( 1, (int) $state['rows'] );
		$done = min( $rows, (int) $state['offset'] );
		echo '<h2>' . esc_html__( 'Step 4 of 4: Importing', 'form-captain' ) . '</h2>';
		echo '<p><progress max="' . (int) $rows . '" value="' . (int) $done . '"></progress> ' . esc_html(
			sprintf(
				/* translators: 1: processed rows, 2: total rows */
				__( '%1$s of %2$s rows processed', 'form-captain' ),
				number_format_i18n( $done ),
				number_format_i18n( $rows )
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( 'The import continues automatically. If it was interrupted, click "Continue" – rows already imported are not imported twice.', 'form-captain' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-fc-autosubmit="1">';
		self::hidden( self::ACTION_IMPORT, $form_id, $token );
		submit_button( __( 'Continue', 'form-captain' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/** @param array<string,mixed> $run */
	private function render_result( int $form_id, string $run_id, array $run ): void {
		echo '<h2>' . esc_html__( 'Import report', 'form-captain' ) . '</h2>';
		if ( 'reverted' === $run['status'] ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of entries */
					__( 'This import was reverted; %s entries were deleted.', 'form-captain' ),
					number_format_i18n( (int) ( $run['reverted'] ?? 0 ) )
				)
			) . '</p>';
		}
		self::render_counts( $run, false );
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=form-captain-entries&form_id=' . $form_id ) ) . '">' . esc_html__( 'View entries', 'form-captain' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::page_url( $form_id ) ) . '">' . esc_html__( 'Import another file', 'form-captain' ) . '</a></p>';
		unset( $run_id );
	}

	private function revert_form( int $form_id, string $run_id, string $label, bool $auto = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $auto ? ' data-fc-autosubmit="1"' : ' onsubmit="return window.confirm(this.dataset.confirm);"' ) . ' data-confirm="' . esc_attr__( 'Permanently delete all entries created by this import? This cannot be undone.', 'form-captain' ) . '">';
		self::hidden( self::ACTION_REVERT, $form_id );
		echo '<input type="hidden" name="fc_run" value="' . esc_attr( $run_id ) . '">';
		submit_button( $label, $auto ? 'primary' : 'delete', 'submit', false, array( 'id' => 'fc-revert-' . $run_id ) );
		echo '</form>';
	}

	private function render_revert_progress( int $form_id, string $run_id ): void {
		echo '<h2>' . esc_html__( 'Reverting import', 'form-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'The entries are deleted in batches. The process continues automatically.', 'form-captain' ) . '</p>';
		$this->revert_form( $form_id, $run_id, __( 'Continue', 'form-captain' ), true );
	}

	private function render_runs( int $form_id ): void {
		$runs = $this->service->runs_for_form( $form_id );
		if ( ! $runs ) {
			return;
		}
		$status = array(
			'running'  => __( 'interrupted / running', 'form-captain' ),
			'done'     => __( 'finished', 'form-captain' ),
			'reverted' => __( 'reverted', 'form-captain' ),
		);
		echo '<h2>' . esc_html__( 'Previous imports', 'form-captain' ) . '</h2>';
		echo '<table class="widefat striped fc-entry-runs"><thead><tr><th scope="col">' . esc_html__( 'Date', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Status', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Imported', 'form-captain' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'form-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $runs as $run_id => $run ) {
			echo '<tr><td>' . esc_html( get_date_from_gmt( (string) $run['created_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>' . esc_html( $status[ $run['status'] ] ?? (string) $run['status'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $run['imported'] ) ) . '</td><td>';
			echo '<a href="' . esc_url( self::page_url( $form_id, array( 'fc_run' => (string) $run_id ) ) ) . '">' . esc_html__( 'Report', 'form-captain' ) . '</a>';
			if ( current_user_can( Capabilities::DELETE_ENTRIES ) && EntryImportService::revertable( $run ) ) {
				$this->revert_form( $form_id, (string) $run_id, __( 'Revert import', 'form-captain' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: number of days */
				__( '"Revert import" permanently deletes exactly the entries created by that import – also if they were edited afterwards. Possible within %d days.', 'form-captain' ),
				EntryImportService::REVERT_DAYS
			)
		) . '</p>';
	}
}
