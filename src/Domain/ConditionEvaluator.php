<?php
/**
 * Server-side evaluation of simple field conditions (P2-10).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain;

final class ConditionEvaluator {

	/**
	 * Returns field_id => visible for all fields, evaluating in order so that a
	 * hidden controlling field makes its dependants evaluate against an empty value.
	 *
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values field_id => raw value
	 * @return array<string,bool>
	 */
	public static function visibility( array $fields, array $values ): array {
		$visible = array();
		foreach ( $fields as $field ) {
			$id        = (string) $field['id'];
			$condition = $field['condition'] ?? null;
			if ( ! is_array( $condition ) ) {
				$visible[ $id ] = true;
				continue;
			}
			$ref            = (string) $condition['field'];
			$value          = ( $visible[ $ref ] ?? false ) ? ( $values[ $ref ] ?? '' ) : '';
			$match          = self::matches( (string) $condition['operator'], $value, (string) ( $condition['value'] ?? '' ) );
			$visible[ $id ] = 'hide' === ( $condition['action'] ?? 'show' ) ? ! $match : $match;
		}
		/** Pro extension point for grouped conditions. */
		return (array) apply_filters( 'form_captain_condition_evaluators_v1', $visible, $fields, $values );
	}

	/**
	 * @param string|array<int,string> $value
	 */
	public static function matches( string $operator, string|array $value, string $expected ): bool {
		$list  = is_array( $value ) ? array_map( 'strval', $value ) : ( '' === $value ? array() : array( (string) $value ) );
		$empty = 0 === count( array_filter( $list, static fn( $v ) => '' !== trim( $v ) ) );
		return match ( $operator ) {
			'equals' => in_array( $expected, $list, true ) && ( 1 === count( $list ) || is_array( $value ) ),
			'not_equals' => ! in_array( $expected, $list, true ),
			'contains' => (bool) array_filter( $list, static fn( $v ) => '' !== $expected && false !== mb_stripos( $v, $expected ) ),
			'is_empty' => $empty,
			'is_not_empty' => ! $empty,
			'checked' => ! $empty,
			default => false,
		};
	}
}
