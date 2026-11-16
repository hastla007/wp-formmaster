<?php
/**
 * Safe built-in workflow actions of the core (P8-19, docs/WORKFLOW-CONTRACT.md §3).
 *
 * The actions live in the reserved namespace `core.*` of the regular action registry and are
 * executed by the outbox like every connector action (job type `connector.action`). The internal
 * "connector" `core` needs no site-wide opt-in because it ships with the core; every action still
 * has to be added and enabled per form.
 *
 * - core.notify_admin     extra admin notification to one fixed address (MailBuilder, never sensitive fields)
 * - core.set_status       moves the entry to `read` or `archived` (EntryService, allowed transitions only)
 * - core.anonymize_after  schedules the anonymization of the entry after N days (PrivacyIntegration)
 * - core.handoff          hand-over to another configured action only after manual admin confirmation
 *
 * There are no built-in actions for payments, tickets, vouchers, partner approvals or publishing
 * reviews.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Integrations;

use FormCaptain\Install\Capabilities;
use FormCaptain\Jobs\JobResult;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Mail\MailJobHandler;
use FormCaptain\Plugin;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\EntryService;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Logger;

final class CoreActions {

	/** Reserved connector/action namespace. */
	public const CONNECTOR = 'core';

	public const NOTIFY_ADMIN    = 'core.notify_admin';
	public const SET_STATUS      = 'core.set_status';
	public const ANONYMIZE_AFTER = 'core.anonymize_after';
	public const HANDOFF         = 'core.handoff';

	/** Delayed job created by core.anonymize_after. */
	public const ANONYMIZE_JOB = 'core.anonymize';

	public const ADMIN_ACTION = 'form_captain_confirm_handoff';

	/** Target statuses core.set_status may set (never spam/trash). */
	public const STATUSES = array( 'read', 'archived' );

	public const MAX_DAYS = 3650;

	private bool $registered = false;

	public function __construct( private Plugin $plugin ) {}

	public function register( Plugin $plugin ): void {
		unset( $plugin );
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;
		add_filter( 'form_captain_job_handlers', array( $this, 'add_job_handler' ) );
		add_filter( 'form_captain_entry_detail_sections_v1', array( $this, 'detail_section' ), 10, 2 );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_confirm_request' ) );
	}

	/**
	 * @param array<string,callable> $handlers
	 * @return array<string,callable>
	 */
	public function add_job_handler( array $handlers ): array {
		$handlers[ self::ANONYMIZE_JOB ] = array( $this, 'handle_anonymize_job' );
		return $handlers;
	}

	/** Registers the built-in actions (called by ConnectorRegistry while collecting). */
	public function register_actions( ConnectorRegistry $registry ): void {
		$base = array(
			'version'   => FORM_CAPTAIN_CONTRACT_VERSION,
			'contract'  => '^1.0',
			'connector' => null,
			'events'    => array( 'entry_created' ),
			'mapping'   => array(),
		);
		$registry->register_action(
			self::NOTIFY_ADMIN,
			$base + array(
				'label'    => __( 'Additional admin notification', 'form-captain' ),
				'settings' => array(
					array( 'key' => 'to', 'type' => 'email', 'label' => __( 'Recipient address', 'form-captain' ), 'required' => true ),
					array( 'key' => 'subject', 'type' => 'text', 'label' => __( 'Subject (optional)', 'form-captain' ) ),
				),
			),
			array( $this, 'notify_admin' )
		);
		$registry->register_action(
			self::SET_STATUS,
			$base + array(
				'label'    => __( 'Set submission status', 'form-captain' ),
				'settings' => array(
					array( 'key' => 'status', 'type' => 'select', 'label' => __( 'New status', 'form-captain' ), 'required' => true, 'options' => self::STATUSES, 'default' => 'archived' ),
				),
			),
			array( $this, 'set_status' )
		);
		$registry->register_action(
			self::ANONYMIZE_AFTER,
			$base + array(
				'label'    => __( 'Anonymize submission after a number of days', 'form-captain' ),
				'settings' => array(
					array( 'key' => 'days', 'type' => 'number', 'label' => __( 'Days after submission', 'form-captain' ), 'required' => true, 'default' => '30' ),
				),
			),
			array( $this, 'anonymize_after' )
		);
		$registry->register_action(
			self::HANDOFF,
			$base + array(
				'label'    => __( 'Hand over to another action after confirmation', 'form-captain' ),
				'settings' => array(
					array( 'key' => 'target_instance', 'type' => 'text', 'label' => __( 'Instance ID of the target action in this form', 'form-captain' ), 'required' => true ),
				),
			),
			array( $this, 'handoff' )
		);
	}

	// ---------------------------------------------------------------------
	// Callbacks (contract signature: array $job → result array)
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,string>
	 */
	public function notify_admin( array $job ): array {
		$raw = trim( (string) ( $job['settings']['to'] ?? '' ) );
		$to  = sanitize_email( $raw );
		if ( '' === $to || $to !== $raw || ! is_email( $to ) || MailBuilder::header_unsafe( $raw ) ) {
			return self::result( 'fail', 'invalid_recipient', 'No valid recipient configured' );
		}
		if ( ! empty( $job['test_mode'] ) ) {
			return self::result( 'success', 'test_ok' );
		}
		$loaded = $this->load( (int) $job['entry_id'] );
		if ( is_string( $loaded ) ) {
			return self::result( 'fail', $loaded );
		}
		[ $entry, $form ] = $loaded;
		$builder          = $this->plugin->get( MailBuilder::class );
		$schema           = $builder->schema_for( $form, $entry );
		$allowed          = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['id'] ) && empty( $field['sensitive'] ) && ! in_array( (string) ( $field['type'] ?? '' ), ConnectorRegistry::BLOCKED_FIELD_TYPES, true ) ) {
				$allowed[] = (string) $field['id'];
			}
		}
		$values                           = array_intersect_key( $this->plugin->get( EntryRepository::class )->values_for( (int) $entry['id'] ), array_flip( $allowed ) );
		$copy                             = $form;
		$copy['settings']['notification'] = array(
			'enabled' => true,
			'to'      => array( $to ),
			'subject' => (string) ( $job['settings']['subject'] ?? '' ),
			'fields'  => $allowed,
		);
		if ( isset( $form['settings']['notification']['format'] ) ) {
			$copy['settings']['notification']['format'] = (string) $form['settings']['notification']['format'];
		}
		$mail = $builder->admin( $copy, $entry, $values );
		if ( is_wp_error( $mail ) ) {
			return self::result( 'fail', (string) $mail->get_error_code() );
		}
		$error = '';
		if ( ! $this->plugin->get( MailJobHandler::class )->send( $mail, $error ) ) {
			return self::result( 'retry', 'mail_failed', '' !== $error ? Logger::redact( $error ) : 'wp_mail returned false' );
		}
		return self::result( 'success', 'sent' );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,string>
	 */
	public function set_status( array $job ): array {
		$status = (string) ( $job['settings']['status'] ?? '' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::result( 'fail', 'invalid_status', 'Status not allowed for workflows' );
		}
		if ( ! empty( $job['test_mode'] ) ) {
			return self::result( 'success', 'test_ok' );
		}
		$entry = $this->plugin->get( EntryRepository::class )->find( (int) $job['entry_id'] );
		if ( ! $entry ) {
			return self::result( 'fail', 'entry_deleted' );
		}
		if ( $status === $entry['status'] ) {
			return self::result( 'success', 'unchanged' );
		}
		if ( ! EntryService::can_transition( (string) $entry['status'], $status ) ) {
			return self::result( 'fail', 'transition_not_allowed', 'Status change ' . $entry['status'] . ' → ' . $status . ' is not allowed' );
		}
		$res = $this->plugin->get( EntryService::class )->transition( (int) $entry['id'], $status, 'workflow' );
		return true === $res ? self::result( 'success', 'status_' . $status ) : self::result( 'fail', (string) $res->get_error_code(), $res->get_error_message() );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,string>
	 */
	public function anonymize_after( array $job ): array {
		$days = (int) ( $job['settings']['days'] ?? 0 );
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			return self::result( 'fail', 'invalid_days', 'Days must be between 1 and ' . self::MAX_DAYS );
		}
		if ( ! empty( $job['test_mode'] ) ) {
			return self::result( 'success', 'test_ok' );
		}
		$entry = $this->plugin->get( EntryRepository::class )->find( (int) $job['entry_id'] );
		if ( ! $entry ) {
			return self::result( 'fail', 'entry_deleted' );
		}
		$key = sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), (int) $entry['id'], self::ANONYMIZE_JOB, (string) ( $job['instance_id'] ?? '' ) );
		$id  = $this->plugin->get( JobRepository::class )->enqueue( self::ANONYMIZE_JOB, $key, array( 'days' => $days ), (int) $entry['id'], (int) $entry['form_id'], $days * DAY_IN_SECONDS, 3 );
		if ( $id <= 0 ) {
			return self::result( 'retry', 'job_enqueue_failed' );
		}
		return self::result( 'success', 'scheduled', '', (string) $id );
	}

	/**
	 * Only records the pending hand-over; the target job is queued after confirmation.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,string>
	 */
	public function handoff( array $job ): array {
		$target = (string) ( $job['settings']['target_instance'] ?? '' );
		if ( ! empty( $job['test_mode'] ) ) {
			return preg_match( '/^a_[0-9a-f]{10}$/', $target ) ? self::result( 'success', 'test_ok' ) : self::result( 'fail', 'handoff_target_invalid' );
		}
		$loaded = $this->load( (int) $job['entry_id'] );
		if ( is_string( $loaded ) ) {
			return self::result( 'fail', $loaded );
		}
		[ $entry, $form ] = $loaded;
		$instance         = self::target_instance( $form, $target );
		if ( is_string( $instance ) ) {
			return self::result( 'fail', $instance );
		}
		$label = (string) ( $this->registry()->action( (string) $instance['action_key'] )['label'] ?? $instance['action_key'] );
		$this->plugin->get( EntryRepository::class )->add_note(
			(int) $entry['id'],
			0,
			/* translators: %s: label of the target action */
			sprintf( __( 'Hand-over pending: "%s" runs only after an administrator confirms it in the submission details.', 'form-captain' ), $label ),
			'note'
		);
		return self::result( 'success', 'handoff_pending' );
	}

	// ---------------------------------------------------------------------
	// Delayed anonymization
	// ---------------------------------------------------------------------

	/** @param array<string,mixed> $job */
	public function handle_anonymize_job( array $job ): JobResult {
		$entry_id = (int) ( $job['entry_id'] ?? 0 );
		$entry    = $entry_id ? $this->plugin->get( EntryRepository::class )->find( $entry_id ) : null;
		if ( ! $entry ) {
			return JobResult::success( array( 'skipped' => 'entry_deleted' ) );
		}
		if ( ! empty( $entry['anonymized_gmt'] ) ) {
			return JobResult::success( array( 'skipped' => 'already_anonymized' ) );
		}
		// Note: PrivacyIntegration cancels all open jobs of the entry, this one included; the job row
		// therefore ends as `cancelled` with code `entry_anonymized`.
		if ( ! $this->plugin->get( PrivacyIntegration::class )->anonymize( $entry_id ) ) {
			return JobResult::retry( 'anonymize_failed', 'Anonymization failed' );
		}
		return JobResult::success( array( 'code' => 'anonymized' ) );
	}

	// ---------------------------------------------------------------------
	// Hand-over confirmation
	// ---------------------------------------------------------------------

	/**
	 * Hand-overs of an entry that wait for confirmation.
	 *
	 * @return array<int,array{instance_id:string,target_instance:string,action_key:string,label:string}>
	 */
	public function pending_handoffs( int $entry_id ): array {
		$entries = $this->plugin->get( EntryRepository::class );
		$entry   = $entries->find( $entry_id );
		if ( ! $entry || ! empty( $entry['anonymized_gmt'] ) || in_array( $entry['status'], array( 'spam', 'trash' ), true ) ) {
			return array();
		}
		$form = $this->plugin->get( FormRepository::class )->find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return array();
		}
		$jobs = $this->plugin->get( JobRepository::class );
		$out  = array();
		foreach ( (array) ( $form['settings']['actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || self::HANDOFF !== ( $action['action_key'] ?? '' ) || empty( $action['enabled'] ) ) {
				continue;
			}
			$instance_id = (string) ( $action['instance_id'] ?? '' );
			$handoff_job = $jobs->find_by_key( self::key( $entry_id, self::HANDOFF, $instance_id ) );
			if ( ! $handoff_job || 'succeeded' !== $handoff_job['status'] ) {
				continue;
			}
			$target = self::target_instance( $form, (string) ( $action['settings']['target_instance'] ?? '' ) );
			if ( is_string( $target ) || $jobs->find_by_key( self::key( $entry_id, (string) $target['action_key'], (string) $target['instance_id'] ) ) ) {
				continue;
			}
			$out[] = array(
				'instance_id'     => $instance_id,
				'target_instance' => (string) $target['instance_id'],
				'action_key'      => (string) $target['action_key'],
				'label'           => (string) ( $this->registry()->action( (string) $target['action_key'] )['label'] ?? $target['action_key'] ),
			);
		}
		return $out;
	}

	/**
	 * Capability-checked confirmation: queues the target action job (idempotent).
	 */
	public function confirm_handoff( int $entry_id, string $instance_id ): int|\WP_Error {
		if ( ! current_user_can( Capabilities::READ_ENTRIES ) || ! current_user_can( Capabilities::EDIT_ENTRIES ) || ! current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) {
			return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to confirm hand-overs.', 'form-captain' ) );
		}
		$entry = $this->plugin->get( EntryRepository::class )->find( $entry_id );
		if ( ! $entry ) {
			return Errors::error( Errors::ENTRY_NOT_FOUND, __( 'Submission not found.', 'form-captain' ) );
		}
		$pending = null;
		foreach ( $this->pending_handoffs( $entry_id ) as $item ) {
			if ( $item['instance_id'] === $instance_id ) {
				$pending = $item;
				break;
			}
		}
		if ( null === $pending ) {
			return new \WP_Error( 'conflict', __( 'There is no pending hand-over for this submission.', 'form-captain' ), array( 'status' => 409 ) );
		}
		$id = $this->plugin->get( JobRepository::class )->enqueue(
			ConnectorRegistry::JOB_TYPE,
			self::key( $entry_id, $pending['action_key'], $pending['target_instance'] ),
			array(
				'action_key'  => $pending['action_key'],
				'instance_id' => $pending['target_instance'],
				'handoff'     => $instance_id,
			),
			$entry_id,
			(int) $entry['form_id']
		);
		if ( $id <= 0 ) {
			return Errors::error( Errors::INTERNAL_ERROR );
		}
		$this->plugin->get( EntryService::class )->audit( $entry_id, 'handoff_confirmed' );
		Logger::log( 'info', 'handoff_confirmed', $pending['action_key'], array( 'entry_id' => $entry_id, 'job_id' => $id ) );
		return $id;
	}

	/** admin-post handler: capability + nonce, then redirect back to the entry. */
	public function handle_confirm_request(): void {
		$entry_id    = isset( $_REQUEST['entry_id'] ) ? absint( wp_unslash( $_REQUEST['entry_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$instance_id = isset( $_REQUEST['instance'] ) ? sanitize_key( wp_unslash( $_REQUEST['instance'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) {
			wp_die( esc_html__( 'You are not allowed to confirm hand-overs.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::nonce_action( $entry_id, $instance_id ) );
		$result = $this->confirm_handoff( $entry_id, $instance_id );
		$back   = admin_url( 'admin.php?page=form-captain-entries&view=' . $entry_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ), 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( 'fc_notice', 'handoff_confirmed', $back ) );
		exit;
	}

	public static function nonce_action( int $entry_id, string $instance_id ): string {
		return self::ADMIN_ACTION . '_' . $entry_id . '_' . $instance_id;
	}

	/**
	 * Entry detail section with a confirm button per pending hand-over.
	 *
	 * @param array<int,array<string,string>> $sections
	 * @param array<string,mixed>             $entry
	 * @return array<int,array<string,string>>
	 */
	public function detail_section( $sections, $entry ): array {
		$sections = is_array( $sections ) ? $sections : array();
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return $sections;
		}
		$pending = $this->pending_handoffs( (int) $entry['id'] );
		if ( ! $pending ) {
			return $sections;
		}
		$can  = current_user_can( Capabilities::MANAGE_INTEGRATIONS ) && current_user_can( Capabilities::EDIT_ENTRIES );
		$html = '<p>' . esc_html__( 'These hand-overs only run after you confirm them. Check the submission first.', 'form-captain' ) . '</p><ul>';
		foreach ( $pending as $item ) {
			$html .= '<li><strong>' . esc_html( $item['label'] ) . '</strong> ';
			if ( $can ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'   => self::ADMIN_ACTION,
							'entry_id' => (int) $entry['id'],
							'instance' => $item['instance_id'],
						),
						admin_url( 'admin-post.php' )
					),
					self::nonce_action( (int) $entry['id'], $item['instance_id'] )
				);
				// A nonce link (the detail view filters sections through wp_kses_post, which drops forms).
				$html .= '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm hand-over', 'form-captain' ) . '</a>';
			} else {
				$html .= '<span class="description">' . esc_html__( 'An administrator with the right to manage integrations has to confirm this hand-over.', 'form-captain' ) . '</span>';
			}
			$html .= '</li>';
		}
		$html      .= '</ul>';
		$sections[] = array(
			'title' => __( 'Pending hand-overs', 'form-captain' ),
			'html'  => $html,
		);
		return $sections;
	}

	/**
	 * Instance IDs that must not be queued automatically because an enabled core.handoff points at them.
	 *
	 * @param array<int,mixed> $actions form settings `actions`
	 * @return array<string,true>
	 */
	public static function handoff_targets( array $actions ): array {
		$out = array();
		foreach ( $actions as $action ) {
			if ( is_array( $action ) && self::HANDOFF === ( $action['action_key'] ?? '' ) && ! empty( $action['enabled'] ) ) {
				$target = (string) ( $action['settings']['target_instance'] ?? '' );
				if ( '' !== $target ) {
					$out[ $target ] = true;
				}
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>|string instance or error code
	 */
	private static function target_instance( array $form, string $target ): array|string {
		if ( ! preg_match( '/^a_[0-9a-f]{10}$/', $target ) ) {
			return 'handoff_target_invalid';
		}
		foreach ( (array) ( $form['settings']['actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || ( $action['instance_id'] ?? '' ) !== $target ) {
				continue;
			}
			$key = (string) ( $action['action_key'] ?? '' );
			if ( str_starts_with( $key, self::CONNECTOR . '.' ) ) {
				return 'handoff_target_invalid';
			}
			if ( empty( $action['enabled'] ) ) {
				return 'action_disabled';
			}
			return $action;
		}
		return 'handoff_target_missing';
	}

	/**
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}|string
	 */
	private function load( int $entry_id ): array|string {
		$entry = $entry_id ? $this->plugin->get( EntryRepository::class )->find( $entry_id ) : null;
		if ( ! $entry ) {
			return 'entry_deleted';
		}
		$form = $this->plugin->get( FormRepository::class )->find( (int) $entry['form_id'] );
		return $form ? array( $entry, $form ) : 'form_not_found';
	}

	private static function key( int $entry_id, string $action_key, string $instance_id ): string {
		return sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), $entry_id, $action_key, $instance_id );
	}

	private function registry(): ConnectorRegistry {
		return $this->plugin->get( ConnectorRegistry::class );
	}

	/** @return array<string,string> */
	private static function result( string $status, string $code, string $message = '', string $remote_id = '' ): array {
		$out = array(
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
		if ( '' !== $remote_id ) {
			$out['remote_id'] = $remote_id;
		}
		return $out;
	}
}
