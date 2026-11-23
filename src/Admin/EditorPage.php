<?php
/**
 * Form editor (P2-05 … P2-15, P8-27): template chooser, editor app bootstrap and the
 * isolated preview endpoint (admin-post.php?action=form_captain_preview&token=…).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Domain\Fields\FieldTypes;
use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\PreviewTokens;
use FormCaptain\Service\Templates;
use FormCaptain\Settings\FormMessagingSettings;
use FormCaptain\Support\Asset;

final class EditorPage {

	public const HANDLE = 'form-captain-editor';

	public function __construct(
		private FormRepository $forms,
		private PreviewTokens $previews,
		private Templates $templates
	) {}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_form_captain_preview', array( $this, 'handle_preview' ) );
	}

	private static function current_form_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
	}

	public function assets( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'form-captain-edit' !== $_GET['page'] || ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return;
		}
		$id = self::current_form_id();
		if ( ! $id || ! $this->forms->find( $id ) ) {
			return;
		}
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( self::HANDLE, Asset::url( 'editor.css' ), array( 'wp-components' ), Asset::version( 'editor.css' ) );
		wp_enqueue_script( self::HANDLE, Asset::url( 'editor.js' ), array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ), Asset::version( 'editor.js' ), true );
		wp_set_script_translations( self::HANDLE, 'form-captain', FORM_CAPTAIN_DIR . 'languages' );
		// JSON_HEX_TAG: page titles and labels can never open/close tags or comments inside the inline <script> (SR-07).
		wp_add_inline_script( self::HANDLE, 'window.formCaptainEditor = ' . wp_json_encode( $this->bootstrap( $id ), JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * Data handed to the editor app.
	 *
	 * @return array<string,mixed>
	 */
	public function bootstrap( int $form_id ): array {
		$types = array();
		foreach ( FieldTypes::all() as $type => $def ) {
			$types[] = array( 'type' => $type ) + $def;
		}
		$pages = array();
		foreach ( get_pages(
			array(
				'post_status' => 'publish',
				'number'      => 500,
				'sort_column' => 'post_title',
			)
		) ?: array() as $page ) {
			if ( '' !== (string) $page->post_password ) {
				continue;
			}
			$pages[] = array(
				'id'    => (int) $page->ID,
				'title' => '' !== $page->post_title ? $page->post_title : '#' . $page->ID,
			);
		}
		$ext = self::extensions();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'fields';
		return array(
			'formId'          => $form_id,
			'restPath'        => '/' . AdminRest::NS . '/admin/forms/' . $form_id,
			'fieldTypes'      => $types,
			'pages'           => $pages,
			'actions'         => array_values( FormService::action_catalog() ),
			'canIntegrations' => current_user_can( Capabilities::MANAGE_INTEGRATIONS ),
			'formsUrl'        => admin_url( 'admin.php?page=form-captain-forms' ),
			'integrationsUrl' => admin_url( 'admin.php?page=form-captain-integrations' ),
			'adminEmail'      => (string) get_option( 'admin_email' ),
			'userEmail'       => (string) wp_get_current_user()->user_email,
			'initialTab'      => in_array( $tab, array( 'fields', 'notifications', 'confirmation', 'settings', 'actions' ), true ) ? $tab : 'fields',
			'messages'        => self::error_messages() + FormMessagingSettings::error_messages() + $ext['messages'], // Core messages win.
			'extensions'      => $ext['panels'],
			'proHint'         => DashboardPage::should_show_pro_hint() ? __( 'Need file uploads, multi-step forms or grouped conditions? They are available with Form Captain Pro.', 'form-captain' ) : '',
		);
	}

	/** Control types the editor can show for extensions (contract 1.1.0). */
	private const EXTENSION_CONTROLS = array( 'text', 'textarea', 'number', 'checkbox', 'select', 'rules', 'json' );

	/**
	 * Panels of extensions (filter form_captain_editor_extensions_v1), cleaned for the editor.
	 * Each panel: key, scope (field|form), label, description, types (field types; empty = all),
	 * controls (key, type, label, help, options), messages (error code => text).
	 *
	 * @return array{panels:array<int,array<string,mixed>>,messages:array<string,string>}
	 */
	public static function extensions(): array {
		$panels   = array();
		$messages = array();
		$seen     = array();
		foreach ( (array) apply_filters( 'form_captain_editor_extensions_v1', array() ) as $p ) {
			$key = is_array( $p ) ? (string) ( $p['key'] ?? '' ) : '';
			if ( ! preg_match( '/^[a-z][a-z0-9_]{1,40}$/', $key ) || isset( $seen[ $key ] ) || ! in_array( $p['scope'] ?? '', array( 'field', 'form' ), true ) ) {
				continue;
			}
			$controls = array();
			foreach ( (array) ( $p['controls'] ?? array() ) as $c ) {
				$ckey = is_array( $c ) ? (string) ( $c['key'] ?? '' ) : '';
				$type = is_array( $c ) ? (string) ( $c['type'] ?? 'text' ) : '';
				if ( ! preg_match( '/^[a-z][a-z0-9_]{1,40}$/', $ckey ) || ! in_array( $type, self::EXTENSION_CONTROLS, true ) ) {
					continue;
				}
				$options = array();
				foreach ( (array) ( $c['options'] ?? array() ) as $o ) {
					if ( is_array( $o ) && isset( $o['value'] ) ) {
						$options[] = array(
							'value' => (string) $o['value'],
							'label' => wp_strip_all_tags( (string) ( $o['label'] ?? $o['value'] ) ),
						);
					}
				}
				$controls[] = array(
					'key'     => $ckey,
					'type'    => $type,
					'label'   => wp_strip_all_tags( (string) ( $c['label'] ?? $ckey ) ),
					'help'    => wp_strip_all_tags( (string) ( $c['help'] ?? '' ) ),
					'options' => $options,
				);
			}
			if ( ! $controls ) {
				continue;
			}
			$seen[ $key ] = true;
			$panels[]     = array(
				'key'         => $key,
				'scope'       => (string) $p['scope'],
				'label'       => wp_strip_all_tags( (string) ( $p['label'] ?? $key ) ),
				'description' => wp_strip_all_tags( (string) ( $p['description'] ?? '' ) ),
				'types'       => array_values( array_filter( array_map( 'strval', (array) ( $p['types'] ?? array() ) ), static fn( string $t ): bool => (bool) preg_match( '/^[a-z_]{2,30}$/', $t ) ) ),
				'controls'    => $controls,
			);
			foreach ( (array) ( $p['messages'] ?? array() ) as $code => $text ) {
				if ( is_string( $code ) && preg_match( '/^[a-z][a-z0-9_]{1,40}$/', $code ) && ! isset( $messages[ $code ] ) ) {
					$messages[ $code ] = wp_strip_all_tags( (string) $text );
				}
			}
		}
		return array(
			'panels'   => $panels,
			'messages' => $messages,
		);
	}

	/** @return array<string,string> server error code => translated message */
	public static function error_messages(): array {
		return array(
			'invalid_field'                  => __( 'Invalid field.', 'form-captain' ),
			'unknown_type'                   => __( 'Unknown field type.', 'form-captain' ),
			'invalid_id'                     => __( 'Invalid field ID.', 'form-captain' ),
			'duplicate_id'                   => __( 'Field ID is used twice or was used before.', 'form-captain' ),
			'invalid_key'                    => __( 'Key must start with a lowercase letter and contain only a–z, 0–9 and _ (max. 40).', 'form-captain' ),
			'duplicate_key'                  => __( 'This key is already used by another field.', 'form-captain' ),
			'label_required'                 => __( 'Please enter a label.', 'form-captain' ),
			'consent_prechecked'             => __( 'Consent fields must never be pre-checked.', 'form-captain' ),
			'invalid_option'                 => __( 'Option values may only contain A–Z, a–z, 0–9, _ and - and need a label.', 'form-captain' ),
			'duplicate_option'               => __( 'Option values must be unique.', 'form-captain' ),
			'too_many_options'               => __( 'Too many options.', 'form-captain' ),
			'options_required'               => __( 'Add at least one option.', 'form-captain' ),
			'consent_text_required'          => __( 'Please enter the consent text.', 'form-captain' ),
			'invalid_condition_field'        => __( 'Conditions may only refer to an earlier field that has a value.', 'form-captain' ),
			'invalid_operator'               => __( 'Invalid condition operator.', 'form-captain' ),
			'invalid_pattern'                => __( 'Invalid pattern.', 'form-captain' ),
			'invalid_number'                 => __( 'Please enter a number.', 'form-captain' ),
			'min_greater_than_max'           => __( 'Minimum is greater than maximum.', 'form-captain' ),
			'invalid_date'                   => __( 'Use YYYY-MM-DD or "today".', 'form-captain' ),
			'too_many_fields'                => __( 'Too many fields (maximum 100).', 'form-captain' ),
			'schema_too_large'               => __( 'The form is too large.', 'form-captain' ),
			'no_input_fields'                => __( 'Add at least one input field before publishing.', 'form-captain' ),
			'invalid_recipient'              => __( 'One of the recipient addresses is invalid.', 'form-captain' ),
			'recipient_required'             => __( 'Enter at least one recipient or disable the notification.', 'form-captain' ),
			'invalid_reply_to_field'         => __( 'Reply-To must be an email field of this form.', 'form-captain' ),
			'fields_required'                => __( 'Select at least one field.', 'form-captain' ),
			'confirmation_recipient_missing' => __( 'Mark an email field as recipient of the confirmation email.', 'form-captain' ),
			'subject_required'               => __( 'Please enter a subject.', 'form-captain' ),
			'invalid_page'                   => __( 'Choose a published page.', 'form-captain' ),
			'invalid_email'                  => __( 'Invalid email address.', 'form-captain' ),
			'invalid_action'                 => __( 'Invalid action.', 'form-captain' ),
			'invalid_action_key'             => __( 'Invalid action.', 'form-captain' ),
			'connector_not_found'            => __( 'This action is no longer available.', 'form-captain' ),
			'invalid_mapping'                => __( 'Invalid field mapping.', 'form-captain' ),
			'sensitive_field'                => __( 'Sensitive fields cannot be passed to actions.', 'form-captain' ),
			'type_mismatch'                  => __( 'This field type cannot be used here.', 'form-captain' ),
			'required'                       => __( 'This value is required.', 'form-captain' ),
		);
	}

	// Rendering.

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage forms.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		$id = self::current_form_id();
		if ( ! $id ) {
			$this->render_chooser();
			return;
		}
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Edit form', 'form-captain' ) . '</h1><div class="notice notice-error inline"><p>' . esc_html__( 'Form not found.', 'form-captain' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap fc-editor-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'Edit form', 'form-captain' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-forms' ) ) . '">&larr; ' . esc_html__( 'Back to forms', 'form-captain' ) . '</a></p>';
		FormsPage::notices();
		echo '<div id="form-captain-editor" class="fc-editor" data-form="' . (int) $id . '"><p>' . esc_html__( 'Loading editor…', 'form-captain' ) . '</p></div>';
		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'The form editor needs JavaScript.', 'form-captain' ) . '</p></div></noscript>';
		echo '</div>';
	}

	private function render_chooser(): void {
		echo '<div class="wrap fc-admin"><h1>' . esc_html__( 'Create form', 'form-captain' ) . '</h1>';
		FormsPage::notices();
		echo '<p>' . esc_html__( 'Choose a template to start with. You can change every field afterwards.', 'form-captain' ) . '</p>';
		echo '<div class="fc-template-grid">';
		$items = array(
			array(
				'key'         => 'blank',
				'title'       => __( 'Blank form', 'form-captain' ),
				'description' => __( 'Start from scratch.', 'form-captain' ),
				'fields'      => 0,
			),
		);
		foreach ( $this->templates->all() as $tpl ) {
			$items[] = $tpl;
		}
		foreach ( $items as $tpl ) {
			echo '<div class="fc-card"><h2>' . esc_html( $tpl['title'] ) . '</h2><p>' . esc_html( $tpl['description'] ) . '</p>';
			if ( $tpl['fields'] ) {
				/* translators: %d: number of fields */
				echo '<p class="description">' . esc_html( sprintf( _n( '%d field', '%d fields', (int) $tpl['fields'], 'form-captain' ), (int) $tpl['fields'] ) ) . '</p>';
			}
			echo '<p><a class="button button-primary" href="' . esc_url( FormsPage::action_url( 'create', 0, array( 'template' => $tpl['key'] ) ) ) . '">' . esc_html__( 'Use this template', 'form-captain' ) . '<span class="screen-reader-text">: ' . esc_html( $tpl['title'] ) . '</span></a></p></div>';
		}
		echo '</div>';
		echo '<p>' . esc_html__( 'You can also import a form definition (JSON) on the forms overview.', 'form-captain' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=form-captain-forms' ) ) . '">' . esc_html__( 'Go to forms', 'form-captain' ) . '</a></p>';
		echo '</div>';
	}

	// Preview (P2-14, P2-15).

	/**
	 * Builds the isolated preview document. Never creates entries, mails or jobs:
	 * no submission token is issued, frontend JS is not loaded and submit is blocked.
	 */
	public function preview_html( string $token ): string|\WP_Error {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to preview forms.', 'form-captain' ), array( 'status' => 403 ) );
		}
		$form_id = $this->previews->verify( $token, get_current_user_id() );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}
		$form = $this->forms->find( $form_id );
		if ( ! $form ) {
			return new \WP_Error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		$renderer = '\\FormCaptain\\Frontend\\Renderer';
		if ( class_exists( $renderer ) ) {
			$body = (string) Plugin::instance()->get( $renderer )->render(
				$form,
				array(
					'preview' => true,
					'title'   => true,
				)
			);
		} else {
			$body = '<p>' . esc_html__( 'The form renderer is not available.', 'form-captain' ) . '</p>';
		}
		$styles = '';
		foreach ( array( 'frontend.css', 'form.css', 'forms.css' ) as $css ) {
			if ( is_readable( FORM_CAPTAIN_DIR . 'assets/src/' . $css ) || is_readable( FORM_CAPTAIN_DIR . 'assets/build/' . $css ) ) {
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone preview document without wp_head().
				$styles .= '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', Asset::version( $css ), Asset::url( $css ) ) ) . '">';
			}
		}
		$notice = esc_html__( 'Preview – submissions are disabled. Nothing is stored or sent.', 'form-captain' );
		$script = 'window.addEventListener("submit",function(e){e.preventDefault();e.stopImmediatePropagation();var n=document.getElementById("fc-preview-blocked");if(n){n.hidden=false;n.focus();}},true);';
		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
			. '<title>' . esc_html( sprintf( /* translators: %s: form title */ __( 'Preview: %s', 'form-captain' ), (string) $form['title'] ) ) . '</title>'
			. $styles
			. '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:16px;background:#fff;color:#1e1e1e}.fc-preview-bar{background:#f0f6fc;border-left:4px solid #72aee6;padding:8px 12px;margin:0 0 16px}.fc-preview-blocked{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;margin:16px 0}</style>'
			. '</head><body class="fc-preview"><p class="fc-preview-bar" role="note">' . $notice . '</p>'
			. $body
			. '<p id="fc-preview-blocked" class="fc-preview-blocked" role="status" tabindex="-1" hidden>' . $notice . '</p>'
			. '<script>' . $script . '</script></body></html>';
	}

	public function handle_preview(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the preview token is the capability.
		$token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		$html  = $this->preview_html( $token );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		if ( is_wp_error( $html ) ) {
			$data = (array) $html->get_error_data();
			wp_die( esc_html( $html->get_error_message() ), esc_html__( 'Preview', 'form-captain' ), array( 'response' => (int) ( $data['status'] ?? 400 ) ) );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts and the renderer.
		exit;
	}
}
