<?php
/**
 * Form lifecycle: create (blank/template/import), save with revision, publish, status
 * transitions, duplicate, delete, JSON export (P2-04, P2-11 … P2-13, P2-16, P2-20, P4-12).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Domain\Fields\FieldTypes;
use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Settings\FormSettingsSchema;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;

final class FormService {

	public const EXPORT_FORMAT = 'form-captain/form';

	public const IMPORT_MAX_BYTES = 262144;

	/** Maximum length of the submit button text (SET-FC-02). */
	public const SUBMIT_LABEL_MAX = 60;

	/** Allowed status transitions (docs/spec/zustaende.md). */
	public const TRANSITIONS = array(
		'draft'     => array( 'published', 'archived' ),
		'published' => array( 'paused', 'draft', 'archived' ),
		'paused'    => array( 'published', 'draft', 'archived' ),
		'archived'  => array( 'draft' ),
	);

	public function __construct(
		private FormRepository $forms,
		private SchemaValidator $validator,
		private EntryRepository $entries,
		private Templates $templates
	) {}

	// Creation.

	/** @return int|\WP_Error new form ID */
	public function create_blank( string $title = '' ): int|\WP_Error {
		$title = '' !== trim( $title ) ? $title : __( 'Untitled form', 'form-captain' );
		return $this->create(
			$title,
			array(
				'schema_version' => SchemaValidator::CURRENT_VERSION,
				'fields'         => array(),
			),
			array()
		);
	}

	/** @return int|\WP_Error new form ID */
	public function create_from_template( string $key ): int|\WP_Error {
		$tpl = $this->templates->get( $key );
		if ( null === $tpl ) {
			return Errors::error( 'not_found', __( 'This template does not exist.', 'form-captain' ), array( 'status' => 404 ) );
		}
		$settings                     = $tpl['settings'];
		$settings['template']         = $tpl['key'];
		$settings['template_version'] = $tpl['template_version'];
		return $this->create( $tpl['title'], $tpl['schema'], $settings );
	}

	/**
	 * Creates a draft form. Schema must be structurally valid (no errors).
	 *
	 * @param array<string,mixed> $schema
	 * @param array<string,mixed> $settings
	 * @return int|\WP_Error
	 */
	public function create( string $title, array $schema, array $settings ): int|\WP_Error {
		$result = $this->validator->validate( $schema );
		if ( isset( $result['errors']['schema_version'] ) ) {
			return $this->schema_error( Errors::SCHEMA_UNSUPPORTED, $result['errors'] );
		}
		if ( $result['errors'] ) {
			return $this->schema_error( Errors::SCHEMA_INVALID, $result['errors'] );
		}
		// Actions are only released via save_actions() (OP-FRM-10: manage_forms + manage_integrations).
		// A new form never starts with enabled or unsanitized actions (SR-01).
		$action_errors       = array();
		$settings['actions'] = array_map(
			static function ( array $a ): array {
				$a['enabled'] = false;
				return $a;
			},
			$this->sanitize_actions( is_array( $settings['actions'] ?? null ) ? $settings['actions'] : array(), $result['schema'], $action_errors, false )
		);
		$clean               = $this->sanitize_settings( array_replace_recursive( $this->default_settings(), $settings ), $result['schema'] );
		$id                  = $this->forms->create( self::plain_title( $title ), $result['schema'], $clean['settings'], get_current_user_id() );
		if ( $id <= 0 ) {
			return Errors::error( Errors::STORAGE_FAILED, __( 'The form could not be saved.', 'form-captain' ) );
		}
		$form = $this->forms->find( $id );
		if ( $form ) {
			do_action( 'form_captain_form_created_v1', $this->dto( $form ) );
		}
		return $id;
	}

	/**
	 * Defaults for new forms. Global sender and appearance are copied (P2-20), so later
	 * changes of the global settings never silently change existing forms.
	 *
	 * @return array<string,mixed>
	 */
	public function default_settings(): array {
		return FormSettingsSchema::defaults();
	}

	// Saving.

	/**
	 * Saves the working copy with optimistic locking. Drafts with validation errors are
	 * stored, but the errors are returned (and block publishing). Actions are never
	 * changed here (OP-FRM-04); use save_actions().
	 *
	 * @param array<string,mixed> $schema
	 * @param array<string,mixed> $settings
	 * @return array{revision:int,errors:array<string,string>,form:array<string,mixed>}|\WP_Error
	 */
	public function save( int $id, string $title, array $schema, array $settings, int $revision ): array|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		if ( $revision !== (int) $form['revision'] ) {
			return Errors::error( Errors::REVISION_CONFLICT, __( 'This form was changed by someone else in the meantime. Reload to see the latest version.', 'form-captain' ) );
		}
		$schema = $this->carry_over( $form['schema'], $schema );
		$result = $this->validator->validate( $schema );
		if ( isset( $result['errors']['schema_version'] ) ) {
			return $this->schema_error( Errors::SCHEMA_UNSUPPORTED, $result['errors'] );
		}
		if ( isset( $result['errors']['schema'] ) || isset( $result['errors']['fields'] ) ) {
			$code = isset( $result['errors']['fields'] ) ? Errors::TOO_MANY_FIELDS : Errors::PAYLOAD_TOO_LARGE;
			return $this->schema_error( $code, $result['errors'] );
		}
		$settings            = array_replace( $this->default_settings(), $settings );
		$settings['actions'] = (array) ( $form['settings']['actions'] ?? array() );
		foreach ( array( 'template', 'template_version' ) as $keep ) {
			if ( isset( $form['settings'][ $keep ] ) ) {
				$settings[ $keep ] = $form['settings'][ $keep ];
			}
		}
		$clean   = $this->sanitize_settings( $settings, $result['schema'] );
		$new_rev = $this->forms->update(
			$id,
			array(
				'title'    => self::plain_title( '' !== trim( $title ) ? $title : (string) $form['title'] ),
				'schema'   => $result['schema'],
				'settings' => $clean['settings'],
			),
			$revision
		);
		if ( is_wp_error( $new_rev ) ) {
			return $new_rev;
		}
		$saved = (array) $this->forms->find( $id );
		do_action( 'form_captain_form_updated_v1', $this->dto( $saved ), 'saved' );
		return array(
			'revision' => (int) $new_rev,
			'errors'   => array_merge( $result['errors'], $clean['errors'], $this->publish_requirements( $result['schema'] ) ),
			'form'     => $saved,
		);
	}

	/**
	 * Saves the form actions (OP-FRM-10). Mapping only on existing fields with
	 * matching types, never on sensitive fields.
	 *
	 * @param array<int,array<string,mixed>> $actions
	 * @return array{revision:int,errors:array<string,string>,form:array<string,mixed>}|\WP_Error
	 */
	public function save_actions( int $id, array $actions, int $revision ): array|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		if ( $revision !== (int) $form['revision'] ) {
			return Errors::error( Errors::REVISION_CONFLICT, __( 'This form was changed by someone else in the meantime. Reload to see the latest version.', 'form-captain' ) );
		}
		$errors              = array();
		$settings            = $form['settings'];
		$settings['actions'] = $this->sanitize_actions( $actions, $form['schema'], $errors, true );
		$new_rev             = $this->forms->update( $id, array( 'settings' => $settings ), $revision );
		if ( is_wp_error( $new_rev ) ) {
			return $new_rev;
		}
		$saved = (array) $this->forms->find( $id );
		do_action( 'form_captain_form_updated_v1', $this->dto( $saved ), 'saved' );
		return array(
			'revision' => (int) $new_rev,
			'errors'   => $errors,
			'form'     => $saved,
		);
	}

	/**
	 * Keeps immutable data stable between saves: retired field IDs and consent revisions.
	 *
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new
	 * @return array<string,mixed>
	 */
	public function carry_over( array $old, array $new ): array {
		$old_fields = array();
		foreach ( (array) ( $old['fields'] ?? array() ) as $f ) {
			if ( is_array( $f ) && isset( $f['id'] ) ) {
				$old_fields[ (string) $f['id'] ] = $f;
			}
		}
		$new_ids = array();
		$fields  = is_array( $new['fields'] ?? null ) ? array_values( $new['fields'] ) : array();
		foreach ( $fields as $i => $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$fid             = (string) ( $f['id'] ?? '' );
			$new_ids[ $fid ] = true;
			if ( 'consent' === ( $f['type'] ?? '' ) && isset( $old_fields[ $fid ] ) && 'consent' === ( $old_fields[ $fid ]['type'] ?? '' ) ) {
				$old_rev  = max( 1, (int) ( $old_fields[ $fid ]['consent_revision'] ?? 1 ) );
				$in_rev   = (int) ( $f['consent_revision'] ?? 0 );
				$old_text = trim( (string) ( $old_fields[ $fid ]['consent_text'] ?? '' ) );
				$new_text = trim( \FormCaptain\Domain\HtmlSanitizer::clean( (string) ( $f['consent_text'] ?? '' ) ) );
				if ( $old_text !== $new_text ) {
					$fields[ $i ]['consent_revision'] = max( $old_rev + 1, $in_rev );
				} else {
					$fields[ $i ]['consent_revision'] = $old_rev;
				}
			}
		}
		$retired = array_merge(
			array_values( array_filter( (array) ( $old['retired_ids'] ?? array() ), 'is_string' ) ),
			array_values( array_filter( (array) ( $new['retired_ids'] ?? array() ), 'is_string' ) )
		);
		foreach ( array_keys( $old_fields ) as $fid ) {
			if ( ! isset( $new_ids[ $fid ] ) ) {
				$retired[] = $fid;
			}
		}
		$retired       = array_values( array_unique( array_filter( $retired, static fn( $rid ) => (bool) preg_match( '/^f_[a-z0-9]{6,32}$/', $rid ) ) ) );
		$new['fields'] = $fields;
		if ( $retired ) {
			$new['retired_ids'] = $retired;
		} else {
			unset( $new['retired_ids'] );
		}
		return $new;
	}

	// Publishing and status.

	/**
	 * Validation result of the stored working copy incl. publish requirements.
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,string>
	 */
	public function errors_for( array $form ): array {
		$result = $this->validator->validate( (array) $form['schema'] );
		$clean  = $this->sanitize_settings( array_replace( $this->default_settings(), (array) $form['settings'] ), $result['schema'] );
		return array_merge( $result['errors'], $clean['errors'], $this->publish_requirements( $result['schema'] ) );
	}

	/** @return array{status:string,version_id:int}|\WP_Error */
	public function publish( int $id ): array|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		if ( 'archived' === $form['status'] ) {
			return Errors::error( 'conflict', __( 'Archived forms cannot be published. Restore the form as a draft first.', 'form-captain' ), array( 'status' => 409 ) );
		}
		$errors = $this->errors_for( $form );
		if ( $errors ) {
			return $this->schema_error( Errors::SCHEMA_INVALID, $errors, __( 'The form cannot be published until all errors are fixed.', 'form-captain' ) );
		}
		$schema     = $this->validator->validate( $form['schema'] )['schema'];
		$version_id = $this->forms->publish_version( $id, $schema );
		$saved      = (array) $this->forms->find( $id );
		do_action( 'form_captain_form_updated_v1', $this->dto( $saved ), 'published' );
		return array(
			'status'     => 'published',
			'version_id' => $version_id,
		);
	}

	/** @return array{status:string}|\WP_Error */
	public function set_status( int $id, string $status ): array|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		$from = (string) $form['status'];
		if ( ! isset( self::TRANSITIONS[ $from ] ) || ! in_array( $status, self::TRANSITIONS[ $from ], true ) ) {
			return Errors::error( 'conflict', __( 'This status change is not possible.', 'form-captain' ), array( 'status' => 409 ) );
		}
		if ( 'published' === $status ) {
			if ( 'draft' === $from || null === $form['published_version_id'] ) {
				$res = $this->publish( $id );
				return is_wp_error( $res ) ? $res : array( 'status' => 'published' );
			}
		}
		$this->forms->set_status( $id, $status );
		$change = match ( $status ) {
			'paused' => 'paused',
			'archived' => 'archived',
			'draft' => 'unpublished',
			default => 'published',
		};
		$saved = (array) $this->forms->find( $id );
		do_action( 'form_captain_form_updated_v1', $this->dto( $saved ), $change );
		return array( 'status' => $status );
	}

	/**
	 * @param array<string,mixed> $schema normalized schema
	 * @return array<string,string>
	 */
	private function publish_requirements( array $schema ): array {
		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( 'hidden' !== $type && FieldTypes::has_value( $type ) ) {
				return array();
			}
		}
		return array( 'fields' => 'no_input_fields' );
	}

	// Duplicate / delete.

	/** @return int|\WP_Error new form ID */
	public function duplicate( int $id ): int|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		$settings            = (array) $form['settings'];
		$settings['actions'] = array_map(
			static function ( $a ) {
				$a                = (array) $a;
				$a['enabled']     = false;
				$a['instance_id'] = self::new_instance_id();
				return $a;
			},
			(array) ( $settings['actions'] ?? array() )
		);
		/* translators: %s: form title */
		$title  = sprintf( __( '%s (copy)', 'form-captain' ), (string) $form['title'] );
		$new_id = $this->forms->create( self::plain_title( $title ), (array) $form['schema'], $settings, get_current_user_id() );
		if ( $new_id <= 0 ) {
			return Errors::error( Errors::STORAGE_FAILED, __( 'The form could not be saved.', 'form-captain' ) );
		}
		$new = $this->forms->find( $new_id );
		if ( $new ) {
			do_action( 'form_captain_form_created_v1', $this->dto( $new ) );
		}
		return $new_id;
	}

	/** Total number of entries of a form incl. trash and spam. */
	/** @phpstan-impure Reads the live entry count. */
	public function entry_total( int $id ): int {
		return (int) array_sum( $this->entries->status_counts( $id ) );
	}

	/**
	 * Deletes a form. With entries only when $with_entries is set, the user has
	 * delete_entries and Agent C's EntryDeletionService is available (OP-FRM-07).
	 */
	public function delete( int $id, bool $with_entries = false ): true|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		if ( $this->entry_total( $id ) > 0 ) {
			if ( ! $with_entries ) {
				return Errors::error( 'conflict', __( 'This form has entries. Archive it instead, or delete it together with its entries.', 'form-captain' ), array( 'status' => 409 ) );
			}
			if ( ! current_user_can( Capabilities::DELETE_ENTRIES ) ) {
				return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to delete entries.', 'form-captain' ) );
			}
			$service = \FormCaptain\Plugin::instance()->get( EntryDeletionService::class );
			$rounds  = 0;
			while ( $rounds < 1000 && $this->entry_total( $id ) > 0 ) {
				$service->delete_for_form( $id );
				++$rounds;
			}
			if ( $this->entry_total( $id ) > 0 ) {
				return Errors::error( 'conflict', __( 'Not all entries could be deleted. Please try again.', 'form-captain' ), array( 'status' => 409 ) );
			}
		}
		$this->forms->delete( $id );
		\FormCaptain\Translation\Translations::forget( $id ); // Source hashes of the translations (SET-FC-36).
		return true;
	}

	// JSON export / import (P4-12, T-JSON-*).

	/**
	 * Portable definition without entries, IDs, user IDs or secrets (T-JSON-06).
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function export( int $id ): array|\WP_Error {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return Errors::error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) );
		}
		$settings = (array) $form['settings'];
		unset( $settings['template'], $settings['template_version'] );
		$settings['actions'] = array_values(
			array_map(
				static function ( $a ) {
					$a = (array) $a;
					return array(
						'action_key' => (string) ( $a['action_key'] ?? '' ),
						'enabled'    => false,
						'settings'   => self::strip_secrets( (array) ( $a['settings'] ?? array() ) ),
						'mapping'    => (array) ( $a['mapping'] ?? array() ),
					);
				},
				(array) ( $settings['actions'] ?? array() )
			)
		);
		return array(
			'format'         => self::EXPORT_FORMAT,
			'format_version' => 1,
			'generator'      => 'Form Captain ' . FORM_CAPTAIN_VERSION,
			'exported_gmt'   => gmdate( 'Y-m-d\TH:i:s\Z', Clock::timestamp() ),
			'title'          => (string) $form['title'],
			'schema'         => $this->validator->validate( (array) $form['schema'] )['schema'],
			'settings'       => $settings,
		);
	}

	/**
	 * Imports a JSON definition as a new draft. Never unserializes, validates before saving.
	 *
	 * @return array{form_id:int,report:array<string,array<int,string>>}|\WP_Error
	 */
	public function import( string $json ): array|\WP_Error {
		if ( strlen( $json ) > self::IMPORT_MAX_BYTES ) {
			return Errors::error( Errors::PAYLOAD_TOO_LARGE, __( 'The file is too large (maximum 256 KB).', 'form-captain' ) );
		}
		$data = json_decode( $json, true, 32 );
		if ( ! is_array( $data ) || ! is_array( $data['schema'] ?? null ) ) {
			return $this->schema_error( Errors::SCHEMA_INVALID, array( 'file' => 'invalid_json' ), __( 'The file is not a valid Form Captain form definition.', 'form-captain' ) );
		}
		$report = array(
			'recipients' => array(),
			'sanitized'  => array(),
			'removed'    => array(),
		);
		$schema = $data['schema'];
		$fields = is_array( $schema['fields'] ?? null ) ? array_values( $schema['fields'] ) : array();
		foreach ( $fields as $i => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			foreach ( array( 'label', 'help', 'placeholder' ) as $prop ) {
				if ( isset( $field[ $prop ] ) && is_string( $field[ $prop ] ) && wp_strip_all_tags( $field[ $prop ] ) !== $field[ $prop ] ) {
					/* translators: 1: field key, 2: property name */
					$report['sanitized'][] = sprintf( __( 'Field "%1$s": markup removed from %2$s.', 'form-captain' ), sanitize_key( (string) ( $field['key'] ?? $i ) ), $prop );
				}
			}
			foreach ( array( 'content', 'consent_text' ) as $prop ) {
				if ( isset( $field[ $prop ] ) && is_string( $field[ $prop ] ) && \FormCaptain\Domain\HtmlSanitizer::clean( $field[ $prop ] ) !== $field[ $prop ] ) {
					/* translators: 1: field key, 2: property name */
					$report['sanitized'][] = sprintf( __( 'Field "%1$s": disallowed HTML removed from %2$s.', 'form-captain' ), sanitize_key( (string) ( $field['key'] ?? $i ) ), $prop );
				}
			}
			if ( 'consent' === ( $field['type'] ?? '' ) ) {
				$fields[ $i ]['consent_revision'] = 1;
			}
		}
		$schema['fields'] = $fields;
		$result           = $this->validator->validate( $schema );
		if ( isset( $result['errors']['schema_version'] ) ) {
			return $this->schema_error( Errors::SCHEMA_UNSUPPORTED, $result['errors'], __( 'This form was created with a newer version of Form Captain.', 'form-captain' ) );
		}
		if ( $result['errors'] ) {
			return $this->schema_error( Errors::SCHEMA_INVALID, $result['errors'], __( 'The form definition contains errors and was not imported.', 'form-captain' ) );
		}

		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		unset( $settings['template'], $settings['template_version'] );
		$errors              = array();
		$settings['actions'] = array_map(
			static function ( $a ) {
				$a['enabled'] = false;
				return $a;
			},
			$this->sanitize_actions( (array) ( $settings['actions'] ?? array() ), $result['schema'], $errors, false )
		);
		foreach ( $errors as $path => $code ) {
			/* translators: %s: setting path */
			$report['removed'][] = sprintf( __( 'Invalid action configuration removed (%s).', 'form-captain' ), $path );
		}
		$clean = $this->sanitize_settings( array_replace_recursive( $this->default_settings(), $settings ), $result['schema'] );
		foreach ( $clean['errors'] as $path => $code ) {
			/* translators: 1: setting path, 2: error code */
			$report['removed'][] = sprintf( __( 'Setting %1$s was invalid and has been reset (%2$s).', 'form-captain' ), $path, $code );
		}
		$report['recipients'] = array_values( (array) ( $clean['settings']['notification']['to'] ?? array() ) );
		$title                = is_string( $data['title'] ?? null ) && '' !== trim( wp_strip_all_tags( $data['title'] ) ) ? $data['title'] : __( 'Imported form', 'form-captain' );
		$id                   = $this->forms->create( self::plain_title( $title ), $result['schema'], $clean['settings'], get_current_user_id() );
		if ( $id <= 0 ) {
			return Errors::error( Errors::STORAGE_FAILED, __( 'The form could not be saved.', 'form-captain' ) );
		}
		$form = $this->forms->find( $id );
		if ( $form ) {
			do_action( 'form_captain_form_created_v1', $this->dto( $form ) );
		}
		return array(
			'form_id' => $id,
			'report'  => $report,
		);
	}

	// Settings sanitizing.

	/**
	 * Normalizes form settings (docs/spec/formularschema.md). Invalid values are reset
	 * to safe defaults and reported as errors (path => code).
	 *
	 * @param array<string,mixed> $in
	 * @param array<string,mixed> $schema normalized schema
	 * @return array{settings:array<string,mixed>,errors:array<string,string>}
	 */
	public function sanitize_settings( array $in, array $schema ): array {
		$fields = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $f ) {
			$fields[ (string) $f['id'] ] = $f;
		}
		// Described values (defaults, sanitizing, cross checks): Settings\FormSettingsSchema (SET-FC-28).
		$res    = FormSettingsSchema::sanitize( $in, $fields );
		$out    = $res['settings'];
		$errors = $res['errors'];

		foreach ( array( 'template', 'template_version' ) as $keep ) {
			if ( isset( $in[ $keep ] ) && is_string( $in[ $keep ] ) && preg_match( '/^[a-z0-9_.\-]{1,40}$/', $in[ $keep ] ) ) {
				$out[ $keep ] = $in[ $keep ];
			}
		}
		// Form settings of an extension (contract 1.1.0), stored under `x`; the core never interprets them.
		$ext = apply_filters( 'form_captain_normalize_form_settings_v1', null, is_array( $in['x'] ?? null ) ? $in['x'] : array(), $schema );
		if ( is_array( $ext ) ) {
			foreach ( (array) ( $ext['errors'] ?? array() ) as $key => $code ) {
				if ( is_string( $key ) && preg_match( '/^[a-z0-9_.]{1,60}$/', $key ) && is_string( $code ) && preg_match( '/^[a-z][a-z0-9_]{1,40}$/', $code ) ) {
					$errors[ "x.$key" ] = $code;
				}
			}
			$x    = is_array( $ext['settings'] ?? null ) ? $ext['settings'] : array();
			$json = wp_json_encode( $x );
			if ( $x && is_string( $json ) && strlen( $json ) <= 16384 ) {
				$out['x'] = $x;
			} elseif ( $x ) {
				$errors['x'] = 'extension_settings_too_large';
			}
		} elseif ( is_array( $in['x'] ?? null ) && $in['x'] ) {
			// No extension active: keep stored extension settings unchanged, so a lapsed
			// license or a deactivated add-on never deletes them (P7-06).
			$json = wp_json_encode( $in['x'] );
			if ( is_string( $json ) && strlen( $json ) <= 16384 ) {
				$out['x'] = $in['x'];
			}
		}
		return array(
			'settings' => $out,
			'errors'   => $errors,
		);
	}

	/**
	 * Normalizes settings.actions (shared interface, see agent brief).
	 *
	 * @param array<int,mixed>     $actions
	 * @param array<string,mixed>  $schema
	 * @param array<string,string> $errors
	 * @return array<int,array<string,mixed>>
	 */
	public function sanitize_actions( array $actions, array $schema, array &$errors, bool $require_known ): array {
		$catalog = self::action_catalog();
		$fields  = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $f ) {
			$fields[ (string) $f['id'] ] = $f;
		}
		$out  = array();
		$seen = array();
		foreach ( array_slice( array_values( $actions ), 0, 50 ) as $i => $a ) {
			if ( ! is_array( $a ) ) {
				$errors[ "actions.$i" ] = 'invalid_action';
				continue;
			}
			$key = (string) ( $a['action_key'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $key ) ) {
				$errors[ "actions.$i.action_key" ] = 'invalid_action_key';
				continue;
			}
			$def = $catalog[ $key ] ?? null;
			if ( $require_known && null === $def ) {
				$errors[ "actions.$i.action_key" ] = Errors::CONNECTOR_NOT_FOUND;
				continue;
			}
			$instance = (string) ( $a['instance_id'] ?? '' );
			if ( ! preg_match( '/^a_[0-9a-f]{10}$/', $instance ) || isset( $seen[ $instance ] ) ) {
				$instance = self::new_instance_id();
			}
			$seen[ $instance ] = true;

			$settings = array();
			$raw      = is_array( $a['settings'] ?? null ) ? $a['settings'] : array();
			if ( null !== $def ) {
				foreach ( $def['settings'] as $s ) {
					$sk = $s['key'];
					$v  = $raw[ $sk ] ?? '';
					if ( 'checkbox' === $s['type'] || 'bool' === $s['type'] ) {
						$settings[ $sk ] = ! empty( $v );
					} else {
						$settings[ $sk ] = self::plain( $v, 500 );
						if ( $s['required'] && '' === $settings[ $sk ] && ! empty( $a['enabled'] ) ) {
							$errors[ "actions.$i.settings.$sk" ] = 'required';
						}
						if ( $s['options'] && '' !== $settings[ $sk ] && ! in_array( $settings[ $sk ], $s['options'], true ) ) {
							$errors[ "actions.$i.settings.$sk" ] = 'invalid_option';
							$settings[ $sk ]                     = '';
						}
					}
				}
			} else {
				foreach ( $raw as $sk => $v ) {
					if ( is_string( $sk ) && preg_match( '/^[a-z0-9_]{1,40}$/', $sk ) && is_scalar( $v ) ) {
						$settings[ $sk ] = is_bool( $v ) ? $v : self::plain( $v, 500 );
					}
				}
			}

			$mapping = array();
			$raw_map = is_array( $a['mapping'] ?? null ) ? $a['mapping'] : array();
			$targets = null !== $def ? $def['mapping'] : array();
			foreach ( $raw_map as $mk => $fid ) {
				$mk  = (string) $mk;
				$fid = (string) $fid;
				if ( '' === $fid ) {
					continue;
				}
				if ( ! preg_match( '/^[a-z0-9_]{1,40}$/', $mk ) || ! isset( $fields[ $fid ] ) || ! FieldTypes::has_value( (string) $fields[ $fid ]['type'] ) ) {
					$errors[ "actions.$i.mapping.$mk" ] = 'invalid_mapping';
					continue;
				}
				if ( ! empty( $fields[ $fid ]['sensitive'] ) ) {
					$errors[ "actions.$i.mapping.$mk" ] = 'sensitive_field';
					continue;
				}
				if ( null !== $def ) {
					if ( ! isset( $targets[ $mk ] ) ) {
						$errors[ "actions.$i.mapping.$mk" ] = 'invalid_mapping';
						continue;
					}
					if ( $targets[ $mk ]['types'] && ! in_array( $fields[ $fid ]['type'], $targets[ $mk ]['types'], true ) ) {
						$errors[ "actions.$i.mapping.$mk" ] = 'type_mismatch';
						continue;
					}
				}
				$mapping[ $mk ] = $fid;
			}
			if ( null !== $def && ! empty( $a['enabled'] ) ) {
				foreach ( $targets as $mk => $t ) {
					if ( $t['required'] && ! isset( $mapping[ $mk ] ) ) {
						$errors[ "actions.$i.mapping.$mk" ] = 'required';
					}
				}
			}
			$out[] = array(
				'instance_id' => $instance,
				'action_key'  => $key,
				'enabled'     => ! empty( $a['enabled'] ) && ! isset( $errors[ "actions.$i" ] ),
				'settings'    => $settings,
				'mapping'     => $mapping,
			);
		}
		return $out;
	}

	/**
	 * Action definitions from Agent D's ConnectorRegistry, normalized (empty when unavailable).
	 *
	 * @return array<string,array{key:string,label:string,connector:string,available:bool,message:string,settings:array<int,array{key:string,type:string,label:string,required:bool,options:array<int,string>}>,mapping:array<string,array{key:string,label:string,types:array<int,string>,required:bool}>}>
	 */
	public static function action_catalog(): array {
		try {
			$raw = (array) \FormCaptain\Plugin::instance()->get( \FormCaptain\Integrations\ConnectorRegistry::class )->actions_for_editor();
		} catch ( \Throwable $e ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $k => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$key = (string) ( $def['key'] ?? $def['action_key'] ?? ( is_string( $k ) ? $k : '' ) );
			if ( ! preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $key ) ) {
				continue;
			}
			$settings = array();
			foreach ( (array) ( $def['settings'] ?? array() ) as $s ) {
				if ( ! is_array( $s ) || ! isset( $s['key'] ) || ! preg_match( '/^[a-z0-9_]{1,40}$/', (string) $s['key'] ) ) {
					continue;
				}
				$options = array();
				foreach ( (array) ( $s['options'] ?? array() ) as $ok => $ov ) {
					$options[] = is_array( $ov ) ? (string) ( $ov['value'] ?? '' ) : ( is_string( $ok ) ? $ok : (string) $ov );
				}
				$settings[] = array(
					'key'      => (string) $s['key'],
					'type'     => (string) ( $s['type'] ?? 'text' ),
					'label'    => (string) ( $s['label'] ?? $s['key'] ),
					'required' => ! empty( $s['required'] ),
					'options'  => $options,
				);
			}
			$mapping = array();
			foreach ( (array) ( $def['mapping'] ?? array() ) as $m ) {
				if ( ! is_array( $m ) || ! isset( $m['key'] ) || ! preg_match( '/^[a-z0-9_]{1,40}$/', (string) $m['key'] ) ) {
					continue;
				}
				$mapping[ (string) $m['key'] ] = array(
					'key'      => (string) $m['key'],
					'label'    => (string) ( $m['label'] ?? $m['key'] ),
					'types'    => array_values( array_map( 'strval', (array) ( $m['types'] ?? array() ) ) ),
					'required' => ! empty( $m['required'] ),
				);
			}
			$out[ $key ] = array(
				'key'       => $key,
				'label'     => (string) ( $def['label'] ?? $key ),
				'connector' => (string) ( $def['connector_label'] ?? $def['connector'] ?? '' ),
				'available' => ! array_key_exists( 'available', $def ) || ! empty( $def['available'] ),
				'message'   => (string) ( $def['message'] ?? '' ),
				'settings'  => $settings,
				'mapping'   => $mapping,
			);
		}
		return $out;
	}

	// Helpers.

	/**
	 * Form DTO for events (api-v1.md).
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	public function dto( array $form ): array {
		$version = null;
		if ( ! empty( $form['published_version_id'] ) ) {
			$v       = $this->forms->find_version( (int) $form['published_version_id'] );
			$version = $v ? (int) $v['version_no'] : null;
		}
		$fields = array();
		foreach ( (array) ( $form['schema']['fields'] ?? array() ) as $f ) {
			$fields[] = array(
				'id'        => (string) ( $f['id'] ?? '' ),
				'key'       => (string) ( $f['key'] ?? '' ),
				'type'      => (string) ( $f['type'] ?? '' ),
				'label'     => (string) ( $f['label'] ?? '' ),
				'required'  => ! empty( $f['required'] ),
				'sensitive' => ! empty( $f['sensitive'] ),
				'options'   => (array) ( $f['options'] ?? array() ),
			);
		}
		return array(
			'id'        => (int) $form['id'],
			'public_id' => (string) $form['public_id'],
			'title'     => (string) $form['title'],
			'status'    => (string) $form['status'],
			'version'   => $version,
			'fields'    => $fields,
		);
	}

	/**
	 * Changes between the published snapshot and the working copy (P2-16).
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	public function pending_changes( array $form ): array {
		$published = array();
		if ( ! empty( $form['published_version_id'] ) ) {
			$v         = $this->forms->find_version( (int) $form['published_version_id'] );
			$published = $v ? $v['schema'] : array();
		}
		$diff                = FormSchemaDiff::compare( $published, (array) $form['schema'] );
		$diff['unpublished'] = ! empty( $form['published_version_id'] ) && $published !== $this->validator->validate( (array) $form['schema'] )['schema'];
		return $diff;
	}

	public static function new_instance_id(): string {
		return 'a_' . bin2hex( random_bytes( 5 ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	private static function strip_secrets( array $settings ): array {
		foreach ( array_keys( $settings ) as $k ) {
			if ( preg_match( '/secret|token|password|passwd|api_?key|credential|auth/i', (string) $k ) ) {
				unset( $settings[ $k ] );
			}
		}
		return $settings;
	}

	/**
	 * @param array<string,string> $errors
	 */
	private function schema_error( string $code, array $errors, string $message = '' ): \WP_Error {
		$status = match ( $code ) {
			Errors::PAYLOAD_TOO_LARGE, Errors::TOO_MANY_FIELDS => 413,
			default => 422,
		};
		return Errors::error(
			$code,
			'' !== $message ? $message : __( 'The form definition is invalid.', 'form-captain' ),
			array(
				'status' => $status,
				'errors' => $errors,
			)
		);
	}

	private static function plain_title( string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );
		return mb_substr( '' !== $title ? $title : __( 'Untitled form', 'form-captain' ), 0, 190 );
	}

	private static function plain( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return mb_substr( trim( sanitize_text_field( $value ) ), 0, $max );
	}
}
