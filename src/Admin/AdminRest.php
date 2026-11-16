<?php
/**
 * Admin REST routes for forms (OP-FRM-01 … OP-FRM-12, P2-13, P2-15).
 *
 * Cookie authentication with X-WP-Nonce is enforced by WordPress core; every route
 * checks its capability in permission_callback. Application passwords are refused.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\PreviewTokens;
use FormCaptain\Service\Templates;
use FormCaptain\Support\Errors;
use FormCaptain\Support\Logger;

final class AdminRest {

	public const NS = 'form-captain/v1';

	public function __construct(
		private FormService $service,
		private FormRepository $forms,
		private PreviewTokens $previews,
		private Templates $templates
	) {}

	public function register( Plugin $plugin ): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$id = '(?P<id>\d+)';
		register_rest_route(
			self::NS,
			'/admin/forms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_forms' ),
					'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
					'args'                => array(
						'status'   => array(
							'type' => 'string',
							'enum' => array( '', 'draft', 'published', 'paused', 'archived' ),
						),
						'search'   => array( 'type' => 'string' ),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_form' ),
					'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/forms/' . $id,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_form' ),
					'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_form' ),
					'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_form' ),
					'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
				),
			)
		);
		$simple = array(
			'publish'      => 'publish_form',
			'status'       => 'status_form',
			'duplicate'    => 'duplicate_form',
			'preview-link' => 'preview_link',
		);
		foreach ( $simple as $path => $method ) {
			register_rest_route(
				self::NS,
				'/admin/forms/' . $id . '/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $method ),
					'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
				)
			);
		}
		register_rest_route(
			self::NS,
			'/admin/forms/' . $id . '/actions',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_actions' ),
				'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS, Capabilities::MANAGE_INTEGRATIONS ),
			)
		);
		register_rest_route(
			self::NS,
			'/admin/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => $this->cap( Capabilities::MANAGE_FORMS ),
			)
		);
	}

	/** permission_callback factory: login, no application passwords, all given capabilities. */
	private function cap( string ...$caps ): callable {
		return static function ( \WP_REST_Request $request ) use ( $caps ): bool|\WP_Error {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'unauthenticated', __( 'Please log in.', 'form-captain' ), array( 'status' => 401 ) );
			}
			$auth = (string) $request->get_header( 'authorization' );
			// Header check plus core's own record: servers that pass Basic auth only via PHP_AUTH_USER have no header.
			$app_password = function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password();
			if ( $app_password || ( '' !== $auth && 0 === stripos( $auth, 'basic ' ) ) ) {
				return Errors::error( Errors::FORBIDDEN, __( 'Application passwords are not accepted for these routes.', 'form-captain' ) );
			}
			foreach ( $caps as $cap ) {
				if ( ! current_user_can( $cap ) ) {
					return Errors::error( Errors::FORBIDDEN, __( 'You are not allowed to do this.', 'form-captain' ) );
				}
			}
			return true;
		};
	}

	/* ------------------------------------------------------------------ */

	public function list_forms( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->forms->list(
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);
		$counts = $this->forms->entry_counts();
		$items  = array();
		foreach ( $result['items'] as $form ) {
			$items[] = array(
				'id'          => $form['id'],
				'public_id'   => $form['public_id'],
				'title'       => $form['title'],
				'status'      => $form['status'],
				'entries'     => $counts[ $form['id'] ] ?? 0,
				'updated_gmt' => $form['updated_gmt'],
			);
		}
		$response = new \WP_REST_Response(
			array(
				'success' => true,
				'items'   => $items,
				'total'   => $result['total'],
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	public function create_form( \WP_REST_Request $request ): \WP_REST_Response {
		$template = (string) $request->get_param( 'template' );
		if ( '' !== $template ) {
			$id = $this->service->create_from_template( $template );
		} elseif ( is_array( $request->get_param( 'schema' ) ) ) {
			$id = $this->service->create( (string) $request->get_param( 'title' ), (array) $request->get_param( 'schema' ), (array) ( $request->get_param( 'settings' ) ?: array() ) );
		} else {
			$id = $this->service->create_blank( (string) $request->get_param( 'title' ) );
		}
		if ( is_wp_error( $id ) ) {
			return $this->error( $id );
		}
		return $this->form_response( (int) $id, 201 );
	}

	public function get_form( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->form_response( (int) $request['id'] );
	}

	public function update_form( \WP_REST_Request $request ): \WP_REST_Response {
		$revision = $request->get_param( 'revision' ) ?? $request->get_param( 'schema_revision' );
		if ( ! is_numeric( $revision ) || ! is_array( $request->get_param( 'schema' ) ) ) {
			return $this->error( new \WP_Error( 'invalid_request', __( 'Revision and schema are required.', 'form-captain' ), array( 'status' => 400 ) ) );
		}
		$result = $this->service->save(
			(int) $request['id'],
			(string) $request->get_param( 'title' ),
			(array) $request->get_param( 'schema' ),
			is_array( $request->get_param( 'settings' ) ) ? (array) $request->get_param( 'settings' ) : array(),
			(int) $revision
		);
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return $this->form_response( (int) $request['id'] );
	}

	public function update_actions( \WP_REST_Request $request ): \WP_REST_Response {
		$revision = $request->get_param( 'revision' );
		if ( ! is_numeric( $revision ) || ! is_array( $request->get_param( 'actions' ) ) ) {
			return $this->error( new \WP_Error( 'invalid_request', __( 'Revision and actions are required.', 'form-captain' ), array( 'status' => 400 ) ) );
		}
		$result = $this->service->save_actions( (int) $request['id'], (array) $request->get_param( 'actions' ), (int) $revision );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		$response = $this->form_response( (int) $request['id'] );
		$data     = (array) $response->get_data();
		if ( ! empty( $data['form'] ) ) {
			$data['action_errors'] = $result['errors'];
			$response->set_data( $data );
		}
		return $response;
	}

	public function delete_form( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->service->delete( (int) $request['id'], rest_sanitize_boolean( $request->get_param( 'delete_entries' ) ?? false ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return new \WP_REST_Response(
			array(
				'success' => true,
				'code'    => 'deleted',
			)
		);
	}

	public function publish_form( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->service->publish( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return $this->form_response( (int) $request['id'] );
	}

	public function status_form( \WP_REST_Request $request ): \WP_REST_Response {
		$status = (string) $request->get_param( 'status' );
		if ( ! in_array( $status, FormRepository::STATUSES, true ) ) {
			return $this->error( new \WP_Error( 'invalid_request', __( 'Unknown status.', 'form-captain' ), array( 'status' => 400 ) ) );
		}
		$result = $this->service->set_status( (int) $request['id'], $status );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return $this->form_response( (int) $request['id'] );
	}

	public function duplicate_form( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $this->service->duplicate( (int) $request['id'] );
		if ( is_wp_error( $id ) ) {
			return $this->error( $id );
		}
		return $this->form_response( (int) $id, 201 );
	}

	public function preview_link( \WP_REST_Request $request ): \WP_REST_Response {
		$form = $this->forms->find( (int) $request['id'] );
		if ( ! $form ) {
			return $this->error( new \WP_Error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) ) );
		}
		$link     = $this->previews->create( (int) $form['id'], get_current_user_id() );
		$response = new \WP_REST_Response(
			array(
				'success'    => true,
				'url'        => $link['url'],
				'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $link['expires_at'] ),
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public function list_templates(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'items'   => array_values( $this->templates->all() ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	private function form_response( int $id, int $status = 200 ): \WP_REST_Response {
		$form = $this->forms->find( $id );
		if ( ! $form ) {
			return $this->error( new \WP_Error( 'not_found', __( 'Form not found.', 'form-captain' ), array( 'status' => 404 ) ) );
		}
		return new \WP_REST_Response(
			array(
				'success' => true,
				'form'    => self::admin_form( $form, $this->service ),
			),
			$status
		);
	}

	/**
	 * Admin representation of a form (OP-FRM-03): schema + settings, no secrets.
	 *
	 * @param array<string,mixed> $form
	 * @return array<string,mixed>
	 */
	public static function admin_form( array $form, FormService $service ): array {
		$entries = $service->entry_total( (int) $form['id'] );
		return array(
			'id'                   => (int) $form['id'],
			'public_id'            => (string) $form['public_id'],
			'title'                => (string) $form['title'],
			'status'               => (string) $form['status'],
			'revision'             => (int) $form['revision'],
			'schema'               => $form['schema'],
			'settings'             => $form['settings'],
			'published_version_id' => $form['published_version_id'],
			'created_gmt'          => (string) $form['created_gmt'],
			'updated_gmt'          => (string) $form['updated_gmt'],
			'entries'              => $entries,
			'shortcode'            => '[form_captain id="' . $form['public_id'] . '"]',
			'errors'               => (object) $service->errors_for( $form ),
			'pending_changes'      => $service->pending_changes( $form ),
		);
	}

	/** Uniform error body (rechtevertrag.md 3). */
	private function error( \WP_Error $error ): \WP_REST_Response {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 500 );
		$body   = array(
			'success'    => false,
			'code'       => $error->get_error_code(),
			'message'    => $error->get_error_message(),
			'request_id' => Logger::correlation_id(),
		);
		if ( isset( $data['errors'] ) ) {
			$body['errors'] = (object) $data['errors'];
		}
		return new \WP_REST_Response( $body, $status );
	}
}
