<?php
/**
 * Entry status, notes, audit and bulk actions (P4-06, P4-10, docs/spec/zustaende.md).
 *
 * Public methods check the current user's capabilities (capability check first, then
 * existence – T-IDOR-01). `transition()` is the capability-free core used by services
 * and system jobs.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Logger;

final class EntryService {

	/** Allowed manual transitions (trash/restore are handled separately). */
	private const TRANSITIONS = array(
		'new'      => array( 'read', 'archived', 'spam' ),
		'read'     => array( 'archived', 'spam' ),
		'archived' => array( 'read', 'spam' ),
		'spam'     => array( 'read' ),
	);

	public const BULK_ACTIONS = array( 'read', 'archive', 'unarchive', 'spam', 'not_spam', 'trash', 'restore', 'delete' );

	public const MAX_BULK = 500;

	public function __construct( private EntryRepository $entries, private JobRepository $jobs ) {}

	public static function can_transition( string $from, string $to ): bool {
		if ( 'trash' === $to ) {
			return 'trash' !== $from;
		}
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/** Capability required to move an entry into $to. */
	public static function capability_for( string $to ): string {
		return 'trash' === $to ? Capabilities::DELETE_ENTRIES : Capabilities::EDIT_ENTRIES;
	}

	/**
	 * Capability-checked status change by the current user.
	 */
	public function set_status( int $entry_id, string $status ): true|\WP_Error {
		$cap = self::capability_for( $status );
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( $cap ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to change this submission.', 'form-captain' ) );
		}
		return $this->transition( $entry_id, $status, 'manual' );
	}

	/**
	 * Auto-read when the detail view is opened (only `new` → `read`, needs read_entries only).
	 */
	public function mark_read( int $entry_id ): bool {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) ) {
			return false;
		}
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry || 'new' !== $entry['status'] ) {
			return false;
		}
		return true === $this->transition( $entry_id, 'read', 'auto' );
	}

	/**
	 * Capability-free transition with validation, audit note and event (after the update).
	 */
	public function transition( int $entry_id, string $status, string $source = 'system' ): true|\WP_Error {
		if ( ! in_array( $status, EntryRepository::STATUSES, true ) ) {
			return new \WP_Error( 'invalid_request', __( 'Unknown status.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND, __( 'Submission not found.', 'form-captain' ) );
		}
		$old = (string) $entry['status'];
		if ( ! self::can_transition( $old, $status ) ) {
			return self::conflict( __( 'This status change is not allowed.', 'form-captain' ) );
		}
		if ( null === $this->entries->set_status( $entry_id, $status ) ) {
			return self::conflict( __( 'The status could not be changed.', 'form-captain' ) );
		}
		$this->audit( $entry_id, 'status_' . $old . '_' . $status . ( 'auto' === $source ? '_auto' : '' ) );
		do_action( 'form_captain_entry_status_changed_v1', $entry_id, $old, $status, (int) $entry['form_id'] );
		return true;
	}

	/** Restores a trashed entry to its previous status within the trash period. */
	public function restore_entry( int $entry_id ): true|\WP_Error {
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND, __( 'Submission not found.', 'form-captain' ) );
		}
		if ( 'trash' !== $entry['status'] ) {
			return self::conflict( __( 'Only submissions in the trash can be restored.', 'form-captain' ) );
		}
		$days = max( 1, (int) Settings::get( 'privacy.trash_days' ) );
		if ( ! empty( $entry['trashed_gmt'] ) && strtotime( $entry['trashed_gmt'] . ' UTC' ) < Clock::timestamp() - $days * DAY_IN_SECONDS ) {
			return self::conflict( __( 'The restore period for this submission has expired.', 'form-captain' ) );
		}
		$target = (string) ( $entry['previous_status'] ?? '' );
		if ( ! in_array( $target, array( 'new', 'read', 'archived', 'spam' ), true ) ) {
			$target = 'read';
		}
		$this->entries->set_status( $entry_id, $target );
		$this->audit( $entry_id, 'restored_' . $target );
		do_action( 'form_captain_entry_status_changed_v1', $entry_id, 'trash', $target, (int) $entry['form_id'] );
		return true;
	}

	/** Capability-checked restore. */
	public function restore( int $entry_id ): true|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to restore submissions.', 'form-captain' ) );
		}
		return $this->restore_entry( $entry_id );
	}

	/** Capability-checked permanent deletion. */
	public function delete( int $entry_id ): true|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to delete submissions.', 'form-captain' ) );
		}
		if ( ! $this->entries->find( $entry_id ) ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND, __( 'Submission not found.', 'form-captain' ) );
		}
		return Plugin::instance()->get( EntryDeletionService::class )->delete( $entry_id, 'manual' )
			? true
			: Errors::error( Errors::INTERNAL_ERROR );
	}

	/**
	 * Adds an internal plain-text note. Never touches submitted values (P4-10).
	 */
	public function add_note( int $entry_id, string $message ): int|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to add notes.', 'form-captain' ) );
		}
		if ( ! $this->entries->find( $entry_id ) ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND, __( 'Submission not found.', 'form-captain' ) );
		}
		$message = trim( sanitize_textarea_field( $message ) );
		if ( '' === $message || mb_strlen( $message ) > 5000 ) {
			return Errors::error( Errors::VALIDATION_FAILED, __( 'Notes must contain between 1 and 5000 characters.', 'form-captain' ) );
		}
		return $this->entries->add_note( $entry_id, get_current_user_id(), $message, 'note' );
	}

	/**
	 * Notes (incl. audit records) visible to users with read_entries.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function notes( int $entry_id ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN );
		}
		if ( ! $this->entries->find( $entry_id ) ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND );
		}
		return $this->entries->notes_for( $entry_id );
	}

	/** Audit record: action code and acting user only, never field values. */
	public function audit( int $entry_id, string $code ): void {
		$code = substr( (string) preg_replace( '/[^a-z0-9_.:\-]/', '', strtolower( $code ) ), 0, 60 );
		$this->entries->add_note( $entry_id, get_current_user_id(), $code, 'audit' );
	}

	/**
	 * Manual retry of a job attached to an entry (OP-ENT-11 / OP-INT-06).
	 */
	public function retry_job( int $entry_id, int $job_id ): true|\WP_Error {
		$job = $this->jobs->find( $job_id );
		$cap = $job && str_starts_with( (string) $job['type'], 'connector.' ) ? Capabilities::MANAGE_INTEGRATIONS : Capabilities::EDIT_ENTRIES;
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( $cap ) ) {
			return Errors::error( Errors::FORBIDDEN );
		}
		if ( ! $this->entries->find( $entry_id ) ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND );
		}
		if ( ! $job || (int) $job['entry_id'] !== $entry_id ) {
			return Errors::error( Errors::JOB_NOT_FOUND, __( 'Job not found.', 'form-captain' ) );
		}
		if ( ! str_starts_with( (string) $job['type'], 'mail.' ) && ! str_starts_with( (string) $job['type'], 'connector.' ) ) {
			return new \WP_Error( 'job_not_retryable', __( 'This job cannot be retried here.', 'form-captain' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $job['status'], array( 'failed', 'dead' ), true ) || ! $this->jobs->retry( $job_id ) ) {
			return new \WP_Error( 'job_not_retryable', __( 'Only failed jobs can be retried.', 'form-captain' ), array( 'status' => 409 ) );
		}
		$this->audit( $entry_id, 'job_retry_' . str_replace( '.', '_', (string) $job['type'] ) );
		Logger::log( 'info', 'job_manual_retry', 'user ' . get_current_user_id(), array( 'job_id' => $job_id, 'entry_id' => $entry_id ) );
		return true;
	}

	/**
	 * Queues the admin notification again (e.g. after "not spam"). Reuses a failed job when present.
	 */
	public function resend_notification( int $entry_id ): true|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN );
		}
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND );
		}
		if ( in_array( $entry['status'], array( 'spam', 'trash' ), true ) ) {
			return self::conflict( __( 'Notifications are not sent for spam or trashed submissions.', 'form-captain' ) );
		}
		foreach ( $this->jobs->for_entry( $entry_id ) as $job ) {
			if ( 'mail.admin' === $job['type'] && in_array( $job['status'], array( 'pending', 'running' ), true ) ) {
				return true; // Already queued – dedupe.
			}
		}
		$key = sprintf( 'form-captain:%d:%d:mail.admin:manual:%d', get_current_blog_id(), $entry_id, Clock::timestamp() );
		$this->jobs->enqueue( 'mail.admin', $key, array( 'notification' => 'admin', 'manual' => true ), $entry_id, (int) $entry['form_id'] );
		$this->audit( $entry_id, 'notification_queued' );
		return true;
	}

	/**
	 * Applies a bulk action; every ID is checked individually (T-IDOR-05).
	 *
	 * @param int[] $ids
	 * @return array{done:int[],skipped:array<int,array{id:int,code:string}>}|\WP_Error
	 */
	public function bulk( string $action, array $ids ): array|\WP_Error {
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return new \WP_Error( 'invalid_request', __( 'Unknown bulk action.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids || count( $ids ) > self::MAX_BULK ) {
			return new \WP_Error( 'invalid_request', __( 'Select between 1 and 500 submissions.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$out = array(
			'done'    => array(),
			'skipped' => array(),
		);
		foreach ( $ids as $id ) {
			$result = match ( $action ) {
				'read', 'unarchive', 'not_spam' => $this->set_status( $id, 'read' ),
				'archive' => $this->set_status( $id, 'archived' ),
				'spam' => $this->set_status( $id, 'spam' ),
				'trash' => $this->set_status( $id, 'trash' ),
				'restore' => $this->restore( $id ),
				'delete' => $this->delete( $id ),
			};
			if ( true === $result ) {
				$out['done'][] = $id;
			} else {
				$out['skipped'][] = array(
					'id'   => $id,
					'code' => $result->get_error_code(),
				);
			}
		}
		return $out;
	}

	private static function conflict( string $message ): \WP_Error {
		return new \WP_Error( 'conflict', $message, array( 'status' => 409 ) );
	}
}
