<?php
/**
 * Public connector contract v1 (docs/spec/api-v1.md, docs/CONNECTORS.md).
 *
 * This interface is part of the public API. Changes within v1 are additive only.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Integrations;

interface ConnectorInterface {

	/** Stable key, ^[a-z0-9_]+$ (e.g. "reference"). */
	public function key(): string;

	/** Translated, human readable name. */
	public function label(): string;

	/** Version of the connector plugin. */
	public function version(): string;

	/** Supported contract version, e.g. "1.0". The major must match the core contract major. */
	public function contract(): string;

	/**
	 * Action keys this connector provides.
	 *
	 * @return string[]
	 */
	public function capabilities(): array;

	/**
	 * Runtime requirements, e.g. a configured remote endpoint.
	 *
	 * @return array{ok:bool,messages:string[]}
	 */
	public function requirements(): array;

	/**
	 * Global connector settings (definitions only – secrets are stored by the connector).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_schema(): array;

	/** Registers the connector's form actions via form_captain_register_action_v1(). */
	public function register_actions(): void;
}
