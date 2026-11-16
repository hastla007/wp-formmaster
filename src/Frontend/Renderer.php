<?php
/**
 * Semantic, accessible form markup (P3-01, P3-04, P3-14, P3-22).
 *
 * Works without JavaScript (POST to admin-post.php); frontend.js enhances it (REST submit, conditions,
 * token refresh). The HTML is cache-safe: it contains no WordPress nonce and no user data.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Frontend;

use FormCaptain\Domain\HtmlSanitizer;
use FormCaptain\Install\Capabilities;
use FormCaptain\Security\SignedContext;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\FormSchemaLoader;
use FormCaptain\Settings\Settings;

final class Renderer {

	public const RESULT_PARAM  = 'fc_r';
	public const RESULT_PREFIX = 'fc_r_';
	public const RESULT_TTL    = 600;

	private static int $instance = 0;

	/** @var array<string,int> renders per public ID (unique DOM IDs) */
	private static array $per_form = array();

	public function __construct(
		private FormSchemaLoader $loader,
		private SubmissionToken $tokens,
		private SignedContext $contexts
	) {}

	/**
	 * @param array<string,mixed> $form form row
	 * @param array{preview?:bool,context?:string,title?:bool,context_ref?:array<string,string>,source_post_id?:int} $args
	 */
	public function render( array $form, array $args = array() ): string {
		$public_id = (string) ( $form['public_id'] ?? '' );
		$status    = (string) ( $form['status'] ?? '' );
		$editor    = current_user_can( Capabilities::MANAGE_FORMS );
		$preview   = ! empty( $args['preview'] ) && $editor;

		if ( '' === $public_id ) {
			return $editor ? self::editor_notice( __( 'This form does not exist (anymore). Please choose another form.', 'form-captain' ) ) : '';
		}
		if ( ! $preview ) {
			if ( 'paused' === $status ) {
				$message = trim( (string) ( $form['settings']['paused_message'] ?? '' ) );
				$message = '' !== $message ? $message : __( 'This form is currently not accepting submissions.', 'form-captain' );
				$out     = '<div class="fc-form-wrap fc-form-wrap--paused"><p class="fc-paused" role="status">' . esc_html( $message ) . '</p></div>';
				return ( $editor ? self::editor_notice( __( 'This form is paused. Visitors see the message below instead of the form.', 'form-captain' ) ) : '' ) . $out;
			}
			if ( 'published' !== $status || null === $this->loader->published( $form ) ) {
				if ( ! $editor ) {
					return '';
				}
				return self::editor_notice(
					'archived' === $status
						? __( 'This form is archived and not shown to visitors.', 'form-captain' )
						: __( 'This form is not published yet and not shown to visitors.', 'form-captain' )
				);
			}
		}

		$version = $preview ? $this->loader->working( $form ) : $this->loader->published( $form );
		$fields  = $version['fields'] ?? array();
		$mode    = (string) ( $form['settings']['appearance']['mode'] ?? Settings::get( 'appearance.mode' ) );
		$mode    = 'base' === $mode ? 'base' : 'inherit';
		Assets::mark_rendered( $mode );

		++self::$instance;
		self::$per_form[ $public_id ] = ( self::$per_form[ $public_id ] ?? 0 ) + 1;
		$n                            = self::$instance;
		$wrap_id                      = 'fc-' . $public_id . ( self::$per_form[ $public_id ] > 1 ? '-' . self::$per_form[ $public_id ] : '' );
		$prefix                       = 'fc' . $n;

		$result = $preview ? null : self::load_result( $public_id );
		$errors = is_array( $result['errors'] ?? null ) ? $result['errors'] : array();
		$values = is_array( $result['values'] ?? null ) ? $result['values'] : array();

		$html  = '<div class="fc-form-wrap fc-mode-' . esc_attr( $mode ) . '" id="' . esc_attr( $wrap_id ) . '" data-fc-wrap="' . esc_attr( $public_id ) . '">';
		$html .= $preview ? '<p class="fc-preview-note">' . esc_html__( 'Preview – submissions are not saved.', 'form-captain' ) . '</p>' : '';
		if ( ! empty( $args['title'] ) ) {
			$html .= '<h2 class="fc-title">' . esc_html( (string) ( $form['title'] ?? '' ) ) . '</h2>';
		}

		// Success message (role=status). After a no-JS success it replaces the form.
		$success = is_array( $result ) && 'success' === ( $result['status'] ?? '' );
		$html   .= '<div class="fc-status" role="status" tabindex="-1"' . ( $success ? ' data-fc-focus="1"' : ' hidden' ) . '>' . ( $success ? '<p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p>' : '' ) . '</div>';
		if ( $success ) {
			return $html . '</div>';
		}

		$html .= $this->summary( $result, $errors, $fields, $prefix );
		$html .= '<div class="fc-live fc-sr-only" aria-live="polite"></div>';

		$token = $preview ? array( 'token' => '', 'issued_at' => 0 ) : $this->tokens->issue( $public_id );
		$attrs = array(
			'class'                => 'fc-form',
			'id'                   => $prefix . '-form',
			'method'               => 'post',
			'action'               => admin_url( 'admin-post.php' ),
			'accept-charset'       => 'UTF-8',
			'data-fc-form'         => $public_id,
			'data-fc-token-issued' => (string) $token['issued_at'],
		);
		if ( $preview ) {
			$attrs['data-fc-preview'] = '1';
		}
		$ctx_hidden = '';
		$context    = (string) apply_filters( 'form_captain_render_context_v1', (string) ( $args['context'] ?? '' ), $form );
		$ref        = is_array( $args['context_ref'] ?? null ) ? $args['context_ref'] : null;
		if ( null !== $ref && ! $preview ) {
			$attrs['data-fc-ctx-purpose']  = (string) ( $ref['purpose'] ?? '' );
			$attrs['data-fc-ctx-ref-type'] = (string) ( $ref['ref_type'] ?? '' );
			$attrs['data-fc-ctx-ref-id']   = (string) ( $ref['ref_id'] ?? '' );
			if ( '' === $context ) {
				// No-JS fallback: value signed at render time (JS replaces it with a fresh one).
				$signed  = $this->contexts->sign( $attrs['data-fc-ctx-purpose'], (int) $form['id'], $attrs['data-fc-ctx-ref-type'], $attrs['data-fc-ctx-ref-id'], 0, 'public_endpoint' );
				$context = ! empty( $signed['success'] ) ? (string) $signed['context'] : '';
			}
		}
		if ( '' !== $context && strlen( $context ) <= SignedContext::MAX_LENGTH ) {
			$ctx_hidden = '<input type="hidden" name="_fc_ctx" value="' . esc_attr( $context ) . '">';
		}

		$html  .= '<form' . self::attrs( $attrs ) . '>';
		$html  .= '<input type="hidden" name="action" value="form_captain_submit">';
		$html  .= '<input type="hidden" name="_fc_form" value="' . esc_attr( $public_id ) . '">';
		$html  .= '<input type="hidden" name="_fc_token" value="' . esc_attr( (string) $token['token'] ) . '">';
		$source = (int) ( $args['source_post_id'] ?? get_queried_object_id() );
		if ( $source > 0 ) {
			$html .= '<input type="hidden" name="_fc_src" value="' . esc_attr( (string) $source ) . '">';
		}
		$html .= $ctx_hidden;
		$html .= self::honeypot( $public_id, $prefix );

		$has_required = false;
		foreach ( $fields as $field ) {
			$has_required = $has_required || ! empty( $field['required'] );
		}
		if ( $has_required ) {
			$html .= '<p class="fc-required-note">' . sprintf(
				/* translators: %s: required marker (asterisk) */
				esc_html__( 'Fields marked with %s are required.', 'form-captain' ),
				'<span class="fc-required">*</span>'
			) . '</p>';
		}

		$html .= '<div class="fc-fields">';
		foreach ( $fields as $field ) {
			$html .= $this->field( $field, $prefix, $values, $errors );
		}
		$html .= '</div>';

		$label = trim( (string) ( $form['settings']['submit_label'] ?? '' ) );
		$html .= '<div class="fc-actions"><button type="submit" class="fc-submit wp-element-button">' . esc_html( '' !== $label ? $label : __( 'Send', 'form-captain' ) ) . '</button></div>';
		$html .= '</form></div>';
		return $html;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed>|null       $result
	 * @param array<string,string>           $errors
	 * @param array<int,array<string,mixed>> $fields
	 */
	private function summary( ?array $result, array $errors, array $fields, string $prefix ): string {
		$visible = is_array( $result ) && 'error' === ( $result['status'] ?? '' );
		$out     = '<div class="fc-summary" role="alert" tabindex="-1"' . ( $visible ? ' data-fc-focus="1"' : ' hidden' ) . '>';
		if ( $visible ) {
			$out .= '<p class="fc-summary__title">' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p>';
			if ( $errors ) {
				$labels = array();
				foreach ( $fields as $f ) {
					$labels[ (string) $f['id'] ] = (string) ( $f['label'] ?? '' );
				}
				$out .= '<ul>';
				foreach ( $errors as $id => $message ) {
					if ( ! isset( $labels[ $id ] ) ) {
						continue;
					}
					$out .= '<li><a href="#' . esc_attr( self::uid( $prefix, (string) $id ) ) . '">' . esc_html( $labels[ $id ] ) . ': ' . esc_html( (string) $message ) . '</a></li>';
				}
				$out .= '</ul>';
			}
		}
		return $out . '</div>';
	}

	private static function honeypot( string $public_id, string $prefix ): string {
		if ( ! Settings::get( 'spam.honeypot' ) ) {
			return '';
		}
		$name = SpamGuard::honeypot_name( $public_id );
		$id   = $prefix . '-hp';
		return '<div class="fc-hp" aria-hidden="true"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Leave this field empty', 'form-captain' ) . '</label>'
			. '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="" tabindex="-1" autocomplete="off"></div>';
	}

	private static function uid( string $prefix, string $field_id ): string {
		return $prefix . '-' . $field_id;
	}

	/**
	 * @param array<string,mixed>          $field
	 * @param array<string,mixed>          $values
	 * @param array<string,string>         $errors
	 */
	private function field( array $field, string $prefix, array $values, array $errors ): string {
		$id       = (string) $field['id'];
		$type     = (string) $field['type'];
		$uid      = self::uid( $prefix, $id );
		$name     = 'fc[' . $id . ']';
		$required = ! empty( $field['required'] );
		$error    = isset( $errors[ $id ] ) ? (string) $errors[ $id ] : '';
		$value    = $values[ $id ] ?? ( $field['default'] ?? '' );
		$cond     = is_array( $field['condition'] ?? null ) ? $field['condition'] : null;

		if ( 'hidden' === $type ) {
			return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $field['default'] ?? '' ) ) . '" data-fc-field="' . esc_attr( $id ) . '"' . ( $cond ? ' data-fc-condition="' . esc_attr( (string) wp_json_encode( $cond ) ) . '"' : '' ) . '>';
		}

		$wrap = array(
			'class'         => trim( 'fc-field fc-field--' . $type . ( $error ? ' fc-field--invalid' : '' ) . ' ' . (string) ( $field['css_class'] ?? '' ) ),
			'data-fc-field' => $id,
			'data-fc-type'  => $type,
		);
		if ( $cond ) {
			$wrap['data-fc-condition'] = (string) wp_json_encode( $cond );
		}

		if ( 'html' === $type ) {
			return '<div' . self::attrs( $wrap ) . '>' . HtmlSanitizer::clean( (string) ( $field['content'] ?? '' ) ) . '</div>';
		}

		$help_id   = $uid . '-help';
		$error_id  = $uid . '-error';
		$describe  = trim( ( '' !== (string) ( $field['help'] ?? '' ) ? $help_id . ' ' : '' ) . $error_id );
		$star      = $required ? ' <span class="fc-required" aria-hidden="true">*</span>' : '';
		$label     = esc_html( (string) ( $field['label'] ?? '' ) ) . $star;
		$help      = '' !== (string) ( $field['help'] ?? '' ) ? '<p class="fc-help" id="' . esc_attr( $help_id ) . '">' . esc_html( (string) $field['help'] ) . '</p>' : '';
		$error_out = '<p class="fc-error" id="' . esc_attr( $error_id ) . '"' . ( $error ? '' : ' hidden' ) . '>' . esc_html( $error ) . '</p>';
		$invalid   = $error ? array( 'aria-invalid' => 'true' ) : array();
		$req       = $required ? array(
			'required'      => true,
			'aria-required' => 'true',
		) : array();
		$options   = is_array( $field['options'] ?? null ) ? $field['options'] : array();
		$rules     = is_array( $field['validation'] ?? null ) ? $field['validation'] : array();

		switch ( $type ) {
			case 'radio':
			case 'checkbox':
				$group = array(
					'class'            => $wrap['class'] . ' fc-group',
					'aria-describedby' => $describe,
				) + array_diff_key( $wrap, array( 'class' => 1 ) );
				if ( 'radio' === $type ) {
					$group['role'] = 'radiogroup';
					if ( $required ) {
						$group['aria-required'] = 'true';
					}
					if ( $error ) {
						$group['aria-invalid'] = 'true';
					}
				}
				$selected = array_map( 'strval', (array) $value );
				$out      = '<fieldset' . self::attrs( $group ) . '><legend class="fc-label">' . $label . '</legend>' . $help . '<div class="fc-options">';
				foreach ( array_values( $options ) as $i => $opt ) {
					$oid   = $uid . '-' . $i;
					$input = array(
						'type'  => $type,
						'id'    => $oid,
						'name'  => 'checkbox' === $type ? $name . '[]' : $name,
						'value' => (string) $opt['value'],
					);
					if ( in_array( (string) $opt['value'], $selected, true ) ) {
						$input['checked'] = true;
					}
					if ( 'radio' === $type && $required ) {
						$input['required'] = true;
					}
					if ( 'checkbox' === $type && $error ) {
						$input['aria-invalid'] = 'true';
					}
					$out .= '<div class="fc-option"><input' . self::attrs( $input ) . '> <label for="' . esc_attr( $oid ) . '">' . esc_html( (string) $opt['label'] ) . '</label></div>';
				}
				return $out . '</div>' . $error_out . '</fieldset>';

			case 'consent':
				$text = HtmlSanitizer::clean( str_replace( '{privacy_policy_url}', esc_url( (string) get_privacy_policy_url() ), (string) ( $field['consent_text'] ?? '' ) ) );
				if ( ! empty( $field['privacy_link'] ) && '' !== (string) get_privacy_policy_url() ) {
					$text .= ' <a href="' . esc_url( (string) get_privacy_policy_url() ) . '">' . esc_html__( 'Privacy policy', 'form-captain' ) . '</a>';
				}
				// Never pre-checked (DS-C-01/02) – not even when re-displaying after an error.
				$input = array(
					'type'             => 'checkbox',
					'id'               => $uid,
					'name'             => $name,
					'value'            => '1',
					'aria-describedby' => $describe,
				) + $req + $invalid;
				return '<fieldset' . self::attrs( array( 'class' => $wrap['class'] . ' fc-group' ) + array_diff_key( $wrap, array( 'class' => 1 ) ) ) . '><legend class="fc-label">' . $label . '</legend>' . $help
					. '<div class="fc-option"><input' . self::attrs( $input ) . '> <label for="' . esc_attr( $uid ) . '">' . $text . '</label></div>' . $error_out . '</fieldset>';

			case 'select':
				$multiple = ! empty( $field['multiple'] );
				$selected = array_map( 'strval', (array) $value );
				$input    = array(
					'id'               => $uid,
					'name'             => $multiple ? $name . '[]' : $name,
					'aria-describedby' => $describe,
				) + $req + $invalid;
				if ( $multiple ) {
					$input['multiple'] = true;
				}
				$out = '<select' . self::attrs( $input ) . '>';
				if ( ! $multiple ) {
					$out .= '<option value="">' . esc_html__( '— Please choose —', 'form-captain' ) . '</option>';
				}
				foreach ( $options as $opt ) {
					$out .= '<option value="' . esc_attr( (string) $opt['value'] ) . '"' . ( in_array( (string) $opt['value'], $selected, true ) ? ' selected' : '' ) . '>' . esc_html( (string) $opt['label'] ) . '</option>';
				}
				$out .= '</select>';
				return '<div' . self::attrs( $wrap ) . '><label class="fc-label" for="' . esc_attr( $uid ) . '">' . $label . '</label>' . $help . $out . $error_out . '</div>';

			case 'textarea':
				$input = array(
					'id'               => $uid,
					'name'             => $name,
					'rows'             => (string) ( $field['rows'] ?? 5 ),
					'aria-describedby' => $describe,
				) + $req + $invalid;
				if ( isset( $rules['max_length'] ) ) {
					$input['maxlength'] = (string) $rules['max_length'];
				}
				if ( ! empty( $field['placeholder'] ) ) {
					$input['placeholder'] = (string) $field['placeholder'];
				}
				return '<div' . self::attrs( $wrap ) . '><label class="fc-label" for="' . esc_attr( $uid ) . '">' . $label . '</label>' . $help
					. '<textarea' . self::attrs( $input ) . '>' . esc_textarea( is_string( $value ) ? $value : '' ) . '</textarea>' . $error_out . '</div>';

			default:
				$html_type = match ( $type ) {
					'email' => 'email',
					'tel' => 'tel',
					'number' => 'text',
					'date' => 'date',
					default => 'text',
				};
				$input = array(
					'type'             => $html_type,
					'id'               => $uid,
					'name'             => $name,
					'value'            => is_string( $value ) ? $value : '',
					'aria-describedby' => $describe,
				) + $req + $invalid;
				if ( 'email' === $type ) {
					$input['autocomplete'] = 'email';
				} elseif ( 'tel' === $type ) {
					$input['autocomplete'] = 'tel';
				} elseif ( 'number' === $type ) {
					$input['inputmode'] = 'decimal';
				}
				if ( isset( $rules['max_length'] ) && in_array( $type, array( 'text', 'email' ), true ) ) {
					$input['maxlength'] = (string) $rules['max_length'];
				}
				if ( 'date' === $type ) {
					foreach ( array( 'min', 'max' ) as $k ) {
						if ( isset( $rules[ $k ] ) ) {
							$input[ $k ] = 'today' === $rules[ $k ] ? wp_date( 'Y-m-d' ) : (string) $rules[ $k ];
						}
					}
				}
				if ( ! empty( $field['placeholder'] ) ) {
					$input['placeholder'] = (string) $field['placeholder'];
				}
				return '<div' . self::attrs( $wrap ) . '><label class="fc-label" for="' . esc_attr( $uid ) . '">' . $label . '</label>' . $help
					. '<input' . self::attrs( $input ) . '>' . $error_out . '</div>';
		}
	}

	/**
	 * @param array<string,string|bool> $attrs
	 */
	private static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $k => $v ) {
			if ( true === $v ) {
				$out .= ' ' . esc_attr( $k );
			} elseif ( false !== $v && '' !== $v ) {
				$out .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
			}
		}
		return $out;
	}

	private static function editor_notice( string $message ): string {
		return '<div class="fc-notice fc-notice--editor" role="note"><p><strong>' . esc_html__( 'Form Captain:', 'form-captain' ) . '</strong> ' . esc_html( $message ) . ' <em>' . esc_html__( '(Only visible to form managers.)', 'form-captain' ) . '</em></p></div>';
	}

	// No-JS result hand-over via short-lived transient (only a random key in the URL).

	/**
	 * @param array<string,mixed> $data public_id, status (success|error), code, message, errors, values
	 */
	public static function store_result( array $data ): string {
		$key = bin2hex( random_bytes( 16 ) );
		set_transient( self::RESULT_PREFIX . $key, $data, self::RESULT_TTL );
		return $key;
	}

	/** @return array<string,mixed>|null */
	public static function load_result( string $public_id ): ?array {
		$key = isset( $_GET[ self::RESULT_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::RESULT_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return null;
		}
		$data = get_transient( self::RESULT_PREFIX . $key );
		if ( ! is_array( $data ) || ( $data['public_id'] ?? '' ) !== $public_id ) {
			return null;
		}
		return $data;
	}

	/** Test helper. */
	public static function reset_counters(): void {
		self::$instance = 0;
		self::$per_form = array();
	}
}
