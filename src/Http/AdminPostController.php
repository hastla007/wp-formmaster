<?php
/**
 * No-JS fallback: POST /wp-admin/admin-post.php?action=form_captain_submit (OP-PUB-03).
 *
 * Same service as REST. Answers with a 303 redirect (PRG) back to the source page carrying only a random
 * key (`fc_r`) of a short-lived transient with the outcome; or to the configured internal confirmation page.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Http;

use FormCaptain\Frontend\Renderer;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Security\RateLimiter;
use FormCaptain\Service\FormSchemaLoader;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Limits;
use FormCaptain\Support\UploadedFiles;

final class AdminPostController {

	public const ACTION = 'form_captain_submit';

	public function __construct( private Plugin $plugin, private FormRepository $forms ) {}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public form protected by the submission token (ADR 0003).
		$post   = wp_unslash( $_POST );
		$target = $this->process( is_array( $post ) ? $post : array(), $_SERVER, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		nocache_headers();
		wp_safe_redirect( $target['location'], $target['status'] );
		exit;
	}

	/**
	 * @param array<string,mixed> $post   unslashed POST data
	 * @param array<string,mixed> $server $_SERVER subset (CONTENT_LENGTH, HTTP_REFERER)
	 * @param array<string,mixed> $files  $_FILES (only used by extension field types, contract 1.1.0)
	 * @return array{location:string,status:int,result:array<string,mixed>}
	 */
	public function process( array $post, array $server, array $files = array() ): array {
		$public_id = is_string( $post['_fc_form'] ?? null ) ? (string) $post['_fc_form'] : '';
		$form      = preg_match( '/^[a-z0-9]{16}$/', $public_id ) ? $this->forms->find_by_public_id( $public_id ) : null;
		$back      = self::back_url( $post, $server );

		if ( ! $form ) {
			$result = array(
				'success' => false,
				'code'    => Errors::FORM_NOT_FOUND,
				'message' => Errors::public_message( Errors::FORM_NOT_FOUND ),
			);
		} elseif ( (int) ( $server['CONTENT_LENGTH'] ?? 0 ) - self::file_bytes( $files ) > Limits::get( 'request_bytes' ) ) {
			$result = array(
				'success' => false,
				'code'    => Errors::PAYLOAD_TOO_LARGE,
				'message' => Errors::public_message( Errors::PAYLOAD_TOO_LARGE ),
			);
		} else {
			$result = $this->plugin->get( SubmissionService::class )->submit(
				$form,
				$post,
				array(
					'ip'             => RateLimiter::client_ip(),
					'source_url'     => $back,
					'source_post_id' => $post['_fc_src'] ?? null,
					'user_id'        => 0, // No nonce in cached HTML → no user binding (ADR 0003).
					'body_bytes'     => max( 0, (int) ( $server['CONTENT_LENGTH'] ?? 0 ) - self::file_bytes( $files ) ),
					'files'          => UploadedFiles::lists_by_field( $files ),
				)
			);
		}

		if ( ! empty( $result['success'] ) ) {
			if ( ! empty( $result['redirect_url'] ) ) {
				$safe = wp_validate_redirect( (string) $result['redirect_url'], '' );
				if ( '' !== $safe ) {
					return array(
						'location' => $safe,
						'status'   => 303,
						'result'   => $result,
					);
				}
			}
			$key = Renderer::store_result(
				array(
					'public_id' => $public_id,
					'status'    => 'success',
					'code'      => (string) $result['code'],
					'message'   => (string) ( $result['message'] ?? '' ),
				)
			);
		} else {
			$key = Renderer::store_result(
				array(
					'public_id' => $public_id,
					'status'    => 'error',
					'code'      => (string) ( $result['code'] ?? Errors::INTERNAL_ERROR ),
					'message'   => (string) ( $result['message'] ?? Errors::public_message( Errors::INTERNAL_ERROR ) ),
					'errors'    => is_array( $result['errors'] ?? null ) ? $result['errors'] : array(),
					'values'    => $form ? $this->repopulate( $form, $post ) : array(),
				)
			);
		}
		$location = add_query_arg( Renderer::RESULT_PARAM, $key, $back ) . '#fc-' . rawurlencode( $public_id );
		return array(
			'location' => $location,
			'status'   => 303,
			'result'   => $result,
		);
	}

	/**
	 * Bytes of uploaded files for extension fields: they do not count against the request limit
	 * of the form fields; their own limits are checked by the extension (contract 1.1.0).
	 *
	 * @param array<string,mixed> $files $_FILES
	 */
	private static function file_bytes( array $files ): int {
		$sum = 0;
		foreach ( UploadedFiles::lists_by_field( $files ) as $list ) {
			foreach ( $list as $file ) {
				$sum += max( 0, (int) $file['size'] );
			}
		}
		return $sum;
	}

	/**
	 * Source page, validated to this site (T-RED-04). Never an arbitrary redirect parameter.
	 *
	 * @param array<string,mixed> $post
	 * @param array<string,mixed> $server
	 */
	public static function back_url( array $post, array $server ): string {
		$candidates = array(
			is_string( $server['HTTP_REFERER'] ?? null ) ? (string) $server['HTTP_REFERER'] : '',
		);
		$src        = is_numeric( $post['_fc_src'] ?? null ) ? (int) $post['_fc_src'] : 0;
		if ( $src > 0 && 'publish' === get_post_status( $src ) ) {
			$candidates[] = (string) get_permalink( $src );
		}
		foreach ( $candidates as $url ) {
			if ( '' === $url ) {
				continue;
			}
			$safe = wp_validate_redirect( $url, '' );
			if ( '' !== $safe && ! str_contains( $safe, '/wp-admin/' ) ) {
				return remove_query_arg( Renderer::RESULT_PARAM, strtok( $safe, '#' ) ?: $safe );
			}
		}
		return home_url( '/' );
	}

	/**
	 * Values to re-display after an error: only known, non-sensitive fields of the published version,
	 * never consent (DS-C-02) or hidden fields.
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $post
	 * @return array<string,string|array<int,string>>
	 */
	private function repopulate( array $form, array $post ): array {
		$input   = is_array( $post['fc'] ?? null ) ? $post['fc'] : array();
		$version = $this->plugin->get( FormSchemaLoader::class )->published( $form );
		$out     = array();
		foreach ( $version['fields'] ?? array() as $field ) {
			$id = (string) $field['id'];
			if ( ! empty( $field['sensitive'] ) || in_array( $field['type'], array( 'consent', 'hidden', 'html' ), true ) || ! isset( $input[ $id ] ) ) {
				continue;
			}
			$value = $input[ $id ];
			if ( is_array( $value ) ) {
				$out[ $id ] = array_slice( array_map( static fn( $v ) => mb_substr( (string) $v, 0, 64 ), array_filter( $value, 'is_scalar' ) ), 0, 200 );
			} elseif ( is_scalar( $value ) ) {
				$out[ $id ] = mb_substr( (string) $value, 0, 10000 );
			}
		}
		return $out;
	}
}
