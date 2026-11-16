<?php
/**
 * Configurable test double implementing the public connector interface.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Integrations\ConnectorInterface;

final class FakeConnector implements ConnectorInterface {

	/** @var array<int,array<string,mixed>> received $job arrays */
	public array $calls = array();

	/** @var callable|null */
	public $behaviour = null;

	/** @var array<string,array<string,mixed>> action key => definition overrides */
	public array $actions;

	public function __construct(
		public string $key = 'fake',
		public string $contract = '1.0',
		public bool $requirements_ok = true,
		?array $actions = null
	) {
		$this->actions = $actions ?? array(
			$key . '.send' => array(
				'mapping' => array(
					array( 'key' => 'email', 'label' => 'E-Mail', 'types' => array( 'email' ), 'required' => true ),
					array( 'key' => 'name', 'label' => 'Name', 'types' => array( 'text' ) ),
				),
			),
		);
	}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return 'Fake ' . $this->key;
	}

	public function version(): string {
		return '0.1.0';
	}

	public function contract(): string {
		return $this->contract;
	}

	public function capabilities(): array {
		return array_keys( $this->actions );
	}

	public function requirements(): array {
		return array(
			'ok'       => $this->requirements_ok,
			'messages' => $this->requirements_ok ? array() : array( 'API key missing' ),
		);
	}

	public function settings_schema(): array {
		return array();
	}

	public function register_actions(): void {
		foreach ( $this->actions as $action => $def ) {
			form_captain_register_action_v1(
				$action,
				array_merge(
					array(
						'label'     => 'Send ' . $action,
						'version'   => '1.0.0',
						'contract'  => '^1.0',
						'connector' => $this->key,
						'settings'  => array( array( 'key' => 'list', 'type' => 'text', 'label' => 'List' ) ),
					),
					$def
				),
				array( $this, 'callback' )
			);
		}
	}

	/** @param array<string,mixed> $job */
	public function callback( array $job ): mixed {
		$this->calls[] = $job;
		if ( $this->behaviour ) {
			return ( $this->behaviour )( $job );
		}
		return array( 'status' => 'success', 'code' => 'ok', 'remote_id' => 'R-' . $job['entry_id'] );
	}
}
