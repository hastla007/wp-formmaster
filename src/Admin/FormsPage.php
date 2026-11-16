<?php
/**
 * Forms overview, row actions via admin-post (P2-02, P2-03, P2-23) and JSON import/export (P4-12).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Admin\Tables\FormsListTable;
use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\PreviewTokens;
use FormCaptain\Support\Asset;

final class FormsPage {

	/** Row actions handled via admin-post.php?action=form_captain_form_{action}. */
	public const ACTIONS = array( 'create', 'duplicate', 'pause', 'resume', 'publish', 'unpublish', 'archive', 'restore', 'export', 'delete', 'import', 'preview' );

	/** Actions without form ID (nonce without ID suffix). */
	private const GLOBAL_ACTIONS = array( 'create', 'import' );

	public function __construct(
		private FormService $service,
		private FormRepository $forms,
		private PreviewTokens $previews
	) {}

	public function register( Plugin $plugin ): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_form_captain_form_' . $action, array( $this, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'form-captain' ) ) {
			return;
		}
		wp_enqueue_style( 'form-captain-forms-admin', Asset::url( 'forms-admin.css' ), array(), Asset::version( 'forms-admin.css' ) );
		wp_enqueue_script( 'form-captain-forms-admin', Asset::url( 'forms-admin.js' ), array(), Asset::version( 'forms-admin.js' ), true );
	}

	/**
	 * @param array<string,scalar> $extra
	 */
	public static function action_url( string $action, int $form_id = 0, array $extra = array() ): string {
		$args = array_merge( array( 'action' => 'form_captain_form_' . $action ), $form_id ? array( 'form' => $form_id ) : array(), $extra );
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::nonce_action( $action, $form_id ) );
	}

	public static function nonce_action( string $action, int $form_id = 0 ): string {
		return in_array( $action, self::GLOBAL_ACTIONS, true ) ? 'form_captain_form_' . $action : 'form_captain_form_' . $action . '_' . $form_id;
	}

	public static function entry_total( int $form_id ): int {
		return Plugin::instance()->get( FormService::class )->entry_total( $form_id );
	}

	/** admin-post entry point: verifies, processes, then redirects or downloads. */
	public function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_form_captain_form_' ) );
		$result = $this->dispatch( $action );
		if ( isset( $result['download'] ) ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $result['filename'] ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			echo $result['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
			exit;
		}
		wp_safe_redirect( $result['redirect'] );
		exit;
	}

	/**
	 * Capability + nonce check (dies on failure), then processing.
	 *
	 * @return array{redirect?:string,download?:string,filename?:string}
	 */
	public function dispatch( string $action ): array {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			wp_die( esc_html__( 'Unknown action.', 'form-captain' ), '', array( 'response' => 400 ) );
		}
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage forms.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified right below.
		$form_id = isset( $_REQUEST['form'] ) ? absint( $_REQUEST['form'] ) : 0;
		check_admin_referer( self::nonce_action( $action, $form_id ) );
		return $this->process( $action, $form_id );
	}

	/**
	 * @return array{redirect?:string,download?:string,filename?:string}
	 */
	private function process( string $action, int $form_id ): array {
		$list = admin_url( 'admin.php?page=form-captain-forms' );
		// phpcs:disable WordPress.Security.NonceVerification -- verified in dispatch().
		switch ( $action ) {
			case 'create':
				$template = isset( $_REQUEST['template'] ) ? sanitize_key( wp_unslash( $_REQUEST['template'] ) ) : '';
				$id       = '' !== $template && 'blank' !== $template ? $this->service->create_from_template( $template ) : $this->service->create_blank();
				if ( is_wp_error( $id ) ) {
					return array( 'redirect' => add_query_arg( 'fc_error', $id->get_error_code(), $list ) );
				}
				return array( 'redirect' => admin_url( 'admin.php?page=form-captain-edit&form=' . (int) $id . '&fc_notice=created' ) );
			case 'import':
				return array( 'redirect' => $this->import_upload( $list ) );
			case 'export':
				$data = $this->service->export( $form_id );
				if ( is_wp_error( $data ) ) {
					return array( 'redirect' => add_query_arg( 'fc_error', $data->get_error_code(), $list ) );
				}
				$slug = sanitize_title( (string) $data['title'] ) ?: 'form';
				return array(
					'download' => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					'filename' => 'form-captain-' . $slug . '.json',
				);
			case 'preview':
				if ( ! $this->forms->find( $form_id ) ) {
					return array( 'redirect' => add_query_arg( 'fc_error', 'not_found', $list ) );
				}
				return array( 'redirect' => $this->previews->create( $form_id, get_current_user_id() )['url'] );
			case 'duplicate':
				$res = $this->service->duplicate( $form_id );
				return array( 'redirect' => is_wp_error( $res ) ? add_query_arg( 'fc_error', $res->get_error_code(), $list ) : add_query_arg( 'fc_notice', 'duplicated', $list ) );
			case 'delete':
				$with = ! empty( $_REQUEST['delete_entries'] );
				$res  = $this->service->delete( $form_id, $with );
				return array( 'redirect' => is_wp_error( $res ) ? add_query_arg( 'fc_error', $res->get_error_code(), $list ) : add_query_arg( 'fc_notice', 'deleted', $list ) );
			case 'publish':
				$res = $this->service->publish( $form_id );
				break;
			default:
				$target = array(
					'pause'     => 'paused',
					'resume'    => 'published',
					'unpublish' => 'draft',
					'archive'   => 'archived',
					'restore'   => 'draft',
				)[ $action ];
				$res    = $this->service->set_status( $form_id, $target );
		}
		// phpcs:enable
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'fc_notice', 'fc_error' ), $back ) : $list;
		return array( 'redirect' => is_wp_error( $res ) ? add_query_arg( 'fc_error', $res->get_error_code(), $back ) : add_query_arg( 'fc_notice', $action, $back ) );
	}

	private function import_upload( string $list ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in dispatch(); only tmp_name/size/error/name are read and checked.
		$file = $_FILES['form_json'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$code = is_array( $file ) && in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'payload_too_large' : 'import_no_file';
			return add_query_arg( 'fc_error', $code, $list );
		}
		$name = (string) ( $file['name'] ?? '' );
		if ( ! str_ends_with( strtolower( $name ), '.json' ) ) {
			return add_query_arg( 'fc_error', 'unsupported_media_type', $list );
		}
		if ( (int) $file['size'] > FormService::IMPORT_MAX_BYTES ) {
			return add_query_arg( 'fc_error', 'payload_too_large', $list );
		}
		$json = (string) file_get_contents( (string) $file['tmp_name'], false, null, 0, FormService::IMPORT_MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return $this->import_result_url( $this->service->import( $json ), $list );
	}

	/**
	 * @param array{form_id:int,report:array<string,array<int,string>>}|\WP_Error $result
	 */
	public function import_result_url( array|\WP_Error $result, string $list ): string {
		$key = 'form_captain_import_report_' . get_current_user_id();
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			set_transient(
				$key,
				array(
					'error'   => $result->get_error_message(),
					'details' => array_slice( array_map( static fn( $p, $c ) => $p . ': ' . $c, array_keys( (array) ( $data['errors'] ?? array() ) ), (array) ( $data['errors'] ?? array() ) ), 0, 20 ),
				),
				300
			);
			return add_query_arg( 'fc_error', $result->get_error_code(), $list );
		}
		set_transient( $key, array( 'form_id' => $result['form_id'] ) + $result['report'], 300 );
		return add_query_arg( 'fc_notice', 'imported', $list );
	}

	// Rendering.

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage forms.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		$table = new FormsListTable( $this->forms );
		$table->prepare_items();
		echo '<div class="wrap fc-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Forms', 'form-captain' ) . '</h1> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=form-captain-edit' ) ) . '" class="page-title-action">' . esc_html__( 'Add new form', 'form-captain' ) . '</a>';
		echo '<hr class="wp-header-end">';
		self::notices();
		$this->import_report();
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="form-captain-forms">';
		$status = FormsListTable::current_status();
		if ( '' !== $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		}
		$table->search_box( __( 'Search forms', 'form-captain' ), 'fc-form-search' );
		$table->display();
		echo '</form>';
		$this->import_form();
		DashboardPage::pro_hint( 'forms' );
		echo '</div>';
	}

	private function import_form(): void {
		echo '<details class="fc-import"><summary>' . esc_html__( 'Import form from JSON', 'form-captain' ) . '</summary>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="form_captain_form_import">';
		wp_nonce_field( self::nonce_action( 'import' ) );
		echo '<input type="hidden" name="MAX_FILE_SIZE" value="' . (int) FormService::IMPORT_MAX_BYTES . '">';
		echo '<p><label for="fc-import-file">' . esc_html__( 'Form definition (.json, max. 256 KB)', 'form-captain' ) . '</label><br>';
		echo '<input type="file" id="fc-import-file" name="form_json" accept=".json,application/json" required></p>';
		echo '<p class="description">' . esc_html__( 'Only the form definition is imported – never entries, files or credentials. The form is created as a draft; connector actions stay disabled until you enable them.', 'form-captain' ) . '</p>';
		submit_button( __( 'Import', 'form-captain' ), 'secondary', 'submit', false );
		echo '</form></details>';
	}

	private function import_report(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['fc_notice'] ) && empty( $_GET['fc_error'] ) ) {
			return;
		}
		$key    = 'form_captain_import_report_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );
		if ( isset( $report['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $report['error'] ) . '</p>';
			if ( ! empty( $report['details'] ) ) {
				echo '<ul class="ul-disc">';
				foreach ( (array) $report['details'] as $line ) {
					echo '<li><code>' . esc_html( (string) $line ) . '</code></li>';
				}
				echo '</ul>';
			}
			echo '</div>';
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Import report', 'form-captain' ) . '</strong> – ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=form-captain-edit&form=' . (int) $report['form_id'] ) ) . '">' . esc_html__( 'Open imported form', 'form-captain' ) . '</a></p>';
		$sections = array(
			'recipients' => __( 'Please check these notification recipients:', 'form-captain' ),
			'sanitized'  => __( 'Cleaned values:', 'form-captain' ),
			'removed'    => __( 'Removed or reset values:', 'form-captain' ),
		);
		foreach ( $sections as $k => $label ) {
			if ( empty( $report[ $k ] ) ) {
				continue;
			}
			echo '<p>' . esc_html( $label ) . '</p><ul class="ul-disc">';
			foreach ( (array) $report[ $k ] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/** Translated notices for fc_notice / fc_error query args (whitelisted codes only). */
	public static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['fc_notice'] ) ? sanitize_key( wp_unslash( $_GET['fc_notice'] ) ) : '';
		$error  = isset( $_GET['fc_error'] ) ? sanitize_key( wp_unslash( $_GET['fc_error'] ) ) : '';
		// phpcs:enable
		$ok   = array(
			'created'      => __( 'Form created.', 'form-captain' ),
			'duplicated'   => __( 'Form duplicated. The copy is a draft; connector actions are disabled.', 'form-captain' ),
			'deleted'      => __( 'Form deleted.', 'form-captain' ),
			'publish'      => __( 'Form published.', 'form-captain' ),
			'pause'        => __( 'Form paused. Visitors see the pause message.', 'form-captain' ),
			'resume'       => __( 'Form resumed.', 'form-captain' ),
			'unpublish'    => __( 'Form moved back to draft.', 'form-captain' ),
			'archive'      => __( 'Form archived.', 'form-captain' ),
			'restore'      => __( 'Form restored as draft.', 'form-captain' ),
			'imported'     => __( 'Form imported as draft.', 'form-captain' ),
			'step_done'    => __( 'Step marked as done.', 'form-captain' ),
			'page_created' => __( 'Draft page with your form created.', 'form-captain' ),
		);
		$fail = array(
			'conflict'               => __( 'This action is not possible in the current state. Forms with entries can be archived instead of deleted.', 'form-captain' ),
			'forbidden'              => __( 'You are not allowed to do this.', 'form-captain' ),
			'not_found'              => __( 'Form not found.', 'form-captain' ),
			'schema_invalid'         => __( 'The form has errors. Open the editor to fix them before publishing.', 'form-captain' ),
			'schema_unsupported'     => __( 'This form was created with a newer version of Form Captain.', 'form-captain' ),
			'payload_too_large'      => __( 'The file is too large (maximum 256 KB).', 'form-captain' ),
			'unsupported_media_type' => __( 'Please upload a .json file.', 'form-captain' ),
			'import_no_file'         => __( 'Please choose a file to import.', 'form-captain' ),
			'storage_failed'         => __( 'The form could not be saved.', 'form-captain' ),
		);
		if ( isset( $ok[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $ok[ $notice ] ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $fail[ $error ] ?? __( 'The action could not be completed.', 'form-captain' ) ) . '</p></div>';
		}
	}
}
