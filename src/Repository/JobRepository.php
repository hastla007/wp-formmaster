<?php
/**
 * Outbox/queue persistence (fc_jobs). See docs/adr/0004-queue.md.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Repository;

use FormCaptain\Database\Tables;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

final class JobRepository {

	public const STATUSES = array( 'pending', 'running', 'succeeded', 'failed', 'dead', 'cancelled' );

	private function table(): string {
		return Tables::name( Tables::JOBS );
	}

	/**
	 * Inserts a job unless the idempotency key exists. Returns job ID (existing or new).
	 * Payload must contain references/configuration only – never submitted field values.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function enqueue( string $type, string $idempotency_key, array $payload, ?int $entry_id = null, ?int $form_id = null, int $delay = 0, ?int $max_attempts = null ): int {
		global $wpdb;
		$existing = $this->find_by_key( $idempotency_key );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$now = Clock::now();
		$ok  = $wpdb->insert(
			$this->table(),
			array(
				'type'            => $type,
				'entry_id'        => $entry_id,
				'form_id'         => $form_id,
				'idempotency_key' => mb_substr( $idempotency_key, 0, 191 ),
				'payload_json'    => (string) wp_json_encode( $payload ),
				'status'          => 'pending',
				'attempts'        => 0,
				'max_attempts'    => $max_attempts ?? Limits::get( 'job_max_attempts' ),
				'run_after_gmt'   => Clock::plus( $delay ),
				'created_gmt'     => $now,
				'updated_gmt'     => $now,
			)
		);
		if ( ! $ok ) {
			$existing = $this->find_by_key( $idempotency_key );
			return $existing ? (int) $existing['id'] : 0;
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_key( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return int[] IDs of runnable jobs */
	public function runnable_ids( int $limit ): array {
		global $wpdb;
		$now = Clock::now();
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$this->table()} WHERE status IN ('pending','failed') AND run_after_gmt <= %s AND (locked_until_gmt IS NULL OR locked_until_gmt < %s) ORDER BY run_after_gmt ASC, id ASC LIMIT %d",
					$now,
					$now,
					$limit
				)
			)
		);
	}

	/** Atomic claim. Returns lock token or null if another worker won. */
	public function claim( int $id ): ?string {
		global $wpdb;
		$token    = bin2hex( random_bytes( 16 ) );
		$now      = Clock::now();
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'running', lock_token = %s, locked_until_gmt = %s, attempts = attempts + 1, updated_gmt = %s WHERE id = %d AND status IN ('pending','failed') AND run_after_gmt <= %s AND (locked_until_gmt IS NULL OR locked_until_gmt < %s)",
				$token,
				Clock::plus( Limits::get( 'job_lock_seconds' ) ),
				$now,
				$id,
				$now,
				$now
			)
		);
		return 1 === (int) $affected ? $token : null;
	}

	/**
	 * @param array<string,mixed>|null $result
	 */
	public function complete( int $id, string $token, ?array $result = null ): bool {
		return $this->finish( $id, $token, 'succeeded', null, null, $result );
	}

	/** Records a failure; schedules retry with backoff or moves to dead. */
	public function fail( int $id, string $token, string $code, string $message, bool $permanent = false ): string {
		$job = $this->find( $id );
		if ( ! $job ) {
			return 'missing';
		}
		$dead = $permanent || (int) $job['attempts'] >= (int) $job['max_attempts'];
		if ( $dead ) {
			$this->finish( $id, $token, 'dead', $code, $message );
			return 'dead';
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'failed', lock_token = NULL, locked_until_gmt = NULL, run_after_gmt = %s, last_error_code = %s, last_error_message = %s, updated_gmt = %s WHERE id = %d AND lock_token = %s",
				Clock::plus( self::backoff( (int) $job['attempts'] ) ),
				mb_substr( $code, 0, 60 ),
				Logger::redact( $message ),
				Clock::now(),
				$id,
				$token
			)
		);
		return 'failed';
	}

	public static function backoff( int $attempts ): int {
		$base   = (int) min( 2 ** max( 0, $attempts ) * 60, 6 * 3600 );
		$jitter = (int) round( $base * ( random_int( -10, 10 ) / 100 ) );
		return max( 30, $base + $jitter );
	}

	private function finish( int $id, string $token, string $status, ?string $code, ?string $message, ?array $result = null ): bool {
		global $wpdb;
		$now      = Clock::now();
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = %s, lock_token = NULL, locked_until_gmt = NULL, last_error_code = %s, last_error_message = %s, result_json = %s, finished_gmt = %s, updated_gmt = %s WHERE id = %d AND lock_token = %s",
				$status,
				$code,
				null !== $message ? Logger::redact( $message ) : null,
				null !== $result ? wp_json_encode( $result ) : null,
				$now,
				$now,
				$id,
				$token
			)
		);
		return 1 === (int) $affected;
	}

	/** Releases jobs whose lock expired (crashed worker) back to failed. */
	public function release_expired_locks(): int {
		global $wpdb;
		$now = Clock::now();
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'failed', lock_token = NULL, locked_until_gmt = NULL, last_error_code = 'lock_expired', updated_gmt = %s WHERE status = 'running' AND locked_until_gmt < %s", $now, $now ) );
	}

	/** Manual retry of a dead/failed job. */
	public function retry( int $id ): bool {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'pending', attempts = 0, run_after_gmt = %s, lock_token = NULL, locked_until_gmt = NULL, finished_gmt = NULL, updated_gmt = %s WHERE id = %d AND status IN ('dead','failed','cancelled')", Clock::now(), Clock::now(), $id ) );
		return 1 === (int) $affected;
	}

	/** Cancels all open jobs of an entry (P4-18). */
	public function cancel_for_entry( int $entry_id, string $reason = 'entry_deleted' ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'cancelled', last_error_code = %s, lock_token = NULL, locked_until_gmt = NULL, finished_gmt = %s, updated_gmt = %s WHERE entry_id = %d AND status IN ('pending','failed','running')", $reason, Clock::now(), Clock::now(), $entry_id ) );
	}

	/** Cancels open jobs of a given type prefix (e.g. connector deactivated). */
	public function cancel_by_type_prefix( string $prefix, string $reason ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'cancelled', last_error_code = %s, finished_gmt = %s, updated_gmt = %s WHERE type LIKE %s AND status IN ('pending','failed')", $reason, Clock::now(), Clock::now(), $wpdb->esc_like( $prefix ) . '%' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function for_entry( int $entry_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE entry_id = %d ORDER BY id ASC", $entry_id ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * @param array{status?:string,type_prefix?:string,per_page?:int,page?:int} $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( array $args = array() ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['type_prefix'] ) ) {
			$where[]  = 'type LIKE %s';
			$params[] = $wpdb->esc_like( (string) $args['type_prefix'] ) . '%';
		}
		$w        = implode( ' AND ', $where );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
		$total    = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$this->table()} WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( 'items' => array_map( array( $this, 'hydrate' ), $rows ?: array() ), 'total' => $total );
	}

	/** @return array<string,mixed> aggregated health for system status */
	public function health(): array {
		global $wpdb;
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$this->table()} GROUP BY status", ARRAY_A ) ?: array() as $r ) {
			$counts[ $r['status'] ] = (int) $r['c'];
		}
		$oldest = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(run_after_gmt) FROM {$this->table()} WHERE status IN ('pending','failed') AND run_after_gmt <= %s", Clock::now() ) );
		return array(
			'counts'           => $counts,
			'oldest_due_gmt'   => $oldest ?: null,
			'oldest_due_age_s' => $oldest ? max( 0, Clock::timestamp() - (int) strtotime( $oldest . ' UTC' ) ) : 0,
		);
	}

	/** Cancels a single open or dead job (admin action). */
	public function cancel( int $id, string $reason = 'cancelled_by_admin' ): bool {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'cancelled', last_error_code = %s, lock_token = NULL, locked_until_gmt = NULL, finished_gmt = %s, updated_gmt = %s WHERE id = %d AND status IN ('pending','failed','dead')", mb_substr( $reason, 0, 60 ), Clock::now(), Clock::now(), $id ) );
		return 1 === (int) $affected;
	}

	/**
	 * Aggregated error codes of failed/dead jobs updated since $since (no payloads, no messages).
	 *
	 * @return array<string,int> last_error_code => count
	 */
	public function error_summary( string $type_prefix, string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT last_error_code code, COUNT(*) c FROM {$this->table()} WHERE type LIKE %s AND status IN ('failed','dead') AND last_error_code IS NOT NULL AND updated_gmt >= %s GROUP BY last_error_code ORDER BY c DESC LIMIT 20", $wpdb->esc_like( $type_prefix ) . '%', $since ), ARRAY_A );
		$out  = array();
		foreach ( $rows ?: array() as $r ) {
			$out[ (string) $r['code'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** Deletes old finished jobs. */
	public function purge_finished(): int {
		global $wpdb;
		$a = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE status IN ('succeeded','cancelled') AND finished_gmt < %s LIMIT 5000", Clock::plus( -30 * 86400 ) ) );
		$b = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE status = 'dead' AND finished_gmt < %s LIMIT 5000", Clock::plus( -90 * 86400 ) ) );
		return $a + $b;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( array( 'id', 'attempts', 'max_attempts' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$row['entry_id'] = null !== $row['entry_id'] ? (int) $row['entry_id'] : null;
		$row['form_id']  = null !== $row['form_id'] ? (int) $row['form_id'] : null;
		$row['payload']  = (array) json_decode( (string) $row['payload_json'], true );
		$row['result']   = $row['result_json'] ? (array) json_decode( (string) $row['result_json'], true ) : null;
		unset( $row['payload_json'], $row['result_json'] );
		return $row;
	}
}
