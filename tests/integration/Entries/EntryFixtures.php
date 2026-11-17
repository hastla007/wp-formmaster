<?php
/**
 * Helpers to create entries/jobs directly through the repositories.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

namespace FormCaptain\Tests\Integration\Entries;

use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;

trait EntryFixtures {

	/**
	 * @param array<string,mixed>                     $form
	 * @param array<string,string|array<int,string>> $values
	 * @param array<string,mixed>                     $data
	 */
	protected function make_entry( array $form, array $values = array(), array $data = array() ): int {
		$repo   = $this->plugin()->get( EntryRepository::class );
		$values = $values ?: array(
			'f_name01' => 'Max Müller',
			'f_mail01' => 'max@example.org',
			'f_msg001' => "Hallo\nWelt",
		);
		$id     = $repo->insert(
			array_merge(
				array(
					'form_id'         => (int) $form['id'],
					'form_version_id' => (int) $form['published_version_id'],
					'status'          => 'new',
					'request_key'     => hash( 'sha256', uniqid( 'fc', true ) . random_int( 0, PHP_INT_MAX ) ),
				),
				$data
			)
		);
		$this->assertGreaterThan( 0, $id );
		$repo->insert_values( $id, $values );
		return $id;
	}

	/** Queues jobs like the OutboxPlanner would. */
	protected function enqueue_mail( int $entry_id, int $form_id, string $type = 'mail.admin' ): int {
		return $this->plugin()->get( JobRepository::class )->enqueue( $type, sprintf( 'form-captain:%d:%d:%s', get_current_blog_id(), $entry_id, $type ), array( 'notification' => 'mail.admin' === $type ? 'admin' : 'confirmation' ), $entry_id, $form_id );
	}

	protected function entries(): EntryRepository {
		return $this->plugin()->get( EntryRepository::class );
	}

	protected function jobs(): JobRepository {
		return $this->plugin()->get( JobRepository::class );
	}

	protected function forms(): FormRepository {
		return $this->plugin()->get( FormRepository::class );
	}

	protected function runner(): JobRunner {
		return $this->plugin()->get( JobRunner::class );
	}

	/** @return array<int,object> */
	protected function sent_mails(): array {
		$mailer = tests_retrieve_phpmailer_instance();
		return array_map( static fn( array $m ): object => (object) $m, $mailer->mock_sent );
	}

	protected function reset_mailer(): void {
		reset_phpmailer_instance();
	}

	protected function role_with_caps( string $role, array $caps ): int {
		// Always start from a fresh role: add_role() is a no-op for an existing role, so caps from
		// another test using the same role name would leak into this one.
		remove_role( $role );
		add_role( $role, $role, array( 'read' => true ) );
		$r = get_role( $role );
		foreach ( $caps as $cap ) {
			$r->add_cap( $cap );
		}
		return $this->as_role( $role );
	}
}
