<?php
/**
 * Central submission flow (P4-01, P4-02, P4-03, P4-16, P3-10, P3-15, P3-17, P8-13).
 *
 * Order: form status → token/idempotency → limits → rate limit → signed context → validation
 * → spam → transaction (entry, values, context nonce, outbox) → COMMIT → event + worker nudge.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Domain\SubmissionValidator;
use FormCaptain\Jobs\Scheduler;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Security\RateLimiter;
use FormCaptain\Security\SignedContext;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;

final class SubmissionService {

	public const CODE_CREATED   = 'created';
	public const CODE_DUPLICATE = 'duplicate_request';

	/** Top-level request keys that are never field values. */
	private const RESERVED = array( '_fc_form', '_fc_token', '_fc_ctx', '_fc_src', 'action', 'fields', 'fc' );

	public function __construct(
		private EntryRepository $entries,
		private FormSchemaLoader $loader,
		private OutboxPlanner $planner,
		private SubmissionToken $tokens,
		private RateLimiter $limiter,
		private SpamGuard $spam,
		private SignedContext $contexts,
		private SubmissionValidator $validator
	) {}

	/**
	 * @param array<string,mixed> $form form row
	 * @param array<string,mixed> $raw  request data: `fields` (field_id => value) plus `_fc_token`, `_fc_ctx`,
	 *                                  honeypot; or a flat field_id/key => value map for trusted callers.
	 * @param array<string,mixed> $meta trusted, plugin, idempotency_key, request_key, signed_context, ip,
	 *                                  source_url, source_post_id, user_id, request_id, body_bytes
	 * @return array<string,mixed> result object (api-v1.md)
	 */
	public function submit( array $form, array $raw, array $meta = array() ): array {
		$request_id = isset( $meta['request_id'] ) && is_string( $meta['request_id'] ) ? $meta['request_id'] : Logger::correlation_id();
		$form_id    = (int) ( $form['id'] ?? 0 );
		$trusted    = ! empty( $meta['trusted'] );
		$fail       = static function ( string $code, array $extra = array() ) use ( $form_id, $request_id ): array {
			return array_merge(
				array(
					'success'    => false,
					'code'       => $code,
					'message'    => Errors::public_message( $code ),
					'form_id'    => $form_id ?: null,
					'entry_id'   => null,
					'request_id' => $request_id,
					'errors'     => array(),
				),
				$extra
			);
		};

		// 1. Form must be published (T-SUB-05: no existence oracle for drafts/archived).
		$status = (string) ( $form['status'] ?? '' );
		if ( 'paused' === $status ) {
			$message = (string) ( $form['settings']['paused_message'] ?? '' );
			return $fail( Errors::FORM_NOT_ACCEPTING, '' !== $message ? array( 'message' => $message ) : array() );
		}
		$version = 'published' === $status ? $this->loader->published( $form ) : null;
		if ( null === $version ) {
			return $fail( Errors::FORM_NOT_FOUND );
		}
		$fields = $version['fields'];

		// 2. Request size (P3-17).
		if ( isset( $meta['body_bytes'] ) && (int) $meta['body_bytes'] > Limits::get( 'request_bytes' ) ) {
			return $fail( Errors::PAYLOAD_TOO_LARGE );
		}
		$input = $this->field_input( $raw, $trusted );
		if ( count( $input ) > Limits::get( 'fields_per_form' ) ) {
			return $fail( Errors::TOO_MANY_FIELDS );
		}

		// 3. Token + idempotency reference (P3-11, P4-03).
		$issued_at = null;
		if ( $trusted ) {
			$request_key = $this->trusted_request_key( $form_id, $meta );
		} else {
			$token = $this->tokens->verify( $raw['_fc_token'] ?? null, (string) $form['public_id'] );
			if ( ! $token['ok'] ) {
				return $fail( Errors::INVALID_TOKEN );
			}
			$issued_at   = (int) $token['issued_at'];
			$request_key = SubmissionToken::request_key( $form_id, (string) $token['random'] );
		}
		$existing = $this->entries->find_by_request_key( $request_key );
		if ( $existing ) {
			return $this->duplicate( $form, $existing, $request_id );
		}

		// 4. Rate limits (P3-09).
		$rate = $trusted
			? $this->limiter->check_api( $form_id, (string) ( $meta['plugin'] ?? $meta['source'] ?? 'contract' ) )
			: $this->limiter->check_submission( $form_id, isset( $meta['ip'] ) ? (string) $meta['ip'] : RateLimiter::client_ip() );
		if ( ! $rate['allowed'] ) {
			return $fail( Errors::RATE_LIMITED, array( 'retry_after' => (int) $rate['retry_after'] ) );
		}

		// 5. Signed suite context (suite-kontext.md 4).
		$context    = null;
		$ctx_result = null;
		if ( SignedContext::accepted_purposes( $form ) ) {
			$ctx_value = $trusted ? (string) ( $meta['signed_context'] ?? '' ) : ( $raw['_fc_ctx'] ?? '' );
			if ( is_array( $ctx_value ) ) {
				$ctx_result = array( 'ok' => false, 'code' => 'context_malformed', 'public_code' => Errors::INVALID_CONTEXT );
			} elseif ( '' === (string) $ctx_value ) {
				if ( ! empty( $form['settings']['context_required'] ) ) {
					$ctx_result = array( 'ok' => false, 'code' => 'context_missing', 'public_code' => Errors::INVALID_CONTEXT );
				}
			} else {
				$ctx_result = $this->contexts->verify( (string) $ctx_value, $form );
			}
			if ( null !== $ctx_result && ! $ctx_result['ok'] ) {
				$extra = array( 'message' => SignedContext::public_message( $ctx_result['public_code'] ) );
				if ( $trusted ) {
					$extra['detail_code'] = $ctx_result['code'];
				}
				return $fail( $ctx_result['public_code'], $extra );
			}
			$context = $ctx_result['context'] ?? null;
		}

		// 6. Field validation (P3-05, P3-07).
		$checked = $this->validator->validate(
			$fields,
			$input,
			array(
				'today'       => wp_date( 'Y-m-d' ),
				'accept_keys' => $trusted,
			)
		);
		if ( $checked['errors'] ) {
			return $fail(
				Errors::VALIDATION_FAILED,
				array(
					'errors'      => $checked['errors'],
					'error_codes' => $checked['codes'],
				)
			);
		}
		$values = $checked['values'];

		// 7. Spam (P3-08, T-SUB-09/10).
		$spam_reason = $this->spam->check(
			$form,
			$raw,
			$values,
			$fields,
			array(
				'trusted'   => $trusted,
				'issued_at' => $issued_at,
				'now'       => Clock::timestamp(),
			)
		);

		// 8. Transactional write (Outbox pattern).
		$now   = Clock::now();
		$entry = array(
			'form_id'         => $form_id,
			'form_version_id' => (int) $version['version_id'],
			'status'          => null !== $spam_reason ? 'spam' : 'new',
			'request_key'     => $request_key,
			'spam_reason'     => $spam_reason,
			'source_url'      => self::source_url( $meta['source_url'] ?? null ),
			'source_post_id'  => self::source_post_id( $meta['source_post_id'] ?? null ),
			'user_id'         => ! empty( $meta['user_id'] ) ? (int) $meta['user_id'] : null,
			'expires_gmt'     => self::expires_gmt( $form, $now ),
		);
		if ( null !== $context ) {
			$entry['context'] = $context;
		}
		$consent = $this->consent_proof( $fields, $checked['consent'], (int) $version['version_no'], $now );
		if ( $consent ) {
			$entry['consent'] = $consent;
		}

		$entry_id = 0;
		$tx       = $this->begin();
		try {
			$entry_id = $this->entries->insert( $entry );
			if ( $entry_id <= 0 ) {
				$this->abort( $tx, 0, null );
				$race = $this->entries->find_by_request_key( $request_key );
				return $race ? $this->duplicate( $form, $race, $request_id ) : $fail( Errors::STORAGE_FAILED );
			}
			if ( ! $this->entries->insert_values( $entry_id, $values ) ) {
				throw new \RuntimeException( 'values_insert_failed' );
			}
			if ( ! empty( $ctx_result['single_use'] ) && ! $this->contexts->consume_nonce( (string) $ctx_result['nonce'], (int) $ctx_result['exp'], $form_id ) ) {
				$this->abort( $tx, $entry_id, null );
				return $fail( Errors::CONTEXT_REPLAYED, array( 'message' => SignedContext::public_message( Errors::CONTEXT_REPLAYED ) ) + ( $trusted ? array( 'detail_code' => 'context_replayed' ) : array() ) );
			}
			if ( null === $spam_reason ) {
				$this->planner->plan( $form, $entry_id );
			}
			if ( $tx ) {
				$this->commit();
			}
		} catch ( \Throwable $e ) {
			$this->abort( $tx, $entry_id, ! empty( $ctx_result['single_use'] ) ? (string) $ctx_result['nonce'] : null );
			Logger::log( 'error', 'submission_storage_failed', get_class( $e ), array( 'form_id' => $form_id ) );
			return $fail( Errors::STORAGE_FAILED );
		}

		// 9. After commit: public event and worker nudge. Never for spam.
		if ( null !== $spam_reason ) {
			Logger::log( 'info', 'submission_spam', 'Submission classified as spam: ' . $spam_reason, array( 'form_id' => $form_id, 'entry_id' => $entry_id ) );
			if ( $trusted ) {
				return $fail( Errors::SPAM_SUSPECTED, array( 'entry_id' => $entry_id ) );
			}
			return $this->success( $form, $entry_id, $request_id, self::CODE_CREATED );
		}
		$this->dispatch_event( $form, $fields, $entry_id, (int) $version['version_no'], $values, $context, $request_id, $now );
		Scheduler::nudge();
		return $this->success( $form, $entry_id, $request_id, self::CODE_CREATED );
	}

	/**
	 * Extracts field_id => value from the request.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<mixed,mixed>
	 */
	private function field_input( array $raw, bool $trusted ): array {
		if ( isset( $raw['fields'] ) && is_array( $raw['fields'] ) ) {
			return $raw['fields'];
		}
		if ( isset( $raw['fc'] ) && is_array( $raw['fc'] ) ) {
			return $raw['fc'];
		}
		if ( ! $trusted ) {
			return array();
		}
		return array_diff_key( $raw, array_flip( self::RESERVED ) );
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function trusted_request_key( int $form_id, array $meta ): string {
		if ( isset( $meta['request_key'] ) && is_string( $meta['request_key'] ) && preg_match( '/^[0-9a-f]{64}$/', $meta['request_key'] ) ) {
			return $meta['request_key'];
		}
		$key = isset( $meta['idempotency_key'] ) && is_string( $meta['idempotency_key'] ) ? $meta['idempotency_key'] : '';
		if ( '' === $key ) {
			return hash( 'sha256', $form_id . '|contract|' . bin2hex( random_bytes( 16 ) ) );
		}
		return hash( 'sha256', $form_id . '|contract|' . (string) ( $meta['plugin'] ?? '' ) . '|' . $key );
	}

	/**
	 * Same answer as the original request, without new entries/jobs (T-SUB-12).
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private function duplicate( array $form, array $entry, string $request_id ): array {
		if ( (int) $entry['form_id'] !== (int) $form['id'] ) {
			return array(
				'success'    => false,
				'code'       => Errors::INVALID_TOKEN,
				'message'    => Errors::public_message( Errors::INVALID_TOKEN ),
				'form_id'    => (int) $form['id'],
				'entry_id'   => null,
				'request_id' => $request_id,
				'errors'     => array(),
			);
		}
		return $this->success( $form, (int) $entry['id'], $request_id, self::CODE_DUPLICATE );
	}

	/**
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	private function success( array $form, int $entry_id, string $request_id, string $code ): array {
		$out = array(
			'success'    => true,
			'code'       => $code,
			'message'    => '',
			'form_id'    => (int) $form['id'],
			'entry_id'   => $entry_id,
			'request_id' => $request_id,
			'errors'     => array(),
		);
		return array_merge( $out, self::confirmation( $form ) );
	}

	/**
	 * Confirmation: message or internal, published page (P3-10, P3-15). Never taken from the request.
	 *
	 * @param array<string,mixed> $form
	 * @return array{message:string,redirect_url?:string}
	 */
	public static function confirmation( array $form ): array {
		$conf    = is_array( $form['settings']['confirmation'] ?? null ) ? $form['settings']['confirmation'] : array();
		$message = trim( wp_strip_all_tags( (string) ( $conf['message'] ?? '' ) ) );
		$message = '' !== $message ? $message : __( 'Thank you! Your submission has been received.', 'form-captain' );
		if ( 'page' === ( $conf['type'] ?? '' ) ) {
			$page = get_post( (int) ( $conf['page_id'] ?? 0 ) );
			if ( $page instanceof \WP_Post && 'publish' === $page->post_status && '' === (string) $page->post_password && is_post_type_viewable( $page->post_type ) ) {
				$url = (string) get_permalink( $page );
				if ( '' !== $url && '' !== wp_validate_redirect( $url, '' ) ) {
					return array(
						'message'      => $message,
						'redirect_url' => $url,
					);
				}
			}
		}
		return array( 'message' => $message );
	}

	/** True while a savepoint inside a caller-managed transaction is used instead of our own transaction. */
	private bool $nested = false;

	/**
	 * Starts the entry transaction. When the connection already runs inside a caller-managed
	 * transaction (autocommit off), a savepoint is used so the caller's transaction is not
	 * implicitly committed.
	 */
	private function begin(): bool {
		global $wpdb;
		$this->nested = '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $this->nested ) {
			return false !== $wpdb->query( 'SAVEPOINT fc_submission' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $this->entries->begin();
	}

	private function commit(): void {
		global $wpdb;
		if ( $this->nested ) {
			$wpdb->query( 'RELEASE SAVEPOINT fc_submission' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$this->entries->commit();
	}

	private function rollback(): void {
		global $wpdb;
		if ( $this->nested ) {
			$wpdb->query( 'ROLLBACK TO SAVEPOINT fc_submission' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$this->entries->rollback();
	}

	private function abort( bool $tx, int $entry_id, ?string $nonce ): void {
		if ( $tx ) {
			$this->rollback();
		}
		if ( $entry_id > 0 && null !== $this->entries->find( $entry_id ) ) {
			// Compensation for engines without transaction support.
			$this->entries->purge( $entry_id );
		}
		if ( null !== $nonce && ! $tx ) {
			$this->contexts->release_nonce( $nonce );
		}
	}

	/**
	 * Consent proof per consent field (datenschutz.md 5.2).
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @param array<string,bool>             $given
	 * @return array<string,array<string,mixed>>
	 */
	private function consent_proof( array $fields, array $given, int $version_no, string $now ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$id = (string) $field['id'];
			if ( 'consent' !== ( $field['type'] ?? '' ) || ! array_key_exists( $id, $given ) ) {
				continue;
			}
			$out[ $id ] = array(
				'given'        => $given[ $id ],
				'text_hash'    => 'sha256:' . hash( 'sha256', self::normalize_consent_text( (string) ( $field['consent_text'] ?? '' ) ) ),
				'revision'     => max( 1, (int) ( $field['consent_revision'] ?? 1 ) ),
				'form_version' => $version_no,
				'given_at'     => str_replace( ' ', 'T', $now ) . 'Z',
			);
		}
		return $out;
	}

	public static function normalize_consent_text( string $text ): string {
		$text = str_replace( '{privacy_policy_url}', (string) get_privacy_policy_url(), $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		if ( class_exists( \Normalizer::class ) ) {
			$norm = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			$text = false !== $norm ? $norm : $text;
		}
		return $text;
	}

	/**
	 * @param array<string,mixed> $form
	 */
	public static function expires_gmt( array $form, ?string $created_gmt = null ): ?string {
		return RetentionService::expires_for( RetentionService::mode_for_form( $form ), $created_gmt ?? Clock::now() );
	}

	private static function source_url( mixed $url ): ?string {
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return null;
		}
		$clean = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
		return mb_substr( esc_url_raw( $clean ), 0, 2048 );
	}

	private static function source_post_id( mixed $id ): ?int {
		$id = is_numeric( $id ) ? (int) $id : 0;
		return $id > 0 && get_post( $id ) ? $id : null;
	}

	/**
	 * Fires form_captain_entry_created_v1 after COMMIT (api-v1.md). Values only via allowlist, never sensitive.
	 *
	 * @param array<string,mixed>                     $form
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values
	 * @param array<string,mixed>|null                $context
	 */
	private function dispatch_event( array $form, array $fields, int $entry_id, int $version_no, array $values, ?array $context, string $request_id, string $now ): void {
		$allow = apply_filters( 'form_captain_event_value_allowlist_v1', array(), (int) $form['id'] );
		$allow = is_array( $allow ) ? array_map( 'strval', $allow ) : array();
		$out   = array();
		foreach ( $fields as $field ) {
			$id  = (string) $field['id'];
			$key = (string) ( $field['key'] ?? '' );
			if ( '' !== $key && in_array( $key, $allow, true ) && empty( $field['sensitive'] ) && array_key_exists( $id, $values ) ) {
				$out[ $key ] = $values[ $id ];
			}
		}
		$event = array(
			'contract'       => FORM_CAPTAIN_CONTRACT_VERSION,
			'entry_id'       => $entry_id,
			'form_id'        => (int) $form['id'],
			'form_public_id' => (string) $form['public_id'],
			'form_version'   => $version_no,
			'status'         => 'new',
			'created_gmt'    => str_replace( ' ', 'T', $now ) . 'Z',
			'request_id'     => $request_id,
			'context'        => $context,
			'values'         => $out,
		);
		try {
			do_action( 'form_captain_entry_created_v1', $event );
		} catch ( \Throwable $e ) {
			Logger::log( 'error', 'event_listener_failed', get_class( $e ), array( 'form_id' => (int) $form['id'], 'entry_id' => $entry_id ) );
		}
	}
}
