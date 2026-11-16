<?php
/**
 * Public contract API v1 (docs/spec/api-v1.md). Thin wrappers around FormCaptain\Api\ContractApi.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

use FormCaptain\Api\ContractApi;
use FormCaptain\Plugin;

if ( ! function_exists( 'form_captain_contract_version' ) ) {

	function form_captain_contract_version(): string {
		return FORM_CAPTAIN_CONTRACT_VERSION;
	}

	/** @return array<string,mixed>|WP_Error */
	function form_captain_get_form_v1( int|string $form_id ): array|WP_Error {
		return Plugin::instance()->get( ContractApi::class )->get_form( $form_id );
	}

	/**
	 * @param string[] $scopes
	 * @return array<string,mixed>|WP_Error
	 */
	function form_captain_get_entry_v1( int $entry_id, array $scopes = array( 'meta' ) ): array|WP_Error {
		return Plugin::instance()->get( ContractApi::class )->get_entry( $entry_id, $scopes );
	}

	/**
	 * @param array<string,mixed> $payload field key or id => value
	 * @param array<string,mixed> $context
	 * @return array<string,mixed> result object
	 */
	function form_captain_submit_v1( int|string $form_id, array $payload, array $context = array() ): array {
		return Plugin::instance()->get( ContractApi::class )->submit( $form_id, $payload, $context );
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	function form_captain_register_action_v1( string $key, array $definition, callable $callback ): true|WP_Error {
		return Plugin::instance()->get( ContractApi::class )->register_action( $key, $definition, $callback );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	function form_captain_get_render_v1( int|string $form_id, array $args = array() ): string {
		return Plugin::instance()->get( ContractApi::class )->render( $form_id, $args );
	}

	/** @return array<string,mixed> */
	function form_captain_sign_context_v1( string $purpose, int $form_id, string $ref_type, string $ref_id, int $ttl = 0 ): array {
		return Plugin::instance()->get( ContractApi::class )->sign_context( $purpose, $form_id, $ref_type, $ref_id, $ttl );
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	function form_captain_register_context_purpose_v1( string $purpose, array $definition ): true|WP_Error {
		return Plugin::instance()->get( ContractApi::class )->register_context_purpose( $purpose, $definition );
	}
}
