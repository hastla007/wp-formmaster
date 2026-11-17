<?php
/**
 * Normalizes and validates submitted values against a published schema (P3-05, P3-07, T-SUB-03/04).
 *
 * Pure logic (no database access); uses only WordPress helpers for translation and e-mail checks.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain;

use FormCaptain\Domain\Fields\FieldTypes;

final class SubmissionValidator {

	private const TRUTHY = array( '1', 'on', 'true', 'yes' );

	/**
	 * @param array<int,array<string,mixed>> $fields  normalized schema fields
	 * @param array<mixed,mixed>             $input   field_id (or key, see accept_keys) => raw value
	 * @param array{today?:string,accept_keys?:bool,files?:array<string,mixed>,form?:array<string,mixed>} $options
	 *        files: field_id => uploaded file data (PHP $_FILES shape) for Pro field types (contract 1.1.0)
	 * @return array{values:array<string,string|array<int,string>>,errors:array<string,string>,codes:array<string,string>,visible:array<string,bool>,consent:array<string,bool>}
	 */
	public function validate( array $fields, array $input, array $options = array() ): array {
		$today       = (string) ( $options['today'] ?? gmdate( 'Y-m-d' ) );
		$accept_keys = ! empty( $options['accept_keys'] );
		$by_id       = array();
		$by_key      = array();
		foreach ( $fields as $field ) {
			$by_id[ (string) $field['id'] ] = $field;
			if ( ! empty( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = (string) $field['id'];
			}
		}

		$codes = array();
		$raw   = array();

		// 1. Normalization: unknown IDs, arrays, nesting, duplicates.
		foreach ( $input as $name => $value ) {
			$name = (string) $name;
			$id   = isset( $by_id[ $name ] ) ? $name : ( $accept_keys && isset( $by_key[ $name ] ) ? $by_key[ $name ] : null );
			if ( null === $id ) {
				$codes[ preg_match( '/^f_[a-z0-9]{6,32}$/', $name ) ? $name : '_form' ] = 'unknown_field';
				continue;
			}
			$field = $by_id[ $id ];
			$type  = (string) $field['type'];
			if ( ! FieldTypes::has_value( $type ) || 'hidden' === $type ) {
				continue; // html notes carry no value; hidden values come from the schema (T-SUB-04).
			}
			if ( array_key_exists( $id, $raw ) ) {
				$codes[ $id ] = 'duplicate_value';
				continue;
			}
			$multi = 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) );
			$norm  = self::normalize_raw( $value, $multi, 'textarea' === $type );
			if ( null === $norm ) {
				$codes[ $id ] = 'invalid_value';
				continue;
			}
			$raw[ $id ] = $norm;
		}

		// 2. Values used for condition evaluation (same semantics as the client).
		$pre = array();
		foreach ( $fields as $field ) {
			$id   = (string) $field['id'];
			$type = (string) $field['type'];
			if ( ! FieldTypes::has_value( $type ) ) {
				continue;
			}
			$multi = 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) );
			if ( 'hidden' === $type ) {
				$pre[ $id ] = (string) ( $field['default'] ?? '' );
			} elseif ( 'consent' === $type ) {
				$pre[ $id ] = self::truthy( $raw[ $id ] ?? '' ) ? '1' : '';
			} elseif ( $multi ) {
				$pre[ $id ] = is_array( $raw[ $id ] ?? null ) ? $raw[ $id ] : array();
			} else {
				$pre[ $id ] = is_string( $raw[ $id ] ?? null ) ? trim( $raw[ $id ] ) : '';
			}
		}
		$visible = ConditionEvaluator::visibility( $fields, $pre );
		foreach ( array_keys( $codes ) as $id ) {
			if ( isset( $by_id[ $id ] ) && empty( $visible[ $id ] ) ) {
				unset( $codes[ $id ] ); // Values of invisible fields are discarded, never rejected.
			}
		}

		// 3. Type validation for visible fields; invisible values are discarded.
		$values  = array();
		$consent = array();
		foreach ( $fields as $field ) {
			$id   = (string) $field['id'];
			$type = (string) $field['type'];
			if ( ! FieldTypes::has_value( $type ) || isset( $codes[ $id ] ) || empty( $visible[ $id ] ) ) {
				continue;
			}
			$result = empty( FieldTypes::all()[ $type ]['core'] )
				? self::validate_extension_field( $field, $raw[ $id ] ?? null, $options )
				: $this->validate_field( $field, $raw[ $id ] ?? null, $today );
			if ( isset( $result['code'] ) ) {
				$codes[ $id ] = $result['code'];
				continue;
			}
			if ( 'consent' === $type ) {
				$consent[ $id ] = '1' === $result['value'];
			}
			if ( null !== $result['value'] && '' !== $result['value'] && array() !== $result['value'] ) {
				$values[ $id ] = $result['value'];
			}
		}

		$errors = array();
		foreach ( $codes as $id => $code ) {
			$errors[ $id ] = self::message( $code, $by_id[ $id ] ?? array() );
		}
		return array(
			'values'  => $values,
			'errors'  => $errors,
			'codes'   => $codes,
			'visible' => $visible,
			'consent' => $consent,
		);
	}

	/**
	 * Returns a string, a list of strings, or null if the shape is not allowed.
	 *
	 * @return string|array<int,string>|null
	 */
	private static function normalize_raw( mixed $value, bool $multi, bool $multiline ): string|array|null {
		if ( is_array( $value ) ) {
			if ( ! $multi ) {
				return null;
			}
			$out = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) || is_object( $item ) ) {
					return null; // Payload depth > 2.
				}
				$s = self::scalar( $item, false );
				if ( null === $s ) {
					return null;
				}
				if ( '' !== trim( $s ) ) {
					$out[] = trim( $s );
				}
			}
			return array_values( array_unique( $out ) );
		}
		if ( is_object( $value ) ) {
			return null;
		}
		$s = self::scalar( $value, $multiline );
		if ( null === $s ) {
			return null;
		}
		if ( $multi ) {
			return '' === trim( $s ) ? array() : array( trim( $s ) );
		}
		return $s;
	}

	private static function scalar( mixed $value, bool $multiline ): ?string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$s = (string) $value;
		if ( ! mb_check_encoding( $s, 'UTF-8' ) ) {
			return null;
		}
		$s = str_replace( "\0", '', $s );
		if ( $multiline ) {
			$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
			return (string) preg_replace( '/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		}
		return (string) preg_replace( '/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
	}

	private static function truthy( mixed $value ): bool {
		return is_string( $value ) && in_array( strtolower( trim( $value ) ), self::TRUTHY, true );
	}

	/**
	 * @param array<string,mixed>           $field
	 * @param string|array<int,string>|null $value
	 * @return array{value?:string|array<int,string>|null,code?:string}
	 */
	private function validate_field( array $field, string|array|null $value, string $today ): array {
		$type     = (string) $field['type'];
		$required = ! empty( $field['required'] );
		$rules    = is_array( $field['validation'] ?? null ) ? $field['validation'] : array();
		$options  = array_map( static fn( $o ) => (string) ( $o['value'] ?? '' ), is_array( $field['options'] ?? null ) ? $field['options'] : array() );

		if ( 'hidden' === $type ) {
			return array( 'value' => mb_substr( (string) ( $field['default'] ?? '' ), 0, 500 ) );
		}
		if ( 'consent' === $type ) {
			$given = self::truthy( is_string( $value ) ? $value : '' );
			if ( $required && ! $given ) {
				return array( 'code' => 'consent_required' );
			}
			return array( 'value' => $given ? '1' : '0' );
		}
		if ( 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) ) ) {
			$list = is_array( $value ) ? $value : array();
			if ( ! $list ) {
				return $required ? array( 'code' => 'required' ) : array( 'value' => null );
			}
			foreach ( $list as $v ) {
				if ( ! in_array( $v, $options, true ) ) {
					return array( 'code' => 'invalid_option' );
				}
			}
			if ( isset( $rules['min_choices'] ) && count( $list ) < (int) $rules['min_choices'] ) {
				return array( 'code' => 'too_few_choices' );
			}
			if ( isset( $rules['max_choices'] ) && count( $list ) > (int) $rules['max_choices'] ) {
				return array( 'code' => 'too_many_choices' );
			}
			return array( 'value' => array_values( $list ) );
		}

		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return $required ? array( 'code' => 'required' ) : array( 'value' => null );
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
				$max = (int) ( $rules['max_length'] ?? ( 'text' === $type ? 190 : 5000 ) );
				$min = (int) ( $rules['min_length'] ?? 0 );
				$len = mb_strlen( $value );
				if ( $len > $max ) {
					return array( 'code' => 'too_long' );
				}
				if ( $len < $min ) {
					return array( 'code' => 'too_short' );
				}
				$pattern = match ( (string) ( $rules['pattern'] ?? '' ) ) {
					'alpha' => '/^[\p{L}\s\'\-]+$/u',
					'alnum' => '/^[\p{L}\p{N}\s\'\-]+$/u',
					'zip_de' => '/^\d{5}$/',
					default => null,
				};
				if ( 'text' === $type && null !== $pattern && ! preg_match( $pattern, $value ) ) {
					return array( 'code' => 'invalid_pattern' );
				}
				return array( 'value' => $value );

			case 'email':
				return self::email( $value, (int) ( $rules['max_length'] ?? 254 ) );

			case 'tel':
				return preg_match( '/^[0-9 +()\-\/.]{3,40}$/', $value ) ? array( 'value' => $value ) : array( 'code' => 'invalid_tel' );

			case 'number':
				$n = str_replace( ',', '.', ltrim( $value, '+' ) );
				if ( ! preg_match( '/^-?\d{1,15}(\.\d{1,10})?$/', $n ) ) {
					return array( 'code' => 'invalid_number' );
				}
				$f = (float) $n;
				if ( ( isset( $rules['min'] ) && $f < (float) $rules['min'] ) || ( isset( $rules['max'] ) && $f > (float) $rules['max'] ) ) {
					return array( 'code' => 'out_of_range' );
				}
				if ( isset( $rules['step'] ) && (float) $rules['step'] > 0 ) {
					$steps = ( $f - (float) ( $rules['min'] ?? 0 ) ) / (float) $rules['step'];
					if ( abs( $steps - round( $steps ) ) > 1e-9 ) {
						return array( 'code' => 'invalid_step' );
					}
				}
				return array( 'value' => $n );

			case 'date':
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return array( 'code' => 'invalid_date' );
				}
				$min = isset( $rules['min'] ) ? ( 'today' === $rules['min'] ? $today : (string) $rules['min'] ) : null;
				$max = isset( $rules['max'] ) ? ( 'today' === $rules['max'] ? $today : (string) $rules['max'] ) : null;
				if ( ( null !== $min && $value < $min ) || ( null !== $max && $value > $max ) ) {
					return array( 'code' => 'out_of_range' );
				}
				return array( 'value' => $value );

			case 'select':
			case 'radio':
				return in_array( $value, $options, true ) ? array( 'value' => $value ) : array( 'code' => 'invalid_option' );
		}

		// Extension types: keep as bounded plain text.
		return mb_strlen( $value ) > 1000 ? array( 'code' => 'too_long' ) : array( 'value' => $value );
	}

	/** @return array{value?:string,code?:string} */
	private static function email( string $value, int $max ): array {
		if ( preg_match( '/[\r\n]/', $value ) || mb_strlen( $value ) > $max ) {
			return array( 'code' => mb_strlen( $value ) > $max ? 'too_long' : 'invalid_email' );
		}
		$value = mb_strtolower( $value );
		$check = $value;
		$at    = strrpos( $value, '@' );
		if ( false !== $at && preg_match( '/[^\x00-\x7F]/', substr( $value, $at + 1 ) ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( substr( $value, $at + 1 ), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$check = false !== $ascii ? substr( $value, 0, $at + 1 ) . $ascii : $value;
		}
		if ( ! is_email( $check ) || ! preg_match( '/@[^@]+\.[^@]+$/', $check ) ) {
			return array( 'code' => 'invalid_email' );
		}
		return array( 'value' => $value );
	}

	/**
	 * Field types registered by an extension (form_captain_field_types_v1) are validated by that
	 * extension (contract 1.1.0). Without a validator a required field fails and an optional one
	 * stays empty, so no unchecked value is ever stored.
	 *
	 * @param array<string,mixed>      $field
	 * @param string|array<int,string> $value
	 * @param array<string,mixed>      $options
	 * @return array{value?:string|array<int,string>|null,code?:string}
	 */
	private static function validate_extension_field( array $field, string|array|null $value, array $options ): array {
		$id     = (string) $field['id'];
		$files  = is_array( $options['files'] ?? null ) ? $options['files'] : array();
		$result = apply_filters(
			'form_captain_validate_field_v1',
			null,
			$field,
			$value,
			array(
				'file' => $files[ $id ] ?? null,
				'form' => is_array( $options['form'] ?? null ) ? $options['form'] : array(),
			)
		);
		if ( is_array( $result ) && ( array_key_exists( 'value', $result ) || isset( $result['code'] ) ) ) {
			if ( isset( $result['code'] ) ) {
				return array( 'code' => preg_match( '/^[a-z][a-z0-9_]{1,40}$/', (string) $result['code'] ) ? (string) $result['code'] : 'invalid_value' );
			}
			$v = $result['value'];
			if ( null === $v || is_string( $v ) ) {
				return array( 'value' => null === $v ? null : mb_substr( $v, 0, 5000 ) );
			}
			if ( is_array( $v ) ) {
				return array( 'value' => array_values( array_map( static fn( $x ): string => mb_substr( (string) $x, 0, 500 ), array_filter( $v, 'is_scalar' ) ) ) );
			}
			return array( 'code' => 'invalid_value' );
		}
		return ! empty( $field['required'] ) ? array( 'code' => 'required' ) : array( 'value' => null );
	}

	/**
	 * Translated, visitor-safe message for a field error code.
	 *
	 * @param array<string,mixed> $field
	 */
	public static function message( string $code, array $field = array() ): string {
		$rules = is_array( $field['validation'] ?? null ) ? $field['validation'] : array();
		switch ( $code ) {
			case 'required':
				return __( 'This field is required.', 'form-captain' );
			case 'too_long':
				return isset( $rules['max_length'] )
					/* translators: %d: maximum number of characters */
					? sprintf( __( 'Please enter at most %d characters.', 'form-captain' ), (int) $rules['max_length'] )
					: __( 'This value is too long.', 'form-captain' );
			case 'too_short':
				/* translators: %d: minimum number of characters */
				return sprintf( __( 'Please enter at least %d characters.', 'form-captain' ), (int) ( $rules['min_length'] ?? 1 ) );
			case 'invalid_email':
				return __( 'Please enter a valid email address.', 'form-captain' );
			case 'invalid_tel':
				return __( 'Please enter a valid phone number (digits, spaces and + ( ) - / only).', 'form-captain' );
			case 'invalid_number':
				return __( 'Please enter a number.', 'form-captain' );
			case 'out_of_range':
				return __( 'This value is outside the allowed range.', 'form-captain' );
			case 'invalid_step':
				return __( 'Please enter a value in the allowed steps.', 'form-captain' );
			case 'invalid_date':
				return __( 'Please enter a valid date (YYYY-MM-DD).', 'form-captain' );
			case 'invalid_option':
				return __( 'Please choose one of the offered options.', 'form-captain' );
			case 'too_few_choices':
				/* translators: %d: minimum number of choices */
				return sprintf( __( 'Please choose at least %d options.', 'form-captain' ), (int) ( $rules['min_choices'] ?? 1 ) );
			case 'too_many_choices':
				/* translators: %d: maximum number of choices */
				return sprintf( __( 'Please choose at most %d options.', 'form-captain' ), (int) ( $rules['max_choices'] ?? 1 ) );
			case 'consent_required':
				return __( 'Please confirm this checkbox to send the form.', 'form-captain' );
			case 'invalid_pattern':
				return __( 'Please check the format of this field.', 'form-captain' );
			case 'unknown_field':
				return __( 'The submission contained data that does not belong to this form. Please reload the page.', 'form-captain' );
			case 'duplicate_value':
			case 'invalid_value':
			default:
				/** Extensions translate their own codes (contract 1.1.0). */
				$custom = apply_filters( 'form_captain_field_error_message_v1', '', $code, $field );
				return is_string( $custom ) && '' !== $custom ? wp_strip_all_tags( $custom ) : __( 'This value is not allowed.', 'form-captain' );
		}
	}
}
