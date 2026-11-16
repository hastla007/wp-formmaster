<?php
/**
 * Pure schema migrations between format versions (docs/spec/formularschema.md).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain\Schema;

final class Migrations {

	/**
	 * Upgrades any supported schema version to the current version in memory.
	 *
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	public static function upgrade( array $schema ): array {
		$version = (int) ( $schema['schema_version'] ?? 1 );
		// v1 is the first version; future steps: if ( 1 === $version ) { $schema = V1To2::migrate( $schema ); $version = 2; }
		$schema['schema_version'] = max( 1, $version );
		return $schema;
	}
}
