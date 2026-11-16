<?php
/**
 * Registry, opt-in, compatibility and action registration (P8-02, P8-15, P8-25, P8-26, P8-36).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/ConnectorContractAssertions.php';
require_once __DIR__ . '/FakeConnector.php';

final class ConnectorRegistryTest extends TestCase {

	use ConnectorContractAssertions;

	public function set_up(): void {
		parent::set_up();
		delete_option( ConnectorRegistry::OPTION );
		$this->registry()->reset();
	}

	public function tear_down(): void {
		$this->registry()->reset();
		parent::tear_down();
	}

	private function install( object ...$connectors ): void {
		add_filter(
			'form_captain_connectors_v1',
			static function ( array $list ) use ( $connectors ): array {
				foreach ( $connectors as $c ) {
					$list += array( $c instanceof FakeConnector ? $c->key : 'broken' => $c );
				}
				return $list;
			}
		);
		$this->registry()->reload();
	}

	public function test_connector_is_detected_but_disabled_by_default(): void {
		$fake = new FakeConnector();
		$this->install( $fake );
		$this->assertConnectorShape( $fake );
		$this->assertSame( ConnectorRegistry::STATE_DETECTED, $this->registry()->state( 'fake' ) );
		$this->assertFalse( $this->registry()->is_runnable( 'fake' ) );
		$this->assertArrayHasKey( 'fake.send', $this->registry()->actions() );
		$this->assertSame( 'connector_disabled', $this->registry()->unavailable_reason( 'fake.send' ) );
	}

	public function test_opt_in_activation_and_independent_deactivation(): void {
		$this->install( new FakeConnector( 'one' ), new FakeConnector( 'two' ) );
		$this->as_admin();
		$this->assertTrue( $this->registry()->activate( 'one' ) );
		$this->assertSame( ConnectorRegistry::STATE_ACTIVE, $this->registry()->state( 'one' ) );
		$this->assertSame( ConnectorRegistry::STATE_DETECTED, $this->registry()->state( 'two' ) );
		$this->registry()->deactivate( 'one' );
		$this->assertSame( ConnectorRegistry::STATE_DISABLED, $this->registry()->state( 'one' ) );
		$stored = get_option( ConnectorRegistry::OPTION );
		$this->assertFalse( $stored['one']['enabled'] );
		$this->assertArrayNotHasKey( 'two', $stored );
	}

	public function test_incompatible_major_is_visible_and_cannot_be_activated(): void {
		$this->install( new FakeConnector( 'future', '2.0' ) );
		$info = $this->registry()->connector_info( 'future' );
		$this->assertSame( ConnectorRegistry::STATE_INCOMPATIBLE, $info['state'] );
		$this->assertSame( 'incompatible_contract', $info['code'] );
		$res = $this->registry()->activate( 'future' );
		$this->assertWPError( $res );
		$this->assertSame( 'incompatible_contract', $res->get_error_code() );
		$this->assertArrayNotHasKey( 'future.send', $this->registry()->actions(), 'Incompatible connectors must not register actions' );
	}

	public function test_contract_version_rules(): void {
		$this->assertTrue( ConnectorRegistry::contract_compatible( '1.0', '1.0.0' ) );
		$this->assertTrue( ConnectorRegistry::contract_compatible( '^1.0', '1.4.2' ) );
		$this->assertTrue( ConnectorRegistry::contract_compatible( '1', '1.0.0' ) );
		$this->assertFalse( ConnectorRegistry::contract_compatible( '^1.5', '1.0.0' ), 'Connector needs a newer minor' );
		$this->assertFalse( ConnectorRegistry::contract_compatible( '2.0', '1.9.0' ) );
		$this->assertFalse( ConnectorRegistry::contract_compatible( '0.9', '1.0.0' ) );
		$this->assertFalse( ConnectorRegistry::contract_compatible( 'latest', '1.0.0' ) );
	}

	public function test_invalid_registrations_are_rejected(): void {
		add_filter(
			'form_captain_connectors_v1',
			static fn( array $l ): array => $l + array(
				'Bad Key'  => new FakeConnector( 'Bad Key' ),
				'plain'    => new \stdClass(),
				'mismatch' => new FakeConnector( 'other' ),
			)
		);
		$this->registry()->reload();
		$all = $this->registry()->all();
		$this->assertArrayNotHasKey( 'Bad Key', $all );
		$this->assertSame( 'invalid_connector', $all['plain']['code'] );
		$this->assertSame( 'invalid_connector', $all['mismatch']['code'] );
		$this->assertFalse( $this->registry()->is_runnable( 'mismatch' ) );
	}

	public function test_requirements_block_activation(): void {
		$this->install( new FakeConnector( 'needy', '1.0', false ) );
		$res = $this->registry()->activate( 'needy' );
		$this->assertWPError( $res );
		$this->assertSame( 'requirements_not_met', $res->get_error_code() );
		$this->assertSame( 'requirements_not_met', $this->registry()->unavailable_reason( 'needy.send' ) );
	}

	public function test_missing_connector_with_opt_in_is_reported(): void {
		update_option( ConnectorRegistry::OPTION, array( 'gone' => array( 'enabled' => true ) ) );
		$this->registry()->reload();
		$info = $this->registry()->connector_info( 'gone' );
		$this->assertSame( ConnectorRegistry::STATE_MISSING, $info['state'] );
		$this->assertSame( 'connector_not_found', $info['code'] );
	}

	public function test_register_action_validation(): void {
		$cb = static fn( array $job ): array => array( 'status' => 'success', 'code' => 'ok' );
		$ok = array( 'label' => 'X', 'contract' => '^1.0', 'connector' => null );
		$this->assertTrue( form_captain_register_action_v1( 'custom.valid', $ok, $cb ) );
		$this->assertSame( 'invalid_request', form_captain_register_action_v1( 'Custom.Bad-Key', $ok, $cb )->get_error_code() );
		$this->assertSame( 'invalid_request', form_captain_register_action_v1( 'custom.valid', $ok, $cb )->get_error_code(), 'duplicate' );
		$this->assertSame( 'invalid_request', form_captain_register_action_v1( 'custom.nolabel', array( 'contract' => '^1.0' ), $cb )->get_error_code() );
		$this->assertSame( 'invalid_request', form_captain_register_action_v1( 'custom.nocontract', array( 'label' => 'x' ), $cb )->get_error_code() );
		$this->assertSame( 'incompatible_contract', form_captain_register_action_v1( 'custom.future', array( 'label' => 'x', 'contract' => '^2.0' ), $cb )->get_error_code() );
		$this->assertSame( 'invalid_request', form_captain_register_action_v1( 'custom.badmap', $ok + array( 'mapping' => array( array( 'key' => 'Bad Key' ) ) ), $cb )->get_error_code() );
		$this->assertSame( '', $this->registry()->unavailable_reason( 'custom.valid' ), 'Actions without connector are available' );
	}

	public function test_actions_for_editor_shape(): void {
		$this->install( new FakeConnector() );
		$list = array_values( array_filter( $this->registry()->actions_for_editor(), static fn( $a ) => 'fake.send' === $a['key'] ) );
		$this->assertCount( 1, $list );
		$item = $list[0];
		foreach ( array( 'key', 'label', 'connector', 'settings', 'mapping', 'available', 'reason' ) as $k ) {
			$this->assertArrayHasKey( $k, $item );
		}
		$this->assertFalse( $item['available'] );
		$this->assertSame( 'connector_disabled', $item['reason'] );
		$this->assertSame( 'email', $item['mapping'][0]['key'] );
		$this->as_admin();
		$this->registry()->activate( 'fake' );
		$item = array_values( array_filter( $this->registry()->actions_for_editor(), static fn( $a ) => 'fake.send' === $a['key'] ) )[0];
		$this->assertTrue( $item['available'] );
	}

	public function test_entitlement_requirement_is_reported(): void {
		form_captain_register_action_v1(
			'pro.workflow',
			array( 'label' => 'Pro', 'contract' => '^1.0', 'requires' => array( 'entitlement' => 'form.workflows' ) ),
			static fn( array $job ): array => array( 'status' => 'success', 'code' => 'ok' )
		);
		$this->assertSame( 'entitlement_missing', $this->registry()->unavailable_reason( 'pro.workflow' ) );
		add_filter( 'form_captain_entitlement_granted', static fn( $granted, $e ) => 'form.workflows' === $e, 10, 2 );
		$this->assertSame( '', $this->registry()->unavailable_reason( 'pro.workflow' ) );
	}

	public function test_every_diagnostic_code_has_a_next_step(): void {
		foreach ( ConnectorRegistry::diagnostic_codes() as $code => $info ) {
			$this->assertNotSame( '', $info['label'], $code );
			$this->assertNotSame( '', $info['next'], $code );
		}
		$this->assertNotSame( '', ConnectorRegistry::next_step( 'some_vendor_code' ) );
	}
}
