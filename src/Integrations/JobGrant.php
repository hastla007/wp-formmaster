<?php
/**
 * Job grant (docs/spec/rechtevertrag.md 5): while the worker runs an action callback,
 * code may read exactly the mapped fields of exactly that entry via form_captain_get_entry_v1().
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Integrations;

final class JobGrant {

	/** @var array{entry_id:int,fields:string[],sensitive:string[]}|null */
	private static ?array $grant = null;

	/**
	 * @param string[] $field_ids           mapped, non-sensitive field IDs
	 * @param string[] $sensitive_field_ids mapped sensitive field IDs explicitly allowed by the action
	 */
	public static function open( int $entry_id, array $field_ids, array $sensitive_field_ids = array() ): void {
		self::$grant = array(
			'entry_id'  => $entry_id,
			'fields'    => array_values( array_map( 'strval', $field_ids ) ),
			'sensitive' => array_values( array_map( 'strval', $sensitive_field_ids ) ),
		);
	}

	public static function close(): void {
		self::$grant = null;
	}

	/** @return array{entry_id:int,fields:string[],sensitive:string[]}|null grant for this entry */
	public static function for_entry( int $entry_id ): ?array {
		return ( null !== self::$grant && self::$grant['entry_id'] === $entry_id && $entry_id > 0 ) ? self::$grant : null;
	}
}
