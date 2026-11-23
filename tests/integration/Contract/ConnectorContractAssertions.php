<?php
/**
 * Contract test kit v1 (P8-36): reusable assertions and helpers for connector authors.
 *
 * Connector plugins can copy or require this trait in their own WP integration tests. It uses only
 * the public contract (functions, filters, ConnectorInterface) plus documented fixtures.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Integrations\ConnectorInterface;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Plugin;

trait ConnectorContractAssertions {

	/** @return array<string,mixed> */
	protected static function contract_fixture( string $name ): array {
		$file = dirname( __DIR__, 2 ) . '/fixtures/contract/v1/' . $name . '.json';
		return (array) json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	protected function assertConnectorShape( ConnectorInterface $connector ): void {
		$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $connector->key(), 'Connector key format' );
		$this->assertNotSame( '', $connector->label() );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+/', $connector->version() );
		$this->assertTrue( ConnectorRegistry::contract_compatible( $connector->contract() ), 'Connector contract must be compatible with core ' . FORM_CAPTAIN_CONTRACT_VERSION );
		foreach ( $connector->capabilities() as $action ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $action );
		}
		$req = $connector->requirements();
		$this->assertArrayHasKey( 'ok', $req );
		$this->assertIsBool( $req['ok'] );
		$this->assertIsArray( $req['messages'] ?? array() );
		$this->assertIsArray( $connector->settings_schema() );
	}

	/** Entry-event payload (api-v1.md): required keys present, unknown keys tolerated, no forbidden keys. */
	protected function assertEntryEventShape( array $event ): void {
		foreach ( array( 'contract', 'entry_id', 'form_id', 'form_public_id', 'form_version', 'status', 'created_gmt', 'request_id', 'context', 'values' ) as $key ) {
			$this->assertArrayHasKey( $key, $event, 'Entry event misses ' . $key );
		}
		$this->assertSame( 1, (int) explode( '.', (string) $event['contract'] )[0], 'Event contract major' );
		$this->assertIsInt( $event['entry_id'] );
		$this->assertIsArray( $event['values'] );
		foreach ( array( 'ip', 'ip_address', 'user_agent', 'remote_addr', 'password' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $event );
		}
	}

	/** Consent-withdrawn event payload (api-v1.md): IDs, time, source – never values or personal data. */
	protected function assertConsentWithdrawnEventShape( array $event ): void {
		foreach ( array( 'contract', 'entry_id', 'form_id', 'form_public_id', 'withdrawn_gmt', 'source' ) as $key ) {
			$this->assertArrayHasKey( $key, $event, 'Consent event misses ' . $key );
		}
		$this->assertSame( 1, (int) explode( '.', (string) $event['contract'] )[0], 'Event contract major' );
		$this->assertIsInt( $event['entry_id'] );
		$this->assertIsInt( $event['form_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $event['withdrawn_gmt'] );
		foreach ( array( 'values', 'context', 'ip', 'ip_address', 'user_agent', 'email', 'consent' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $event );
		}
	}

	/** Action callback argument ($job) as documented in api-v1.md. */
	protected function assertJobArrayShape( array $job ): void {
		foreach ( array( 'form_id', 'entry_id', 'idempotency_key', 'settings', 'values', 'context', 'test_mode' ) as $key ) {
			$this->assertArrayHasKey( $key, $job, 'Job array misses ' . $key );
		}
		$this->assertIsBool( $job['test_mode'] );
		$this->assertIsArray( $job['values'] );
		if ( ! $job['test_mode'] ) {
			// entry_created; since contract 1.4.0 also the keys of the later events (api-v1.md).
			$this->assertMatchesRegularExpression( '/^form-captain:\d+:\d+:(?:(?:entry_status_changed:[a-z]+-[a-z]+:\d+|consent_withdrawn|entry_deleted):)?[a-z0-9_]+\.[a-z0-9_]+:[a-z0-9_]+$/', (string) $job['idempotency_key'] );
		}
	}

	protected function assertActionResultShape( array $result ): void {
		$this->assertContains( $result['status'] ?? null, array( 'success', 'retry', 'fail' ) );
		$this->assertIsString( $result['code'] ?? null );
		if ( isset( $result['remote_id'] ) ) {
			$this->assertLessThanOrEqual( 191, strlen( (string) $result['remote_id'] ) );
		}
	}

	/** Only mapped keys reach the connector. */
	protected function assertOnlyMappedValues( array $job, array $allowed_keys ): void {
		$this->assertSame( array(), array_diff( array_keys( $job['values'] ), $allowed_keys ), 'Connector received unmapped values' );
	}

	/** Calling the same job twice must give the same outcome (idempotency, P8-16/P8-29). */
	protected function assertIdempotentCallback( callable $callback, array $job ): void {
		$first  = $callback( $job );
		$second = $callback( $job );
		$this->assertActionResultShape( $first );
		$this->assertSame( $first['status'], $second['status'] );
		$this->assertSame( $first['remote_id'] ?? null, $second['remote_id'] ?? null );
	}

	/* --- helpers ---------------------------------------------------------- */

	protected function registry(): ConnectorRegistry {
		return Plugin::instance()->get( ConnectorRegistry::class );
	}

	/**
	 * Inserts an entry with values directly (no submission pipeline needed).
	 *
	 * @param array<string,mixed>                     $form
	 * @param array<string,string|array<int,string>> $values field_id => value
	 */
	protected function make_entry( array $form, array $values, array $extra = array() ): int {
		$repo = Plugin::instance()->get( EntryRepository::class );
		$id   = $repo->insert(
			array_merge(
				array(
					'form_id'         => (int) $form['id'],
					'form_version_id' => (int) $form['published_version_id'],
					'status'          => 'new',
					'request_key'     => hash( 'sha256', uniqid( 'fc', true ) ),
				),
				$extra
			)
		);
		$repo->insert_values( $id, $values );
		return $id;
	}

	/** Enqueues the connector.action job like the OutboxPlanner does. */
	protected function enqueue_action_job( array $form, int $entry_id, string $action_key, string $instance_id, ?int $max_attempts = null ): int {
		return Plugin::instance()->get( JobRepository::class )->enqueue(
			ConnectorRegistry::JOB_TYPE,
			sprintf( 'form-captain:%d:%d:%s:%s', get_current_blog_id(), $entry_id, $action_key, $instance_id ),
			array(
				'action_key'  => $action_key,
				'instance_id' => $instance_id,
			),
			$entry_id,
			(int) $form['id'],
			0,
			$max_attempts
		);
	}

	/** @return array<string,mixed> */
	protected static function action_instance( string $action_key, string $instance_id, array $mapping, array $settings = array(), bool $enabled = true ): array {
		return array(
			'instance_id' => $instance_id,
			'action_key'  => $action_key,
			'enabled'     => $enabled,
			'settings'    => $settings,
			'mapping'     => $mapping,
		);
	}
}
