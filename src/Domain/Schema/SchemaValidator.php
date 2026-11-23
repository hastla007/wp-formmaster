<?php
/**
 * Validates and normalizes form schema v1 (docs/spec/formularschema.md).
 *
 * Pure logic apart from wp_kses (HTML sanitizing) – unit-testable.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain\Schema;

use FormCaptain\Domain\Fields\FieldTypes;
use FormCaptain\Domain\HtmlSanitizer;
use FormCaptain\Domain\ValidationPatterns;
use FormCaptain\Support\Limits;

final class SchemaValidator {

	public const CURRENT_VERSION = 1;

	private const OPERATORS = array( 'equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty', 'checked' );

	/** Autofill purposes a field may declare (HTML autocomplete tokens, SET-FC-33). */
	public const AUTOCOMPLETE = array( 'off', 'name', 'given-name', 'family-name', 'honorific-prefix', 'organization', 'email', 'tel', 'street-address', 'address-line1', 'address-line2', 'postal-code', 'address-level2', 'address-level1', 'country-name', 'bday', 'url' );

	/** Field types that accept an autocomplete purpose. */
	public const AUTOCOMPLETE_TYPES = array( 'text', 'textarea', 'email', 'tel', 'number', 'select', 'date' );

	/** Highest minimum age that can be required on a date field (SET-FC-30). */
	public const MIN_AGE_MAX = 120;

	/**
	 * Validates a schema. Returns normalized schema and list of errors (path => message).
	 * A schema with errors may still be saved as draft but never published.
	 *
	 * @param array<string,mixed> $schema
	 * @return array{schema:array<string,mixed>,errors:array<string,string>}
	 */
	public function validate( array $schema ): array {
		$errors  = array();
		$version = (int) ( $schema['schema_version'] ?? self::CURRENT_VERSION );
		if ( $version > self::CURRENT_VERSION || $version < 1 ) {
			return array(
				'schema' => $schema,
				'errors' => array( 'schema_version' => 'schema_unsupported' ),
			);
		}
		$schema = Migrations::upgrade( $schema );
		$fields = is_array( $schema['fields'] ?? null ) ? array_values( $schema['fields'] ) : array();
		if ( count( $fields ) > Limits::get( 'fields_per_form' ) ) {
			$errors['fields'] = 'too_many_fields';
			$fields           = array_slice( $fields, 0, Limits::get( 'fields_per_form' ) );
		}
		$retired = array_values( array_filter( (array) ( $schema['retired_ids'] ?? array() ), static fn( $id ) => is_string( $id ) && preg_match( '/^f_[a-z0-9]{6,32}$/', $id ) ) );
		$ids     = array();
		$keys    = array();
		$out     = array();
		$types   = FieldTypes::all();
		foreach ( $fields as $i => $field ) {
			$p = "fields.$i";
			if ( ! is_array( $field ) ) {
				$errors[ $p ] = 'invalid_field';
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( ! isset( $types[ $type ] ) ) {
				$errors[ "$p.type" ] = 'unknown_type';
				continue;
			}
			$id = (string) ( $field['id'] ?? '' );
			if ( ! preg_match( '/^f_[a-z0-9]{6,32}$/', $id ) ) {
				$errors[ "$p.id" ] = 'invalid_id';
			} elseif ( isset( $ids[ $id ] ) || in_array( $id, $retired, true ) ) {
				$errors[ "$p.id" ] = 'duplicate_id';
			}
			$ids[ $id ] = $type;

			$key = (string) ( $field['key'] ?? '' );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) ) {
				$errors[ "$p.key" ] = 'invalid_key';
			} elseif ( isset( $keys[ $key ] ) ) {
				$errors[ "$p.key" ] = 'duplicate_key';
			}
			$keys[ $key ] = true;

			$n = array(
				'id'        => $id,
				'type'      => $type,
				'key'       => $key,
				'label'     => self::plain( $field['label'] ?? '', 190 ),
				'required'  => ! in_array( $type, array( 'html', 'hidden' ), true ) && ! empty( $field['required'] ),
				'help'      => self::plain( $field['help'] ?? '', 500 ),
				'css_class' => preg_match( '/^[A-Za-z0-9_\- ]{0,100}$/', (string) ( $field['css_class'] ?? '' ) ) ? (string) ( $field['css_class'] ?? '' ) : '',
				'sensitive' => ! empty( $field['sensitive'] ),
			);
			if ( '' === $n['label'] && ! in_array( $type, array( 'html', 'hidden' ), true ) ) {
				$errors[ "$p.label" ] = 'label_required';
			}
			if ( $types[ $type ]['has_placeholder'] ) {
				$n['placeholder'] = self::plain( $field['placeholder'] ?? '', 190 );
			}
			if ( 'consent' !== $type && 'html' !== $type ) {
				$n['default'] = self::plain( $field['default'] ?? '', 500 );
			}
			if ( 'consent' === $type && ! empty( $field['default'] ) ) {
				$errors[ "$p.default" ] = 'consent_prechecked';
			}

			// Type-specific.
			if ( $types[ $type ]['has_options'] ) {
				$opts   = is_array( $field['options'] ?? null ) ? $field['options'] : array();
				$values = array();
				$clean  = array();
				foreach ( array_slice( $opts, 0, Limits::get( 'options_per_field' ) ) as $j => $opt ) {
					$v = (string) ( $opt['value'] ?? '' );
					$l = self::plain( $opt['label'] ?? '', 190 );
					if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $v ) || '' === $l ) {
						$errors[ "$p.options.$j" ] = 'invalid_option';
						continue;
					}
					if ( isset( $values[ $v ] ) ) {
						$errors[ "$p.options.$j" ] = 'duplicate_option';
						continue;
					}
					$values[ $v ] = true;
					$clean[]      = array( 'value' => $v, 'label' => $l );
				}
				if ( count( $opts ) > Limits::get( 'options_per_field' ) ) {
					$errors[ "$p.options" ] = 'too_many_options';
				}
				if ( ! $clean ) {
					$errors[ "$p.options" ] = 'options_required';
				}
				$n['options'] = $clean;
				if ( 'select' === $type ) {
					$n['multiple'] = ! empty( $field['multiple'] );
				}
			}
			if ( 'textarea' === $type ) {
				$n['rows'] = max( 2, min( 20, (int) ( $field['rows'] ?? 5 ) ) );
			}
			if ( 'html' === $type ) {
				$n['content'] = HtmlSanitizer::clean( (string) ( $field['content'] ?? '' ) );
			}
			if ( 'consent' === $type ) {
				$n['consent_text']     = HtmlSanitizer::clean( (string) ( $field['consent_text'] ?? '' ) );
				$n['privacy_link']     = ! empty( $field['privacy_link'] );
				$n['consent_revision'] = max( 1, (int) ( $field['consent_revision'] ?? 1 ) );
				if ( '' === trim( wp_strip_all_tags( $n['consent_text'] ) ) ) {
					$errors[ "$p.consent_text" ] = 'consent_text_required';
				}
			}
			if ( in_array( $type, self::AUTOCOMPLETE_TYPES, true ) && isset( $field['autocomplete'] ) && '' !== $field['autocomplete'] ) {
				if ( is_string( $field['autocomplete'] ) && in_array( $field['autocomplete'], self::AUTOCOMPLETE, true ) ) {
					$n['autocomplete'] = $field['autocomplete'];
				} else {
					$errors[ "$p.autocomplete" ] = 'invalid_autocomplete';
				}
			}
			if ( 'email' === $type ) {
				$n['confirmation_recipient'] = ! empty( $field['confirmation_recipient'] );
				$n['privacy_lookup']         = ! array_key_exists( 'privacy_lookup', $field ) || ! empty( $field['privacy_lookup'] );
			}
			$n['validation'] = self::validation_rules( $type, is_array( $field['validation'] ?? null ) ? $field['validation'] : array(), $errors, $p );

			// Condition: only references to earlier fields with values.
			$n['condition'] = null;
			if ( ! empty( $field['condition'] ) && is_array( $field['condition'] ) ) {
				$c   = $field['condition'];
				$ref = (string) ( $c['field'] ?? '' );
				$op  = (string) ( $c['operator'] ?? '' );
				if ( ! isset( $ids[ $ref ] ) || $ref === $id || ! FieldTypes::has_value( $ids[ $ref ] ) ) {
					$errors[ "$p.condition.field" ] = 'invalid_condition_field';
				} elseif ( ! in_array( $op, self::OPERATORS, true ) ) {
					$errors[ "$p.condition.operator" ] = 'invalid_operator';
				} else {
					$n['condition'] = array(
						'action'   => 'hide' === ( $c['action'] ?? 'show' ) ? 'hide' : 'show',
						'field'    => $ref,
						'operator' => $op,
						'value'    => self::plain( $c['value'] ?? '', 190 ),
					);
				}
			}
			$n     = self::extension_settings( $n, $field, $ids, $errors, $p );
			$out[] = $n;
		}

		$normalized = array(
			'schema_version' => self::CURRENT_VERSION,
			'fields'         => $out,
		);
		if ( $retired ) {
			$normalized['retired_ids'] = array_values( array_unique( $retired ) );
		}
		$json = wp_json_encode( $normalized );
		if ( is_string( $json ) && strlen( $json ) > Limits::get( 'schema_bytes' ) ) {
			$errors['schema'] = 'schema_too_large';
		}
		return array(
			'schema' => $normalized,
			'errors' => $errors,
		);
	}

	/**
	 * Settings an extension keeps on a field (contract 1.1.0), stored under the key `x`.
	 * Filter form_captain_normalize_field_v1( null, $raw_field, $earlier_fields ) returns
	 * array{settings?:array, errors?:array<string,string>} or null. The core never interprets `x`.
	 *
	 * @param array<string,mixed>  $n      normalized field
	 * @param array<string,mixed>  $field  raw field
	 * @param array<string,string> $ids    field_id => type of this and the earlier fields
	 * @param array<string,string> $errors
	 * @return array<string,mixed>
	 */
	private static function extension_settings( array $n, array $field, array $ids, array &$errors, string $p ): array {
		$ext = apply_filters( 'form_captain_normalize_field_v1', null, $field, $ids );
		if ( ! is_array( $ext ) ) {
			// No extension active (license lapsed, add-on off): keep stored settings unchanged so
			// saving the form never deletes them (P7-06). They are not interpreted by the core.
			$raw  = is_array( $field['x'] ?? null ) ? $field['x'] : array();
			$json = wp_json_encode( $raw );
			if ( $raw && is_string( $json ) && strlen( $json ) <= 8192 ) {
				$n['x'] = $raw;
			}
			return $n;
		}
		foreach ( (array) ( $ext['errors'] ?? array() ) as $key => $code ) {
			if ( is_string( $key ) && preg_match( '/^[a-z0-9_.]{1,60}$/', $key ) && is_string( $code ) && preg_match( '/^[a-z][a-z0-9_]{1,40}$/', $code ) ) {
				$errors[ "$p.x.$key" ] = $code;
			}
		}
		$settings = is_array( $ext['settings'] ?? null ) ? $ext['settings'] : array();
		$json     = wp_json_encode( $settings );
		if ( $settings && is_string( $json ) && strlen( $json ) <= 8192 ) {
			$n['x'] = $settings;
		} elseif ( $settings ) {
			$errors[ "$p.x" ] = 'extension_settings_too_large';
		}
		return $n;
	}

	public static function new_field_id(): string {
		return 'f_' . substr( bin2hex( random_bytes( 6 ) ), 0, 10 );
	}

	/**
	 * @param array<string,mixed>  $rules
	 * @param array<string,string> $errors
	 * @return array<string,mixed>
	 */
	private static function validation_rules( string $type, array $rules, array &$errors, string $p ): array {
		$out = array();
		switch ( $type ) {
			case 'text':
			case 'textarea':
				$cap               = 'text' === $type ? Limits::get( 'text_max' ) : Limits::get( 'textarea_max' );
				$default           = 'text' === $type ? 190 : 5000;
				$out['max_length'] = max( 1, min( $cap, (int) ( $rules['max_length'] ?? $default ) ) );
				$out['min_length'] = max( 0, min( $out['max_length'], (int) ( $rules['min_length'] ?? 0 ) ) );
				if ( 'text' === $type && ! empty( $rules['pattern'] ) ) {
					$pattern = is_string( $rules['pattern'] ) ? $rules['pattern'] : '';
					if ( ValidationPatterns::CUSTOM === $pattern ) {
						$custom = is_string( $rules['custom_pattern'] ?? null ) ? trim( $rules['custom_pattern'] ) : '';
						$check  = ValidationPatterns::check_custom( $custom );
						if ( null === $check ) {
							$out['pattern']        = $pattern;
							$out['custom_pattern'] = $custom;
						} else {
							$errors[ "$p.validation.custom_pattern" ] = $check;
						}
					} elseif ( isset( ValidationPatterns::all()[ $pattern ] ) ) {
						$out['pattern'] = $pattern;
					} else {
						$errors[ "$p.validation.pattern" ] = 'invalid_pattern';
					}
				}
				break;
			case 'tel':
				if ( ! empty( $rules['format'] ) ) {
					if ( ValidationPatterns::TEL_INTERNATIONAL === $rules['format'] ) {
						$out['format'] = ValidationPatterns::TEL_INTERNATIONAL;
					} else {
						$errors[ "$p.validation.format" ] = 'invalid_pattern';
					}
				}
				break;
			case 'email':
				$out['max_length'] = max( 3, min( 254, (int) ( $rules['max_length'] ?? 254 ) ) );
				break;
			case 'number':
				foreach ( array( 'min', 'max', 'step' ) as $k ) {
					if ( isset( $rules[ $k ] ) && '' !== $rules[ $k ] ) {
						if ( ! is_numeric( $rules[ $k ] ) ) {
							$errors[ "$p.validation.$k" ] = 'invalid_number';
						} else {
							$out[ $k ] = (string) $rules[ $k ];
						}
					}
				}
				if ( isset( $out['min'], $out['max'] ) && (float) $out['min'] > (float) $out['max'] ) {
					$errors[ "$p.validation" ] = 'min_greater_than_max';
				}
				break;
			case 'date':
				foreach ( array( 'min', 'max' ) as $k ) {
					if ( isset( $rules[ $k ] ) && '' !== $rules[ $k ] ) {
						if ( 'today' === $rules[ $k ] || preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $rules[ $k ] ) ) {
							$out[ $k ] = (string) $rules[ $k ];
						} else {
							$errors[ "$p.validation.$k" ] = 'invalid_date';
						}
					}
				}
				if ( isset( $rules['min_age'] ) && '' !== $rules['min_age'] ) {
					$age = is_numeric( $rules['min_age'] ) ? (int) $rules['min_age'] : 0;
					if ( $age >= 1 && $age <= self::MIN_AGE_MAX && (string) $age === trim( (string) $rules['min_age'] ) ) {
						$out['min_age'] = $age;
					} else {
						$errors[ "$p.validation.min_age" ] = 'invalid_min_age';
					}
				}
				break;
			case 'checkbox':
				if ( isset( $rules['min_choices'] ) ) {
					$out['min_choices'] = max( 0, (int) $rules['min_choices'] );
				}
				if ( isset( $rules['max_choices'] ) ) {
					$out['max_choices'] = max( 1, (int) $rules['max_choices'] );
				}
				break;
		}
		// Own error message for this field (SET-FC-11); empty = translated default message.
		if ( FieldTypes::has_value( $type ) && 'hidden' !== $type ) {
			$message = self::plain( $rules['message'] ?? '', 190 );
			if ( '' !== $message ) {
				$out['message'] = $message;
			}
		}
		return $out;
	}

	private static function plain( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', wp_strip_all_tags( $value ) ) ?? '' );
		return mb_substr( $value, 0, $max );
	}
}
