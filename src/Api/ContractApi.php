<?php
/**
 * Implementation of the public contract functions v1 (includes/functions.php, docs/spec/api-v1.md,
 * docs/spec/rechtevertrag.md 5 and 6.6).
 *
 * Depends on classes of other modules (Renderer, SubmissionService, SignedContext). When one of them
 * is not available the call answers `not_ready` instead of failing hard.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Api;

use FormCaptain\Install\Capabilities;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Integrations\JobGrant;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Support\Logger;

final class ContractApi {

	public const SCOPES = array( 'meta', 'values', 'sensitive' );

	private const SUBMISSION_SERVICE = 'FormCaptain\\Service\\SubmissionService';
	private const RENDERER           = 'FormCaptain\\Frontend\\Renderer';
	private const SIGNED_CONTEXT     = 'FormCaptain\\Security\\SignedContext';

	public function __construct(
		private Plugin $plugin,
		private FormRepository $forms,
		private EntryRepository $entries,
		private ConnectorRegistry $registry
	) {}

	private static function ready(): bool {
		return ConnectorRegistry::core_loaded();
	}

	private static function not_ready(): \WP_Error {
		return new \WP_Error( 'not_ready', __( 'Form Captain is not ready yet. Call the contract API in or after form_captain_loaded_v1.', 'form-captain' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_form( int|string $form_id ): array|\WP_Error {
		if ( ! self::ready() ) {
			return self::not_ready();
		}
		$form    = $this->forms->resolve( $form_id );
		$manager = current_user_can( Capabilities::MANAGE_FORMS );
		if ( ! $form || ( ! $manager && 'published' !== $form['status'] ) ) {
			return new \WP_Error( 'form_not_found', __( 'This form is not available.', 'form-captain' ) );
		}
		$version = $form['published_version_id'] ? $this->forms->find_version( (int) $form['published_version_id'] ) : null;
		$schema  = $version['schema'] ?? $form['schema'];
		$fields  = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			$options = array();
			foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
				if ( is_array( $opt ) && isset( $opt['value'] ) ) {
					$options[] = array(
						'value' => (string) $opt['value'],
						'label' => (string) ( $opt['label'] ?? $opt['value'] ),
					);
				}
			}
			$fields[] = array(
				'id'        => (string) $field['id'],
				'key'       => (string) ( $field['key'] ?? '' ),
				'type'      => (string) ( $field['type'] ?? '' ),
				'label'     => (string) ( $field['label'] ?? '' ),
				'required'  => ! empty( $field['required'] ),
				'sensitive' => ! empty( $field['sensitive'] ),
				'options'   => $options,
			);
		}
		return array(
			'id'        => (int) $form['id'],
			'public_id' => (string) $form['public_id'],
			'title'     => (string) $form['title'],
			'status'    => (string) $form['status'],
			'version'   => $version ? (int) $version['version_no'] : null,
			'fields'    => $fields,
		);
	}

	/**
	 * @param string[] $scopes
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_entry( int $entry_id, array $scopes = array( 'meta' ) ): array|\WP_Error {
		if ( ! self::ready() ) {
			return self::not_ready();
		}
		$scopes = array_values( array_unique( array_map( 'strval', $scopes ?: array( 'meta' ) ) ) );
		if ( array_diff( $scopes, self::SCOPES ) ) {
			return new \WP_Error( 'invalid_scope', __( 'Unknown scope requested.', 'form-captain' ) );
		}
		// Permission first – no existence oracle (rechtevertrag.md 7).
		$can_read   = current_user_can( Capabilities::READ_ENTRIES );
		$can_export = $can_read && current_user_can( Capabilities::EXPORT_ENTRIES );
		$grant      = JobGrant::for_entry( $entry_id );
		if ( ! $can_read && null === $grant ) {
			return new \WP_Error( 'forbidden', __( 'You are not allowed to read this entry.', 'form-captain' ) );
		}
		if ( in_array( 'sensitive', $scopes, true ) && ! $can_export && ( null === $grant || ! $grant['sensitive'] ) ) {
			return new \WP_Error( 'forbidden', __( 'Reading sensitive values requires the export capability.', 'form-captain' ) );
		}
		$entry = $this->entries->find( $entry_id );
		if ( ! $entry ) {
			return new \WP_Error( 'entry_not_found', __( 'Entry not found.', 'form-captain' ) );
		}
		$version = $this->forms->find_version( (int) $entry['form_version_id'] );
		$ctx     = is_array( $entry['context'] ) ? $entry['context'] : null;
		$out     = array(
			'contract'              => FORM_CAPTAIN_CONTRACT_VERSION,
			'entry_id'              => (int) $entry['id'],
			'form_id'               => (int) $entry['form_id'],
			'form_version'          => $version ? (int) $version['version_no'] : null,
			'status'                => (string) $entry['status'],
			'created_gmt'           => self::iso( (string) $entry['created_gmt'] ),
			'updated_gmt'           => self::iso( (string) $entry['updated_gmt'] ),
			'context'               => $ctx ? array(
				'purpose'  => (string) ( $ctx['purpose'] ?? '' ),
				'ref_type' => (string) ( $ctx['ref_type'] ?? '' ),
				'ref_id'   => (string) ( $ctx['ref_id'] ?? '' ),
				'verified' => ! empty( $ctx['verified'] ),
			) : null,
			'anonymized'            => ! empty( $entry['anonymized_gmt'] ),
			'anonymized_gmt'        => ! empty( $entry['anonymized_gmt'] ) ? self::iso( (string) $entry['anonymized_gmt'] ) : null,
			// Additive (contract 1.x): consent withdrawal marker, no values.
			'consent_withdrawn'     => ! empty( $entry['consent_withdrawn_gmt'] ),
			'consent_withdrawn_gmt' => ! empty( $entry['consent_withdrawn_gmt'] ) ? self::iso( (string) $entry['consent_withdrawn_gmt'] ) : null,
		);
		$want_values    = in_array( 'values', $scopes, true ) || in_array( 'sensitive', $scopes, true );
		$want_sensitive = in_array( 'sensitive', $scopes, true );
		if ( $want_values ) {
			$values = array();
			$stored = $this->entries->values_for( $entry_id );
			foreach ( (array) ( $version['schema']['fields'] ?? array() ) as $field ) {
				$id = (string) ( $field['id'] ?? '' );
				if ( '' === $id || ! array_key_exists( $id, $stored ) ) {
					continue;
				}
				$sensitive = ! empty( $field['sensitive'] );
				if ( null !== $grant && ! $can_read ) {
					$allowed = $sensitive ? ( $want_sensitive && in_array( $id, $grant['sensitive'], true ) ) : in_array( $id, $grant['fields'], true );
				} else {
					$allowed = ! $sensitive || $want_sensitive;
				}
				if ( $allowed ) {
					$values[ (string) ( $field['key'] ?? $id ) ] = $stored[ $id ];
				}
			}
			$out['values'] = $values;
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $payload field key or field id => value
	 * @param array<string,mixed> $context source, idempotency_key, signed_context, user_id
	 * @return array<string,mixed> result object
	 */
	public function submit( int|string $form_id, array $payload, array $context = array() ): array {
		$request_id = Logger::correlation_id();
		$result     = static fn( string $code, ?int $fid = null, array $extra = array() ): array => array_merge(
			array(
				'success'    => false,
				'code'       => $code,
				'form_id'    => $fid,
				'entry_id'   => null,
				'request_id' => $request_id,
				'errors'     => array(),
			),
			$extra
		);
		if ( ! self::ready() ) {
			return $result( 'not_ready' );
		}
		$source = isset( $context['source'] ) && is_string( $context['source'] ) ? sanitize_key( $context['source'] ) : '';
		$key    = isset( $context['idempotency_key'] ) && is_string( $context['idempotency_key'] ) ? $context['idempotency_key'] : '';
		if ( '' === $source || '' === $key || strlen( $key ) > 191 ) {
			return $result( 'invalid_request', null, array( 'message' => __( 'submit_v1 requires context "source" (plugin slug) and "idempotency_key" (max. 191 characters).', 'form-captain' ) ) );
		}
		$form = $this->forms->resolve( $form_id );
		if ( ! $form ) {
			return $result( 'form_not_found' );
		}
		if ( ! class_exists( self::SUBMISSION_SERVICE ) ) {
			return $result( 'not_ready', (int) $form['id'] );
		}
		$version = $form['published_version_id'] ? $this->forms->find_version( (int) $form['published_version_id'] ) : null;
		$by_key  = array();
		foreach ( (array) ( $version['schema']['fields'] ?? $form['schema']['fields'] ?? array() ) as $field ) {
			if ( isset( $field['key'], $field['id'] ) ) {
				$by_key[ (string) $field['key'] ] = (string) $field['id'];
			}
		}
		$raw = array();
		foreach ( $payload as $k => $v ) {
			$raw[ $by_key[ (string) $k ] ?? (string) $k ] = $v;
		}
		$meta = array(
			'source'          => 'contract',
			'trusted'         => true,
			'plugin'          => $source,
			'idempotency_key' => $key,
			'request_key'     => hash( 'sha256', (int) $form['id'] . '|contract|' . $source . '|' . $key ),
			'signed_context'  => isset( $context['signed_context'] ) && is_string( $context['signed_context'] ) ? $context['signed_context'] : '',
			'user_id'         => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
			'request_id'      => $request_id,
		);
		try {
			$service = $this->plugin->get( self::SUBMISSION_SERVICE );
			$res     = (array) $service->submit( $form, $raw, $meta );
		} catch ( \Throwable $e ) {
			Logger::log( 'error', 'contract_submit_failed', get_class( $e ), array( 'form_id' => (int) $form['id'] ) );
			return $result( 'internal_error', (int) $form['id'] );
		}
		return array_merge( $result( (string) ( $res['code'] ?? 'internal_error' ), (int) $form['id'] ), $res );
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	public function register_action( string $key, array $definition, callable $callback ): bool|\WP_Error {
		return $this->registry->register_action( $key, $definition, $callback );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	public function render( int|string $form_id, array $args = array() ): string {
		if ( ! self::ready() || ! class_exists( self::RENDERER ) ) {
			return '';
		}
		$form = $this->forms->resolve( $form_id );
		if ( ! $form ) {
			return '';
		}
		$allowed = array_intersect_key( $args, array_flip( array( 'context', 'title' ) ) );
		try {
			return (string) $this->plugin->get( self::RENDERER )->render( $form, $allowed );
		} catch ( \Throwable $e ) {
			Logger::log( 'error', 'contract_render_failed', get_class( $e ), array( 'form_id' => (int) $form['id'] ) );
			return '';
		}
	}

	/** @return array<string,mixed> */
	public function sign_context( string $purpose, int $form_id, string $ref_type, string $ref_id, int $ttl = 0 ): array {
		$fail = static fn( string $code ): array => array(
			'success'    => false,
			'code'       => $code,
			'request_id' => Logger::correlation_id(),
		);
		if ( ! self::ready() || ! class_exists( self::SIGNED_CONTEXT ) ) {
			return $fail( 'not_ready' );
		}
		$service = $this->plugin->get( self::SIGNED_CONTEXT );
		foreach ( array( 'sign', 'issue' ) as $method ) {
			if ( method_exists( $service, $method ) ) {
				$res = $service->{$method}( $purpose, $form_id, $ref_type, $ref_id, $ttl );
				if ( is_wp_error( $res ) ) {
					return $fail( (string) $res->get_error_code() );
				}
				if ( is_string( $res ) ) {
					return array(
						'success'    => true,
						'code'       => 'ok',
						'context'    => $res,
						'request_id' => Logger::correlation_id(),
					);
				}
				return array_merge( array( 'request_id' => Logger::correlation_id() ), (array) $res );
			}
		}
		return $fail( 'not_ready' );
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	public function register_context_purpose( string $purpose, array $definition ): bool|\WP_Error {
		if ( ! self::ready() || ! class_exists( self::SIGNED_CONTEXT ) ) {
			return self::not_ready();
		}
		$service = $this->plugin->get( self::SIGNED_CONTEXT );
		foreach ( array( 'register_purpose', 'register_context_purpose' ) as $method ) {
			if ( method_exists( $service, $method ) ) {
				$res = $service->{$method}( $purpose, $definition );
				return is_wp_error( $res ) ? $res : true;
			}
		}
		return self::not_ready();
	}

	private static function iso( string $gmt ): string {
		return '' === $gmt ? '' : str_replace( ' ', 'T', $gmt ) . 'Z';
	}
}
