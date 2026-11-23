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
	/**
	 * Starts a transaction; returns false if unsupported.
	 *
	 * When an outer transaction is already open (e.g. the WordPress test suite wraps
	 * every test in one), START TRANSACTION would implicitly commit it. The filter
	 * `form_captain_db_in_outer_transaction` switches to savepoints in that case.
	 */
	public function begin(): bool {
		global $wpdb;
		if ( self::nested() ) {
			return false !== $wpdb->query( 'SAVEPOINT form_captain_tx' );
		}
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		global $wpdb;
		$wpdb->query( self::nested() ? 'RELEASE SAVEPOINT form_captain_tx' : 'COMMIT' );
	}

	public function rollback(): void {
		global $wpdb;
		$wpdb->query( self::nested() ? 'ROLLBACK TO SAVEPOINT form_captain_tx' : 'ROLLBACK' );
	}

	private static function nested(): bool {
		return (bool) apply_filters( 'form_captain_db_in_outer_transaction', false );
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
		$now = Clock::now();
		$row = array(
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
		if ( isset( $data['entry_number'] ) && '' !== (string) $data['entry_number'] ) {
			$row['entry_number'] = mb_substr( (string) $data['entry_number'], 0, 40 );
		}
		// SET-FC-10/21: only written when set, so callers without these values keep their old row shape.
		foreach ( array( 'locale', 'confirmation_status', 'unconfirmed_until_gmt' ) as $optional ) {
			if ( isset( $data[ $optional ] ) && '' !== $data[ $optional ] ) {
				$row[ $optional ] = (string) $data[ $optional ];
			}
		}
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
	 * @param array{form_id?:int,status?:string,search?:string,date_from?:string,date_to?:string,per_page?:int,page?:int,order?:string,ids?:int[],consent?:string} $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		list( $w, $params ) = $this->where( $args );
		$per_page           = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset             = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$order              = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$total              = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->entries()} e WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$this->entries()} e WHERE {$w}" );
		$params[]           = $per_page;
		$params[]           = $offset;
		$rows               = $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM {$this->entries()} e WHERE {$w} ORDER BY e.created_gmt {$order}, e.id {$order} LIMIT %d OFFSET %d", $params ), ARRAY_A );
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
		$params[]           = $after_id;
		$params[]           = $limit;
		$rows               = $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM {$this->entries()} e WHERE {$w} AND e.id > %d ORDER BY e.id ASC LIMIT %d", $params ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Returns a valid "Y-m-d H:i:s" (or "Y-m-d", expanded to midnight) datetime string or null.
	 */
	public static function valid_datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		if ( isset( $m[4] ) && ( (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) ) {
			return null;
		}
		return isset( $m[4] ) ? $value : $value . ' 00:00:00';
	}

	/**
	 * @return array{0:string,1:array<int,mixed>}
	 *
	 * @param array<string,mixed> $args
	 */
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
		// Only well-formed UTC datetimes reach the database; anything else is ignored
		// (strict SQL modes reject invalid DATETIME literals with an error).
		$date_from = self::valid_datetime( $args['date_from'] ?? null );
		if ( null !== $date_from ) {
			$where[]  = 'e.created_gmt >= %s';
			$params[] = $date_from;
		}
		$date_to = self::valid_datetime( $args['date_to'] ?? null );
		if ( null !== $date_to ) {
			$where[]  = 'e.created_gmt <= %s';
			$params[] = $date_to;
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids     = array_map( 'intval', (array) $args['ids'] );
			$where[] = 'e.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$this->values()} v WHERE v.entry_id = e.id AND v.value LIKE %s)";
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['not_anonymized'] ) ) {
			$where[] = 'e.anonymized_gmt IS NULL';
		}
		if ( 'withdrawn' === ( $args['consent'] ?? '' ) ) {
			$where[] = 'e.consent_withdrawn_gmt IS NOT NULL';
		} elseif ( 'active' === ( $args['consent'] ?? '' ) ) {
			$where[] = 'e.consent_withdrawn_gmt IS NULL';
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Entry IDs whose lookup fields equal $email (privacy).
	 *
	 * @param array<int,int|string> $field_ids
	 * @return array<int,int>
	 */
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

	/**
	 * Entries of one form created by one entry import run (context "import_run", any status).
	 *
	 * @return int[]
	 */
	public function ids_for_import_run( int $form_id, string $run_id, int $limit ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"import_run":"' . $run_id . '"' ) . '%';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->entries()} WHERE form_id = %d AND context_json LIKE %s ORDER BY id LIMIT %d", $form_id, $like, $limit ) ) );
	}

	/**
	 * Unconfirmed double opt-in entries whose confirmation period ended (SET-FC-10).
	 *
	 * @return int[]
	 */
	public function ids_unconfirmed_before( string $now, int $limit ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->entries()} WHERE confirmation_status = 'pending' AND unconfirmed_until_gmt IS NOT NULL AND unconfirmed_until_gmt <= %s ORDER BY id LIMIT %d", $now, $limit ) ) );
	}

	/** Stores the hash of a new double opt-in link token while the entry is still pending. */
	public function set_confirmation_token( int $id, string $hash ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->entries()} SET confirmation_token = %s WHERE id = %d AND confirmation_status = 'pending'", $hash, $id ) );
	}

	/**
	 * Constant-time check of a double opt-in link token hash against a pending entry, or against a
	 * pending or confirmed entry with $pending_only = false (the hash of the last link is kept after
	 * the confirmation so that only its holder learns "already confirmed", SET-FC-P4-05).
	 */
	public function confirmation_token_matches( int $id, string $hash, bool $pending_only = true ): bool {
		global $wpdb;
		$status = $pending_only ? "confirmation_status = 'pending'" : "confirmation_status IN ('pending','confirmed')";
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT confirmation_token FROM {$this->entries()} WHERE id = %d AND {$status}", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed condition.
		return is_string( $stored ) && 64 === strlen( $stored ) && hash_equals( $stored, $hash );
	}

	/**
	 * Marks a pending entry as confirmed exactly once (single use: the status changes, a second click
	 * finds no pending entry). The hash of the link stays for the "already confirmed" answer.
	 * Returns false when the entry was not pending (already confirmed, or concurrent click).
	 */
	public function mark_confirmed( int $id, string $when ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->entries()} SET confirmation_status = 'confirmed', confirmed_gmt = %s, unconfirmed_until_gmt = NULL, updated_gmt = %s WHERE id = %d AND confirmation_status = 'pending'", $when, $when, $id ) );
	}

	/** @return int[] trash/spam older than cutoff */
	public function ids_trashed_before( string $cutoff, int $limit ): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->entries()} WHERE status IN ('trash','spam') AND trashed_gmt IS NOT NULL AND trashed_gmt <= %s ORDER BY id LIMIT %d", $cutoff, $limit ) ) );
	}

	/**
	 * Active (not trash/spam/anonymized) entries whose expiry falls in [now, until].
	 *
	 * @return array{count:int,earliest:string|null}
	 */
	public function expiring_summary( string $now, string $until, int $form_id = 0 ): array {
		global $wpdb;
		$sql = "SELECT COUNT(*) c, MIN(expires_gmt) earliest FROM {$this->entries()} WHERE expires_gmt IS NOT NULL AND expires_gmt >= %s AND expires_gmt <= %s AND status NOT IN ('trash','spam')";
		$row = $form_id
			? $wpdb->get_row( $wpdb->prepare( $sql . ' AND form_id = %d', $now, $until, $form_id ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( $sql, $now, $until ), ARRAY_A );
		return array(
			'count'    => (int) ( $row['c'] ?? 0 ),
			'earliest' => ! empty( $row['earliest'] ) ? (string) $row['earliest'] : null,
		);
	}

	/** @return int[] active entry IDs expiring on or before $until (for the "expiring soon" filter). */
	public function ids_expiring_before( string $until, int $form_id = 0, int $limit = 1000 ): array {
		global $wpdb;
		$sql = "SELECT id FROM {$this->entries()} WHERE expires_gmt IS NOT NULL AND expires_gmt <= %s AND status NOT IN ('trash','spam')";
		return array_map(
			'intval',
			$form_id
				? $wpdb->get_col( $wpdb->prepare( $sql . ' AND form_id = %d ORDER BY id LIMIT %d', $until, $form_id, $limit ) )
				: $wpdb->get_col( $wpdb->prepare( $sql . ' ORDER BY id LIMIT %d', $until, $limit ) )
		);
	}

	/** Sets expires_gmt without touching updated_gmt semantics of the submission. */
	public function set_expires( int $id, ?string $expires_gmt ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->entries(), array( 'expires_gmt' => $expires_gmt ), array( 'id' => $id ) );
	}

	/** Deletes fc_files rows referencing an entry (physical files are removed via filter). */
	public function delete_file_rows( int $entry_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( Tables::name( Tables::FILES ), array( 'entry_id' => $entry_id ) );
	}

	/** @return array<string,mixed>|null */
	public function find_note( int $note_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->notes()} WHERE id = %d", $note_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Privacy lookup (datenschutz.md 6.2): entries whose lookup e-mail fields equal $email
	 * or whose user_id is $user_id. Anonymized entries are excluded. Stable order id ASC.
	 *
	 * @param string[] $field_ids
	 * @return int[]
	 */
	public function ids_for_privacy( array $field_ids, string $email, int $user_id, int $limit, int $offset = 0 ): array {
		global $wpdb;
		$parts  = array();
		$params = array();
		if ( $field_ids && '' !== $email ) {
			$in      = implode( ',', array_fill( 0, count( $field_ids ), '%s' ) );
			$parts[] = "SELECT v.entry_id AS id FROM {$this->values()} v WHERE v.field_id IN ({$in}) AND v.value = %s";
			$params  = array_merge( $params, array_values( $field_ids ), array( $email ) );
		}
		if ( $user_id > 0 ) {
			$parts[]  = "SELECT e2.id AS id FROM {$this->entries()} e2 WHERE e2.user_id = %d";
			$params[] = $user_id;
		}
		if ( ! $parts ) {
			return array();
		}
		$params[] = $limit;
		$params[] = $offset;
		$sql      = "SELECT DISTINCT e.id FROM {$this->entries()} e INNER JOIN (" . implode( ' UNION ', $parts ) . ') m ON m.id = e.id WHERE e.anonymized_gmt IS NULL ORDER BY e.id ASC LIMIT %d OFFSET %d';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Records a consent withdrawal once (atomic). Returns true only for the call that set the marker.
	 */
	public function mark_consent_withdrawn( int $id, string $when ): bool {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$this->entries()} SET consent_withdrawn_gmt = %s, updated_gmt = %s WHERE id = %d AND consent_withdrawn_gmt IS NULL", $when, Clock::now(), $id ) );
		return 1 === (int) $affected;
	}

	/** Deletes all notes of an entry (anonymization). */
	public function delete_notes( int $entry_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( $this->notes(), array( 'entry_id' => $entry_id ) );
	}

	/**
	 * Count and oldest creation date of entries (any status), optionally limited to forms.
	 *
	 * @param int[]|null $form_ids null = all forms
	 * @return array{count:int,oldest_created:string|null}
	 */
	public function count_for_forms( ?array $form_ids = null ): array {
		global $wpdb;
		$sql = "SELECT COUNT(*) c, MIN(created_gmt) oldest FROM {$this->entries()} WHERE anonymized_gmt IS NULL";
		if ( null !== $form_ids ) {
			$form_ids = array_values( array_filter( array_map( 'intval', $form_ids ) ) );
			if ( ! $form_ids ) {
				return array( 'count' => 0, 'oldest_created' => null );
			}
			$sql = $wpdb->prepare( $sql . ' AND form_id IN (' . implode( ',', array_fill( 0, count( $form_ids ), '%d' ) ) . ')', $form_ids );
		}
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return array(
			'count'          => (int) ( $row['c'] ?? 0 ),
			'oldest_created' => ! empty( $row['oldest'] ) ? (string) $row['oldest'] : null,
		);
	}

	/** Entries of a form that count towards its maximum (SET-FC-18): every status except spam and trash. */
	public function count_accepted( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->entries()} WHERE form_id = %d AND status NOT IN ('trash','spam')", $form_id ) );
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
		// The double opt-in token hash never leaves the repository (see confirmation_token_matches()).
		unset( $row['context_json'], $row['consent_json'], $row['confirmation_token'] );
		return $row;
	}
}
