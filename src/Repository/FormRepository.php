<?php
/**
 * Form persistence (fc_forms, fc_form_versions).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Repository;

use FormCaptain\Database\Tables;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Secret;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

final class FormRepository {

	public const STATUSES = array( 'draft', 'published', 'paused', 'archived' );

	private function table(): string {
		return Tables::name( Tables::FORMS );
	}

	private function versions(): string {
		return Tables::name( Tables::FORM_VERSIONS );
	}

	/**
	 * @param array<string,mixed> $schema
	 * @param array<string,mixed> $settings
	 */
	public function create( string $title, array $schema, array $settings, int $user_id = 0 ): int {
		global $wpdb;
		$now = Clock::now();
		do {
			$public_id = Secret::random_id( 16 );
		} while ( $this->find_by_public_id( $public_id ) );
		$wpdb->insert(
			$this->table(),
			array(
				'public_id'      => $public_id,
				'title'          => mb_substr( $title, 0, 190 ),
				'status'         => 'draft',
				'schema_json'    => wp_json_encode( $schema ),
				'schema_version' => (int) ( $schema['schema_version'] ?? 1 ),
				'revision'       => 1,
				'settings_json'  => wp_json_encode( $settings ),
				'created_by'     => $user_id,
				'created_gmt'    => $now,
				'updated_gmt'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_public_id( string $public_id ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9]{16}$/', $public_id ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE public_id = %s", $public_id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/** Accepts numeric ID or public ID. */
	public function resolve( int|string $id ): ?array {
		if ( is_int( $id ) || ctype_digit( (string) $id ) ) {
			return $this->find( (int) $id );
		}
		return $this->find_by_public_id( (string) $id );
	}

	/**
	 * Optimistic-locking update. Returns new revision or WP_Error(revision_conflict).
	 *
	 * @param array<string,mixed> $fields title, schema, settings
	 */
	public function update( int $id, array $fields, int $expected_revision ): int|\WP_Error {
		global $wpdb;
		$data = array( 'updated_gmt' => Clock::now() );
		if ( isset( $fields['title'] ) ) {
			$data['title'] = mb_substr( (string) $fields['title'], 0, 190 );
		}
		if ( isset( $fields['schema'] ) ) {
			$data['schema_json']    = wp_json_encode( $fields['schema'] );
			$data['schema_version'] = (int) ( $fields['schema']['schema_version'] ?? 1 );
		}
		if ( isset( $fields['settings'] ) ) {
			$data['settings_json'] = wp_json_encode( $fields['settings'] );
		}
		$sets   = array();
		$values = array();
		foreach ( $data as $col => $val ) {
			$sets[]   = "`{$col}` = %s";
			$values[] = $val;
		}
		$values[] = $id;
		$values[] = $expected_revision;
		$sql      = "UPDATE {$this->table()} SET " . implode( ', ', $sets ) . ', revision = revision + 1 WHERE id = %d AND revision = %d';
		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== (int) $affected ) {
			return Errors::error( Errors::REVISION_CONFLICT, __( 'This form was changed by someone else in the meantime. Reload to see the latest version.', 'form-captain' ) );
		}
		return $expected_revision + 1;
	}

	public function set_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		return false !== $wpdb->update( $this->table(), array( 'status' => $status, 'updated_gmt' => Clock::now() ), array( 'id' => $id ) );
	}

	/**
	 * Creates an immutable snapshot when the schema hash changed and points the form at it.
	 *
	 * @param array<string,mixed> $schema
	 */
	public function publish_version( int $form_id, array $schema ): int {
		global $wpdb;
		$json   = wp_json_encode( $schema );
		$hash   = hash( 'sha256', (string) $json );
		$latest = $wpdb->get_row( $wpdb->prepare( "SELECT id, version_no, schema_hash FROM {$this->versions()} WHERE form_id = %d ORDER BY version_no DESC LIMIT 1", $form_id ), ARRAY_A );
		if ( $latest && $latest['schema_hash'] === $hash ) {
			$version_id = (int) $latest['id'];
		} else {
			$wpdb->insert(
				$this->versions(),
				array(
					'form_id'     => $form_id,
					'version_no'  => $latest ? (int) $latest['version_no'] + 1 : 1,
					'schema_json' => $json,
					'schema_hash' => $hash,
					'created_gmt' => Clock::now(),
				)
			);
			$version_id = (int) $wpdb->insert_id;
		}
		$wpdb->update( $this->table(), array( 'published_version_id' => $version_id, 'status' => 'published', 'updated_gmt' => Clock::now() ), array( 'id' => $form_id ) );
		return $version_id;
	}

	/** @return array{id:int,form_id:int,version_no:int,schema:array<string,mixed>}|null */
	public function find_version( int $version_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->versions()} WHERE id = %d", $version_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'form_id'    => (int) $row['form_id'],
			'version_no' => (int) $row['version_no'],
			'schema'     => (array) json_decode( (string) $row['schema_json'], true ),
		);
	}

	/**
	 * @param array{status?:string,search?:string,per_page?:int,page?:int,orderby?:string,order?:string} $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		} else {
			$where[] = "status <> 'archived'";
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		$orderby  = in_array( $args['orderby'] ?? '', array( 'title', 'updated_gmt', 'created_gmt', 'status' ), true ) ? $args['orderby'] : 'updated_gmt';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$w        = implode( ' AND ', $where );
		$total    = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$w} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'items' => array_map( array( $this, 'hydrate' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/** @return array<int,int> form_id => count (excluding trash/spam) */
	public function entry_counts(): array {
		global $wpdb;
		$entries = Tables::name( Tables::ENTRIES );
		$rows    = $wpdb->get_results( "SELECT form_id, COUNT(*) c FROM {$entries} WHERE status NOT IN ('trash','spam') GROUP BY form_id", ARRAY_A );
		$out     = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (int) $r['form_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** Deletes form row and its versions. Caller must delete entries first (EntryDeletionService). */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->versions(), array( 'form_id' => $id ) );
		$wpdb->delete( $this->table(), array( 'id' => $id ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']                   = (int) $row['id'];
		$row['revision']             = (int) $row['revision'];
		$row['schema_version']       = (int) $row['schema_version'];
		$row['created_by']           = (int) $row['created_by'];
		$row['published_version_id'] = null !== $row['published_version_id'] ? (int) $row['published_version_id'] : null;
		$row['schema']               = (array) json_decode( (string) $row['schema_json'], true );
		$row['settings']             = (array) json_decode( (string) $row['settings_json'], true );
		unset( $row['schema_json'], $row['settings_json'] );
		return $row;
	}
}
