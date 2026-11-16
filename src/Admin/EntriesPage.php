<?php
/**
 * Entries admin screen: list, detail view, actions, notes, delivery status and CSV export
 * (P4-04, P4-05, P4-06, P4-09, P4-10, P4-11, P5-15).
 *
 * Every ID-based action checks the capability first and then loads the object (T-IDOR-01).
 * No IP address or user agent is ever shown (none is stored).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Admin\Tables\EntriesListTable;
use FormCaptain\Domain\HtmlSanitizer;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Install\Capabilities;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Mail\MailJobHandler;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\ConsentService;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\RetentionService;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Errors;

final class EntriesPage {

	public const SLUG          = 'form-captain-entries';
	public const ACTION_BULK   = 'form_captain_entries_bulk';
	public const ACTION_ENTRY  = 'form_captain_entry_action';
	public const NONCE_BULK    = 'form_captain_entries_bulk';
	public const NONCE_ENTRY   = 'form_captain_entry_';
	public const ENTRY_ACTIONS = array( 'status', 'trash', 'restore', 'delete', 'note', 'retry', 'resend', 'withdraw_consent' );

	public function __construct(
		private EntryRepository $entries,
		private FormRepository $forms,
		private JobRepository $jobs,
		private EntryService $service,
		private CsvExporter $exporter
	) {}

	public function register( Plugin $plugin ): void {
		// Not modules on their own: registered here so they also run in cron/CLI requests.
		$plugin->get( MailJobHandler::class )->register( $plugin );
		$plugin->get( RetentionService::class )->register( $plugin );

		add_action( 'admin_post_' . self::ACTION_BULK, array( $this, 'handle_bulk' ) );
		add_action( 'admin_post_' . self::ACTION_ENTRY, array( $this, 'handle_entry_action' ) );
		add_action( 'admin_post_' . CsvExporter::ACTION, array( $this->exporter, 'handle_request' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	// Request handling.

	/**
	 * Bulk action from the list (nonce + capability per action, every ID checked).
	 *
	 * @param array<string,mixed> $request
	 * @return array{done:int[],skipped:array<int,array{id:int,code:string}>,action:string}|\WP_Error
	 */
	public function process_bulk( array $request ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN );
		}
		$nonce = (string) ( $request['_fc_nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_BULK ) ) {
			return Errors::error( Errors::INVALID_NONCE, __( 'Your session has expired. Please reload the page.', 'form-captain' ) );
		}
		$action = (string) ( $request['fc_bulk_action'] ?? '-1' );
		if ( '-1' === $action || '' === $action ) {
			$action = (string) ( $request['fc_bulk_action2'] ?? '-1' );
		}
		$result = $this->service->bulk( $action, array_map( 'intval', (array) ( $request['ids'] ?? array() ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['action'] = $action;
		return $result;
	}

	/**
	 * Single-entry action from the detail view.
	 *
	 * @param array<string,mixed> $request
	 */
	public function process_entry_action( array $request ): string|\WP_Error {
		$entry_id = (int) ( $request['entry_id'] ?? 0 );
		$do       = (string) ( $request['do'] ?? '' );
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN );
		}
		if ( ! wp_verify_nonce( (string) ( $request['_fc_nonce'] ?? '' ), self::NONCE_ENTRY . $entry_id ) ) {
			return Errors::error( Errors::INVALID_NONCE, __( 'Your session has expired. Please reload the page.', 'form-captain' ) );
		}
		if ( ! in_array( $do, self::ENTRY_ACTIONS, true ) ) {
			return new \WP_Error( 'invalid_request', __( 'Unknown action.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$result = match ( $do ) {
			'status' => $this->service->set_status( $entry_id, (string) ( $request['status'] ?? '' ) ),
			'trash' => $this->service->set_status( $entry_id, 'trash' ),
			'restore' => $this->service->restore( $entry_id ),
			'delete' => $this->service->delete( $entry_id ),
			'note' => $this->service->add_note( $entry_id, (string) ( $request['note'] ?? '' ) ),
			'retry' => $this->service->retry_job( $entry_id, (int) ( $request['job_id'] ?? 0 ) ),
			'resend' => $this->service->resend_notification( $entry_id ),
			'withdraw_consent' => $this->withdraw_consent( $entry_id, $request ),
		};
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'withdraw_consent' === $do ) {
			return 'deleted' === $result['result'] ? 'consent_withdrawn_deleted' : 'consent_withdrawn';
		}
		return match ( $do ) {
			'status' => 'status_changed',
			'trash' => 'trashed',
			'restore' => 'restored',
			'delete' => 'deleted',
			'note' => 'note_added',
			'retry', 'resend' => 'queued',
		};
	}

	/**
	 * Consent withdrawal from the detail view (explicit confirmation required).
	 *
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>|\WP_Error
	 */
	private function withdraw_consent( int $entry_id, array $request ): array|\WP_Error {
		$then = sanitize_key( (string) ( $request['then'] ?? 'keep' ) );
		if ( ! in_array( $then, ConsentService::THEN, true ) ) {
			return new \WP_Error( 'invalid_request', __( 'Unknown follow-up action.', 'form-captain' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( Capabilities::EDIT_ENTRIES ) || ( 'delete' === $then && ! current_user_can( Capabilities::DELETE_ENTRIES ) ) ) {
			return Errors::error( Errors::FORBIDDEN );
		}
		if ( empty( $request['confirm_withdrawal'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Please confirm the consent withdrawal.', 'form-captain' ), array( 'status' => 400 ) );
		}
		return Plugin::instance()->get( ConsentService::class )->withdraw_as_current_user( $entry_id, $then, 'admin' );
	}

	public function handle_bulk(): void {
		$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_bulk().
		$result  = $this->process_bulk( (array) $request );
		$back    = $this->safe_back( (string) ( $request['_fc_back'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			$this->die_or_redirect( $result, $back );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'fc_notice'  => 'bulk_done',
					'fc_done'    => count( $result['done'] ),
					'fc_skipped' => count( $result['skipped'] ),
				),
				$back
			)
		);
		exit;
	}

	public function handle_entry_action(): void {
		$request  = (array) wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process_entry_action().
		$result   = $this->process_entry_action( $request );
		$entry_id = (int) ( $request['entry_id'] ?? 0 );
		$back     = in_array( $result, array( 'deleted', 'consent_withdrawn_deleted' ), true ) ? self::url() : self::url( array( 'view' => $entry_id ) );
		if ( is_wp_error( $result ) ) {
			$this->die_or_redirect( $result, $back );
		}
		wp_safe_redirect( add_query_arg( 'fc_notice', $result, $back ) );
		exit;
	}

	private function die_or_redirect( \WP_Error $error, string $back ): void {
		if ( in_array( $error->get_error_code(), array( Errors::FORBIDDEN, Errors::INVALID_NONCE ), true ) ) {
			wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( array( 'fc_notice' => 'error', 'fc_code' => $error->get_error_code() ), $back ) );
		exit;
	}

	private function safe_back( string $url ): string {
		$url = wp_validate_redirect( $url, '' );
		return '' !== $url && str_contains( $url, 'page=' . self::SLUG ) ? $url : self::url();
	}

	// Rendering.

	public function render(): void {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) ) {
			wp_die( esc_html__( 'You are not allowed to view submissions.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen parameters.
		$view   = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		$export = ! empty( $_GET['export'] );
		// phpcs:enable
		echo '<div class="wrap form-captain-entries">';
		$this->render_notice();
		if ( $view ) {
			$this->render_detail( $view );
		} elseif ( $export ) {
			$this->render_export();
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['fc_notice'] ) ? sanitize_key( wp_unslash( $_GET['fc_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$type    = 'success';
		$message = match ( $code ) {
			'status_changed' => __( 'Status updated.', 'form-captain' ),
			'trashed' => __( 'Submission moved to the trash.', 'form-captain' ),
			'restored' => __( 'Submission restored.', 'form-captain' ),
			'deleted' => __( 'Submission deleted permanently.', 'form-captain' ),
			'note_added' => __( 'Note added.', 'form-captain' ),
			'queued' => __( 'The message was queued and will be sent shortly.', 'form-captain' ),
			'consent_withdrawn' => __( 'Consent withdrawal recorded. Open deliveries were cancelled.', 'form-captain' ),
			'consent_withdrawn_deleted' => __( 'Consent withdrawal recorded and the submission was deleted permanently.', 'form-captain' ),
			'bulk_done' => sprintf(
				/* translators: 1: processed count, 2: skipped count */
				__( '%1$s submissions processed, %2$s skipped.', 'form-captain' ),
				number_format_i18n( isset( $_GET['fc_done'] ) ? absint( $_GET['fc_done'] ) : 0 ),
				number_format_i18n( isset( $_GET['fc_skipped'] ) ? absint( $_GET['fc_skipped'] ) : 0 )
			),
			default => '',
		};
		if ( 'error' === $code ) {
			$type    = 'error';
			$message = self::error_message( isset( $_GET['fc_code'] ) ? sanitize_key( wp_unslash( $_GET['fc_code'] ) ) : '' );
		}
		// phpcs:enable
		if ( '' !== $message ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	private static function error_message( string $code ): string {
		return match ( $code ) {
			'conflict' => __( 'This action is not possible in the current state of the submission.', 'form-captain' ),
			'entry_not_found' => __( 'Submission not found.', 'form-captain' ),
			'job_not_found' => __( 'Job not found.', 'form-captain' ),
			'job_not_retryable' => __( 'Only failed deliveries can be retried.', 'form-captain' ),
			'validation_failed' => __( 'Notes must contain between 1 and 5000 characters.', 'form-captain' ),
			'invalid_request' => __( 'Invalid request.', 'form-captain' ),
			'confirmation_required' => __( 'Please confirm the consent withdrawal.', 'form-captain' ),
			default => __( 'The action could not be completed.', 'form-captain' ),
		};
	}

	/** @return array{form_id:int,status:string,search:string,date_from:string,date_to:string,paged:int,consent:string} */
	private function list_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$date   = static function ( string $key ): string {
			$v = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$out    = array(
			'form_id'   => isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0,
			'status'    => in_array( $status, array_merge( EntryRepository::STATUSES, array( 'expiring' ) ), true ) ? $status : '',
			'search'    => isset( $_GET['s'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 100 ) : '',
			'date_from' => $date( 'date_from' ),
			'date_to'   => $date( 'date_to' ),
			'paged'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);

		$consent        = isset( $_GET['consent'] ) ? sanitize_key( wp_unslash( $_GET['consent'] ) ) : '';
		$out['consent'] = in_array( $consent, array( 'withdrawn', 'active' ), true ) ? $consent : '';
		// phpcs:enable
		return $out;
	}

	private function render_list(): void {
		$filters = $this->list_filters();
		$base    = self::url(
			array_filter(
				array(
					'form_id'   => $filters['form_id'] ?: null,
					'status'    => $filters['status'] ?: null,
					's'         => $filters['search'] ?: null,
					'date_from' => $filters['date_from'] ?: null,
					'date_to'   => $filters['date_to'] ?: null,
					'consent'   => $filters['consent'] ?: null,
				)
			)
		);
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Submissions', 'form-captain' ) . '</h1>';
		if ( current_user_can( Capabilities::EXPORT_ENTRIES ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( array_filter( array( 'export' => 1, 'form_id' => $filters['form_id'] ?: null ) ) ) ) . '">' . esc_html__( 'Export CSV', 'form-captain' ) . '</a>';
		}
		echo '<hr class="wp-header-end" />';

		$upcoming = Plugin::instance()->get( RetentionService::class )->upcoming( 14, $filters['form_id'] );
		if ( $upcoming['count'] > 0 && null !== $upcoming['earliest'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: 1: number of submissions, 2: date */
					__( '%1$s submissions will be deleted automatically on or after %2$s according to your retention setting. Export them now if you still need them.', 'form-captain' ),
					number_format_i18n( $upcoming['count'] ),
					get_date_from_gmt( $upcoming['earliest'], (string) get_option( 'date_format' ) )
				)
			) . ' <a href="' . esc_url( self::url( array_filter( array( 'status' => 'expiring', 'form_id' => $filters['form_id'] ?: null ) ) ) ) . '">' . esc_html__( 'Show these submissions', 'form-captain' ) . '</a></p></div>';
		}
		if ( 'trash' === $filters['status'] ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: number of days */
					__( 'Items in the trash are deleted permanently after %s days.', 'form-captain' ),
					number_format_i18n( (int) Settings::get( 'privacy.trash_days' ) )
				)
			) . '</p>';
		}

		$table = new EntriesListTable( $this->entries, $this->forms, $filters, self::url( array_filter( array( 'form_id' => $filters['form_id'] ?: null ) ) ) );
		$table->prepare_items();
		$table->views();

		// Filters (GET).
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="fc-entries-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		if ( '' !== $filters['status'] ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $filters['status'] ) . '" />';
		}
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="fc-entry-search">' . esc_html__( 'Search submissions', 'form-captain' ) . '</label>';
		echo '<input type="search" id="fc-entry-search" name="s" value="' . esc_attr( $filters['search'] ) . '" /> ';
		submit_button( __( 'Search', 'form-captain' ), '', '', false );
		echo '</p>';
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="fc-filter-form">' . esc_html__( 'Filter by form', 'form-captain' ) . '</label>';
		echo '<select id="fc-filter-form" name="form_id"><option value="0">' . esc_html__( 'All forms', 'form-captain' ) . '</option>';
		foreach ( $this->all_forms() as $form ) {
			echo '<option value="' . (int) $form['id'] . '"' . selected( $filters['form_id'], (int) $form['id'], false ) . '>' . esc_html( (string) $form['title'] ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="fc-date-from">' . esc_html__( 'From', 'form-captain' ) . '</label> <input type="date" id="fc-date-from" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" /> ';
		echo '<label for="fc-date-to">' . esc_html__( 'To', 'form-captain' ) . '</label> <input type="date" id="fc-date-to" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" /> ';
		echo '<label class="screen-reader-text" for="fc-filter-consent">' . esc_html__( 'Filter by consent', 'form-captain' ) . '</label>';
		echo '<select id="fc-filter-consent" name="consent">';
		echo '<option value="">' . esc_html__( 'Any consent state', 'form-captain' ) . '</option>';
		echo '<option value="withdrawn"' . selected( $filters['consent'], 'withdrawn', false ) . '>' . esc_html__( 'Consent withdrawn', 'form-captain' ) . '</option>';
		echo '<option value="active"' . selected( $filters['consent'], 'active', false ) . '>' . esc_html__( 'Consent not withdrawn', 'form-captain' ) . '</option>';
		echo '</select> ';
		submit_button( __( 'Filter', 'form-captain' ), '', 'filter_action', false );
		echo '</div></form><br class="clear" />';

		// Bulk actions (POST to admin-post).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_BULK ) . '" />';
		echo '<input type="hidden" name="_fc_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE_BULK ) ) . '" />';
		echo '<input type="hidden" name="_fc_back" value="' . esc_attr( $base ) . '" />';
		$table->display();
		echo '</form>';
	}

	/** @return array<int,array<string,mixed>> */
	private function all_forms(): array {
		$out = array();
		foreach ( array( '', 'archived' ) as $status ) {
			$out = array_merge( $out, $this->forms->list( array( 'status' => $status, 'per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) )['items'] );
		}
		return $out;
	}

	private function render_detail( int $entry_id ): void {
		$entry = $this->entries->find( $entry_id );
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Back to submissions', 'form-captain' ) . '</a></p>';
		if ( ! $entry ) {
			echo '<h1>' . esc_html__( 'Submission not found', 'form-captain' ) . '</h1>';
			return;
		}
		if ( $this->service->mark_read( $entry_id ) ) {
			$entry = $this->entries->find( $entry_id ) ?? $entry;
		}
		$form    = $this->forms->find( (int) $entry['form_id'] );
		$version = $this->forms->find_version( (int) $entry['form_version_id'] );
		$schema  = $version ? $version['schema'] : (array) ( $form['schema'] ?? array() );
		$values  = $this->entries->values_for( $entry_id );
		$fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		/* translators: %d: submission ID */
		echo '<h1>' . esc_html( sprintf( __( 'Submission #%d', 'form-captain' ), $entry_id ) ) . ' <span class="fc-status fc-status-' . esc_attr( (string) $entry['status'] ) . '">' . esc_html( CsvExporter::status_label( $entry ) ) . '</span></h1>';
		if ( ! empty( $entry['anonymized_gmt'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Personal data of this submission was anonymized.', 'form-captain' ) . '</p></div>';
		}
		if ( ! empty( $entry['consent_withdrawn_gmt'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . EntriesListTable::consent_withdrawn_badge( $entry ) . ' ' . esc_html__( 'No further messages or integration deliveries are sent for this submission.', 'form-captain' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is escaped.
		}
		if ( ! empty( $entry['expires_gmt'] ) ) {
			/* translators: %s: date */
			echo '<p class="description">' . esc_html( sprintf( __( 'Will be deleted automatically on %s.', 'form-captain' ), get_date_from_gmt( (string) $entry['expires_gmt'], (string) get_option( 'date_format' ) ) ) ) . '</p>';
		}

		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
		echo '<div id="post-body-content">';

		// Answers.
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Answers', 'form-captain' ) . '</h2><div class="inside">';
		echo '<table class="widefat striped fc-answers"><tbody>';
		$current_ids = array();
		foreach ( MailBuilder::fields( (array) ( $form['schema'] ?? array() ) ) as $f ) {
			$current_ids[ $f['id'] ] = true;
		}
		$shown = array();
		foreach ( MailBuilder::fields( $schema ) as $field ) {
			if ( 'html' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$shown[ $field['id'] ] = true;
			$label                 = (string) ( $field['label'] ?? $field['key'] ?? $field['id'] );
			$marks                 = '';
			if ( $form && ! isset( $current_ids[ $field['id'] ] ) ) {
				$marks .= ' <span class="fc-badge">' . esc_html__( 'Field removed', 'form-captain' ) . '</span>';
			}
			if ( ! empty( $field['sensitive'] ) ) {
				$marks .= ' <span class="fc-badge">' . esc_html__( 'Sensitive', 'form-captain' ) . '</span>';
			}
			$value = array_key_exists( $field['id'], $values ) ? MailBuilder::display_value( $field, $values[ $field['id'] ] ) : '';
			echo '<tr><th scope="row">' . esc_html( $label ) . $marks . '</th><td>' . ( '' !== $value ? nl2br( esc_html( $value ) ) : '<span class="description">&mdash;</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $marks is built from escaped parts.
		}
		foreach ( $values as $field_id => $value ) {
			if ( ! isset( $shown[ $field_id ] ) ) {
				echo '<tr><th scope="row">' . esc_html( (string) $field_id ) . ' <span class="fc-badge">' . esc_html__( 'Field removed', 'form-captain' ) . '</span></th><td>' . nl2br( esc_html( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table></div></div>';

		$this->render_consent( $entry, $schema, $fmt );
		$this->render_jobs( $entry );
		$this->render_notes( $entry, $fmt );

		foreach ( (array) apply_filters( 'form_captain_entry_detail_sections_v1', array(), $entry ) as $section ) {
			if ( is_array( $section ) && isset( $section['title'], $section['html'] ) ) {
				echo '<div class="postbox"><h2 class="hndle">' . esc_html( (string) $section['title'] ) . '</h2><div class="inside">' . wp_kses_post( (string) $section['html'] ) . '</div></div>';
			}
		}
		echo '</div>'; // post-body-content.

		// Sidebar: details + actions.
		echo '<div id="postbox-container-1" class="postbox-container">';
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Details', 'form-captain' ) . '</h2><div class="inside"><dl class="fc-details">';
		$this->detail_row( __( 'Form', 'form-captain' ), $form ? (string) $form['title'] : __( '(deleted form)', 'form-captain' ) );
		if ( $version ) {
			$this->detail_row( __( 'Form version', 'form-captain' ), (string) $version['version_no'] );
		}
		$this->detail_row( __( 'Submitted', 'form-captain' ), get_date_from_gmt( (string) $entry['created_gmt'], $fmt ) );
		$this->detail_row( __( 'Last change', 'form-captain' ), get_date_from_gmt( (string) $entry['updated_gmt'], $fmt ) );
		$this->detail_row( __( 'Status', 'form-captain' ), CsvExporter::status_label( $entry ) );
		if ( ! empty( $entry['spam_reason'] ) ) {
			$this->detail_row( __( 'Spam reason', 'form-captain' ), EntriesListTable::spam_reason_label( (string) $entry['spam_reason'] ) );
		}
		if ( ! empty( $entry['source_url'] ) ) {
			$this->detail_row( __( 'Source page', 'form-captain' ), (string) $entry['source_url'] );
		}
		if ( ! empty( $entry['source_post_id'] ) ) {
			$title = get_the_title( (int) $entry['source_post_id'] );
			$this->detail_row( __( 'Source post', 'form-captain' ), '' !== $title ? $title : '#' . (int) $entry['source_post_id'] );
		}
		if ( ! empty( $entry['user_id'] ) ) {
			$user = get_userdata( (int) $entry['user_id'] );
			$this->detail_row( __( 'User', 'form-captain' ), $user ? $user->display_name : '#' . (int) $entry['user_id'] );
		}
		if ( ! empty( $entry['context'] ) && is_array( $entry['context'] ) ) {
			$ctx = $entry['context'];
			$this->detail_row( __( 'Context', 'form-captain' ), trim( (string) ( $ctx['purpose'] ?? '' ) . ' ' . (string) ( $ctx['ref_type'] ?? '' ) . ':' . (string) ( $ctx['ref_id'] ?? '' ) ) );
		}
		echo '</dl></div></div>';
		$this->render_actions( $entry );
		echo '</div>'; // sidebar.
		echo '</div></div>'; // poststuff.
	}

	private function detail_row( string $label, string $value ): void {
		echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( $value ) . '</dd>';
	}

	/**
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $schema
	 */
	private function render_consent( array $entry, array $schema, string $fmt ): void {
		if ( empty( $entry['consent'] ) || ! is_array( $entry['consent'] ) ) {
			return;
		}
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Consent record', 'form-captain' ) . '</h2><div class="inside"><ul>';
		foreach ( $entry['consent'] as $field_id => $record ) {
			$record = (array) $record;
			$text   = '';
			foreach ( MailBuilder::fields( $schema ) as $field ) {
				if ( $field['id'] === $field_id ) {
					$text = (string) ( $field['consent_text'] ?? '' );
				}
			}
			$when = ! empty( $record['given_at'] ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $record['given_at'] ) ), $fmt ) : get_date_from_gmt( (string) $entry['created_gmt'], $fmt );
			echo '<li>';
			if ( ! empty( $record['given'] ) ) {
				/* translators: 1: date, 2: revision number */
				echo esc_html( sprintf( __( 'Consent given on %1$s, text revision %2$s', 'form-captain' ), $when, (string) (int) ( $record['revision'] ?? 1 ) ) );
			} else {
				esc_html_e( 'Consent not given', 'form-captain' );
			}
			if ( '' !== $text ) {
				echo '<details><summary>' . esc_html__( 'Show wording', 'form-captain' ) . '</summary><div>' . wp_kses( HtmlSanitizer::clean( str_replace( '{privacy_policy_url}', esc_url( get_privacy_policy_url() ), $text ) ), HtmlSanitizer::allowed(), array( 'http', 'https', 'mailto' ) ) . '</div></details>';
			}
			echo '</li>';
		}
		echo '</ul></div></div>';
	}

	/** @param array<string,mixed> $entry */
	private function render_jobs( array $entry ): void {
		$jobs = $this->jobs->for_entry( (int) $entry['id'] );
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Delivery status', 'form-captain' ) . '</h2><div class="inside">';
		if ( ! $jobs ) {
			echo '<p>' . esc_html__( 'No notifications or actions were queued for this submission.', 'form-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Type', 'form-captain' ) . '</th><th>' . esc_html__( 'Status', 'form-captain' ) . '</th><th>' . esc_html__( 'Attempts', 'form-captain' ) . '</th><th>' . esc_html__( 'Last error', 'form-captain' ) . '</th><th>' . esc_html__( 'Updated', 'form-captain' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $jobs as $job ) {
				$type = (string) $job['type'];
				$cap  = str_starts_with( $type, 'connector.' ) ? Capabilities::MANAGE_INTEGRATIONS : Capabilities::EDIT_ENTRIES;
				echo '<tr><td>' . esc_html( self::job_label( $job ) ) . '</td>';
				echo '<td>' . esc_html( 'paused' === $job['status'] ? Plugin::instance()->get( ConnectorRegistry::class )->paused_label( $job ) : self::job_status_label( (string) $job['status'] ) ) . '</td>';
				echo '<td>' . esc_html( (int) $job['attempts'] . ' / ' . (int) $job['max_attempts'] ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $job['last_error_code'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( get_date_from_gmt( (string) $job['updated_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td><td>';
				if ( in_array( $job['status'], array( 'failed', 'dead' ), true ) && ( str_starts_with( $type, 'mail.' ) || str_starts_with( $type, 'connector.' ) ) && current_user_can( $cap ) ) {
					$this->action_form( $entry, 'retry', __( 'Send again', 'form-captain' ), array( 'job_id' => (int) $job['id'] ) );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( current_user_can( Capabilities::EDIT_ENTRIES ) && ! in_array( $entry['status'], array( 'spam', 'trash' ), true ) && empty( $entry['consent_withdrawn_gmt'] ) ) {
			echo '<p>';
			$this->action_form( $entry, 'resend', __( 'Send admin notification again', 'form-captain' ), array(), 'secondary' );
			echo '</p>';
		}
		echo '</div></div>';
	}

	/** @param array<string,mixed> $job */
	private static function job_label( array $job ): string {
		return match ( (string) $job['type'] ) {
			'mail.admin' => __( 'Admin notification', 'form-captain' ),
			'mail.confirmation' => __( 'Confirmation to submitter', 'form-captain' ),
			'connector.action' => sprintf(
				/* translators: %s: action key */
				__( 'Integration: %s', 'form-captain' ),
				(string) ( $job['payload']['action_key'] ?? '' )
			),
			default => (string) $job['type'],
		};
	}

	private static function job_status_label( string $status ): string {
		return match ( $status ) {
			'pending' => __( 'Queued', 'form-captain' ),
			'running' => __( 'Sending', 'form-captain' ),
			'succeeded' => __( 'Sent', 'form-captain' ),
			'failed' => __( 'Failed – will retry', 'form-captain' ),
			'dead' => __( 'Failed permanently', 'form-captain' ),
			'cancelled' => __( 'Cancelled', 'form-captain' ),
			'paused' => __( 'Paused (connector disabled)', 'form-captain' ),
			default => $status,
		};
	}

	/** @param array<string,mixed> $entry */
	private function render_notes( array $entry, string $fmt ): void {
		$notes = $this->service->notes( (int) $entry['id'] );
		if ( is_wp_error( $notes ) ) {
			return;
		}
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Internal notes', 'form-captain' ) . '</h2><div class="inside">';
		if ( ! $notes ) {
			echo '<p class="description">' . esc_html__( 'No notes yet.', 'form-captain' ) . '</p>';
		} else {
			echo '<ul class="fc-notes">';
			foreach ( $notes as $note ) {
				$user   = get_userdata( (int) $note['user_id'] );
				$author = $user ? $user->display_name : __( 'System', 'form-captain' );
				$text   = 'audit' === $note['type'] ? self::audit_label( (string) $note['message'] ) : (string) $note['message'];
				echo '<li class="fc-note fc-note-' . esc_attr( (string) $note['type'] ) . '"><div>' . nl2br( esc_html( $text ) ) . '</div><span class="description">' . esc_html( $author . ' · ' . get_date_from_gmt( (string) $note['created_gmt'], $fmt ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		if ( current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden_fields( $entry, 'note' );
			echo '<label for="fc-note" class="screen-reader-text">' . esc_html__( 'New note', 'form-captain' ) . '</label>';
			echo '<textarea id="fc-note" name="note" rows="3" class="large-text" maxlength="5000" required aria-describedby="fc-note-hint"></textarea>';
			echo '<p id="fc-note-hint" class="description">' . esc_html__( 'Notes are included when the person requests a copy of their data. Write factually.', 'form-captain' ) . '</p>';
			submit_button( __( 'Add note', 'form-captain' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div></div>';
	}

	public static function audit_label( string $code ): string {
		if ( preg_match( '/^status_([a-z]+)_([a-z]+)(_auto)?$/', $code, $m ) ) {
			$from = CsvExporter::status_label( array( 'status' => $m[1] ) );
			$to   = CsvExporter::status_label( array( 'status' => $m[2] ) );
			return ! empty( $m[3] )
				/* translators: 1: old status, 2: new status */
				? sprintf( __( 'Opened: status changed from %1$s to %2$s', 'form-captain' ), $from, $to )
				/* translators: 1: old status, 2: new status */
				: sprintf( __( 'Status changed from %1$s to %2$s', 'form-captain' ), $from, $to );
		}
		if ( preg_match( '/^restored_([a-z]+)$/', $code, $m ) ) {
			/* translators: %s: status */
			return sprintf( __( 'Restored from trash (%s)', 'form-captain' ), CsvExporter::status_label( array( 'status' => $m[1] ) ) );
		}
		if ( str_starts_with( $code, 'job_retry_' ) ) {
			return __( 'Delivery retried manually', 'form-captain' );
		}
		if ( 'notification_queued' === $code ) {
			return __( 'Admin notification queued manually', 'form-captain' );
		}
		if ( 'consent_withdrawn_anonymized' === $code ) {
			return __( 'Anonymized after consent withdrawal', 'form-captain' );
		}
		if ( str_starts_with( $code, 'consent_withdrawn_' ) ) {
			return __( 'Consent withdrawal recorded', 'form-captain' );
		}
		return $code;
	}

	/** @param array<string,mixed> $entry */
	private function render_actions( array $entry ): void {
		$status = (string) $entry['status'];
		echo '<div class="postbox"><h2 class="hndle">' . esc_html__( 'Actions', 'form-captain' ) . '</h2><div class="inside fc-actions">';
		if ( current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			$targets = array(
				'read'     => 'spam' === $status ? __( 'Not spam', 'form-captain' ) : ( 'archived' === $status ? __( 'Unarchive', 'form-captain' ) : __( 'Mark as read', 'form-captain' ) ),
				'archived' => __( 'Archive', 'form-captain' ),
				'spam'     => __( 'Mark as spam', 'form-captain' ),
			);
			foreach ( $targets as $to => $label ) {
				if ( EntryService::can_transition( $status, $to ) ) {
					$this->action_form( $entry, 'status', $label, array( 'status' => $to ), 'secondary' );
				}
			}
		}
		if ( current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			if ( 'trash' === $status ) {
				$this->action_form( $entry, 'restore', __( 'Restore', 'form-captain' ), array(), 'secondary' );
			} else {
				$this->action_form( $entry, 'trash', __( 'Move to trash', 'form-captain' ), array(), 'secondary' );
			}
			$this->action_form(
				$entry,
				'delete',
				__( 'Delete permanently', 'form-captain' ),
				array(),
				'delete',
				/* translators: %s: number of submissions */
				sprintf( __( 'Delete %s submissions permanently? This cannot be undone. Files and notes are deleted as well.', 'form-captain' ), '1' )
			);
		}
		echo '</div></div>';
		$this->render_consent_withdrawal( $entry );
	}

	/**
	 * "Withdraw consent" with follow-up choice and explicit confirmation (edit_entries; delete needs delete_entries).
	 *
	 * @param array<string,mixed> $entry
	 */
	private function render_consent_withdrawal( array $entry ): void {
		if ( ! current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			return;
		}
		echo '<div class="postbox fc-consent-withdrawal"><h2 class="hndle">' . esc_html__( 'Withdraw consent', 'form-captain' ) . '</h2><div class="inside">';
		if ( ! empty( $entry['consent_withdrawn_gmt'] ) ) {
			echo '<p>' . EntriesListTable::consent_withdrawn_badge( $entry ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is escaped.
			return;
		}
		$id = (int) $entry['id'];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden_fields( $entry, 'withdraw_consent' );
		echo '<p class="description">' . esc_html__( 'Records that the person withdrew their consent. Open notifications and integration deliveries are cancelled and no new ones are sent.', 'form-captain' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'What should happen with the submission?', 'form-captain' ) . '</legend>';
		$choices = array(
			'keep'      => __( 'Keep the submission', 'form-captain' ),
			'anonymize' => __( 'Anonymize personal data', 'form-captain' ),
		);
		if ( current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			$choices['delete'] = __( 'Delete the submission permanently', 'form-captain' );
		}
		foreach ( $choices as $value => $label ) {
			echo '<label for="fc-withdraw-' . esc_attr( $value . '-' . $id ) . '"><input type="radio" id="fc-withdraw-' . esc_attr( $value . '-' . $id ) . '" name="then" value="' . esc_attr( $value ) . '"' . checked( 'keep', $value, false ) . ' /> ' . esc_html( $label ) . '</label><br />';
		}
		echo '</fieldset>';
		echo '<p><label for="fc-withdraw-confirm-' . (int) $id . '"><input type="checkbox" id="fc-withdraw-confirm-' . (int) $id . '" name="confirm_withdrawal" value="1" required /> ' . esc_html__( 'I confirm that the person withdrew their consent. This cannot be undone.', 'form-captain' ) . '</label></p>';
		echo '<button type="submit" class="button">' . esc_html__( 'Withdraw consent', 'form-captain' ) . '</button>';
		echo '</form></div></div>';
	}

	/** @param array<string,mixed> $entry */
	private function hidden_fields( array $entry, string $do ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ENTRY ) . '" />';
		echo '<input type="hidden" name="entry_id" value="' . (int) $entry['id'] . '" />';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '" />';
		echo '<input type="hidden" name="_fc_nonce" value="' . esc_attr( wp_create_nonce( self::NONCE_ENTRY . (int) $entry['id'] ) ) . '" />';
	}

	/**
	 * @param array<string,mixed>      $entry
	 * @param array<string,int|string> $extra
	 */
	private function action_form( array $entry, string $do, string $label, array $extra = array(), string $style = 'secondary', string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fc-inline-form" style="display:inline-block;margin:0 4px 4px 0;"' . ( '' !== $confirm ? ' onsubmit="return window.confirm(\'' . esc_js( $confirm ) . '\');"' : '' ) . '>';
		$this->hidden_fields( $entry, $do );
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		echo '<button type="submit" class="button' . ( 'delete' === $style ? ' fc-button-delete" style="color:#8a1f11;border-color:#8a1f11' : '' ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private function render_export(): void {
		echo '<h1>' . esc_html__( 'Export submissions', 'form-captain' ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Back to submissions', 'form-captain' ) . '</a></p>';
		if ( ! current_user_can( Capabilities::EXPORT_ENTRIES ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to export submissions.', 'form-captain' ) . '</p>';
			return;
		}
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id ? $this->forms->find( $form_id ) : null;

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="export" value="1" />';
		echo '<label for="fc-export-form">' . esc_html__( 'Form', 'form-captain' ) . '</label> <select id="fc-export-form" name="form_id"><option value="0">' . esc_html__( 'Choose a form', 'form-captain' ) . '</option>';
		foreach ( $this->all_forms() as $f ) {
			echo '<option value="' . (int) $f['id'] . '"' . selected( $form_id, (int) $f['id'], false ) . '>' . esc_html( (string) $f['title'] ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Choose', 'form-captain' ), 'secondary', '', false );
		echo '</form>';
		if ( ! $form ) {
			echo '<p class="description">' . esc_html__( 'Columns depend on the form, so please choose one form first.', 'form-captain' ) . '</p>';
			return;
		}

		$columns = $this->exporter->columns( $form );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CsvExporter::ACTION ) . '" />';
		echo '<input type="hidden" name="form_id" value="' . (int) $form['id'] . '" />';
		wp_nonce_field( CsvExporter::NONCE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Columns', 'form-captain' ) . '</th><td><fieldset>';
		foreach ( $columns as $key => $col ) {
			$id = 'fc-col-' . md5( $key );
			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="columns[]" value="' . esc_attr( $key ) . '"' . checked( ! $col['sensitive'], true, false ) . ' /> ' . esc_html( $col['label'] ) . '</label>';
			if ( $col['sensitive'] ) {
				echo ' <span class="fc-badge">' . esc_html__( 'Sensitive – only exported when selected', 'form-captain' ) . '</span>';
			}
			echo '<br />';
		}
		echo '</fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Status', 'form-captain' ) . '</th><td><fieldset>';
		foreach ( EntryRepository::STATUSES as $status ) {
			echo '<label><input type="checkbox" name="status[]" value="' . esc_attr( $status ) . '" /> ' . esc_html( CsvExporter::status_label( array( 'status' => $status ) ) ) . '</label> ';
		}
		echo '<p class="description">' . esc_html__( 'Without a selection, all active submissions are exported (no spam, no trash).', 'form-captain' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Period', 'form-captain' ) . '</th><td>';
		echo '<label>' . esc_html__( 'From', 'form-captain' ) . ' <input type="date" name="date_from" /></label> <label>' . esc_html__( 'To', 'form-captain' ) . ' <input type="date" name="date_to" /></label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Format', 'form-captain' ) . '</th><td>';
		echo '<label><input type="checkbox" name="bom" value="1" checked="checked" /> ' . esc_html__( 'Add UTF-8 byte order mark (recommended for Excel)', 'form-captain' ) . '</label><br />';
		echo '<label>' . esc_html__( 'Separator', 'form-captain' ) . ' <select name="delimiter"><option value=",">,</option><option value=";">;</option></select></label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Download CSV', 'form-captain' ) );
		echo '</form>';
	}
}
