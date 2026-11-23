<?php
/**
 * Describing list of the per-form settings (SET-FC-28, docs/spec/einstellungen.md section 4).
 *
 * One source for defaults, sanitizing, labels and help texts of the values stored in a form's
 * `settings_json`. FormService uses it for new forms and for every save/import; the editor, the
 * form export and the documentation can read the same list.
 *
 * Adding a value: append one entry to definitions() in its section (keep the order of the
 * existing entries, it is the stored order). Each entry has:
 *  - type: bool | enum | string (one line) | text (multi-line) | email | int | page | field_ref |
 *          field_list | field_selection | recipients | list | custom
 *  - default: value or callable returning the value (evaluated when a new form is created)
 *  - label, help: translated texts for editor and help
 *  - options (enum), min/max (int), max_length (string/text), field_type (field_ref),
 *    max_items (recipients), empty_default (string/text: an empty value falls back to the default),
 *    too_long_error (string: error code when the value was shortened to max_length),
 *    error (error code used when the value is rejected), sanitize (callable for type custom:
 *    fn( mixed $raw, array $ctx ): array{0:mixed,1:?string}).
 * Cross checks between values of one section live in checks(). Invalid values fall back to the
 * default and are reported as error codes (path => code); the save itself never fails here.
 *
 * Not part of this list: `template`/`template_version` (provenance) and `x` (extension values,
 * contract 1.1.0); FormService keeps handling them.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Domain\Fields\FieldTypes;
use FormCaptain\Service\FormLimits;
use FormCaptain\Service\FormService;

final class FormSettingsSchema {

	/**
	 * Definitions keyed by path ("section.key" or a top-level "key"), in stored order.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$defs = array(
			// Notification to the site owner.
			'notification.enabled'        => array(
				'type'    => 'bool',
				'default' => true,
				'label'   => __( 'Send a notification', 'form-captain' ),
				'help'    => __( 'Sends an e-mail to the recipients below for every new submission.', 'form-captain' ),
			),
			'notification.to'             => array(
				'type'      => 'recipients',
				'default'   => array( '{admin_email}' ),
				'max_items' => 10,
				'error'     => 'invalid_recipient',
				'label'     => __( 'Recipients', 'form-captain' ),
				'help'      => __( 'Up to 10 addresses. {admin_email} stands for the site administrator address.', 'form-captain' ),
			),
			'notification.reply_to_field' => array(
				'type'       => 'field_ref',
				'field_type' => 'email',
				'default'    => '',
				'error'      => 'invalid_reply_to_field',
				'label'      => __( 'Reply-to field', 'form-captain' ),
				'help'       => __( 'An e-mail field of this form whose value is used as reply-to address.', 'form-captain' ),
			),
			'notification.subject'        => array(
				'type'       => 'string',
				'max_length' => 190,
				/* translators: {form_title} is a placeholder and must not be translated. */
				'default'    => static fn(): string => __( 'New submission: {form_title}', 'form-captain' ),
				'label'      => __( 'Subject', 'form-captain' ),
				'help'       => __( 'Placeholders such as {form_title} are replaced when the e-mail is sent.', 'form-captain' ),
			),
			'notification.fields'         => array(
				'type'    => 'field_selection',
				'default' => 'all_non_sensitive',
				'error'   => 'fields_required',
				'label'   => __( 'Fields in the notification', 'form-captain' ),
				'help'    => __( 'All fields not marked as sensitive, or a selection.', 'form-captain' ),
			),

			// Confirmation e-mail to the visitor.
			'confirmation_mail.enabled'   => array(
				'type'    => 'bool',
				'default' => false,
				'label'   => __( 'Send a confirmation e-mail', 'form-captain' ),
				'help'    => __( 'Needs an e-mail field marked as confirmation recipient.', 'form-captain' ),
			),
			'confirmation_mail.subject'   => array(
				'type'       => 'string',
				'max_length' => 190,
				'default'    => '',
				'label'      => __( 'Confirmation subject', 'form-captain' ),
				'help'       => __( 'Required when the confirmation e-mail is on.', 'form-captain' ),
			),
			'confirmation_mail.body'      => array(
				'type'       => 'text',
				'max_length' => 5000,
				'default'    => '',
				'label'      => __( 'Confirmation text', 'form-captain' ),
				'help'       => __( 'Plain text, up to 5000 characters.', 'form-captain' ),
			),
			'confirmation_mail.fields'    => array(
				'type'    => 'field_list',
				'default' => array(),
				'label'   => __( 'Fields in the confirmation', 'form-captain' ),
				'help'    => __( 'Submitted values that are repeated in the confirmation e-mail.', 'form-captain' ),
			),

			// What the visitor sees after sending.
			'confirmation.type'           => array(
				'type'    => 'enum',
				'options' => array(
					'message' => __( 'Show a message', 'form-captain' ),
					'page'    => __( 'Redirect to a page', 'form-captain' ),
				),
				'default' => 'message',
				'label'   => __( 'After sending', 'form-captain' ),
				'help'    => __( 'Show a message below the form or redirect to a published page.', 'form-captain' ),
			),
			'confirmation.message'        => array(
				'type'          => 'text',
				'max_length'    => 1000,
				'empty_default' => true,
				'default'       => static fn(): string => __( 'Thank you! Your submission has been received.', 'form-captain' ),
				'label'         => __( 'Confirmation message', 'form-captain' ),
				'help'          => __( 'Shown after a successful submission. Empty uses the default text.', 'form-captain' ),
			),
			'confirmation.page_id'        => array(
				'type'    => 'page',
				'default' => 0,
				'error'   => 'invalid_page',
				'label'   => __( 'Redirect page', 'form-captain' ),
				'help'    => __( 'A published page without password.', 'form-captain' ),
			),

			'paused_message'              => array(
				'type'          => 'text',
				'max_length'    => 500,
				'empty_default' => true,
				'default'       => static fn(): string => __( 'This form is currently not accepting submissions.', 'form-captain' ),
				'label'         => __( 'Message while paused', 'form-captain' ),
				'help'          => __( 'Shown instead of the form while it is paused.', 'form-captain' ),
			),

			// Copied from the global settings when a form is created (P2-20).
			'appearance.mode'             => array(
				'type'    => 'enum',
				'options' => array(
					'inherit' => __( 'Use theme styles', 'form-captain' ),
					'base'    => __( 'Form Captain base styles', 'form-captain' ),
				),
				'default' => static fn(): string => 'base' === Settings::get( 'appearance.mode' ) ? 'base' : 'inherit',
				'invalid' => 'inherit',
				'label'   => __( 'Form styling', 'form-captain' ),
				'help'    => __( 'Copied from the global setting when the form is created.', 'form-captain' ),
			),
			'email.from_name'             => array(
				'type'       => 'string',
				'max_length' => 190,
				'default'    => static fn(): string => (string) Settings::get( 'email.from_name' ),
				'label'      => __( 'Sender name', 'form-captain' ),
				'help'       => __( 'Copied from the global setting when the form is created.', 'form-captain' ),
			),
			'email.from_address'          => array(
				'type'    => 'email',
				'default' => static fn(): string => (string) Settings::get( 'email.from_address' ),
				'error'   => 'invalid_email',
				'label'   => __( 'Sender address', 'form-captain' ),
				'help'    => __( 'Copied from the global setting when the form is created. Empty uses the global sender.', 'form-captain' ),
			),

			// SET-FC-01: `global` or a retention period (manual, 1d…30y, see RetentionPeriod).
			'retention.mode'              => array(
				'type'     => 'custom',
				'default'  => 'global',
				'error'    => 'invalid_retention',
				'sanitize' => static function ( mixed $raw ): array {
					$mode = is_scalar( $raw ) ? trim( (string) $raw ) : 'global';
					if ( in_array( $mode, array( '', 'global', 'inherit' ), true ) ) {
						return array( 'global', null );
					}
					$period = RetentionPeriod::normalize( $mode );
					return null === $period ? array( 'global', 'invalid_retention' ) : array( $period, null );
				},
				'label'    => __( 'Keep submissions for', 'form-captain' ),
				'help'     => __( 'Overrides the global retention period for this form: "global", "manual" or a period such as 90d, 12m or 2y.', 'form-captain' ),
			),

			// Stored with save_actions() (OP-FRM-04); sanitize_settings() only passes the list through.
			'actions'                     => array(
				'type'     => 'list',
				'default'  => array(),
				'internal' => true,
				'label'    => __( 'Actions', 'form-captain' ),
				'help'     => __( 'Steps that run after a submission, such as notifications or anonymization.', 'form-captain' ),
			),

			// Texts per form (SET-FC-02, SET-FC-11); empty = translated default at render time.
			'submit_label'                => array(
				'type'           => 'string',
				'max_length'     => FormService::SUBMIT_LABEL_MAX,
				'too_long_error' => 'text_too_long',
				'default'        => '',
				'label'          => __( 'Submit button text', 'form-captain' ),
				'help'           => __( 'Text of the send button, up to 60 characters. Empty uses "Send".', 'form-captain' ),
			),
			'texts.required_hint'         => array(
				'type'           => 'string',
				'max_length'     => 190,
				'too_long_error' => 'text_too_long',
				'default'        => '',
				'label'          => __( 'Required fields hint', 'form-captain' ),
				'help'           => __( 'Shown above the form when it has required fields. Empty uses the default text.', 'form-captain' ),
			),
			'texts.error_summary'         => array(
				'type'           => 'string',
				'max_length'     => 190,
				'too_long_error' => 'text_too_long',
				'default'        => '',
				'label'          => __( 'Error summary', 'form-captain' ),
				'help'           => __( 'Heading of the error list after a failed submission. Empty uses the default text.', 'form-captain' ),
			),

			// Submission period and maximum number of entries (SET-FC-18); checks in FormLimits::sanitize().
			'limits.opens_at'             => array(
				'type'     => 'custom',
				'default'  => '',
				'sanitize' => static fn( mixed $raw, array $ctx ): array => self::limit_value( 'opens_at', $ctx ),
				'label'    => __( 'Accept submissions from', 'form-captain' ),
				'help'     => __( 'Date and time in the site time zone. Empty means the form is open right away.', 'form-captain' ),
			),
			'limits.closes_at'            => array(
				'type'     => 'custom',
				'default'  => '',
				'sanitize' => static fn( mixed $raw, array $ctx ): array => self::limit_value( 'closes_at', $ctx ),
				'label'    => __( 'Accept submissions until', 'form-captain' ),
				'help'     => __( 'Date and time in the site time zone; must be after the start. Empty means no end.', 'form-captain' ),
			),
			'limits.max_entries'          => array(
				'type'     => 'custom',
				'default'  => 0,
				'sanitize' => static fn( mixed $raw, array $ctx ): array => self::limit_value( 'max_entries', $ctx ),
				'label'    => __( 'Maximum number of entries', 'form-captain' ),
				'help'     => __( '0 means no limit. Spam and entries in the trash do not count.', 'form-captain' ),
			),
			'limits.closed_message'       => array(
				'type'     => 'custom',
				'default'  => '',
				'sanitize' => static fn( mixed $raw, array $ctx ): array => self::limit_value( 'closed_message', $ctx ),
				'label'    => __( 'Message when closed', 'form-captain' ),
				'help'     => __( 'Shown instead of the form outside the period or when the maximum is reached. Empty uses the default text.', 'form-captain' ),
			),
		);
		foreach ( $defs as $path => $def ) {
			$parts                    = explode( '.', $path, 2 );
			$defs[ $path ]['path']    = $path;
			$defs[ $path ]['section'] = isset( $parts[1] ) ? $parts[0] : '';
			$defs[ $path ]['key']     = $parts[1] ?? $parts[0];
		}
		return $defs;
	}

	/**
	 * Defaults for a new form (nested array in stored order).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( self::definitions() as $def ) {
			self::put( $out, $def, self::default_of( $def ) );
		}
		return $out;
	}

	/** @param array<string,mixed> $def */
	public static function default_of( array $def ): mixed {
		$default = $def['default'] ?? null;
		return $default instanceof \Closure ? $default() : $default;
	}

	/**
	 * Sanitizes all described values. Unknown keys are dropped.
	 *
	 * @param array<string,mixed>                $in     raw settings
	 * @param array<string,array<string,mixed>>  $fields value fields of the form keyed by ID
	 * @return array{settings:array<string,mixed>,errors:array<string,string>}
	 */
	public static function sanitize( array $in, array $fields ): array {
		$out    = array();
		$errors = array();
		foreach ( self::definitions() as $path => $def ) {
			$section_in            = '' === $def['section'] ? $in : ( is_array( $in[ $def['section'] ] ?? null ) ? $in[ $def['section'] ] : array() );
			$ctx                   = array(
				'fields'  => $fields,
				'section' => $section_in,
				'input'   => $in,
			);
			$present               = array_key_exists( $def['key'], $section_in );
			list( $value, $error ) = self::sanitize_value( $def, $present ? $section_in[ $def['key'] ] : null, $present, $ctx );
			if ( null !== $error ) {
				$errors[ $path ] = $error;
			}
			self::put( $out, $def, $value );
		}
		foreach ( self::checks() as $check ) {
			$check( $out, $errors, $fields );
		}
		return array(
			'settings' => $out,
			'errors'   => $errors,
		);
	}

	/**
	 * @param array<string,mixed>  $def
	 * @param array<string,mixed>  $ctx fields, section (raw section input), input (all raw input)
	 * @return array{0:mixed,1:?string} [value, error code]
	 */
	public static function sanitize_value( array $def, mixed $raw, bool $present, array $ctx ): array {
		$default = self::default_of( $def );
		$fields  = (array) ( $ctx['fields'] ?? array() );
		switch ( $def['type'] ) {
			case 'bool':
				return array( $present ? ! empty( $raw ) : (bool) $default, null );
			case 'enum':
				$raw = is_scalar( $raw ) ? (string) $raw : '';
				if ( array_key_exists( $raw, (array) $def['options'] ) ) {
					return array( $raw, null );
				}
				return array( $def['invalid'] ?? $default, ( $present && isset( $def['error'] ) ) ? (string) $def['error'] : null );
			case 'string':
				$value = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
				$error = null;
				if ( mb_strlen( $value ) > (int) ( $def['max_length'] ?? 190 ) ) {
					$value = mb_substr( $value, 0, (int) ( $def['max_length'] ?? 190 ) );
					$error = isset( $def['too_long_error'] ) ? (string) $def['too_long_error'] : null;
				}
				return array( ( '' === $value && ! empty( $def['empty_default'] ) ) ? $default : $value, $error );
			case 'text':
				$value = mb_substr( sanitize_textarea_field( is_scalar( $raw ) ? (string) $raw : '' ), 0, (int) ( $def['max_length'] ?? 5000 ) );
				return array( ( '' === trim( $value ) && ! empty( $def['empty_default'] ) ) ? $default : $value, null );
			case 'int':
				$int = is_numeric( $raw ) ? (int) $raw : (int) $default;
				if ( $int < (int) ( $def['min'] ?? PHP_INT_MIN ) || $int > (int) ( $def['max'] ?? PHP_INT_MAX ) ) {
					return array( $default, (string) ( $def['error'] ?? 'out_of_range' ) );
				}
				return array( $int, null );
			case 'email':
				$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( '' !== $value && ! is_email( $value ) ) {
					return array( $default, (string) ( $def['error'] ?? 'invalid_email' ) );
				}
				return array( $value, null );
			case 'page':
				return array( absint( is_scalar( $raw ) ? $raw : 0 ), null );
			case 'field_ref':
				$value = is_scalar( $raw ) ? (string) $raw : '';
				if ( '' !== $value && ( ! isset( $fields[ $value ] ) || ( isset( $def['field_type'] ) && $def['field_type'] !== $fields[ $value ]['type'] ) ) ) {
					return array( '', (string) ( $def['error'] ?? 'invalid_field' ) );
				}
				return array( $value, null );
			case 'field_list':
				return array( self::value_field_ids( $present ? (array) $raw : array(), $fields ), null );
			case 'field_selection':
				// 'all_non_sensitive' or an explicit list of field IDs (MailBuilder contract); the
				// editor may also send 'selected' plus a sibling `selected_fields` list.
				$section = (array) ( $ctx['section'] ?? array() );
				$list    = is_array( $raw ) ? $raw : ( 'selected' === $raw ? (array) ( $section['selected_fields'] ?? array() ) : null );
				if ( null === $list ) {
					return array( $default, null );
				}
				$ids = self::value_field_ids( $list, $fields );
				return array( $ids, $ids ? null : (string) ( $def['error'] ?? 'fields_required' ) );
			case 'recipients':
				$error = null;
				$list  = array();
				$raw   = is_string( $raw ) ? ( preg_split( '/[\s,;]+/', $raw ) ?: array() ) : ( $present ? (array) $raw : array() );
				foreach ( $raw as $addr ) {
					$addr = trim( is_scalar( $addr ) ? (string) $addr : '' );
					if ( '' === $addr ) {
						continue;
					}
					if ( '{admin_email}' === $addr || is_email( $addr ) ) {
						$list[] = '{admin_email}' === $addr ? $addr : strtolower( $addr );
					} else {
						$error = (string) ( $def['error'] ?? 'invalid_recipient' );
					}
				}
				return array( array_slice( array_values( array_unique( $list ) ), 0, (int) ( $def['max_items'] ?? 10 ) ), $error );
			case 'list':
				return array( is_array( $raw ) ? array_values( $raw ) : (array) $default, null );
			case 'custom':
				if ( isset( $def['sanitize'] ) && is_callable( $def['sanitize'] ) ) {
					$res = (array) call_user_func( $def['sanitize'], $raw, $ctx + array( 'present' => $present ) );
					return array( $res[0] ?? $default, isset( $res[1] ) ? (string) $res[1] : null );
				}
				return array( $default, null );
			default:
				return array( $default, null );
		}
	}

	/**
	 * Rules that combine several values. Each check gets the sanitized settings, the error list
	 * and the value fields of the form, and may reset values to safe defaults.
	 *
	 * @return array<string,callable(array<string,mixed>&,array<string,string>&,array<string,array<string,mixed>>):void>
	 */
	public static function checks(): array {
		return array(
			'notification'      => static function ( array &$out, array &$errors ): void {
				$n = &$out['notification'];
				if ( $n['enabled'] && ! $n['to'] ) {
					$errors['notification.to'] = $errors['notification.to'] ?? 'recipient_required';
				}
				if ( $n['enabled'] && '' === $n['subject'] ) {
					$n['subject'] = self::default_of( self::definitions()['notification.subject'] );
				}
			},
			'confirmation_mail' => static function ( array &$out, array &$errors, array $fields ): void {
				if ( empty( $out['confirmation_mail']['enabled'] ) ) {
					return;
				}
				$has_recipient = false;
				foreach ( $fields as $f ) {
					if ( 'email' === $f['type'] && ! empty( $f['confirmation_recipient'] ) ) {
						$has_recipient = true;
					}
				}
				if ( ! $has_recipient ) {
					$errors['confirmation_mail.enabled'] = 'confirmation_recipient_missing';
				}
				if ( '' === $out['confirmation_mail']['subject'] ) {
					$errors['confirmation_mail.subject'] = 'subject_required';
				}
			},
			'confirmation'      => static function ( array &$out, array &$errors ): void {
				$c = &$out['confirmation'];
				if ( 'page' !== $c['type'] ) {
					$c['page_id'] = 0;
					return;
				}
				$page = $c['page_id'] ? get_post( (int) $c['page_id'] ) : null;
				if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || '' !== (string) $page->post_password ) {
					$errors['confirmation.page_id'] = 'invalid_page';
					$c['type']                      = 'message';
					$c['page_id']                   = 0;
				}
			},
		);
	}

	/**
	 * Labels and help texts for editor and documentation, keyed by path.
	 *
	 * @return array<string,array{label:string,help:string}>
	 */
	public static function help(): array {
		$out = array();
		foreach ( self::definitions() as $path => $def ) {
			$out[ $path ] = array(
				'label' => (string) ( $def['label'] ?? $path ),
				'help'  => (string) ( $def['help'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * One value of settings.limits, checked together with the other limit values (FormLimits::sanitize()).
	 *
	 * @param array<string,mixed> $ctx
	 * @return array{0:mixed,1:?string}
	 */
	private static function limit_value( string $key, array $ctx ): array {
		$errors = array();
		$limits = FormLimits::sanitize( $ctx['section'] ?? array(), $errors );
		return array( $limits[ $key ], $errors[ 'limits.' . $key ] ?? null );
	}

	/**
	 * @param array<mixed>        $out
	 * @param array<string,mixed> $def
	 */
	private static function put( array &$out, array $def, mixed $value ): void {
		if ( '' === $def['section'] ) {
			$out[ $def['key'] ] = $value;
			return;
		}
		if ( ! isset( $out[ $def['section'] ] ) || ! is_array( $out[ $def['section'] ] ) ) {
			$out[ $def['section'] ] = array();
		}
		$out[ $def['section'] ][ $def['key'] ] = $value;
	}

	/**
	 * @param array<int|string,mixed>            $ids
	 * @param array<string,array<string,mixed>>  $fields
	 * @return string[]
	 */
	public static function value_field_ids( array $ids, array $fields ): array {
		$ids = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', array_values( $ids ) );
		return array_values( array_unique( array_filter( $ids, static fn( $fid ) => isset( $fields[ $fid ] ) && FieldTypes::has_value( (string) $fields[ $fid ]['type'] ) ) ) );
	}
}
