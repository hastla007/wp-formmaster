<?php
/**
 * Built-in safe workflow actions core.* (P8-19).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Workflow;

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Integrations\CoreActions;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\OutboxPlanner;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\Contract\ConnectorContractAssertions;
use FormCaptain\Tests\Integration\Contract\FakeConnector;
use FormCaptain\Tests\Integration\TestCase;

require_once dirname( __DIR__ ) . '/Contract/ConnectorContractAssertions.php';
require_once dirname( __DIR__ ) . '/Contract/FakeConnector.php';

final class CoreActionsTest extends TestCase {

	use ConnectorContractAssertions;

	private const FIELDS = array(
		array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
		array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
		array( 'id' => 'f_iban01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
	);

	/** @var array<int,array<string,mixed>> */
	private array $mails = array();

	private FakeConnector $fake;

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
		$this->fake = new FakeConnector( 'fake' );
		$fake       = $this->fake;
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'fake' => $fake ) );
		$this->registry()->reload();
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'fake' ) );
		wp_set_current_user( 0 );
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, array $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		$this->registry()->reset();
		parent::tear_down();
	}

	/** @param array<int,array<string,mixed>> $actions */
	private function form( array $actions ): array {
		return $this->create_published_form( self::FIELDS, array( 'actions' => $actions ) );
	}

	private function entry( array $form, array $extra = array() ): int {
		return $this->make_entry(
			$form,
			array(
				'f_name01' => 'Ada',
				'f_mail01' => 'ada@example.org',
				'f_iban01' => 'DE00123456781234567890',
			),
			$extra
		);
	}

	private function run_job( int $id ): ?string {
		return $this->plugin()->get( JobRunner::class )->run_one( $id );
	}

	private function job( int $id ): array {
		return $this->plugin()->get( JobRepository::class )->find( $id );
	}

	public function test_core_actions_are_registered_without_opt_in(): void {
		$actions = $this->registry()->actions();
		foreach ( array( CoreActions::NOTIFY_ADMIN, CoreActions::SET_STATUS, CoreActions::ANONYMIZE_AFTER, CoreActions::HANDOFF ) as $key ) {
			$this->assertArrayHasKey( $key, $actions );
			$this->assertNull( $actions[ $key ]['connector'] );
			$this->assertSame( '', $this->registry()->unavailable_reason( $key ), $key . ' is always available' );
		}
		$keys = array_column( $this->registry()->actions_for_editor(), 'key' );
		$this->assertContains( CoreActions::HANDOFF, $keys );
		$this->assertArrayNotHasKey( 'core', $this->registry()->all(), 'The internal core connector is not listed as opt-in connector' );
		foreach ( array_keys( $actions ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/payment|ticket|voucher|coupon|partner|review|publish/', $key, 'No unsafe built-in actions' );
		}
	}

	public function test_core_namespace_is_reserved(): void {
		$result = form_captain_register_action_v1( 'core.evil', array( 'label' => 'Evil', 'contract' => '^1.0' ), static fn() => array( 'status' => 'success' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );

		$fake = new FakeConnector( 'core' );
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'core' => $fake ) );
		$this->registry()->reload();
		$this->assertNotSame( ConnectorRegistry::STATE_ACTIVE, $this->registry()->state( 'core' ) );
		$this->assertArrayNotHasKey( 'core.send', $this->registry()->actions() );
		$this->as_admin();
		$this->assertWPError( $this->registry()->activate( 'core' ) );
	}

	public function test_actions_run_only_when_enabled_in_form(): void {
		$form  = $this->form( array( self::action_instance( CoreActions::SET_STATUS, 'a_0000000001', array(), array( 'status' => 'archived' ), false ) ) );
		$entry = $this->entry( $form );
		$this->plugin()->get( OutboxPlanner::class )->plan( $form, $entry );
		$this->assertSame( array( 'mail.admin' ), array_column( $this->plugin()->get( JobRepository::class )->for_entry( $entry ), 'type' ) );
	}

	public function test_notify_admin_sends_to_fixed_address_without_sensitive_fields(): void {
		$form  = $this->form( array( self::action_instance( CoreActions::NOTIFY_ADMIN, 'a_0000000002', array( 'email' => 'f_mail01' ), array( 'to' => 'team@example.net', 'subject' => 'Neu {field:iban} {form_title}' ) ) ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, CoreActions::NOTIFY_ADMIN, 'a_0000000002' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertCount( 1, $this->mails );
		$mail = $this->mails[0];
		$this->assertSame( array( 'team@example.net' ), (array) $mail['to'] );
		$this->assertStringContainsString( 'Kontakt', (string) $mail['subject'] );
		$dump = (string) wp_json_encode( $mail );
		$this->assertStringContainsString( 'Ada', $dump );
		$this->assertStringNotContainsString( 'DE00123456781234567890', $dump, 'Sensitive fields never leave via core.notify_admin' );
		$this->assertSame( 'sent', $this->job( $id )['result']['code'] );
	}

	public function test_notify_admin_rejects_invalid_recipient(): void {
		$form = $this->form( array( self::action_instance( CoreActions::NOTIFY_ADMIN, 'a_0000000003', array(), array( 'to' => "evil@example.org\r\nBcc: x@example.org" ) ) ) );
		$id   = $this->enqueue_action_job( $form, $this->entry( $form ), CoreActions::NOTIFY_ADMIN, 'a_0000000003' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'invalid_recipient', $this->job( $id )['last_error_code'] );
		$this->assertCount( 0, $this->mails );
	}

	public function test_set_status_uses_allowed_transitions_and_fires_event(): void {
		$form   = $this->form( array( self::action_instance( CoreActions::SET_STATUS, 'a_0000000004', array(), array( 'status' => 'archived' ) ) ) );
		$entry  = $this->entry( $form );
		$events = array();
		add_action(
			'form_captain_entry_status_changed_v1',
			static function ( $id, $old, $new ) use ( &$events ): void {
				$events[] = array( $id, $old, $new );
			},
			10,
			3
		);
		$id = $this->enqueue_action_job( $form, $entry, CoreActions::SET_STATUS, 'a_0000000004' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );
		$this->assertSame( 'archived', $this->plugin()->get( EntryRepository::class )->find( $entry )['status'] );
		$this->assertSame( array( array( $entry, 'new', 'archived' ) ), $events );

		// Trash → archived is not an allowed transition.
		$trashed = $this->entry( $form, array( 'status' => 'trash' ) );
		$id      = $this->enqueue_action_job( $form, $trashed, CoreActions::SET_STATUS, 'a_0000000004' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'transition_not_allowed', $this->job( $id )['last_error_code'] );
		$this->assertSame( 'trash', $this->plugin()->get( EntryRepository::class )->find( $trashed )['status'] );

		// Spam/trash are never targets, even when called directly.
		$core = $this->plugin()->get( CoreActions::class );
		foreach ( array( 'spam', 'trash', 'new' ) as $status ) {
			$this->assertSame( 'invalid_status', $core->set_status( array( 'entry_id' => $entry, 'settings' => array( 'status' => $status ) ) )['code'] );
		}
		$this->assertSame( 'archived', ConnectorRegistry::sanitize_action_settings( $this->registry()->action( CoreActions::SET_STATUS ), array( 'status' => 'spam' ) )['status'] );
	}

	public function test_anonymize_after_schedules_and_anonymizes(): void {
		$form  = $this->form( array( self::action_instance( CoreActions::ANONYMIZE_AFTER, 'a_0000000005', array(), array( 'days' => '7' ) ) ) );
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, CoreActions::ANONYMIZE_AFTER, 'a_0000000005' );
		$this->assertSame( 'succeeded', $this->run_job( $id ) );

		$jobs    = $this->plugin()->get( JobRepository::class );
		$delayed = $jobs->find_by_key( sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), $entry, CoreActions::ANONYMIZE_JOB, 'a_0000000005' ) );
		$this->assertNotNull( $delayed );
		$this->assertSame( 'pending', $delayed['status'] );
		$this->assertGreaterThanOrEqual( Clock::plus( 7 * DAY_IN_SECONDS - 60 ), $delayed['run_after_gmt'] );
		$this->assertNotContains( (int) $delayed['id'], $jobs->runnable_ids( 100 ), 'Not due before the configured days' );
		$this->assertStringNotContainsString( 'ada@example.org', (string) wp_json_encode( $delayed['payload'] ) );

		// Running the action again does not create a second job (idempotent key).
		$this->assertSame( (int) $delayed['id'], (int) $this->job( $id )['result']['remote_id'] );

		Clock::freeze( time() + 8 * DAY_IN_SECONDS );
		$this->assertContains( (int) $delayed['id'], $jobs->runnable_ids( 100 ) );
		$this->run_job( (int) $delayed['id'] );
		$row = $this->plugin()->get( EntryRepository::class )->find( $entry );
		$this->assertNotEmpty( $row['anonymized_gmt'] );
		$values = $this->plugin()->get( EntryRepository::class )->values_for( $entry );
		$this->assertNotSame( 'ada@example.org', $values['f_mail01'] );
		$this->assertNotSame( 'Ada', $values['f_name01'] );

		$core = $this->plugin()->get( CoreActions::class );
		$this->assertSame( 'invalid_days', $core->anonymize_after( array( 'entry_id' => $entry, 'settings' => array( 'days' => '0' ) ) )['code'] );
		$this->assertSame( 'invalid_days', $core->anonymize_after( array( 'entry_id' => $entry, 'settings' => array( 'days' => '99999' ) ) )['code'] );
	}

	public function test_handoff_waits_for_manual_confirmation(): void {
		$form  = $this->form(
			array(
				self::action_instance( 'fake.send', 'a_00000000aa', array( 'email' => 'f_mail01' ) ),
				self::action_instance( CoreActions::HANDOFF, 'a_00000000bb', array(), array( 'target_instance' => 'a_00000000aa' ) ),
			)
		);
		$entry = $this->entry( $form );
		$this->plugin()->get( OutboxPlanner::class )->plan( $form, $entry );
		$jobs = $this->plugin()->get( JobRepository::class )->for_entry( $entry );
		$this->assertSame( array( CoreActions::HANDOFF ), array_values( array_filter( array_map( static fn( $j ) => $j['payload']['action_key'] ?? null, $jobs ) ) ), 'Target action is not queued automatically' );

		$core = $this->plugin()->get( CoreActions::class );
		$this->assertSame( array(), $core->pending_handoffs( $entry ), 'Nothing pending before the hand-over job ran' );
		$handoff_job = (int) array_values( array_filter( $jobs, static fn( $j ) => 'connector.action' === $j['type'] ) )[0]['id'];
		$this->assertSame( 'succeeded', $this->run_job( $handoff_job ) );
		$this->assertSame( 'handoff_pending', $this->job( $handoff_job )['result']['code'] );
		$this->assertCount( 0, $this->fake->calls );

		$notes = $this->plugin()->get( EntryRepository::class )->notes_for( $entry );
		$this->assertStringContainsString( 'Hand-over pending', (string) end( $notes )['message'] );
		$pending = $core->pending_handoffs( $entry );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'fake.send', $pending[0]['action_key'] );

		// Detail view shows the confirm button only to users who may confirm.
		$this->as_role( 'editor' );
		$this->assertWPError( $core->confirm_handoff( $entry, 'a_00000000bb' ) );
		$admin    = $this->as_admin();
		$sections = $core->detail_section( array(), $this->plugin()->get( EntryRepository::class )->find( $entry ) );
		$this->assertCount( 1, $sections );
		$this->assertStringContainsString( 'action=' . CoreActions::ADMIN_ACTION, html_entity_decode( $sections[0]['html'] ) );
		$this->assertStringContainsString( '_wpnonce=', $sections[0]['html'] );
		$this->assertSame( $sections[0]['html'], wp_kses_post( $sections[0]['html'] ), 'Section survives the kses filter of the detail view' );
		$this->assertGreaterThan( 0, $admin );

		$job_id = $core->confirm_handoff( $entry, 'a_00000000bb' );
		$this->assertIsInt( $job_id );
		$this->assertSame( 'fake.send', $this->job( $job_id )['payload']['action_key'] );
		$this->assertSame( 'succeeded', $this->run_job( $job_id ) );
		$this->assertCount( 1, $this->fake->calls );
		$this->assertSame( 'ada@example.org', $this->fake->calls[0]['values']['email'] );

		$this->assertSame( array(), $core->pending_handoffs( $entry ) );
		$again = $core->confirm_handoff( $entry, 'a_00000000bb' );
		$this->assertWPError( $again );
		$this->assertSame( 'conflict', $again->get_error_code() );
		$this->assertSame( array(), $core->detail_section( array(), $this->plugin()->get( EntryRepository::class )->find( $entry ) ) );
	}

	public function test_handoff_rejects_missing_or_core_targets(): void {
		$form = $this->form(
			array(
				self::action_instance( CoreActions::SET_STATUS, 'a_00000000cc', array(), array( 'status' => 'read' ) ),
				self::action_instance( CoreActions::HANDOFF, 'a_00000000dd', array(), array( 'target_instance' => 'a_00000000cc' ) ),
				self::action_instance( CoreActions::HANDOFF, 'a_00000000ee', array(), array( 'target_instance' => 'a_0000000fff' ) ),
			)
		);
		$entry = $this->entry( $form );
		$id    = $this->enqueue_action_job( $form, $entry, CoreActions::HANDOFF, 'a_00000000dd' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'handoff_target_invalid', $this->job( $id )['last_error_code'] );
		$id = $this->enqueue_action_job( $form, $entry, CoreActions::HANDOFF, 'a_00000000ee' );
		$this->assertSame( 'dead', $this->run_job( $id ) );
		$this->assertSame( 'handoff_target_missing', $this->job( $id )['last_error_code'] );
		$this->assertSame( array(), $this->plugin()->get( CoreActions::class )->pending_handoffs( $entry ) );
	}

	public function test_test_mode_has_no_side_effects(): void {
		foreach ( array( CoreActions::SET_STATUS => array( 'status' => 'archived' ), CoreActions::ANONYMIZE_AFTER => array( 'days' => '3' ), CoreActions::NOTIFY_ADMIN => array( 'to' => 'team@example.net' ), CoreActions::HANDOFF => array( 'target_instance' => 'a_0123456789' ) ) as $key => $settings ) {
			$result = $this->registry()->test_action( $key, $settings );
			$this->assertIsArray( $result, $key );
			$this->assertSame( 'success', $result['status'], $key );
		}
		$this->assertCount( 0, $this->mails );
	}
}
