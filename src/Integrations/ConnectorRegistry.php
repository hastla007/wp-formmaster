<?php
/**
 * Connector and form action registry (docs/spec/api-v1.md, ADR 0006, P8-02, P8-25…P8-32).
 *
 * - Connectors are collected from the public filter `form_captain_connectors_v1` after
 *   `form_captain_loaded_v1` (init, priority 5) and validated (key, contract major, requirements).
 * - Every connector is disabled until an administrator opts in (option `form_captain_connectors`).
 * - Form actions are registered with form_captain_register_action_v1() and executed exclusively by
 *   the outbox worker (job type `connector.action`), never inside the submission request.
 *
 * Deactivation semantics (P8-32): open jobs are not cancelled. They stay in the queue and the handler
 * answers `retry` with code `connector_disabled` until the attempts are exhausted (dead-letter). When
 * the connector is activated again, dead jobs that died with `connector_disabled` are re-queued.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Integrations;

use FormCaptain\Jobs\JobResult;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;

final class ConnectorRegistry {

	public const OPTION   = 'form_captain_connectors';
	public const JOB_TYPE = 'connector.action';

	public const STATE_DETECTED     = 'detected';
	public const STATE_DISABLED     = 'disabled';
	public const STATE_ACTIVE       = 'active';
	public const STATE_INCOMPATIBLE = 'incompatible';
	public const STATE_MISSING      = 'missing';

	private const KEY_PATTERN        = '/^[a-z0-9_]+$/';
	private const ACTION_KEY_PATTERN = '/^[a-z0-9_]+\.[a-z0-9_]+$/';

	/** Field types that are never handed to connectors, whatever the mapping says (P8-18). */
	public const BLOCKED_FIELD_TYPES = array( 'file', 'password', 'payment', 'signature', 'html' );

	private const SETTING_TYPES = array( 'text', 'textarea', 'email', 'url', 'number', 'select', 'checkbox' );

	/** @var array<string,ConnectorInterface> valid (compatible) connectors */
	private array $connectors = array();

	/** @var array<string,array<string,mixed>> key => diagnostic info for every connector seen */
	private array $seen = array();

	/** @var array<string,array{definition:array<string,mixed>,callback:callable}> */
	private array $actions = array();

	/** @var array<string,true> connectors whose register_actions() already ran */
	private array $booted = array();

	private bool $collected = false;

	/** True while the built-in core actions register (reserved namespace `core.*`, P8-19). */
	private bool $registering_core = false;

	public function __construct( private Plugin $plugin ) {}

	public function register( Plugin $plugin ): void {
		add_action( 'init', array( $this, 'boot' ), 5 );
		add_filter( 'form_captain_job_handlers', array( $this, 'add_job_handler' ) );
		$plugin->get( CoreActions::class )->register( $plugin );
	}

	/**
	 * @param array<string,callable> $handlers
	 * @return array<string,callable>
	 */
	public function add_job_handler( array $handlers ): array {
		$handlers[ self::JOB_TYPE ] = array( $this, 'handle_job' );
		return $handlers;
	}

	/** Collects connectors once the core is loaded and lets compatible ones register their actions. */
	public function boot(): void {
		if ( ! self::core_loaded() ) {
			return;
		}
		$this->collect( true );
	}

	/** Re-reads the connector filter (e.g. after a connector plugin was loaded late, tests). */
	public function reload(): void {
		$this->collect( true );
	}

	/** Test helper: forget everything (actions included). */
	public function reset(): void {
		$this->connectors = array();
		$this->seen       = array();
		$this->actions    = array();
		$this->booted     = array();
		$this->collected  = false;
	}

	public static function core_loaded(): bool {
		return did_action( 'form_captain_loaded_v1' ) > 0 || doing_action( 'form_captain_loaded_v1' );
	}

	private function collect( bool $force = false ): void {
		if ( $this->collected && ! $force ) {
			return;
		}
		if ( ! self::core_loaded() ) {
			return;
		}
		$this->collected  = true;
		$this->connectors = array();
		$this->seen       = array();
		$raw              = apply_filters( 'form_captain_connectors_v1', array() );
		foreach ( is_array( $raw ) ? $raw : array() as $key => $connector ) {
			$this->validate_connector( (string) $key, $connector );
		}
		$this->register_core_actions();
		foreach ( $this->connectors as $key => $connector ) {
			if ( isset( $this->booted[ $key ] ) ) {
				continue;
			}
			$this->booted[ $key ] = true;
			try {
				$connector->register_actions();
			} catch ( \Throwable $e ) {
				$this->seen[ $key ]['code']    = 'register_failed';
				$this->seen[ $key ]['message'] = Logger::redact( $e->getMessage() );
				Logger::log( 'error', 'connector_register_failed', $key );
			}
		}
	}

	/** Built-in safe actions (P8-19): always available, no opt-in, enabled per form only. */
	private function register_core_actions(): void {
		if ( isset( $this->booted[ CoreActions::CONNECTOR ] ) ) {
			return;
		}
		$this->booted[ CoreActions::CONNECTOR ] = true;
		$this->registering_core                 = true;
		try {
			$this->plugin->get( CoreActions::class )->register_actions( $this );
		} finally {
			$this->registering_core = false;
		}
	}

	private function validate_connector( string $key, mixed $connector ): void {
		$info = array(
			'key'          => $key,
			'label'        => $key,
			'version'      => '',
			'contract'     => '',
			'capabilities' => array(),
			'requirements' => array( 'ok' => false, 'messages' => array() ),
			'settings'     => array(),
			'compatible'   => false,
			'code'         => '',
			'message'      => '',
		);
		if ( CoreActions::CONNECTOR === $key || ! preg_match( self::KEY_PATTERN, $key ) || ! $connector instanceof ConnectorInterface ) {
			$info['code']    = 'invalid_connector';
			$info['message'] = __( 'The registered object does not implement the connector interface or uses an invalid key.', 'form-captain' );
			if ( preg_match( self::KEY_PATTERN, $key ) ) {
				$this->seen[ $key ] = $info;
			}
			return;
		}
		try {
			$info['label']        = (string) $connector->label();
			$info['version']      = (string) $connector->version();
			$info['contract']     = (string) $connector->contract();
			$info['capabilities'] = array_values( array_filter( (array) $connector->capabilities(), 'is_string' ) );
			$info['settings']     = (array) $connector->settings_schema();
			$req                  = (array) $connector->requirements();
			$info['requirements'] = array(
				'ok'       => ! empty( $req['ok'] ),
				'messages' => array_map( 'strval', (array) ( $req['messages'] ?? array() ) ),
			);
			if ( $connector->key() !== $key ) {
				$info['code']    = 'invalid_connector';
				$info['message'] = __( 'The connector key does not match the registered key.', 'form-captain' );
			} elseif ( ! self::contract_compatible( $info['contract'] ) ) {
				$info['code']    = 'incompatible_contract';
				$info['message'] = sprintf(
					/* translators: 1: contract version required by the connector, 2: contract version of Form Captain */
					__( 'The connector expects contract %1$s, Form Captain provides %2$s.', 'form-captain' ),
					$info['contract'],
					FORM_CAPTAIN_CONTRACT_VERSION
				);
			} else {
				$info['compatible'] = true;
			}
		} catch ( \Throwable $e ) {
			$info['code']    = 'invalid_connector';
			$info['message'] = Logger::redact( $e->getMessage() );
		}
		$this->seen[ $key ] = $info;
		if ( $info['compatible'] ) {
			$this->connectors[ $key ] = $connector;
		}
	}

	/**
	 * Contract constraint check: '1.0', '^1.0', '~1.2', '>=1.0', '1' → same major and core >= required.
	 */
	public static function contract_compatible( string $constraint, ?string $core = null ): bool {
		$core = $core ?? FORM_CAPTAIN_CONTRACT_VERSION;
		if ( ! preg_match( '/^\s*(?:\^|~|>=|v)?\s*(\d+)(?:\.(\d+))?(?:\.(\d+))?\s*$/', $constraint, $m ) ) {
			return false;
		}
		$parts    = array_map( 'intval', explode( '.', $core ) + array( 0, 0, 0 ) );
		$required = array( (int) $m[1], (int) ( $m[2] ?? 0 ), (int) ( $m[3] ?? 0 ) );
		if ( $required[0] !== $parts[0] ) {
			return false;
		}
		return version_compare( implode( '.', $parts ), implode( '.', $required ), '>=' );
	}

	// ---------------------------------------------------------------------
	// Opt-in state (P8-26)
	// ---------------------------------------------------------------------

	/** @return array<string,array<string,mixed>> */
	private function optins(): array {
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) ? $opt : array();
	}

	public function is_enabled( string $key ): bool {
		return ! empty( $this->optins()[ $key ]['enabled'] );
	}

	/** Connector may run actions: registered, compatible, requirements met and enabled. */
	public function is_runnable( string $key ): bool {
		$this->collect();
		return isset( $this->connectors[ $key ] ) && ! empty( $this->seen[ $key ]['requirements']['ok'] ) && $this->is_enabled( $key );
	}

	public function state( string $key ): string {
		$this->collect();
		if ( ! isset( $this->seen[ $key ] ) ) {
			return isset( $this->optins()[ $key ] ) ? self::STATE_MISSING : '';
		}
		if ( ! $this->seen[ $key ]['compatible'] ) {
			return self::STATE_INCOMPATIBLE;
		}
		if ( $this->is_enabled( $key ) ) {
			return self::STATE_ACTIVE;
		}
		return isset( $this->optins()[ $key ] ) ? self::STATE_DISABLED : self::STATE_DETECTED;
	}

	/**
	 * Activates a connector (caller checks capability + nonce).
	 *
	 * @return true|\WP_Error
	 */
	public function activate( string $key ): bool|\WP_Error {
		$this->collect();
		if ( ! isset( $this->seen[ $key ] ) ) {
			return new \WP_Error( 'connector_not_found', __( 'This connector is not installed.', 'form-captain' ) );
		}
		if ( ! $this->seen[ $key ]['compatible'] ) {
			return new \WP_Error( 'incompatible_contract', (string) $this->seen[ $key ]['message'] );
		}
		if ( empty( $this->seen[ $key ]['requirements']['ok'] ) ) {
			return new \WP_Error( 'requirements_not_met', implode( ' ', $this->seen[ $key ]['requirements']['messages'] ) ?: __( 'The connector requirements are not met.', 'form-captain' ) );
		}
		$this->store_optin( $key, true );
		$requeued = $this->requeue_disabled_jobs( $key );
		Logger::log( 'info', 'connector_activated', $key . ' requeued=' . $requeued );
		return true;
	}

	public function deactivate( string $key ): bool {
		$this->store_optin( $key, false );
		Logger::log( 'info', 'connector_deactivated', $key );
		return true;
	}

	/** Removes the stored opt-in of a connector that is no longer installed. */
	public function forget( string $key ): void {
		$opt = $this->optins();
		unset( $opt[ $key ] );
		update_option( self::OPTION, $opt, false );
	}

	private function store_optin( string $key, bool $enabled ): void {
		$opt         = $this->optins();
		$opt[ $key ] = array(
			'enabled'     => $enabled,
			'changed_gmt' => Clock::now(),
			'user_id'     => get_current_user_id(),
		);
		update_option( self::OPTION, $opt, false );
	}

	/** Re-queues dead jobs of this connector that died only because it was disabled. */
	private function requeue_disabled_jobs( string $key ): int {
		$jobs  = $this->plugin->get( JobRepository::class );
		$count = 0;
		$page  = 1;
		do {
			$list = $jobs->list( array( 'status' => 'dead', 'type_prefix' => self::JOB_TYPE, 'per_page' => 200, 'page' => $page ) );
			foreach ( $list['items'] as $job ) {
				if ( 'connector_disabled' !== ( $job['last_error_code'] ?? '' ) ) {
					continue;
				}
				$action = $this->actions[ (string) ( $job['payload']['action_key'] ?? '' ) ] ?? null;
				if ( $action && $key === $action['definition']['connector'] && $jobs->retry( (int) $job['id'] ) ) {
					++$count;
				}
			}
			++$page;
			$more = 200 === count( $list['items'] );
		} while ( $more && $page < 50 );
		return $count;
	}

	// ---------------------------------------------------------------------
	// Listing / diagnostics (P8-04, P8-10, P8-31)
	// ---------------------------------------------------------------------

	/**
	 * All connectors known to the site, including missing ones that still have an opt-in record.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		$this->collect();
		$out = array();
		foreach ( $this->seen as $key => $info ) {
			$info['state']   = $this->state( $key );
			$info['actions'] = array_keys( array_filter( $this->actions, static fn( $a ) => $key === $a['definition']['connector'] ) );
			$out[ $key ]     = $info;
		}
		foreach ( $this->optins() as $key => $optin ) {
			if ( isset( $out[ $key ] ) || ! preg_match( self::KEY_PATTERN, (string) $key ) ) {
				continue;
			}
			$out[ $key ] = array(
				'key'          => $key,
				'label'        => $key,
				'version'      => '',
				'contract'     => '',
				'capabilities' => array(),
				'requirements' => array( 'ok' => false, 'messages' => array() ),
				'settings'     => array(),
				'compatible'   => false,
				'code'         => 'connector_not_found',
				'message'      => __( 'The connector plugin is not active or was removed.', 'form-captain' ),
				'state'        => self::STATE_MISSING,
				'actions'      => array(),
			);
		}
		ksort( $out );
		return $out;
	}

	/** @return array<string,mixed>|null */
	public function connector_info( string $key ): ?array {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Stable diagnostic codes with a translated explanation and next step (P8-10, P8-21).
	 *
	 * @return array<string,array{label:string,next:string}>
	 */
	public static function diagnostic_codes(): array {
		return array(
			'connector_not_found'   => array(
				'label' => __( 'Connector plugin missing', 'form-captain' ),
				'next'  => __( 'Install and activate the connector plugin, or remove the action from the form.', 'form-captain' ),
			),
			'connector_disabled'    => array(
				'label' => __( 'Connector disabled', 'form-captain' ),
				'next'  => __( 'Activate the connector under Form Captain → Integrations. Waiting jobs are retried automatically.', 'form-captain' ),
			),
			'incompatible_contract' => array(
				'label' => __( 'Incompatible contract version', 'form-captain' ),
				'next'  => __( 'Update the connector or Form Captain so that both support the same contract major version.', 'form-captain' ),
			),
			'requirements_not_met'  => array(
				'label' => __( 'Requirements not met', 'form-captain' ),
				'next'  => __( 'Complete the connector configuration shown in its requirements.', 'form-captain' ),
			),
			'entitlement_missing'   => array(
				'label' => __( 'Pro feature not available', 'form-captain' ),
				'next'  => __( 'This action needs Form Captain Pro with a valid licence. Renew or activate the licence, or disable the action.', 'form-captain' ),
			),
			'action_not_found'      => array(
				'label' => __( 'Workflow or action missing', 'form-captain' ),
				'next'  => __( 'The plugin that provided this action is inactive. Reactivate it or remove the action from the form.', 'form-captain' ),
			),
			'action_disabled'       => array(
				'label' => __( 'Action disabled in form', 'form-captain' ),
				'next'  => __( 'Enable the action in the form editor if it should run.', 'form-captain' ),
			),
			'action_removed'        => array(
				'label' => __( 'Action removed from form', 'form-captain' ),
				'next'  => __( 'No action needed. The job was stopped because the form no longer contains this action.', 'form-captain' ),
			),
			'mapping_incomplete'    => array(
				'label' => __( 'Field mapping incomplete', 'form-captain' ),
				'next'  => __( 'Map all required fields of the action in the form editor, then retry the job.', 'form-captain' ),
			),
			'mapping_type_mismatch' => array(
				'label' => __( 'Mapped field has the wrong type', 'form-captain' ),
				'next'  => __( 'Choose a field of a supported type in the action mapping, then retry the job.', 'form-captain' ),
			),
			'sensitive_field'       => array(
				'label' => __( 'Sensitive field blocked', 'form-captain' ),
				'next'  => __( 'Sensitive fields are never sent unless the action explicitly allows them. Map a different field.', 'form-captain' ),
			),
			'entry_deleted'         => array(
				'label' => __( 'Entry deleted', 'form-captain' ),
				'next'  => __( 'No action needed. The entry was deleted before the job ran.', 'form-captain' ),
			),
			'entry_anonymized'      => array(
				'label' => __( 'Entry anonymized', 'form-captain' ),
				'next'  => __( 'No action needed. Personal data was removed before the job ran.', 'form-captain' ),
			),
			'consent_withdrawn'     => array(
				'label' => __( 'Consent withdrawn', 'form-captain' ),
				'next'  => __( 'No action needed. The job was stopped because consent was withdrawn.', 'form-captain' ),
			),
			'timeout'               => array(
				'label' => __( 'Timeout', 'form-captain' ),
				'next'  => __( 'The target system did not answer in time. The job is retried automatically; check the target system if it keeps failing.', 'form-captain' ),
			),
			'exception'             => array(
				'label' => __( 'Connector error', 'form-captain' ),
				'next'  => __( 'The connector raised an error. The job is retried automatically; contact the connector vendor if it keeps failing.', 'form-captain' ),
			),
			'invalid_result'        => array(
				'label' => __( 'Invalid connector response', 'form-captain' ),
				'next'  => __( 'The connector returned an invalid result. Update the connector plugin.', 'form-captain' ),
			),
			'lock_expired'          => array(
				'label' => __( 'Worker interrupted', 'form-captain' ),
				'next'  => __( 'The job is retried automatically. If this repeats, check PHP time limits and the cron setup.', 'form-captain' ),
			),
			'queue_timeout'         => array(
				'label' => __( 'Queue delayed', 'form-captain' ),
				'next'  => __( 'Jobs are waiting longer than 15 minutes. Check WP-Cron or run "wp form-captain jobs run" from a system cron.', 'form-captain' ),
			),
		);
	}

	/** Next step for any job/connector code; generic fallback for connector-specific codes. */
	public static function next_step( string $code ): string {
		$codes = self::diagnostic_codes();
		if ( isset( $codes[ $code ] ) ) {
			return $codes[ $code ]['next'];
		}
		return __( 'Check the connector documentation for this code. You can retry the job once the cause is fixed.', 'form-captain' );
	}

	// ---------------------------------------------------------------------
	// Action registry (form_captain_register_action_v1)
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $definition
	 * @return true|\WP_Error
	 */
	public function register_action( string $key, array $definition, callable $callback ): bool|\WP_Error {
		if ( ! self::core_loaded() ) {
			return new \WP_Error( 'not_ready', __( 'Actions can only be registered in or after form_captain_loaded_v1.', 'form-captain' ) );
		}
		if ( ! preg_match( self::ACTION_KEY_PATTERN, $key ) ) {
			return new \WP_Error( 'invalid_request', __( 'Invalid action key. Expected "connector.action" in lowercase letters, digits and underscores.', 'form-captain' ) );
		}
		if ( str_starts_with( $key, CoreActions::CONNECTOR . '.' ) !== $this->registering_core ) {
			return new \WP_Error( 'invalid_request', __( 'The action namespace "core" is reserved for built-in actions.', 'form-captain' ) );
		}
		if ( isset( $this->actions[ $key ] ) ) {
			return new \WP_Error( 'invalid_request', __( 'An action with this key is already registered.', 'form-captain' ) );
		}
		$normalized = self::normalize_definition( $definition );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$this->actions[ $key ] = array(
			'definition' => $normalized,
			'callback'   => $callback,
		);
		return true;
	}

	/**
	 * @param array<string,mixed> $d
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize_definition( array $d ): array|\WP_Error {
		$label = isset( $d['label'] ) && is_string( $d['label'] ) ? trim( $d['label'] ) : '';
		if ( '' === $label ) {
			return new \WP_Error( 'invalid_request', __( 'The action definition needs a label.', 'form-captain' ) );
		}
		$contract = isset( $d['contract'] ) ? (string) $d['contract'] : '';
		if ( '' === $contract ) {
			return new \WP_Error( 'invalid_request', __( 'The action definition needs a contract constraint such as "^1.0".', 'form-captain' ) );
		}
		if ( ! self::contract_compatible( $contract ) ) {
			return new \WP_Error( 'incompatible_contract', __( 'The action expects an incompatible contract version.', 'form-captain' ) );
		}
		$connector = $d['connector'] ?? null;
		if ( null !== $connector && ( ! is_string( $connector ) || ! preg_match( self::KEY_PATTERN, $connector ) ) ) {
			return new \WP_Error( 'invalid_request', __( 'Invalid connector key in action definition.', 'form-captain' ) );
		}
		$settings = array();
		foreach ( (array) ( $d['settings'] ?? array() ) as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['key'] ) || ! preg_match( self::KEY_PATTERN, (string) $s['key'] ) ) {
				return new \WP_Error( 'invalid_request', __( 'Every action setting needs a valid key.', 'form-captain' ) );
			}
			$type       = in_array( $s['type'] ?? 'text', self::SETTING_TYPES, true ) ? (string) ( $s['type'] ?? 'text' ) : 'text';
			$settings[] = array(
				'key'      => (string) $s['key'],
				'type'     => $type,
				'label'    => (string) ( $s['label'] ?? $s['key'] ),
				'required' => ! empty( $s['required'] ),
				'options'  => array_map( 'strval', (array) ( $s['options'] ?? array() ) ),
				'default'  => isset( $s['default'] ) && is_scalar( $s['default'] ) ? (string) $s['default'] : '',
			);
		}
		$mapping = array();
		foreach ( (array) ( $d['mapping'] ?? array() ) as $m ) {
			if ( ! is_array( $m ) || ! isset( $m['key'] ) || ! preg_match( self::KEY_PATTERN, (string) $m['key'] ) ) {
				return new \WP_Error( 'invalid_request', __( 'Every mapping entry needs a valid key.', 'form-captain' ) );
			}
			$mapping[] = array(
				'key'             => (string) $m['key'],
				'label'           => (string) ( $m['label'] ?? $m['key'] ),
				'types'           => array_values( array_map( 'strval', (array) ( $m['types'] ?? array() ) ) ),
				'required'        => ! empty( $m['required'] ),
				'allow_sensitive' => ! empty( $m['allow_sensitive'] ),
			);
		}
		$events = array_values( array_intersect( array_map( 'strval', (array) ( $d['events'] ?? array( 'entry_created' ) ) ), array( 'entry_created' ) ) );
		return array(
			'label'     => $label,
			'version'   => (string) ( $d['version'] ?? '1.0.0' ),
			'contract'  => $contract,
			'connector' => $connector,
			'settings'  => $settings,
			'mapping'   => $mapping,
			'events'    => $events ?: array( 'entry_created' ),
			'requires'  => (array) ( $d['requires'] ?? array() ),
		);
	}

	/** @return array<string,array<string,mixed>> action key => definition */
	public function actions(): array {
		$this->collect();
		return array_map( static fn( $a ) => $a['definition'], $this->actions );
	}

	/** @return array<string,mixed>|null */
	public function action( string $key ): ?array {
		$this->collect();
		return $this->actions[ $key ]['definition'] ?? null;
	}

	/** Reason why an action cannot run right now, '' when available. */
	public function unavailable_reason( string $action_key ): string {
		$this->collect();
		$action = $this->actions[ $action_key ] ?? null;
		if ( ! $action ) {
			return 'action_not_found';
		}
		$def = $action['definition'];
		if ( null !== $def['connector'] ) {
			$state = $this->state( (string) $def['connector'] );
			if ( '' === $state || self::STATE_MISSING === $state ) {
				return 'connector_not_found';
			}
			if ( self::STATE_INCOMPATIBLE === $state ) {
				return 'incompatible_contract';
			}
			if ( empty( $this->seen[ $def['connector'] ]['requirements']['ok'] ) ) {
				return 'requirements_not_met';
			}
			if ( self::STATE_ACTIVE !== $state ) {
				return 'connector_disabled';
			}
		}
		foreach ( (array) ( $def['requires']['entitlement'] ?? array() ) as $entitlement ) {
			if ( ! apply_filters( 'form_captain_entitlement_granted', false, (string) $entitlement ) ) {
				return 'entitlement_missing';
			}
		}
		return '';
	}

	/**
	 * Data for the form editor (P8-27).
	 *
	 * @return array<int,array<string,mixed>> key, action_key, label, connector, connector_label, settings, mapping, available, reason, message
	 */
	public function actions_for_editor(): array {
		$out = array();
		foreach ( $this->actions() as $key => $def ) {
			$reason    = $this->unavailable_reason( $key );
			$connector = null !== $def['connector'] ? (string) ( $this->seen[ $def['connector'] ]['label'] ?? $def['connector'] ) : '';
			$out[]     = array(
				'key'             => $key,
				'action_key'      => $key,
				'label'           => $def['label'],
				'connector'       => $def['connector'],
				'connector_label' => $connector,
				'settings'        => $def['settings'],
				'mapping'         => $def['mapping'],
				'available'       => '' === $reason,
				'reason'          => $reason,
				'message'         => '' === $reason ? '' : self::next_step( $reason ),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Execution (P8-03, P8-28…P8-30)
	// ---------------------------------------------------------------------

	/**
	 * Job handler for `connector.action`.
	 *
	 * @param array<string,mixed> $job hydrated fc_jobs row
	 */
	public function handle_job( array $job ): JobResult {
		$this->collect();
		$payload     = (array) ( $job['payload'] ?? array() );
		$action_key  = (string) ( $payload['action_key'] ?? '' );
		$instance_id = (string) ( $payload['instance_id'] ?? '' );
		$action      = $this->actions[ $action_key ] ?? null;
		if ( null === $action ) {
			// Provider plugin may be temporarily inactive: keep retrying, then dead-letter (no lost jobs).
			return JobResult::retry( 'action_not_found', 'Action ' . $action_key . ' is not registered' );
		}
		$reason = $this->unavailable_reason( $action_key );
		if ( 'incompatible_contract' === $reason || 'entitlement_missing' === $reason ) {
			return JobResult::fail( $reason, 'Action ' . $action_key . ' cannot run: ' . $reason );
		}
		if ( '' !== $reason ) {
			return JobResult::retry( $reason, 'Action ' . $action_key . ' is waiting: ' . $reason );
		}

		$entries  = $this->plugin->get( EntryRepository::class );
		$entry_id = (int) ( $job['entry_id'] ?? 0 );
		$entry    = $entry_id ? $entries->find( $entry_id ) : null;
		if ( ! $entry ) {
			return JobResult::fail( 'entry_deleted', 'Entry no longer exists' );
		}
		if ( ! empty( $entry['anonymized_gmt'] ) ) {
			return JobResult::fail( 'entry_anonymized', 'Entry was anonymized' );
		}
		if ( 'spam' === $entry['status'] ) {
			return JobResult::fail( 'entry_spam', 'Entry is marked as spam' );
		}
		$forms = $this->plugin->get( FormRepository::class );
		$form  = $forms->find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return JobResult::fail( 'form_not_found', 'Form no longer exists' );
		}
		$instance = null;
		foreach ( (array) ( $form['settings']['actions'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['instance_id'] ?? '' ) === $instance_id ) {
				$instance = $candidate;
				break;
			}
		}
		if ( null === $instance || ( $instance['action_key'] ?? '' ) !== $action_key ) {
			return JobResult::fail( 'action_removed', 'Action instance no longer configured' );
		}
		if ( empty( $instance['enabled'] ) ) {
			return JobResult::fail( 'action_disabled', 'Action instance disabled' );
		}
		$version = $forms->find_version( (int) $entry['form_version_id'] );
		$schema  = $version['schema'] ?? $form['schema'];
		$built   = self::build_values( $action['definition'], (array) ( $instance['mapping'] ?? array() ), (array) ( $schema['fields'] ?? array() ), $entries->values_for( $entry_id ) );
		if ( is_wp_error( $built ) ) {
			return JobResult::fail( $built->get_error_code(), $built->get_error_message() );
		}
		$call = array(
			'form_id'         => (int) $entry['form_id'],
			'entry_id'        => $entry_id,
			'idempotency_key' => (string) $job['idempotency_key'],
			'action_key'      => $action_key,
			'instance_id'     => $instance_id,
			'settings'        => self::sanitize_action_settings( $action['definition'], (array) ( $instance['settings'] ?? array() ) ),
			'values'          => $built['values'],
			'context'         => is_array( $entry['context'] ) ? $entry['context'] : array(),
			'test_mode'       => false,
			'attempt'         => (int) ( $job['attempts'] ?? 1 ),
		);
		JobGrant::open( $entry_id, $built['field_ids'], $built['sensitive_ids'] );
		try {
			$result = self::invoke( $action['callback'], $call );
		} finally {
			JobGrant::close();
		}
		return $result;
	}

	/**
	 * Builds mapping_key => value from validated entry values. Only mapped fields, never blocked types,
	 * sensitive fields only when the mapping entry declares `allow_sensitive` (P8-18, P8-28).
	 *
	 * @param array<string,mixed>            $definition
	 * @param array<string,mixed>            $mapping    mapping_key => field_id
	 * @param array<int,array<string,mixed>> $fields     schema fields of the entry version
	 * @param array<string,mixed>            $values     field_id => stored value
	 * @return array{values:array<string,mixed>,field_ids:string[],sensitive_ids:string[]}|\WP_Error
	 */
	public static function build_values( array $definition, array $mapping, array $fields, array $values ): array|\WP_Error {
		$by_id = array();
		foreach ( $fields as $f ) {
			if ( is_array( $f ) && isset( $f['id'] ) ) {
				$by_id[ (string) $f['id'] ] = $f;
			}
		}
		$out = array(
			'values'        => array(),
			'field_ids'     => array(),
			'sensitive_ids' => array(),
		);
		foreach ( (array) $definition['mapping'] as $m ) {
			$field_id = isset( $mapping[ $m['key'] ] ) && is_string( $mapping[ $m['key'] ] ) ? $mapping[ $m['key'] ] : '';
			$field    = '' !== $field_id ? ( $by_id[ $field_id ] ?? null ) : null;
			if ( null === $field ) {
				if ( $m['required'] ) {
					return new \WP_Error( 'mapping_incomplete', 'Required mapping ' . $m['key'] . ' is not mapped to an existing field' );
				}
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, self::BLOCKED_FIELD_TYPES, true ) ) {
				return new \WP_Error( 'sensitive_field', 'Field type ' . $type . ' can never be mapped' );
			}
			if ( $m['types'] && ! in_array( $type, $m['types'], true ) ) {
				return new \WP_Error( 'mapping_type_mismatch', 'Mapping ' . $m['key'] . ' does not accept type ' . $type );
			}
			$sensitive = ! empty( $field['sensitive'] );
			if ( $sensitive && ! $m['allow_sensitive'] ) {
				return new \WP_Error( 'sensitive_field', 'Mapping ' . $m['key'] . ' points to a sensitive field' );
			}
			if ( $sensitive ) {
				$out['sensitive_ids'][] = $field_id;
			} else {
				$out['field_ids'][] = $field_id;
			}
			if ( array_key_exists( $field_id, $values ) ) {
				$out['values'][ $m['key'] ] = $values[ $field_id ];
			} elseif ( $m['required'] ) {
				$out['values'][ $m['key'] ] = '';
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $definition
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function sanitize_action_settings( array $definition, array $settings ): array {
		$out = array();
		foreach ( (array) $definition['settings'] as $s ) {
			$raw = $settings[ $s['key'] ] ?? $s['default'];
			if ( 'checkbox' === $s['type'] ) {
				$out[ $s['key'] ] = ! empty( $raw );
				continue;
			}
			$value = is_scalar( $raw ) ? (string) $raw : '';
			if ( 'select' === $s['type'] && $s['options'] && ! in_array( $value, $s['options'], true ) ) {
				$value = $s['default'];
			}
			$out[ $s['key'] ] = 'textarea' === $s['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		return $out;
	}

	/**
	 * Calls a callback and validates its result (T-ADP-03/04).
	 *
	 * @param array<string,mixed> $call
	 */
	private static function invoke( callable $callback, array $call ): JobResult {
		try {
			$result = $callback( $call );
		} catch ( \Throwable $e ) {
			return JobResult::retry( 'exception', get_class( $e ) . ': ' . $e->getMessage() );
		}
		if ( ! is_array( $result ) || ! in_array( $result['status'] ?? null, array( 'success', 'retry', 'fail' ), true ) ) {
			return JobResult::retry( 'invalid_result', 'Callback returned an invalid result' );
		}
		$code    = substr( (string) preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( (string) ( $result['code'] ?? '' ) ) ), 0, 60 );
		$message = mb_substr( (string) ( $result['message'] ?? '' ), 0, 255 );
		if ( 'success' === $result['status'] ) {
			$data = array( 'code' => '' !== $code ? $code : 'ok' );
			if ( isset( $result['remote_id'] ) && is_scalar( $result['remote_id'] ) && '' !== (string) $result['remote_id'] ) {
				$data['remote_id'] = mb_substr( (string) $result['remote_id'], 0, 191 );
			}
			return JobResult::success( $data );
		}
		$code = '' !== $code ? $code : ( 'retry' === $result['status'] ? 'retry' : 'failed' );
		return 'retry' === $result['status'] ? JobResult::retry( $code, $message ) : JobResult::fail( $code, $message );
	}

	/**
	 * Safe test run without entry or job (OP-INT-04): sample values, test_mode = true.
	 *
	 * @param array<string,mixed> $settings
	 * @return array{status:string,code:string,message:string,remote_id:string,values:array<string,mixed>}|\WP_Error
	 */
	public function test_action( string $action_key, array $settings = array() ): array|\WP_Error {
		$this->collect();
		$action = $this->actions[ $action_key ] ?? null;
		if ( ! $action ) {
			return new \WP_Error( 'action_not_found', __( 'This action is not registered.', 'form-captain' ) );
		}
		$reason = $this->unavailable_reason( $action_key );
		if ( '' !== $reason ) {
			return new \WP_Error( $reason, self::next_step( $reason ) );
		}
		$values = array();
		foreach ( $action['definition']['mapping'] as $m ) {
			$values[ $m['key'] ] = self::sample_value( (string) ( $m['types'][0] ?? 'text' ) );
		}
		$call   = array(
			'form_id'         => 0,
			'entry_id'        => 0,
			'idempotency_key' => 'form-captain:' . get_current_blog_id() . ':test:' . $action_key . ':' . bin2hex( random_bytes( 5 ) ),
			'action_key'      => $action_key,
			'instance_id'     => 'test',
			'settings'        => self::sanitize_action_settings( $action['definition'], $settings ),
			'values'          => $values,
			'context'         => array(),
			'test_mode'       => true,
			'attempt'         => 1,
		);
		$result = self::invoke( $action['callback'], $call );
		Logger::log( 'info', 'connector_test_run', $action_key . ' ' . $result->status . ' ' . $result->code );
		return array(
			'status'    => $result->status,
			'code'      => (string) ( $result->data['code'] ?? $result->code ),
			'message'   => $result->message,
			'remote_id' => (string) ( $result->data['remote_id'] ?? '' ),
			'values'    => $values,
		);
	}

	public static function sample_value( string $type ): string|array {
		return match ( $type ) {
			'email' => 'test@example.com',
			'textarea' => __( 'This is a test message from Form Captain.', 'form-captain' ),
			'tel' => '+49 30 123456',
			'number' => '42',
			'date' => gmdate( 'Y-m-d' ),
			'checkbox' => array( 'sample' ),
			'select', 'radio' => 'sample',
			'consent' => '1',
			default => __( 'Sample value', 'form-captain' ),
		};
	}
}
