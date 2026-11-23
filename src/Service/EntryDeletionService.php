<?php
/**
 * Trash, restore and hard deletion of entries (P4-13, P4-18, datenmodell.md "Löschsemantik").
 *
 * Capability-free service layer: callers (admin pages, privacy eraser, retention, form
 * deletion) check permissions themselves.
 *
 * Hard-delete order: open jobs → cancelled, files (filter + fc_files rows), values,
 * notes, entry row, then the event `form_captain_entry_deleted_v1`.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Integrations\ConnectorEvents;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;

final class EntryDeletionService {

	public const REASONS = array( 'manual', 'retention', 'erasure', 'anonymized', 'form_deleted', 'consent_withdrawn', 'import_reverted' );

	public function __construct(
		private EntryRepository $entries,
		private JobRepository $jobs,
		private EntryService $service
	) {}

	public function trash( int $entry_id ): true|\WP_Error {
		return $this->service->transition( $entry_id, 'trash', 'system' );
	}

	public function restore( int $entry_id ): true|\WP_Error {
		return $this->service->restore_entry( $entry_id );
	}

	/**
	 * Permanently deletes an entry. Returns false when it does not exist.
	 */
	public function delete( int $entry_id, string $reason = 'manual' ): bool {
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			$reason = 'manual';
		}
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry ) {
			return false;
		}
		$form_id = (int) $entry['form_id'];
		ConnectorEvents::remember( $entry ); // reference number etc. for the ID-only deletion event (SET-FC-40)

		// 1. Stop every pending/failed/running job before values disappear (P4-18).
		$running = 0;
		foreach ( $this->jobs->for_entry( $entry_id ) as $job ) {
			if ( 'running' === $job['status'] ) {
				++$running;
			}
		}
		$cancelled = $this->jobs->cancel_for_entry( $entry_id, 'entry_deleted' );
		if ( $cancelled > 0 ) {
			Logger::log(
				$running > 0 ? 'warning' : 'info',
				$running > 0 ? 'entry_deleted_jobs_running' : 'entry_deleted_jobs_cancelled',
				sprintf( 'cancelled=%d running=%d reason=%s', $cancelled, $running, $reason ),
				array( 'entry_id' => $entry_id, 'form_id' => $form_id )
			);
		}

		// 2. Files: extensions (Pro) remove physical files; the core removes the references.
		apply_filters( 'form_captain_delete_entry_files_v1', $entry_id );
		$this->entries->delete_file_rows( $entry_id );

		// 3. Values, notes, entry row.
		$this->entries->purge( $entry_id );
		if ( $this->entries->find( $entry_id ) ) {
			Logger::log( 'error', 'entry_purge_failed', $reason, array( 'entry_id' => $entry_id, 'form_id' => $form_id ) );
			return false;
		}

		// 4. Log (IDs only) and public event.
		Logger::log( 'info', 'entry_purged', $reason, array( 'entry_id' => $entry_id, 'form_id' => $form_id ) );
		do_action( 'form_captain_entry_deleted_v1', $entry_id, $form_id, $reason );
		return true;
	}

	/**
	 * Deletes all entries of a form in batches. Returns the number of deleted entries;
	 * call again until `remaining_for_form()` is 0 when the time budget was hit.
	 */
	public function delete_for_form( int $form_id, int $time_budget = 20 ): int {
		$deadline = microtime( true ) + max( 1, $time_budget );
		$deleted  = 0;
		$after    = 0;
		do {
			$batch = $this->entries->batch_after(
				$after,
				Limits::get( 'cleanup_batch' ),
				array(
					'form_id'    => $form_id,
					'any_status' => true,
				)
			);
			foreach ( $batch as $entry ) {
				$after = (int) $entry['id'];
				if ( $this->delete( (int) $entry['id'], 'form_deleted' ) ) {
					++$deleted;
				}
				if ( microtime( true ) > $deadline ) {
					return $deleted;
				}
			}
		} while ( $batch );
		return $deleted;
	}

	public function remaining_for_form( int $form_id ): int {
		return (int) $this->entries->list(
			array(
				'form_id'    => $form_id,
				'any_status' => true,
				'per_page'   => 1,
			)
		)['total'];
	}
}
