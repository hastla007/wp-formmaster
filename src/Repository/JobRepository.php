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

	public const STATUSES = array( 'pending', 'running', 'succeeded', 'failed', 'dead', 'cancelled', 'paused' );

	/** Job type whose jobs can be paused per connector (ConnectorRegistry::JOB_TYPE). */
	public const CONNECTOR_JOB_TYPE = 'connector.action';

	/** Error code recorded on jobs paused because their connector is disabled. */
	public const PAUSE_CODE = 'connector_disabled';

	private const ACTION_KEY_PATTERN = '/^[a-z0-9_]+\.[a-z0-9_]+$/';

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

	/**
	 * Puts a claimed (running) job back to `paused` without counting the attempt (the claim's
	 * increment is undone). Used when the connector was disabled while the job was claimed.
	 */
	public function pause_claimed( int $id, string $token, string $code = self::PAUSE_CODE ): bool {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'paused', attempts = GREATEST(attempts - 1, 0), lock_token = NULL, locked_until_gmt = NULL, last_error_code = %s, last_error_message = NULL, updated_gmt = %s WHERE id = %d AND lock_token = %s AND status = 'running'",
				mb_substr( $code, 0, 60 ),
				Clock::now(),
				$id,
				$token
			)
		);
		return 1 === (int) $affected;
	}

	/**
	 * Pauses the open (pending/failed) `connector.action` jobs of one connector. Attempts stay
	 * unchanged, the lock is released. Paused jobs are never claimed by the worker and never expire.
	 *
	 * @param string[]|null $action_keys action keys of this connector; null = resolve via the registry
	 * @return int number of paused jobs
	 */
	public function pause_for_connector( string $connector_key, ?array $action_keys = null ): int {
		global $wpdb;
		$ids = $this->connector_job_ids( $connector_key, $action_keys, array( 'pending', 'failed' ) );
		$n   = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			$n += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table()} SET status = 'paused', lock_token = NULL, locked_until_gmt = NULL, last_error_code = %s, last_error_message = NULL, updated_gmt = %s WHERE id IN ({$in}) AND status IN ('pending','failed')",
					self::PAUSE_CODE,
					Clock::now()
				)
			);
		}
		return $n;
	}

	/**
	 * Resumes the paused `connector.action` jobs of one connector: paused → pending, due now,
	 * attempts unchanged.
	 *
	 * @param string[]|null $action_keys action keys of this connector; null = resolve via the registry
	 * @return int number of resumed jobs
	 */
	public function resume_for_connector( string $connector_key, ?array $action_keys = null ): int {
		global $wpdb;
		$ids = $this->connector_job_ids( $connector_key, $action_keys, array( 'paused' ) );
		$n   = 0;
		$now = Clock::now();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			$n += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table()} SET status = 'pending', run_after_gmt = %s, lock_token = NULL, locked_until_gmt = NULL, last_error_code = NULL, last_error_message = NULL, updated_gmt = %s WHERE id IN ({$in}) AND status = 'paused'",
					$now,
					$now
				)
			);
		}
		return $n;
	}

	/**
	 * IDs of `connector.action` jobs in $statuses whose payload.action_key is one of the connector's
	 * action keys. Pre-filtered with an escaped LIKE on payload_json, then verified exactly on the
	 * decoded payload (no false positives through similar keys or JSON formatting).
	 *
	 * @param string[]|null $action_keys
	 * @param string[]      $statuses
	 * @return int[]
	 */
	private function connector_job_ids( string $connector_key, ?array $action_keys, array $statuses ): array {
		global $wpdb;
		if ( null === $action_keys ) {
			$action_keys = self::registry_action_keys( $connector_key );
		}
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $action_keys ), static fn( string $k ): bool => (bool) preg_match( self::ACTION_KEY_PATTERN, $k ) ) ) );
		if ( ! $keys ) {
			return array();
		}
		$statuses = array_values( array_intersect( $statuses, self::STATUSES ) );
		if ( ! $statuses ) {
			return array();
		}
		$like   = array();
		$params = array( self::CONNECTOR_JOB_TYPE );
		foreach ( $keys as $k ) {
			$like[]   = 'payload_json LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $k ) . '%';
		}
		$status_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params    = array_merge( $params, $statuses );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload_json FROM {$this->table()} WHERE type = %s AND (" . implode( ' OR ', $like ) . ") AND status IN ({$status_in})", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids       = array();
		foreach ( $rows ?: array() as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			if ( is_array( $payload ) && in_array( (string) ( $payload['action_key'] ?? '' ), $keys, true ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}

	/** @return string[] action keys the registry currently attributes to $connector_key */
	private static function registry_action_keys( string $connector_key ): array {
		$registry = \FormCaptain\Plugin::instance()->get( \FormCaptain\Integrations\ConnectorRegistry::class );
		return $registry->action_keys_for( $connector_key );
	}

	/**
	 * Paused jobs grouped by payload.action_key (for status pages).
	 *
	 * @return array<string,int>
	 */
	public function paused_by_action(): array {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT payload_json FROM {$this->table()} WHERE type = %s AND status = 'paused' LIMIT 10000", self::CONNECTOR_JOB_TYPE ) ) ?: array() as $json ) {
			$payload = json_decode( (string) $json, true );
			$key     = is_array( $payload ) ? (string) ( $payload['action_key'] ?? '' ) : '';
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
		}
		ksort( $out );
		return $out;
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

	/**
	 * Cancels all open jobs of an entry, paused ones included (P4-18). Provider error texts of all its jobs are dropped as
	 * well: they may quote personal data and must not outlive the entry (SR-08).
	 * With $type_prefixes only jobs of those types are cancelled (e.g. consent withdrawal: mail./connector.).
	 *
	 * @param string[] $type_prefixes
	 */
	public function cancel_for_entry( int $entry_id, string $reason = 'entry_deleted', array $type_prefixes = array() ): int {
		global $wpdb;
		$types  = '';
		$params = array( $reason, Clock::now(), Clock::now(), $entry_id );
		if ( $type_prefixes ) {
			$like = array();
			foreach ( $type_prefixes as $prefix ) {
				$like[]   = 'type LIKE %s';
				$params[] = $wpdb->esc_like( (string) $prefix ) . '%';
			}
			$types = ' AND (' . implode( ' OR ', $like ) . ')';
		}
		$cancelled = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'cancelled', last_error_code = %s, lock_token = NULL, locked_until_gmt = NULL, finished_gmt = %s, updated_gmt = %s WHERE entry_id = %d AND status IN ('pending','failed','running','paused'){$types}", $params ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET last_error_message = NULL WHERE entry_id = %d AND last_error_message IS NOT NULL", $entry_id ) );
		return $cancelled;
	}

	/** Cancels open jobs of a given type prefix (e.g. connector deactivated). */
	public function cancel_by_type_prefix( string $prefix, string $reason ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'cancelled', last_error_code = %s, finished_gmt = %s, updated_gmt = %s WHERE type LIKE %s AND status IN ('pending','failed','paused')", $reason, Clock::now(), Clock::now(), $wpdb->esc_like( $prefix ) . '%' ) );
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

	/** Cancels a single open, paused or dead job (admin action). */
	public function cancel( int $id, string $reason = 'cancelled_by_admin' ): bool {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'cancelled', last_error_code = %s, lock_token = NULL, locked_until_gmt = NULL, finished_gmt = %s, updated_gmt = %s WHERE id = %d AND status IN ('pending','failed','dead','paused')", mb_substr( $reason, 0, 60 ), Clock::now(), Clock::now(), $id ) );
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

	/** Deletes old finished jobs. Paused jobs are open, not finished, and are never purged. */
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
