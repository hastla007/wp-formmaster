<?php
/**
 * Structured consent withdrawal (product decision 4, docs/spec/datenschutz.md 6.6).
 *
 * Order: marker `consent_withdrawn_gmt` → open mail/connector jobs cancelled (code
 * `consent_withdrawn`) → audit note (code only) → event `form_captain_consent_withdrawn_v1`
 * → optional follow-up (anonymize via PrivacyIntegration, delete via EntryDeletionService).
 *
 * `withdraw()` is capability-free (callers check permissions); `withdraw_as_current_user()`
 * checks them. Idempotent: a second call neither moves the marker nor fires the event again.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Install\Capabilities;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Logger;

final class ConsentService {

	public const SOURCES = array( 'admin', 'api', 'cli', 'privacy_request', 'system' );
	public const THEN    = array( 'keep', 'anonymize', 'delete' );
	public const REASON  = 'consent_withdrawn';
	public const EVENT   = 'form_captain_consent_withdrawn_v1';

	public function __construct(
		private EntryRepository $entries,
		private FormRepository $forms,
		private JobRepository $jobs,
		private EntryService $service,
		private EntryDeletionService $deletion,
		private PrivacyIntegration $privacy
	) {}

	/**
	 * Capability-checked withdrawal by the current user.
	 *
	 * @return array{entry_id:int,withdrawn:bool,withdrawn_gmt:string,then:string,result:string}|\WP_Error
	 */
	public function withdraw_as_current_user( int $entry_id, string $then = 'keep', string $source = 'admin' ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to record a consent withdrawal.', 'form-captain' ) );
		}
		if ( 'delete' === $then && ! current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to delete submissions.', 'form-captain' ) );
		}
		return $this->withdraw( $entry_id, $source, $then );
	}

	/**
	 * Records the withdrawal (capability-free core).
	 *
	 * @param string $then keep|anonymize|delete
	 * @return array{entry_id:int,withdrawn:bool,withdrawn_gmt:string,then:string,result:string}|\WP_Error
	 *         `withdrawn` is true only for the call that recorded the withdrawal.
	 */
	public function withdraw( int $entry_id, string $source = 'admin', string $then = 'keep' ): array|\WP_Error {
		if ( ! in_array( $then, self::THEN, true ) ) {
			return new \WP_Error( Errors::INVALID_REQUEST, __( 'Unknown follow-up action.', 'form-captain' ), array( 'status' => 400 ) );
		}
		$source = in_array( $source, self::SOURCES, true ) ? $source : 'admin';
		$entry  = $this->entries->find( $entry_id );
		if ( ! $entry ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND, __( 'Submission not found.', 'form-captain' ) );
		}
		$form_id = (int) $entry['form_id'];
		$now     = Clock::now();
		$new     = empty( $entry['consent_withdrawn_gmt'] ) && $this->entries->mark_consent_withdrawn( $entry_id, $now );
		$when    = $new ? $now : (string) ( $this->entries->find( $entry_id )['consent_withdrawn_gmt'] ?? $entry['consent_withdrawn_gmt'] ?? $now );

		if ( $new ) {
			// Mail and connector jobs only; scheduled privacy jobs (e.g. core.anonymize) stay.
			$cancelled = $this->jobs->cancel_for_entry( $entry_id, self::REASON, array( 'mail.', 'connector.' ) );
			$this->service->audit( $entry_id, 'consent_withdrawn_' . $source );
			Logger::log(
				'info',
				'consent_withdrawn',
				sprintf( 'source=%s then=%s cancelled=%d', $source, $then, $cancelled ),
				array( 'entry_id' => $entry_id, 'form_id' => $form_id )
			);
			do_action( 'form_captain_consent_withdrawn_v1', $entry_id, $form_id, $source, $this->event( $entry_id, $form_id, $when, $source, $then ) );
		}

		$result = 'kept';
		if ( 'anonymize' === $then ) {
			$current = $this->entries->find( $entry_id );
			if ( $current && empty( $current['anonymized_gmt'] ) ) {
				if ( ! $this->privacy->anonymize( $entry_id ) ) {
					return Errors::error( Errors::INTERNAL_ERROR, __( 'The submission could not be anonymized.', 'form-captain' ) );
				}
				// Anonymization removes all notes; keep a value-free trace of why.
				$this->service->audit( $entry_id, 'consent_withdrawn_anonymized' );
			}
			$result = 'anonymized';
		} elseif ( 'delete' === $then ) {
			if ( ! $this->deletion->delete( $entry_id, self::REASON ) ) {
				return Errors::error( Errors::INTERNAL_ERROR, __( 'The submission could not be deleted.', 'form-captain' ) );
			}
			$result = 'deleted';
		}

		return array(
			'entry_id'      => $entry_id,
			'withdrawn'     => $new,
			'withdrawn_gmt' => $when,
			'then'          => $then,
			'result'        => $result,
		);
	}

	/**
	 * Event payload (fixture tests/fixtures/contract/v1/consent-withdrawn.json). IDs, time, source – never values.
	 *
	 * @return array<string,mixed>
	 */
	public function event( int $entry_id, int $form_id, string $when, string $source, string $then ): array {
		$form = $this->forms->find( $form_id );
		return array(
			'contract'       => FORM_CAPTAIN_CONTRACT_VERSION,
			'entry_id'       => $entry_id,
			'form_id'        => $form_id,
			'form_public_id' => $form ? (string) $form['public_id'] : null,
			'withdrawn_gmt'  => str_replace( ' ', 'T', $when ) . 'Z',
			'source'         => $source,
			'then'           => $then,
		);
	}
}
