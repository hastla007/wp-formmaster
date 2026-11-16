<?php
/**
 * Plans follow-up jobs for a new entry inside the entry transaction (outbox pattern, P4-16).
 *
 * Payloads contain references/configuration only – never field values.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Integrations\CoreActions;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;

final class OutboxPlanner {

	public function __construct( private JobRepository $jobs, private FormSchemaLoader $loader, private ?EntryRepository $entries = null ) {}

	/**
	 * @param array<string,mixed> $form form row (settings + published version)
	 * @throws \RuntimeException When a job cannot be stored (caller rolls back).
	 */
	public function plan( array $form, int $entry_id ): void {
		// No new jobs for an entry whose consent was withdrawn.
		$entry = $this->entries ? $this->entries->find( $entry_id ) : null;
		if ( $entry && ! empty( $entry['consent_withdrawn_gmt'] ) ) {
			return;
		}
		$settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();
		$form_id  = (int) $form['id'];
		$prefix   = 'form-captain:' . get_current_blog_id() . ':' . $entry_id . ':';

		if ( ! empty( $settings['notification']['enabled'] ) ) {
			$this->enqueue( 'mail.admin', $prefix . 'mail.admin', array( 'notification' => 'admin' ), $entry_id, $form_id );
		}

		if ( ! empty( $settings['confirmation_mail']['enabled'] ) && $this->has_confirmation_recipient( $form ) ) {
			$this->enqueue( 'mail.confirmation', $prefix . 'mail.confirmation', array( 'notification' => 'confirmation' ), $entry_id, $form_id );
		}

		$actions = is_array( $settings['actions'] ?? null ) ? $settings['actions'] : array();
		// Targets of an enabled core.handoff are only queued after manual confirmation (P8-19).
		$awaiting = class_exists( CoreActions::class ) ? CoreActions::handoff_targets( $actions ) : array();
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['enabled'] ) || isset( $awaiting[ (string) ( $action['instance_id'] ?? '' ) ] ) ) {
				continue;
			}
			$key      = (string) ( $action['action_key'] ?? '' );
			$instance = (string) ( $action['instance_id'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $key ) || ! preg_match( '/^a_[0-9a-f]{10}$/', $instance ) ) {
				continue;
			}
			$this->enqueue(
				'connector.action',
				$prefix . $key . ':' . $instance,
				array(
					'action_key'  => $key,
					'instance_id' => $instance,
				),
				$entry_id,
				$form_id
			);
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function enqueue( string $type, string $key, array $payload, int $entry_id, int $form_id ): void {
		if ( $this->jobs->enqueue( $type, $key, $payload, $entry_id, $form_id ) <= 0 ) {
			throw new \RuntimeException( 'job_enqueue_failed' );
		}
	}

	/**
	 * @param array<string,mixed> $form
	 */
	private function has_confirmation_recipient( array $form ): bool {
		$version = $this->loader->published( $form );
		foreach ( $version['fields'] ?? array() as $field ) {
			if ( 'email' === ( $field['type'] ?? '' ) && ! empty( $field['confirmation_recipient'] ) ) {
				return true;
			}
		}
		return false;
	}
}
