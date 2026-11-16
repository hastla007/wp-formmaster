<?php
/**
 * Compares two form schemas to warn about changes that affect existing entries (P2-16).
 *
 * Entries always keep their form_version_id, so old answers stay readable with the
 * labels of their snapshot; this diff only informs the editor before publishing.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

final class FormSchemaDiff {

	/**
	 * @param array<string,mixed> $old published snapshot (or empty)
	 * @param array<string,mixed> $new working schema
	 * @return array{removed:array<int,array{id:string,label:string}>,added:array<int,array{id:string,label:string}>,newly_required:array<int,array{id:string,label:string}>,type_changed:array<int,array{id:string,label:string}>,options_removed:array<int,array{id:string,label:string,values:array<int,string>}>,has_changes:bool}
	 */
	public static function compare( array $old, array $new ): array {
		$old_fields = self::index( $old );
		$new_fields = self::index( $new );
		$out        = array(
			'removed'         => array(),
			'added'           => array(),
			'newly_required'  => array(),
			'type_changed'    => array(),
			'options_removed' => array(),
			'has_changes'     => false,
		);
		foreach ( $old_fields as $id => $field ) {
			if ( ! isset( $new_fields[ $id ] ) ) {
				$out['removed'][] = self::ref( $field );
				continue;
			}
			$now = $new_fields[ $id ];
			if ( ( $field['type'] ?? '' ) !== ( $now['type'] ?? '' ) ) {
				$out['type_changed'][] = self::ref( $now );
			}
			if ( empty( $field['required'] ) && ! empty( $now['required'] ) ) {
				$out['newly_required'][] = self::ref( $now );
			}
			$old_values = array_map( static fn( $o ) => (string) ( $o['value'] ?? '' ), (array) ( $field['options'] ?? array() ) );
			$new_values = array_map( static fn( $o ) => (string) ( $o['value'] ?? '' ), (array) ( $now['options'] ?? array() ) );
			$gone       = array_values( array_diff( $old_values, $new_values ) );
			if ( $gone ) {
				$out['options_removed'][] = self::ref( $now ) + array( 'values' => $gone );
			}
		}
		foreach ( $new_fields as $id => $field ) {
			if ( ! isset( $old_fields[ $id ] ) ) {
				$out['added'][] = self::ref( $field );
				if ( ! empty( $field['required'] ) && $old_fields ) {
					$out['newly_required'][] = self::ref( $field );
				}
			}
		}
		$out['has_changes'] = (bool) ( $out['removed'] || $out['added'] || $out['newly_required'] || $out['type_changed'] || $out['options_removed'] );
		return $out;
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<string,array<string,mixed>>
	 */
	private static function index( array $schema ): array {
		$out = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) ) {
				$out[ (string) $field['id'] ] = $field;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array{id:string,label:string}
	 */
	private static function ref( array $field ): array {
		return array(
			'id'    => (string) ( $field['id'] ?? '' ),
			'label' => (string) ( $field['label'] ?? $field['key'] ?? '' ),
		);
	}
}
