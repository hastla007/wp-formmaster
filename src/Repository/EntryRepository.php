<?php
/**
 * Entry persistence (fc_entries, fc_entry_values, fc_entry_notes).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Repository;

use FormCaptain\Database\Tables;
use FormCaptain\Support\Clock;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

final class EntryRepository {

	public const STATUSES = array( 'new', 'read', 'archived', 'spam', 'trash' );

	private function entries(): string {
		return Tables::name( Tables::ENTRIES );
	}

	private function values(): string {
		return Tables::name( Tables::ENTRY_VALUES );
	}

	private function notes(): string {
		return Tables::name( Tables::ENTRY_NOTES );
	}

	/** Starts a transaction; returns false if unsupported. */
	public function begin(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	public function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/** @return array<string,mixed>|null */
	public function find_by_request_key( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->entries()} WHERE request_key = %s", $key ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Inserts entry row. Returns ID or 0 on failure (e.g. duplicate request_key).
	 *
	 * @param array<string,mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now  = Clock::now();
		$row  = array(
			'form_id'         => (int) $data['form_id'],
			'form_version_id' => (int) $data['form_version_id'],
			'status'          => (string) ( $data['status'] ?? 'new' ),
			'request_key'     => (string) $data['request_key'],
			'spam_reason'     => $data['spam_reason'] ?? null,
			'source_url'      => isset( $data['source_url'] ) ? mb_substr( (string) $data['source_url'], 0, 2048 ) : null,
			'source_post_id'  => $data['source_post_id'] ?? null,
			'user_id'         => $data['user_id'] ?? null,
			'context_json'    => isset( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
			'consent_json'    => isset( $data['consent'] ) ? wp_json_encode( $data['consent'] ) : null,
			'created_gmt'     => $now,
			'updated_gmt'     => $now,
			'trashed_gmt'     => 'spam' === ( $data['status'] ?? '' ) ? $now : null,
			'expires_gmt'     => $data['expires_gmt'] ?? null,
		);
		$ok = $wpdb->insert( $this->entries(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array<string,string|array<int,string>> $values field_id => value
	 */
	public function insert_values( int $entry_id, array $values ): bool {
		global $wpdb;
		foreach ( $values as $field_id => $value ) {
			$multi = is_array( $value );
			$ok    = $wpdb->insert(
				$this->values(),
				array(
					'entry_id' => $entry_id,
					'field_id' => (string) $field_id,
					'value'    => $multi ? (string) wp_json_encode( array_values( $value ) ) : (string) $value,
					'is_multi' => $multi ? 1 : 0,
				)
			);
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->entries()} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<string,string|array<int,string>> field_id => value */
	public function values_for( int $entry_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT field_id, value, is_multi FROM {$this->values()} WHERE entry_id = %d", $entry_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ $r['field_id'] ] = $r['is_multi'] ? (array) json_decode( (string) $r['value'], true ) : (string) $r['value'];
		}
		return $out;
	}

	/**
	 * @param int[] $entry_ids
	 * @return array<int,array<string,string|array<int,string>>>
	 */
	public function values_for_many( array $entry_ids ): array {
		global $wpdb;
		$entry_ids = array_values( array_filter( array_map( 'intval', $entry_ids ) ) );
		if ( ! $entry_ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, field_id, value, is_multi FROM {$this->values()} WHERE entry_id IN ({$in})", $entry_ids ), ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (int) $r['entry_id'] ][ $r['field_id'] ] = $r['is_multi'] ? (array) json_decode( (string) $r['value'], true ) : (string) $r['value'];
		}
		return $out;
	}

	/**
	 * @param array<string,string|null> $values field_id => value (null = delete row)
	 */
	public function replace_values( int $entry_id, array $values ): void {
		global $wpdb;
		foreach ( $values as $field_id => $value ) {
			if ( null === $value ) {
				$wpdb->delete( $this->values(), array( 'entry_id' => $entry_id, 'field_id' => $field_id ) );
			} else {
				$wpdb->update( $this->values(), array( 'value' => $value, 'is_multi' => 0 ), array( 'entry_id' => $entry_id, 'field_id' => $field_id ) );
			}
		}
	}

	/**
	 * @param array<string,mixed> $fields Columns to update.
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$fields['updated_gmt'] = Clock::now();
		return false !== $wpdb->update( $this->entries(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Status change with trash bookkeeping. Returns previous status or null if not allowed.
	 */
	public function set_status( int $id, string $status ): ?string {
		$entry = $this->find( $id );
		if ( ! $entry || ! in_array( $status, self::STATUSES, true ) || $entry['status'] === $status ) {
			return null;
		}
		$fields = array( 'status' => $status );
		if ( 'trash' === $status ) {
			$fields['previous_status'] = $entry['status'];
			$fields['trashed_gmt']     = Clock::now();
		} elseif ( 'spam' === $status ) {
			$fields['trashed_gmt'] = Clock::now();
		} else {
			$fields['trashed_gmt'] = null;
		}
		$this->update( $id, $fields );
		return (string) $entry['status'];
	}

	/**
	 * @param array{form_id?:int,status?:string,search?:string,date_from?:string,date_to?:string,per_page?:int,page?:int,order?:string,ids?:int[]} $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		list( $w, $params ) = $this->where( $args );
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$total    = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->entries()} e WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$this->entries()} e WHERE {$w}" );
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM {$this->entries()} e WHERE {$w} ORDER BY e.created_gmt {$order}, e.id {$order} LIMIT %d OFFSET %d", $params ), ARRAY_A );
		return array(
			'items' => array_map( array( $this, 'hydrate' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Keyset iteration for exports/cleanup: entries with id > $after_id.
	 *
	 * @param array<string,mixed> $args same filters as list()
	 * @return array<int,array<string,mixed>>
	 */
	public function batch_after( int $after_id, int $limit, array $args = array() ): array {
		global $wpdb;
		list( $w, $params ) = $this->where( $args );
		$params[] = $after_id;
		$params[] = $limit;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM {$this->entries()} e WHERE {$w} AND e.id > %d ORDER BY e.id ASC LIMIT %d", $params ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/** @return array{0:string,1:array<int,mixed>} */
	private function where( array $args ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'e.form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'e.status = %s';
			$params[] = $args['status'];
		} elseif ( empty( $args['any_status'] ) ) {
			$where[] = "e.status NOT IN ('trash','spam')";
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'e.created_gmt >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'e.created_gmt <= %s';
			$params[] = (string) $args['date_to'];
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids      = array_map( 'intval', (array) $args['ids'] );
			$where[]  = 'e.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params   = array_merge( $params, $ids );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$this->values()} v WHERE v.entry_id = e.id AND v.value LIKE %s)";
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['not_anonymized'] ) ) {
			$where[] = 'e.anonymized_gmt IS NULL';
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/** Entry IDs whose lookup fields equal $email (privacy). */
	public function ids_by_value( array $field_ids, string $value, int $limit = 500, int $offset = 0 ): array {
		global $wpdb;
		if ( ! $field_ids ) {
			return array();
		}
		$in     = implode( ',', array_fill( 0, count( $field_ids ), '%s' ) );
		$params = array_merge( array_values( $field_ids ), array( $value, $limit, $offset ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT entry_id FROM {$this->values()} WHERE field_id IN ({$in}) AND value = %s ORDER BY entry_id LIMIT %d OFFSET %d", $params ) ) );
	}

	public function add_note( int $entry_id, int $user_id, string $message, string $type = 'note' ): int {
		global $wpdb;
		$wpdb->insert(
			$this->notes(),
			array(
				'entry_id'    => $entry_id,
				'user_id'     => $user_id,
				'type'        => 'audit' === $type ? 'audit' : 'note',
				'message'     => mb_substr( $message, 0, 5000 ),
				'created_gmt' => Clock::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<int,array<string,mixed>> */
	public function notes_for( int $entry_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->notes()} WHERE entry_id = %d ORDER BY created_gmt ASC, id ASC", $entry_id ), ARRAY_A ) ?: array();
	}

	/** Hard-deletes values, notes and the entry row. Callers use EntryDeletionService. */
	public function purge( int $entry_id ): void {
		global $wpdb;
		$wpdb->delete( $this->values(), array( 'entry_id' => $entry_id ) );
		$wpdb->delete( $this->notes(), array( 'entry_id' => $entry_id ) );
		$wpdb->delete( $this->entries(), array( 'id' => $entry_id ) );
	}

	/** @return int[] */
	public function ids_expired( string $now, int $limit ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->entries()} WHERE expires_gmt IS NOT NULL AND expires_gmt <= %s ORDER BY id LIMIT %d", $now, $limit ) ) );
	}

	/** @return int[] trash/spam older than cutoff */
	public function ids_trashed_before( string $cutoff, int $limit ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->entries()} WHERE status IN ('trash','spam') AND trashed_gmt IS NOT NULL AND trashed_gmt <= %s ORDER BY id LIMIT %d", $cutoff, $limit ) ) );
	}

	/** @return array<string,int> status => count */
	public function status_counts( int $form_id = 0 ): array {
		global $wpdb;
		$sql  = "SELECT status, COUNT(*) c FROM {$this->entries()}" . ( $form_id ? $wpdb->prepare( ' WHERE form_id = %d', $form_id ) : '' ) . ' GROUP BY status';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( $rows ?: array() as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( array( 'id', 'form_id', 'form_version_id' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		foreach ( array( 'source_post_id', 'user_id' ) as $k ) {
			$row[ $k ] = null !== $row[ $k ] ? (int) $row[ $k ] : null;
		}
		$row['context'] = $row['context_json'] ? (array) json_decode( (string) $row['context_json'], true ) : null;
		$row['consent'] = $row['consent_json'] ? (array) json_decode( (string) $row['consent_json'], true ) : null;
		unset( $row['context_json'], $row['consent_json'] );
		return $row;
	}
}
