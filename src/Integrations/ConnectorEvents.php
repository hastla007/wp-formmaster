<?php
/**
 * Connector jobs for entry events after `entry_created` (SET-FC-40, contract 1.4.0).
 *
 * Listens to the public events `form_captain_entry_status_changed_v1`,
 * `form_captain_consent_withdrawn_v1` and `form_captain_entry_deleted_v1` and queues one
 * `connector.action` job per enabled action instance of the form whose definition lists the
 * event in `events` (register_action). Nothing is queued for an action that does not subscribe.
 *
 * - `entry_status_changed`: like `entry_created` – the job keeps the entry reference, the worker
 *   re-reads the entry and builds the mapped values; the job is cancelled with the entry
 *   (deletion, anonymization, consent withdrawal).
 * - `consent_withdrawn` and `entry_deleted`: IDs only (entry id, form id, public form id,
 *   reference number, timestamps, reason/source) – never field values, never the entry context.
 *   The job has no entry reference in its row, so deleting or anonymizing the entry right after
 *   the withdrawal does not cancel it. The withdrawal event is the documented exception to the
 *   rule "no jobs after a consent withdrawal" (docs/spec/api-v1.md); after a withdrawal no
 *   other event (status, deletion) creates a job.
 *
 * Only entries that connectors could have received create jobs: not spam, not awaiting the
 * double opt-in confirmation, not imported. Deletions of a whole form (`form_deleted`) and of
 * an import run (`import_reverted`) create no jobs.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Integrations;

use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\DoubleOptIn;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;

final class ConnectorEvents {

	/** Deletion reasons that create no job (configuration gone / entries never announced). */
	private const SILENT_DELETE_REASONS = array( 'form_deleted', 'import_reverted' );

	/** @var array<int,array<string,mixed>> entry rows remembered right before deletion/anonymization */
	private static array $snapshots = array();

	public function __construct(
		private ConnectorRegistry $registry,
		private EntryRepository $entries,
		private FormRepository $forms,
		private JobRepository $jobs
	) {}

	public function register(): void {
		add_action( 'form_captain_entry_status_changed_v1', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'form_captain_consent_withdrawn_v1', array( $this, 'on_consent_withdrawn' ), 20, 4 );
		add_action( 'form_captain_entry_deleted_v1', array( $this, 'on_entry_deleted' ), 20, 3 );
	}

	/**
	 * Remembers the entry row before it is deleted or anonymized, so the deletion event can
	 * carry the reference number and creation time and be checked like the other events.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function remember( array $entry ): void {
		if ( isset( $entry['id'] ) ) {
			self::$snapshots[ (int) $entry['id'] ] = $entry;
		}
	}

	public function on_status_changed( int $entry_id, string $old, string $new, int $form_id = 0 ): void {
		if ( 'spam' === $old || 'spam' === $new ) {
			return;
		}
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry || ! self::announced( $entry ) || ! empty( $entry['anonymized_gmt'] ) || ! empty( $entry['consent_withdrawn_gmt'] ) ) {
			return;
		}
		$form = $this->forms->find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return;
		}
		$data  = self::base_data( 'entry_status_changed', $entry, $form ) + array(
			'old_status'  => $old,
			'new_status'  => $new,
			'changed_gmt' => self::iso( Clock::now() ),
		);
		$stamp = (string) (int) round( microtime( true ) * 1000000 );
		$this->queue( $form, $entry_id, 'entry_status_changed', $data, $old . '-' . $new . ':' . $stamp, true );
	}

	/**
	 * @param array<string,mixed> $event payload of form_captain_consent_withdrawn_v1
	 */
	public function on_consent_withdrawn( int $entry_id, int $form_id, string $source = 'admin', array $event = array() ): void {
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry || ! self::announced( $entry ) ) {
			return;
		}
		$form = $this->forms->find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return;
		}
		$data = self::base_data( 'consent_withdrawn', $entry, $form ) + array(
			'withdrawn_gmt' => (string) ( $event['withdrawn_gmt'] ?? self::iso( (string) ( $entry['consent_withdrawn_gmt'] ?? Clock::now() ) ) ),
			'source'        => $source,
			'then'          => (string) ( $event['then'] ?? 'keep' ),
		);
		$this->queue( $form, $entry_id, 'consent_withdrawn', $data, '', false );
	}

	public function on_entry_deleted( int $entry_id, int $form_id, string $reason = 'manual' ): void {
		$entry = self::$snapshots[ $entry_id ] ?? null;
		unset( self::$snapshots[ $entry_id ] );
		if ( null === $entry ) {
			$entry = $this->entries->find( $entry_id );
		}
		if ( ! $entry || in_array( $reason, self::SILENT_DELETE_REASONS, true ) ) {
			return;
		}
		// After a withdrawal only the withdrawal event itself is sent; an anonymized entry was announced already.
		if ( ! self::announced( $entry ) || ! empty( $entry['consent_withdrawn_gmt'] ) || ! empty( $entry['anonymized_gmt'] ) ) {
			return;
		}
		$form = $this->forms->find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return;
		}
		$data = self::base_data( 'entry_deleted', $entry, $form ) + array(
			'deleted_gmt' => self::iso( Clock::now() ),
			'reason'      => $reason,
		);
		$this->queue( $form, $entry_id, 'entry_deleted', $data, '', false );
	}

	/**
	 * Whether connectors could have received the entry: not spam, not awaiting the double opt-in
	 * confirmation, not imported (imported entries never create connector jobs).
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function announced( array $entry ): bool {
		$status = (string) ( $entry['status'] ?? '' );
		if ( 'spam' === $status || ( 'trash' === $status && 'spam' === ( $entry['previous_status'] ?? '' ) ) ) {
			return false;
		}
		if ( DoubleOptIn::STATUS_PENDING === ( $entry['confirmation_status'] ?? null ) ) {
			return false;
		}
		$context = is_array( $entry['context'] ?? null ) ? $entry['context'] : array();
		return ! isset( $context['import'] );
	}

	/**
	 * IDs and times shared by every event payload – never values.
	 *
	 * @param array<string,mixed> $entry
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	public static function base_data( string $event, array $entry, array $form ): array {
		$number = (string) ( $entry['entry_number'] ?? '' );
		return array(
			'event'          => $event,
			'contract'       => FORM_CAPTAIN_CONTRACT_VERSION,
			'entry_id'       => (int) $entry['id'],
			'form_id'        => (int) $entry['form_id'],
			'form_public_id' => (string) ( $form['public_id'] ?? '' ),
			'entry_number'   => '' !== $number ? $number : null,
			'created_gmt'    => self::iso( (string) ( $entry['created_gmt'] ?? '' ) ),
		);
	}

	/** `2026-11-23 10:00:00` → `2026-11-23T10:00:00Z` ('' stays ''). */
	public static function iso( string $mysql ): string {
		return '' === $mysql ? '' : str_replace( ' ', 'T', $mysql ) . 'Z';
	}

	/**
	 * Queues one job per subscribed, enabled action instance of the form.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $data
	 * @param bool                $keep_entry true: job row references the entry (cancelled with it)
	 */
	private function queue( array $form, int $entry_id, string $event, array $data, string $discriminator, bool $keep_entry ): void {
		foreach ( $this->subscribed( $form, $event ) as $instance => $key ) {
			$idempotency = sprintf( 'form-captain:%d:%d:%s:', get_current_blog_id(), $entry_id, $event ) . ( '' !== $discriminator ? $discriminator . ':' : '' ) . $key . ':' . $instance;
			$payload     = array(
				'action_key'  => $key,
				'instance_id' => $instance,
				'event'       => $event,
				'entry_id'    => $entry_id,
				'data'        => $data,
			);
			$id          = $this->jobs->enqueue( ConnectorRegistry::JOB_TYPE, $idempotency, $payload, $keep_entry ? $entry_id : null, (int) $form['id'] );
			if ( $id <= 0 ) {
				Logger::log( 'error', 'connector_event_enqueue_failed', $event . ' ' . $key, array( 'entry_id' => $entry_id, 'form_id' => (int) $form['id'] ) );
			}
		}
	}

	/**
	 * Enabled action instances of the form whose registered definition lists the event.
	 * Targets of an enabled hand-over wait for manual confirmation and get no event jobs.
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,string> instance_id => action_key
	 */
	private function subscribed( array $form, string $event ): array {
		$actions  = is_array( $form['settings']['actions'] ?? null ) ? $form['settings']['actions'] : array();
		$awaiting = CoreActions::handoff_targets( $actions );
		$out      = array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['enabled'] ) ) {
				continue;
			}
			$key      = (string) ( $action['action_key'] ?? '' );
			$instance = (string) ( $action['instance_id'] ?? '' );
			if ( isset( $awaiting[ $instance ] ) || ! preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $key ) || ! preg_match( '/^a_[0-9a-f]{10}$/', $instance ) ) {
				continue;
			}
			$definition = $this->registry->action( $key );
			if ( null !== $definition && in_array( $event, (array) $definition['events'], true ) ) {
				$out[ $instance ] = $key;
			}
		}
		return $out;
	}
}
