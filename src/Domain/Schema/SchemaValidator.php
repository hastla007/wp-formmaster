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
use FormCaptain\Support\Limits;

final class SchemaValidator {

	public const CURRENT_VERSION = 1;

	private const OPERATORS = array( 'equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty', 'checked' );

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
					if ( in_array( $rules['pattern'], array( 'alpha', 'alnum', 'zip_de' ), true ) ) {
						$out['pattern'] = $rules['pattern'];
					} else {
						$errors[ "$p.validation.pattern" ] = 'invalid_pattern';
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
		return $out;
	}

	private static function plain( mixed $value, int $max ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', wp_strip_all_tags( $value ) ) ?? '' );
		return mb_substr( $value, 0, $max );
	}
}
