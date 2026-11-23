<?php
/**
 * Public REST routes (ADR 0003, P3-06, P3-13, P3-14, OP-PUB-01/02).
 *
 * GET  /form-captain/v1/forms/{public_id}/token
 * POST /form-captain/v1/forms/{public_id}/submissions
 *
 * Admin routes belong to FormCaptain\Admin\AdminRest.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Http;

use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Security\PowCaptcha;
use FormCaptain\Security\RateLimiter;
use FormCaptain\Security\SignedContext;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Logger;
use FormCaptain\Support\UploadedFiles;

final class RestController {

	public const NAMESPACE = 'form-captain/v1';

	private const ROUTE_PREFIX = '/form-captain/v1/forms/';

	private const MEDIA_TYPES = array( 'application/json', 'application/x-www-form-urlencoded', 'multipart/form-data' );

	public function __construct( private Plugin $plugin, private FormRepository $forms ) {}

	public function register( Plugin $plugin ): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'strip_cors' ), 20, 4 );
		add_filter( 'rest_pre_dispatch', array( $this, 'pre_dispatch' ), 5, 3 );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<public_id>[a-z0-9]{16})/token',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true', // Public by design; checks happen in the callback.
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<public_id>[a-z0-9]{16})/submissions',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => '__return_true', // Public; token, rate limit and validation in the service.
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'method_not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	private static function is_public_route( string $route ): bool {
		return str_starts_with( $route, self::ROUTE_PREFIX );
	}

	/**
	 * Rejects oversized bodies by Content-Length before any route callback parses them (T-SUB-06).
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! self::is_public_route( $request->get_route() ) ) {
			return $result;
		}
		$length = (int) $request->get_header( 'content_length' );
		if ( $length > Limits::get( 'request_bytes' ) ) {
			return self::respond( self::error_body( Errors::PAYLOAD_TOO_LARGE ), 413 );
		}
		return $result;
	}

	/**
	 * Same-origin only: remove the CORS headers WordPress adds for every REST response (T-SUB-11).
	 *
	 * @param bool              $served
	 * @param mixed             $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 */
	public function strip_cors( $served, $result = null, $request = null, $server = null ): bool {
		if ( $request instanceof \WP_REST_Request && self::is_public_route( $request->get_route() ) && ! headers_sent() ) {
			foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' ) as $h ) {
				header_remove( $h );
			}
		}
		return (bool) $served;
	}

	/** OP-PUB-01 */
	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		$rate = $this->plugin->get( RateLimiter::class )->check_token( RateLimiter::client_ip() );
		if ( ! $rate['allowed'] ) {
			$res = self::respond( self::error_body( Errors::RATE_LIMITED ), 429 );
			$res->header( 'Retry-After', (string) $rate['retry_after'] );
			return $res;
		}
		$form = $this->forms->find_by_public_id( (string) $request['public_id'] );
		if ( ! $form || ! in_array( $form['status'], array( 'published', 'paused' ), true ) ) {
			return self::respond( self::error_body( Errors::FORM_NOT_FOUND ), 404 );
		}
		if ( 'paused' === $form['status'] ) {
			return self::respond( self::error_body( Errors::FORM_NOT_ACCEPTING ), 409 );
		}
		$limits = \FormCaptain\Service\FormLimits::state( $form );
		if ( ! $limits['open'] ) {
			return self::respond( self::error_body( Errors::FORM_NOT_ACCEPTING, $limits['message'] ), 409 );
		}
		$issued  = $this->plugin->get( SubmissionToken::class )->issue( (string) $form['public_id'] );
		$body    = array(
			'success'       => true,
			'code'          => 'ok',
			'token'         => $issued['token'],
			'issued_at'     => $issued['issued_at'],
			'expires_at'    => $issued['expires_at'],
			'min_submit_at' => $issued['issued_at'] + \FormCaptain\Security\SpamLimits::get( 'min_seconds' ),
			'request_id'    => Logger::correlation_id(),
		);
		$purpose = $request->get_param( 'ctx_purpose' );
		if ( is_string( $purpose ) && '' !== $purpose ) {
			$signed = $this->plugin->get( SignedContext::class )->sign(
				$purpose,
				(int) $form['id'],
				is_string( $request->get_param( 'ctx_ref_type' ) ) ? (string) $request->get_param( 'ctx_ref_type' ) : '',
				is_string( $request->get_param( 'ctx_ref_id' ) ) ? (string) $request->get_param( 'ctx_ref_id' ) : '',
				0,
				'public_endpoint'
			);
			if ( ! empty( $signed['success'] ) ) {
				$body['_fc_ctx'] = $signed['context'];
			} else {
				$body['context_error'] = (string) $signed['code'];
			}
		}
		$pow = PowCaptcha::challenge_for( $form );
		if ( null !== $pow ) {
			$body['pow'] = $pow; // Built-in proof of work (SET-FC-05), solved by proof-of-work.js.
		}
		return self::respond( $body, 200 );
	}

	/** OP-PUB-02 */
	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > Limits::get( 'request_bytes' ) ) {
			return self::respond( self::error_body( Errors::PAYLOAD_TOO_LARGE ), 413 );
		}
		$type = $request->get_content_type();
		$type = is_array( $type ) ? strtolower( (string) ( $type['value'] ?? '' ) ) : '';
		if ( ! in_array( $type, self::MEDIA_TYPES, true ) ) {
			return self::respond( self::error_body( 'unsupported_media_type', __( 'Unsupported request format.', 'form-captain' ) ), 415 );
		}
		$raw = 'application/json' === $type ? $request->get_json_params() : $request->get_body_params();
		if ( ! is_array( $raw ) ) {
			return self::respond( self::error_body( Errors::VALIDATION_FAILED ), 400 );
		}
		if ( 'multipart/form-data' === $type && strlen( (string) wp_json_encode( $raw ) ) > Limits::get( 'request_bytes' ) ) {
			// Multipart: the raw body is not readable; the non-file fields are limited here, files by their extension.
			return self::respond( self::error_body( Errors::PAYLOAD_TOO_LARGE ), 413 );
		}
		$form = $this->forms->find_by_public_id( (string) $request['public_id'] );
		if ( ! $form ) {
			return self::respond( self::error_body( Errors::FORM_NOT_FOUND ), 404 );
		}
		$result = $this->plugin->get( SubmissionService::class )->submit(
			$form,
			$raw,
			array(
				'ip'             => RateLimiter::client_ip(),
				'source_url'     => (string) $request->get_header( 'referer' ),
				'source_post_id' => $raw['_fc_src'] ?? null,
				'user_id'        => get_current_user_id(),
				'body_bytes'     => strlen( $body ),
				'request_id'     => Logger::correlation_id(),
				'files'          => UploadedFiles::by_field( (array) $request->get_file_params() ),
				'js'             => true, // Route of frontend.js: a proof-of-work solution is expected.
			)
		);
		return self::from_result( $result );
	}

	public function method_not_allowed(): \WP_REST_Response {
		$res = self::respond( self::error_body( 'method_not_allowed', __( 'Method not allowed.', 'form-captain' ) ), 405 );
		$res->header( 'Allow', 'POST' );
		return $res;
	}

	/**
	 * Maps a service result to the public response schema {success, code, message, request_id, errors?, redirect_url?}.
	 * Never contains entry IDs or values (T-SUB-08).
	 *
	 * @param array<string,mixed> $result
	 */
	public static function from_result( array $result ): \WP_REST_Response {
		$code = (string) ( $result['code'] ?? Errors::INTERNAL_ERROR );
		$body = array(
			'success'    => (bool) ( $result['success'] ?? false ),
			'code'       => $code,
			'message'    => (string) ( $result['message'] ?? '' ),
			'request_id' => (string) ( $result['request_id'] ?? Logger::correlation_id() ),
		);
		if ( ! empty( $result['errors'] ) ) {
			$body['errors'] = (array) $result['errors'];
			if ( ! empty( $result['error_codes'] ) ) {
				$body['error_codes'] = (array) $result['error_codes'];
			}
		}
		if ( ! empty( $result['redirect_url'] ) ) {
			$body['redirect_url'] = (string) $result['redirect_url'];
		}
		$status = match ( true ) {
			SubmissionService::CODE_CREATED === $code => 201,
			SubmissionService::CODE_DUPLICATE === $code => 200,
			default => Errors::status( $code ),
		};
		$res = self::respond( $body, $status );
		if ( Errors::RATE_LIMITED === $code ) {
			$res->header( 'Retry-After', (string) max( 1, (int) ( $result['retry_after'] ?? 60 ) ) );
		}
		return $res;
	}

	/** @return array<string,mixed> */
	private static function error_body( string $code, string $message = '' ): array {
		return array(
			'success'    => false,
			'code'       => $code,
			'message'    => '' !== $message ? $message : Errors::public_message( $code ),
			'request_id' => Logger::correlation_id(),
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private static function respond( array $body, int $status ): \WP_REST_Response {
		$res = new \WP_REST_Response( $body, $status );
		$res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$res->header( 'Pragma', 'no-cache' );
		$res->header( 'Vary', '*' );
		$res->header( 'X-Content-Type-Options', 'nosniff' );
		return $res;
	}
}
