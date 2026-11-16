<?php
/**
 * EXAMPLE connector implementing FormCaptain\Integrations\ConnectorInterface.
 *
 * The "remote ticket service" is simulated with the option `fcrc_tickets` (keyed by idempotency key),
 * so repeated deliveries never create a second ticket. A failure mode for tests can be set with the
 * option `fcrc_failure_mode`: '' (off), 'fail_once', 'timeout_after_success', 'permanent'.
 *
 * @package FormCaptainReferenceConnector
 */

declare(strict_types=1);

namespace FormCaptainReferenceConnector;

use FormCaptain\Integrations\ConnectorInterface;

final class Reference_Connector implements ConnectorInterface {

	public const ACTION        = 'reference.create_ticket';
	public const OPTION        = 'fcrc_tickets';
	public const MODE_OPTION   = 'fcrc_failure_mode';
	public const SEEN_OPTION   = 'fcrc_attempts';

	public function key(): string {
		return 'reference';
	}

	public function label(): string {
		return __( 'Reference connector (example)', 'form-captain-reference-connector' );
	}

	public function version(): string {
		return FCRC_VERSION;
	}

	public function contract(): string {
		return '1.0';
	}

	public function capabilities(): array {
		return array( self::ACTION );
	}

	public function requirements(): array {
		// A real connector would check its endpoint and credentials here.
		return array(
			'ok'       => true,
			'messages' => array( __( 'Example service runs locally. No data leaves this site.', 'form-captain-reference-connector' ) ),
		);
	}

	public function settings_schema(): array {
		return array();
	}

	public function register_actions(): void {
		form_captain_register_action_v1(
			self::ACTION,
			array(
				'label'     => __( 'Create example ticket', 'form-captain-reference-connector' ),
				'version'   => '1.0.0',
				'contract'  => '^1.0',
				'connector' => 'reference',
				'settings'  => array(
					array(
						'key'      => 'queue',
						'type'     => 'text',
						'label'    => __( 'Queue', 'form-captain-reference-connector' ),
						'required' => true,
						'default'  => 'support',
					),
				),
				'mapping'   => array(
					array(
						'key'      => 'email',
						'label'    => __( 'E-mail', 'form-captain-reference-connector' ),
						'types'    => array( 'email' ),
						'required' => true,
					),
					array(
						'key'   => 'subject',
						'label' => __( 'Subject', 'form-captain-reference-connector' ),
						'types' => array( 'text', 'select', 'radio' ),
					),
					array(
						'key'   => 'message',
						'label' => __( 'Message', 'form-captain-reference-connector' ),
						'types' => array( 'textarea', 'text' ),
					),
				),
				'events'    => array( 'entry_created' ),
			),
			array( $this, 'create_ticket' )
		);
	}

	/**
	 * Action callback. Receives only mapped, validated values (contract v1 $job array).
	 * Unknown keys in $job are ignored on purpose (forward compatibility).
	 *
	 * @param array<string,mixed> $job
	 * @return array{status:string,code:string,message:string,remote_id?:string}
	 */
	public function create_ticket( array $job ): array {
		$key = (string) ( $job['idempotency_key'] ?? '' );
		if ( ! empty( $job['test_mode'] ) ) {
			// Test mode: validate input, no side effects.
			return array(
				'status'  => 'success',
				'code'    => 'test_ok',
				'message' => 'Test mode – no ticket created.',
			);
		}
		if ( '' === $key ) {
			return array( 'status' => 'fail', 'code' => 'missing_idempotency_key', 'message' => 'No idempotency key.' );
		}
		$queue = trim( (string) ( $job['settings']['queue'] ?? '' ) );
		$email = (string) ( $job['values']['email'] ?? '' );
		if ( '' === $queue || ! is_email( $email ) ) {
			return array( 'status' => 'fail', 'code' => 'invalid_input', 'message' => 'Queue or e-mail missing.' );
		}

		$tickets = get_option( self::OPTION, array() );
		$tickets = is_array( $tickets ) ? $tickets : array();
		// Idempotency: the same key always yields the same ticket.
		if ( isset( $tickets[ $key ] ) ) {
			return array( 'status' => 'success', 'code' => 'already_created', 'message' => '', 'remote_id' => (string) $tickets[ $key ]['id'] );
		}

		$mode     = (string) get_option( self::MODE_OPTION, '' );
		$attempts = get_option( self::SEEN_OPTION, array() );
		$attempts = is_array( $attempts ) ? $attempts : array();
		$count    = (int) ( $attempts[ $key ] ?? 0 ) + 1;
		$attempts[ $key ] = $count;
		update_option( self::SEEN_OPTION, $attempts, false );

		if ( 'permanent' === $mode ) {
			return array( 'status' => 'fail', 'code' => 'remote_rejected', 'message' => 'The example service rejected the ticket.' );
		}
		if ( 'fail_once' === $mode && 1 === $count ) {
			return array( 'status' => 'retry', 'code' => 'remote_unavailable', 'message' => 'The example service is temporarily unavailable.' );
		}

		$ticket_id         = 'T-' . strtoupper( substr( hash( 'sha256', $key ), 0, 10 ) );
		$tickets[ $key ] = array(
			'id'          => $ticket_id,
			'queue'       => $queue,
			'email'       => $email,
			'subject'     => mb_substr( (string) ( is_array( $job['values']['subject'] ?? null ) ? implode( ', ', $job['values']['subject'] ) : ( $job['values']['subject'] ?? '' ) ), 0, 190 ),
			'message'     => mb_substr( (string) ( $job['values']['message'] ?? '' ), 0, 5000 ),
			'form_id'     => (int) ( $job['form_id'] ?? 0 ),
			'entry_id'    => (int) ( $job['entry_id'] ?? 0 ),
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::OPTION, $tickets, false );

		if ( 'timeout_after_success' === $mode && 1 === $count ) {
			// The remote side stored the ticket, but the answer got lost.
			throw new \RuntimeException( 'Simulated timeout after the ticket was stored.' );
		}
		return array( 'status' => 'success', 'code' => 'created', 'message' => '', 'remote_id' => $ticket_id );
	}

	/** Example clean-up hook: only removes data for entries it knows (no blind remote deletion). */
	public static function on_entry_deleted( int $entry_id, int $form_id, string $reason ): void {
		$tickets = get_option( self::OPTION, array() );
		if ( ! is_array( $tickets ) ) {
			return;
		}
		foreach ( $tickets as $key => $ticket ) {
			if ( (int) ( $ticket['entry_id'] ?? 0 ) === $entry_id ) {
				// The example service keeps the ticket but drops personal data – a real connector
				// does what its contract with the target system defines.
				$tickets[ $key ]['email']   = '';
				$tickets[ $key ]['message'] = '';
			}
		}
		update_option( self::OPTION, $tickets, false );
	}
}

