<?php
/**
 * Signed suite context `_fc_ctx` (docs/spec/suite-kontext.md, P8-13).
 *
 * Format: B64U(payload_json) "." B64U(HMAC-SHA256(K_ctx, B64U(payload_json)))
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Security;

use FormCaptain\Database\Tables;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Logger;
use FormCaptain\Support\Secret;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

final class SignedContext {

	public const LABEL      = 'form-captain/ctx/v1';
	public const MAX_LENGTH = 1024;
	public const MAX_TTL    = 86400;
	private const SKEW      = 60;

	private const PURPOSE_RE  = '/^[a-z][a-z0-9_]{0,39}\.[a-z][a-z0-9_]{0,39}$/';
	private const REF_TYPE_RE = '/^[a-z][a-z0-9_]{0,31}$/';
	private const REF_ID_RE   = '/^[A-Za-z0-9_.:-]{1,64}$/';
	private const KEYS        = array( 'purpose', 'form_id', 'ref_type', 'ref_id', 'site', 'exp', 'nonce' );

	/** @var array<string,array<string,mixed>> purposes registered via register_purpose(). */
	private array $registered = array();

	/** @var array<string,array<string,mixed>>|null validated purpose cache */
	private ?array $purposes = null;

	/** @var array<string,string> purpose => diagnostic code for rejected definitions */
	private array $diagnostics = array();

	/** @var array<int,string>|null derived keys (current, previous) */
	private ?array $keys = null;

	/** @var array<string,int> generation counter per purpose (diagnostics only) */
	private array $issued = array();

	public function __construct( private FormRepository $forms ) {}

	// Key handling.

	/** K_ctx derivation (spec 1.2). Pure; used by fixtures. */
	public static function derive_key( string $auth_salt, string $secret ): string {
		return hash_hmac( 'sha256', self::LABEL, $auth_salt . $secret, true );
	}

	/** Site hash (spec 1.1): sha256('fc-site|' . lower(scheme+host) . path without trailing slash). */
	public static function site_hash( ?string $home = null ): string {
		$home  = $home ?? home_url();
		$parts = wp_parse_url( $home );
		$norm  = strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
		return substr( hash( 'sha256', 'fc-site|' . rtrim( $norm, '/' ) ), 0, 32 );
	}

	/**
	 * Encodes and signs a payload with a raw key (pure).
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function encode( array $payload, string $key ): string {
		$segment = Secret::base64url_encode( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		return $segment . '.' . Secret::base64url_encode( hash_hmac( 'sha256', $segment, $key, true ) );
	}

	/** @return array<int,string> current key first, previous key (rotation window) second */
	private function keys(): array {
		if ( null === $this->keys ) {
			$salt       = wp_salt( 'auth' );
			$this->keys = array( self::derive_key( $salt, self::current_secret() ) );
			$previous   = self::previous_secret();
			if ( null !== $previous ) {
				$this->keys[] = self::derive_key( $salt, $previous );
			}
		}
		return $this->keys;
	}

	private static function current_secret(): string {
		if ( defined( 'FORM_CAPTAIN_SECRET' ) && is_string( FORM_CAPTAIN_SECRET ) && strlen( FORM_CAPTAIN_SECRET ) >= 32 ) {
			return FORM_CAPTAIN_SECRET;
		}
		Secret::key( 'ensure' ); // Creates the option on first use.
		return (string) get_option( Secret::OPTION );
	}

	private static function previous_secret(): ?string {
		if ( defined( 'FORM_CAPTAIN_SECRET' ) && is_string( FORM_CAPTAIN_SECRET ) && strlen( FORM_CAPTAIN_SECRET ) >= 32 ) {
			return defined( 'FORM_CAPTAIN_SECRET_PREVIOUS' ) && is_string( FORM_CAPTAIN_SECRET_PREVIOUS ) && strlen( FORM_CAPTAIN_SECRET_PREVIOUS ) >= 32 ? FORM_CAPTAIN_SECRET_PREVIOUS : null;
		}
		$prev  = get_option( 'form_captain_secret_previous' );
		$until = (int) get_option( 'form_captain_secret_previous_until', 0 );
		return is_string( $prev ) && '' !== $prev && $until > Clock::timestamp() ? $prev : null;
	}

	/** Clears cached keys and purposes (tests, rotation). */
	public function reset(): void {
		$this->keys     = null;
		$this->purposes = null;
	}

	// Purpose registry (spec 3.1).

	/**
	 * @param array<string,mixed> $definition
	 */
	public function register_purpose( string $purpose, array $definition ): true|\WP_Error {
		if ( isset( $this->registered[ $purpose ] ) || isset( $this->purposes()[ $purpose ] ) ) {
			$this->diagnostics[ $purpose ] = 'context_purpose_duplicate';
			return new \WP_Error( 'context_purpose_duplicate', __( 'This context purpose is already registered.', 'form-captain' ) );
		}
		$error = self::validate_definition( $purpose, $definition );
		if ( null !== $error ) {
			$this->diagnostics[ $purpose ] = $error;
			return new \WP_Error( $error, __( 'The context purpose definition is invalid.', 'form-captain' ) );
		}
		$this->registered[ $purpose ] = $definition;
		$this->purposes               = null;
		return true;
	}

	/** @return array<string,array<string,mixed>> validated, active purposes */
	public function purposes(): array {
		if ( null !== $this->purposes ) {
			return $this->purposes;
		}
		$base     = $this->registered;
		$filtered = apply_filters( 'form_captain_signed_context_purposes_v1', $base );
		$filtered = is_array( $filtered ) ? $filtered : $base;
		$out      = array();
		foreach ( $filtered as $purpose => $definition ) {
			if ( ! is_string( $purpose ) ) {
				continue;
			}
			if ( isset( $base[ $purpose ] ) && $definition !== $base[ $purpose ] ) {
				// Filters must not overwrite existing entries.
				$this->diagnostics[ $purpose ] = 'context_purpose_duplicate';
				$definition                    = $base[ $purpose ];
			}
			$error = is_array( $definition ) ? self::validate_definition( $purpose, $definition ) : 'context_purpose_invalid';
			if ( null !== $error ) {
				$this->diagnostics[ $purpose ] = $error;
				continue;
			}
			$definition['issuance'] = (array) $definition['issuance'];
			$out[ $purpose ]        = $definition;
		}
		if ( did_action( 'form_captain_loaded_v1' ) ) {
			$this->purposes = $out;
		}
		return $out;
	}

	/** @return array<string,string> */
	public function diagnostics(): array {
		$this->purposes();
		return $this->diagnostics;
	}

	/** @return array<string,int> */
	public function issued_counts(): array {
		return $this->issued;
	}

	/**
	 * @param array<string,mixed> $d
	 */
	private static function validate_definition( string $purpose, array $d ): ?string {
		if ( ! preg_match( self::PURPOSE_RE, $purpose ) ) {
			return 'context_purpose_invalid';
		}
		$owner = (string) ( $d['owner'] ?? '' );
		if ( '' === $owner || ! str_starts_with( $purpose, $owner . '.' ) ) {
			return 'context_purpose_invalid';
		}
		foreach ( array( 'contract', 'label' ) as $k ) {
			if ( ! is_string( $d[ $k ] ?? null ) || '' === $d[ $k ] ) {
				return 'context_purpose_invalid';
			}
		}
		if ( ! self::contract_compatible( (string) $d['contract'] ) ) {
			return 'incompatible_contract';
		}
		$types = $d['ref_types'] ?? null;
		if ( ! is_array( $types ) || ! $types ) {
			return 'context_purpose_invalid';
		}
		foreach ( $types as $t ) {
			if ( ! is_string( $t ) || ! preg_match( self::REF_TYPE_RE, $t ) ) {
				return 'context_purpose_invalid';
			}
		}
		$default = $d['default_ttl'] ?? null;
		$max     = $d['max_ttl'] ?? null;
		if ( ! is_int( $default ) || ! is_int( $max ) || $max < 60 || $max > self::MAX_TTL || $default < 60 || $default > $max ) {
			return 'context_purpose_invalid';
		}
		if ( ! is_bool( $d['single_use'] ?? null ) ) {
			return 'context_purpose_invalid';
		}
		$issuance = (array) ( $d['issuance'] ?? array() );
		if ( ! $issuance || array_diff( $issuance, array( 'render', 'public_endpoint', 'link' ) ) ) {
			return 'context_purpose_invalid';
		}
		if ( in_array( 'public_endpoint', $issuance, true ) && ( $d['single_use'] || ! is_callable( $d['issue_check'] ?? null ) ) ) {
			return 'context_purpose_invalid';
		}
		if ( isset( $d['issue_check'] ) && ! is_callable( $d['issue_check'] ) ) {
			return 'context_purpose_invalid';
		}
		if ( isset( $d['submit_check'] ) && ! is_callable( $d['submit_check'] ) ) {
			return 'context_purpose_invalid';
		}
		if ( ! empty( $d['marks_verified'] ) && ! isset( $d['submit_check'] ) ) {
			return 'context_purpose_invalid';
		}
		return null;
	}

	private static function contract_compatible( string $constraint ): bool {
		if ( ! preg_match( '/(\d+)/', $constraint, $m ) ) {
			return false;
		}
		return (int) $m[1] === (int) explode( '.', FORM_CAPTAIN_CONTRACT_VERSION )[0];
	}

	// Signing (spec 2.1).

	/**
	 * @return array{success:bool,code:string,context?:string,expires_at?:int,request_id:string}
	 */
	public function sign( string $purpose, int $form_id, string $ref_type, string $ref_id, int $ttl = 0, string $issuance = '' ): array {
		$result = array(
			'success'    => false,
			'code'       => 'ok',
			'request_id' => Logger::correlation_id(),
		);
		if ( ! did_action( 'form_captain_loaded_v1' ) ) {
			$result['code'] = 'not_ready';
			return $result;
		}
		$def = $this->purposes()[ $purpose ] ?? null;
		if ( null === $def ) {
			$result['code'] = 'context_purpose_unknown';
			return $result;
		}
		if ( '' !== $issuance && ! in_array( $issuance, $def['issuance'], true ) ) {
			$result['code'] = 'context_purpose_not_allowed';
			return $result;
		}
		$form = $this->forms->find( $form_id );
		if ( ! $form || ! in_array( $purpose, self::accepted_purposes( $form ), true ) ) {
			$result['code'] = 'context_purpose_not_allowed';
			return $result;
		}
		if ( ! in_array( $ref_type, (array) $def['ref_types'], true ) || ! preg_match( self::REF_ID_RE, $ref_id ) ) {
			$result['code'] = 'context_ref_invalid';
			return $result;
		}
		if ( isset( $def['issue_check'] ) && ! self::run_check( $def['issue_check'], $ref_type, $ref_id, $form_id ) ) {
			$result['code'] = 'context_ref_rejected';
			return $result;
		}
		$ttl                      = 0 === $ttl ? (int) $def['default_ttl'] : $ttl;
		$ttl                      = max( 60, min( $ttl, (int) $def['max_ttl'], self::MAX_TTL ) );
		$exp                      = Clock::timestamp() + $ttl;
		$payload                  = array(
			'purpose'  => $purpose,
			'form_id'  => $form_id,
			'ref_type' => $ref_type,
			'ref_id'   => $ref_id,
			'site'     => self::site_hash(),
			'exp'      => $exp,
			'nonce'    => Secret::base64url_encode( random_bytes( 16 ) ),
		);
		$this->issued[ $purpose ] = ( $this->issued[ $purpose ] ?? 0 ) + 1;
		return array(
			'success'    => true,
			'code'       => 'ok',
			'context'    => self::encode( $payload, $this->keys()[0] ),
			'expires_at' => $exp,
			'request_id' => $result['request_id'],
		);
	}

	// Verification (spec 4.2).

	/**
	 * Verifies a context for the addressed form. Steps 1–13 of spec 4.2.
	 *
	 * @param array<string,mixed> $form form row
	 * @return array{ok:bool,code:string,public_code:string,context?:array<string,mixed>,nonce?:string,exp?:int,single_use?:bool}
	 */
	public function verify( mixed $value, array $form ): array {
		$form_id = (int) ( $form['id'] ?? 0 );
		$fail    = function ( string $code, ?string $purpose = null ) use ( $form_id ): array {
			Logger::log( 'warning', $code, 'Signed context rejected' . ( $purpose ? ' for purpose ' . $purpose : '' ), array( 'form_id' => $form_id ) );
			return array(
				'ok'          => false,
				'code'        => $code,
				'public_code' => self::public_code( $code ),
			);
		};
		// 1. Syntax.
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_LENGTH || 1 !== substr_count( $value, '.' ) ) {
			return $fail( 'context_malformed' );
		}
		list( $segment, $sig ) = explode( '.', $value );
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $segment ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $sig ) ) {
			return $fail( 'context_malformed' );
		}
		// 2. Signature before any decoding.
		$valid = false;
		foreach ( $this->keys() as $key ) {
			if ( hash_equals( Secret::base64url_encode( hash_hmac( 'sha256', $segment, $key, true ) ), $sig ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return $fail( 'context_signature_invalid' );
		}
		// 3. Payload schema.
		$json = Secret::base64url_decode( $segment );
		try {
			$p = is_string( $json ) ? json_decode( $json, true, 2, JSON_THROW_ON_ERROR ) : null;
		} catch ( \JsonException $e ) {
			$p = null;
		}
		if ( ! is_array( $p ) || ! self::payload_valid( $p ) ) {
			return $fail( 'context_malformed' );
		}
		$purpose = $p['purpose'];
		$now     = Clock::timestamp();
		// 4.–6.
		if ( ! hash_equals( self::site_hash(), $p['site'] ) ) {
			return $fail( 'context_site_mismatch', $purpose );
		}
		if ( $now > $p['exp'] + self::SKEW ) {
			return $fail( 'context_expired', $purpose );
		}
		if ( $p['exp'] - $now > self::MAX_TTL + self::SKEW ) {
			return $fail( 'context_ttl_invalid', $purpose );
		}
		// 7.–9.
		$def = $this->purposes()[ $purpose ] ?? null;
		if ( null === $def ) {
			return $fail( 'context_purpose_unknown', $purpose );
		}
		if ( $p['exp'] - $now > (int) $def['max_ttl'] + self::SKEW ) {
			return $fail( 'context_ttl_invalid', $purpose );
		}
		if ( ! in_array( $purpose, self::accepted_purposes( $form ), true ) ) {
			return $fail( 'context_purpose_not_allowed', $purpose );
		}
		// 10.–11.
		if ( $p['form_id'] !== $form_id ) {
			return $fail( 'context_form_mismatch', $purpose );
		}
		if ( ! in_array( $p['ref_type'], (array) $def['ref_types'], true ) ) {
			return $fail( 'context_ref_invalid', $purpose );
		}
		// 12. Replay pre-check for single-use purposes.
		$single = (bool) $def['single_use'];
		if ( $single && $this->nonce_used( $p['nonce'] ) ) {
			return $fail( 'context_replayed', $purpose );
		}
		// 13. Submit check.
		$verified = false;
		if ( isset( $def['submit_check'] ) ) {
			if ( ! self::run_check( $def['submit_check'], $p['ref_type'], $p['ref_id'], $form_id ) ) {
				return $fail( 'context_ref_rejected', $purpose );
			}
			$verified = ! empty( $def['marks_verified'] );
		}
		return array(
			'ok'          => true,
			'code'        => 'ok',
			'public_code' => 'ok',
			'context'     => array(
				'purpose'  => $purpose,
				'ref_type' => $p['ref_type'],
				'ref_id'   => $p['ref_id'],
				'verified' => $verified,
			),
			'nonce'       => $p['nonce'],
			'exp'         => $p['exp'],
			'single_use'  => $single,
		);
	}

	/**
	 * @param array<string,mixed> $p
	 */
	private static function payload_valid( array $p ): bool {
		$keys = array_keys( $p );
		sort( $keys );
		$expected = self::KEYS;
		sort( $expected );
		if ( $keys !== $expected ) {
			return false;
		}
		return is_string( $p['purpose'] ) && preg_match( self::PURPOSE_RE, $p['purpose'] )
			&& is_int( $p['form_id'] ) && $p['form_id'] > 0
			&& is_string( $p['ref_type'] ) && preg_match( self::REF_TYPE_RE, $p['ref_type'] )
			&& is_string( $p['ref_id'] ) && preg_match( self::REF_ID_RE, $p['ref_id'] )
			&& is_string( $p['site'] ) && preg_match( '/^[0-9a-f]{32}$/', $p['site'] )
			&& is_int( $p['exp'] )
			&& is_string( $p['nonce'] ) && preg_match( '/^[A-Za-z0-9_-]{22}$/', $p['nonce'] );
	}

	/** Maps internal codes to the three public codes (spec 5). */
	public static function public_code( string $code ): string {
		return match ( $code ) {
			'context_expired' => Errors::CONTEXT_EXPIRED,
			'context_replayed' => Errors::CONTEXT_REPLAYED,
			default => Errors::INVALID_CONTEXT,
		};
	}

	/** Visitor message for public codes (CTX-T-01 / CTX-T-02). */
	public static function public_message( string $public_code ): string {
		return Errors::CONTEXT_EXPIRED === $public_code
			? __( 'This form has expired. Please check your entries and send it again.', 'form-captain' )
			: __( 'This form could not be sent because its link is no longer valid. Please reload the page and try again.', 'form-captain' );
	}

	/**
	 * @param array<string,mixed> $form
	 * @return string[]
	 */
	public static function accepted_purposes( array $form ): array {
		$list = $form['settings']['accepted_context_purposes'] ?? array();
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	private static function run_check( mixed $callback, string $ref_type, string $ref_id, int $form_id ): bool {
		try {
			return true === (bool) call_user_func( $callback, $ref_type, $ref_id, $form_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// Replay protection (spec 4.3).

	private static function nonce_bucket( string $nonce ): string {
		return hash( 'sha256', 'ctxnonce:' . $nonce );
	}

	public function nonce_used( string $nonce ): bool {
		global $wpdb;
		$table = Tables::name( Tables::RATE_LIMITS );
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE bucket = %s", self::nonce_bucket( $nonce ) ) );
	}

	/** Atomically consumes a single-use nonce (inside the entry transaction). */
	public function consume_nonce( string $nonce, int $exp, int $form_id ): bool {
		global $wpdb;
		$table    = Tables::name( Tables::RATE_LIMITS );
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (bucket, form_id, hits, window_start_gmt) VALUES (%s, %d, 1, %s)",
				self::nonce_bucket( $nonce ),
				$form_id,
				Clock::at( $exp + self::SKEW )
			)
		);
		return 1 === (int) $affected;
	}

	/** Compensating delete for engines without transactions. */
	public function release_nonce( string $nonce ): void {
		global $wpdb;
		$wpdb->delete( Tables::name( Tables::RATE_LIMITS ), array( 'bucket' => self::nonce_bucket( $nonce ) ) );
	}
}
