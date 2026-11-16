<?php
/**
 * Loads the published (or working) schema of a form in normalized shape for rendering and validation.
 *
 * Published snapshots are never rewritten; they are migrated/normalized in memory (formularschema.md).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Repository\FormRepository;

final class FormSchemaLoader {

	/** @var array<int,array{version_id:int,version_no:int,fields:array<int,array<string,mixed>>}> */
	private array $cache = array();

	public function __construct( private FormRepository $forms, private SchemaValidator $validator ) {}

	/**
	 * Published version of a form or null when it has none.
	 *
	 * @param array<string,mixed> $form
	 * @return array{version_id:int,version_no:int,fields:array<int,array<string,mixed>>}|null
	 */
	public function published( array $form ): ?array {
		$version_id = (int) ( $form['published_version_id'] ?? 0 );
		if ( $version_id <= 0 ) {
			return null;
		}
		if ( ! isset( $this->cache[ $version_id ] ) ) {
			$version = $this->forms->find_version( $version_id );
			if ( ! $version || (int) $version['form_id'] !== (int) $form['id'] ) {
				return null;
			}
			$this->cache[ $version_id ] = array(
				'version_id' => $version_id,
				'version_no' => (int) $version['version_no'],
				'fields'     => $this->normalize( $version['schema'] ),
			);
		}
		return $this->cache[ $version_id ];
	}

	/**
	 * A published snapshot of a form by its number (versions bound by a signed context).
	 *
	 * @param array<string,mixed> $form
	 * @return array{version_id:int,version_no:int,fields:array<int,array<string,mixed>>}|null
	 */
	public function version( array $form, int $version_no ): ?array {
		foreach ( $this->forms->versions_for( (int) $form['id'] ) as $version ) {
			if ( (int) $version['version_no'] === $version_no ) {
				$id = (int) $version['id'];
				if ( ! isset( $this->cache[ $id ] ) ) {
					$this->cache[ $id ] = array(
						'version_id' => $id,
						'version_no' => $version_no,
						'fields'     => $this->normalize( $version['schema'] ),
					);
				}
				return $this->cache[ $id ];
			}
		}
		return null;
	}

	/**
	 * Working (draft) schema for previews.
	 *
	 * @param array<string,mixed> $form
	 * @return array{version_id:int,version_no:int,fields:array<int,array<string,mixed>>}
	 */
	public function working( array $form ): array {
		return array(
			'version_id' => 0,
			'version_no' => 0,
			'fields'     => $this->normalize( is_array( $form['schema'] ?? null ) ? $form['schema'] : array() ),
		);
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize( array $schema ): array {
		$result = $this->validator->validate( $schema );
		if ( isset( $result['errors']['schema_version'] ) ) {
			return array();
		}
		return array_values( (array) ( $result['schema']['fields'] ?? array() ) );
	}

	public function flush(): void {
		$this->cache = array();
	}
}
