<?php
/**
 * Field type registry (Free types + filter for extensions).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain\Fields;

final class FieldTypes {

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $types = null;

	/** @return array<string,array{label:string,has_value:bool,has_options:bool,has_placeholder:bool,core:bool}> */
	public static function all(): array {
		if ( null === self::$types ) {
			$core = array(
				'text'     => array( 'label' => __( 'Single line text', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => true ),
				'textarea' => array( 'label' => __( 'Paragraph', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => true ),
				'email'    => array( 'label' => __( 'Email', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => true ),
				'tel'      => array( 'label' => __( 'Phone', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => true ),
				'number'   => array( 'label' => __( 'Number', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => true ),
				'select'   => array( 'label' => __( 'Dropdown', 'form-captain' ), 'has_value' => true, 'has_options' => true, 'has_placeholder' => false ),
				'radio'    => array( 'label' => __( 'Radio buttons', 'form-captain' ), 'has_value' => true, 'has_options' => true, 'has_placeholder' => false ),
				'checkbox' => array( 'label' => __( 'Checkboxes', 'form-captain' ), 'has_value' => true, 'has_options' => true, 'has_placeholder' => false ),
				'date'     => array( 'label' => __( 'Date', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => false ),
				'html'     => array( 'label' => __( 'Heading / HTML note', 'form-captain' ), 'has_value' => false, 'has_options' => false, 'has_placeholder' => false ),
				'hidden'   => array( 'label' => __( 'Hidden value', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => false ),
				'consent'  => array( 'label' => __( 'Consent', 'form-captain' ), 'has_value' => true, 'has_options' => false, 'has_placeholder' => false ),
			);
			foreach ( $core as $k => $def ) {
				$core[ $k ]['core'] = true;
			}
			$extra = (array) apply_filters( 'form_captain_field_types_v1', array() );
			foreach ( $extra as $k => $def ) {
				if ( isset( $core[ $k ] ) || ! is_string( $k ) || ! preg_match( '/^[a-z_]{2,30}$/', $k ) || ! is_array( $def ) ) {
					continue;
				}
				$core[ $k ] = array(
					'label'           => (string) ( $def['label'] ?? $k ),
					'has_value'       => (bool) ( $def['has_value'] ?? true ),
					'has_options'     => (bool) ( $def['has_options'] ?? false ),
					'has_placeholder' => (bool) ( $def['has_placeholder'] ?? false ),
					'core'            => false,
				);
			}
			self::$types = $core;
		}
		return self::$types;
	}

	public static function has_value( string $type ): bool {
		return (bool) ( self::all()[ $type ]['has_value'] ?? false );
	}

	public static function reset(): void {
		self::$types = null;
	}
}
