<?php
/**
 * P5-07 / T-ADM-01, T-ADM-02: every registered form-captain REST route and every
 * admin_post_form_captain_* handler refuses anonymous users, subscribers, authors and
 * editors and changes nothing; administrators without nonce are refused as well.
 *
 * Routes and handlers are discovered at runtime (rest_get_server()->get_routes() and
 * $wp_filter), so new entry points are covered automatically. A new handler additionally
 * has to be added to nonce_for() – the test fails until it is.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.NonceVerification, WordPress.NamingConventions.PrefixAllGlobals

namespace FormCaptain\Tests\Integration\Security;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Admin\FormsPage;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Service\PreviewTokens;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/SecurityFixtures.php';

final class RouteAuthorizationTest extends TestCase {

	use SecurityFixtures;

	/** Public by design (rechtevertrag.md 6.1); everything else in the namespace is an admin route. */
	private const PUBLIC_ROUTES = array(
		'/form-captain/v1',
		'/form-captain/v1/forms/(?P<public_id>[a-z0-9]{16})/token',
		'/form-captain/v1/forms/(?P<public_id>[a-z0-9]{16})/submissions',
	);

	/** Public admin-post actions (OP-PUB-03, protected by the submission token). */
	private const PUBLIC_HANDLERS = array( 'form_captain_submit' );

	/** @var array{form:array<string,mixed>,entry:int,job:int,preview:string} */
	private array $fx;

	public function set_up(): void {
		parent::set_up();
		$this->fresh_rest_server();
		$admin = $this->as_admin();
		$form  = $this->create_published_form();
		$entry = $this->plugin()->get( EntryRepository::class )->insert(
			array(
				'form_id'         => (int) $form['id'],
				'form_version_id' => (int) $form['published_version_id'],
				'status'          => 'new',
				'request_key'     => hash( 'sha256', 'route-auth' ),
			)
		);
		$this->plugin()->get( EntryRepository::class )->insert_values( $entry, array( 'f_name01' => 'Route Tester', 'f_mail01' => 'route@example.org' ) );
		$job      = $this->plugin()->get( JobRepository::class )->enqueue( 'mail.admin', 'form-captain:1:' . $entry . ':mail.admin', array( 'notification' => 'admin' ), $entry, (int) $form['id'] );
		$this->fx = array(
			'form'    => $form,
			'entry'   => $entry,
			'job'     => $job,
			'preview' => $this->plugin()->get( PreviewTokens::class )->create( (int) $form['id'], $admin )['token'],
		);
		wp_set_current_user( 0 );
		$this->intercept_redirects();
	}

	public function tear_down(): void {
		global $wp_rest_auth_cookie, $wp_rest_application_password_uuid;
		$wp_rest_auth_cookie               = null;
		$wp_rest_application_password_uuid = null;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$this->drop_rest_server();
		parent::tear_down();
	}

	/* ------------------------------------------------------------------ REST */

	/**
	 * All form-captain routes as [route pattern, method, concrete path].
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	private function rest_routes(): array {
		$out = array();
		foreach ( rest_get_server()->get_routes() as $pattern => $endpoints ) {
			if ( ! str_starts_with( $pattern, '/form-captain/' ) ) {
				continue;
			}
			$path = (string) preg_replace_callback(
				'/\(\?P<(\w+)>[^)]*\)/',
				fn( array $m ): string => match ( $m[1] ) {
					'public_id' => (string) $this->fx['form']['public_id'],
					'id', 'form_id' => (string) $this->fx['form']['id'],
					'entry_id' => (string) $this->fx['entry'],
					'job_id' => (string) $this->fx['job'],
					'key' => 'reference',
					'section' => 'general',
					default => '1',
				},
				$pattern
			);
			foreach ( $endpoints as $endpoint ) {
				foreach ( array_keys( (array) $endpoint['methods'] ) as $method ) {
					$out[] = array( $pattern, (string) $method, $path );
				}
			}
		}
		return $out;
	}

	private function dispatch( string $method, string $path ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $path );
		$request->set_header( 'content-type', 'application/json' );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$request->set_body(
				(string) wp_json_encode(
					array(
						'title'          => 'Pwned',
						'template'       => 'contact',
						'schema'         => array( 'schema_version' => 1, 'fields' => array() ),
						'revision'       => (int) $this->fx['form']['revision'],
						'status'         => 'archived',
						'actions'        => array(),
						'delete_entries' => true,
					)
				)
			);
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_discovers_admin_and_public_routes(): void {
		$patterns = array_unique( array_column( $this->rest_routes(), 0 ) );
		foreach ( self::PUBLIC_ROUTES as $public ) {
			$this->assertContains( $public, $patterns );
		}
		$this->assertGreaterThanOrEqual( 8, count( array_diff( $patterns, self::PUBLIC_ROUTES ) ), 'Admin routes must be discovered' );
		foreach ( $patterns as $pattern ) {
			$this->assertTrue( in_array( $pattern, self::PUBLIC_ROUTES, true ) || str_starts_with( $pattern, '/form-captain/v1/admin/' ), 'New public route must be reviewed and listed: ' . $pattern );
		}
	}

	public function test_every_admin_route_refuses_low_roles_and_changes_nothing(): void {
		foreach ( self::low_roles() as $role ) {
			$this->login_as( $role );
			foreach ( $this->rest_routes() as list( $pattern, $method, $path ) ) {
				if ( in_array( $pattern, self::PUBLIC_ROUTES, true ) ) {
					continue;
				}
				$before = $this->state();
				$res    = $this->dispatch( $method, $path );
				$label  = "$role $method $path";
				$this->assertSame( 'anonymous' === $role ? 401 : 403, $res->get_status(), $label );
				$this->assertSame( $before, $this->state(), "State changed: $label" );
				$body = (string) wp_json_encode( $res->get_data() );
				$this->assertStringNotContainsString( 'route@example.org', $body, $label );
				$this->assertStringNotContainsString( 'f_name01', $body, $label );
			}
		}
	}

	public function test_admin_cookie_request_without_or_with_invalid_nonce_is_refused(): void {
		global $wp_rest_auth_cookie;
		foreach ( array( null, 'deadbeef00' ) as $nonce ) {
			foreach ( $this->rest_routes() as list( $pattern, $method, $path ) ) {
				if ( in_array( $pattern, self::PUBLIC_ROUTES, true ) ) {
					continue;
				}
				$this->as_admin();
				$wp_rest_auth_cookie = true; // Request authenticated by the logged-in cookie only.
				unset( $_SERVER['HTTP_X_WP_NONCE'] );
				if ( null !== $nonce ) {
					$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
				}
				$before = $this->state();
				$auth   = apply_filters( 'rest_authentication_errors', null );
				$label  = ( $nonce ?? 'no nonce' ) . " $method $path";
				if ( is_wp_error( $auth ) ) {
					$this->assertSame( 403, (int) ( $auth->get_error_data()['status'] ?? 0 ), $label );
				} else {
					$this->assertSame( 0, get_current_user_id(), 'Core must drop the cookie user without nonce: ' . $label );
					$this->assertSame( 401, $this->dispatch( $method, $path )->get_status(), $label );
				}
				$this->assertSame( $before, $this->state(), "State changed: $label" );
				$wp_rest_auth_cookie = null;
			}
		}
	}

	public function test_admin_routes_refuse_application_passwords(): void {
		global $wp_rest_application_password_uuid;
		$this->as_admin();
		$wp_rest_application_password_uuid = wp_generate_uuid4(); // As set by core after Basic auth via PHP_AUTH_USER.
		foreach ( $this->rest_routes() as list( $pattern, $method, $path ) ) {
			if ( in_array( $pattern, self::PUBLIC_ROUTES, true ) ) {
				continue;
			}
			$before = $this->state();
			$res    = $this->dispatch( $method, $path );
			$this->assertSame( 403, $res->get_status(), "$method $path" );
			$this->assertSame( 'forbidden', $res->get_data()['code'] ?? '', "$method $path" );
			$this->assertSame( $before, $this->state() );
		}
		$wp_rest_application_password_uuid = null;
		$res                               = $this->dispatch( 'GET', '/form-captain/v1/admin/forms' );
		$this->assertSame( 200, $res->get_status(), 'Positive control: cookie admin with nonce context' );
	}

	/* ------------------------------------------------------------- admin-post */

	/** @return string[] action names (without admin_post_ prefix) */
	private function handlers(): array {
		global $wp_filter;
		$out = array();
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( preg_match( '/^admin_post_(nopriv_)?(form_captain_[a-z0-9_]+)$/', (string) $hook, $m ) ) {
				$out[ $m[2] ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Nonce action and field each handler verifies. Unknown handlers return null and fail the test.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function nonce_for( string $action ): ?array {
		$form  = (int) $this->fx['form']['id'];
		$forms = 'form_captain_form_';
		if ( str_starts_with( $action, $forms ) ) {
			return array( FormsPage::nonce_action( substr( $action, strlen( $forms ) ), $form ), '_wpnonce' );
		}
		return match ( $action ) {
			'form_captain_setup_step', 'form_captain_setup_finish', 'form_captain_setup_page', 'form_captain_dismiss_hint', 'form_captain_test_mail', 'form_captain_rotate_secret', 'form_captain_export_settings', 'form_captain_import_settings' => array( $action, '_wpnonce' ),
			'form_captain_save_settings' => array( 'form_captain_settings_general', '_wpnonce' ),
			'form_captain_reset_settings' => array( 'form_captain_reset_general', '_wpnonce' ),
			'form_captain_connector_toggle' => array( 'form_captain_connector_reference', '_wpnonce' ),
			'form_captain_connector_test' => array( 'form_captain_test_reference.create_ticket', '_wpnonce' ),
			'form_captain_job_retry', 'form_captain_job_cancel' => array( 'form_captain_job_' . $this->fx['job'], '_wpnonce' ),
			EntriesPage::ACTION_BULK => array( EntriesPage::NONCE_BULK, '_fc_nonce' ),
			EntriesPage::ACTION_ENTRY => array( EntriesPage::NONCE_ENTRY . $this->fx['entry'], '_fc_nonce' ),
			CsvExporter::ACTION => array( CsvExporter::NONCE_ACTION, '_wpnonce' ),
			'form_captain_preview' => array( '', 'token' ), // The user-bound preview token is the credential.
			\FormCaptain\Integrations\CoreActions::ADMIN_ACTION => array( \FormCaptain\Integrations\CoreActions::nonce_action( (int) $this->fx['entry'], 'a_0000000000' ), '_wpnonce' ),
			default => null,
		};
	}

	/** Request data that would perform the most damaging variant of each action. */
	private function request_data(): array {
		$all = array_fill_keys( Capabilities::ALL, '1' );
		return array(
			'form'             => (string) $this->fx['form']['id'],
			'form_id'          => (string) $this->fx['form']['id'],
			'entry_id'         => (string) $this->fx['entry'],
			'job_id'           => (string) $this->fx['job'],
			'ids'              => array( (string) $this->fx['entry'] ),
			'fc_bulk_action'   => 'delete',
			'do'               => 'delete',
			'status'           => 'archived',
			'note'             => 'pwned',
			'tab'              => 'general',
			'settings'         => array( 'setup_completed' => '1' ),
			'roles'            => array(
				'subscriber' => $all,
				'author'     => $all,
				'editor'     => $all,
			),
			'confirm_reset'    => '1',
			'confirm_rotate'   => '1',
			'discard_previous' => '1',
			'connector'        => 'reference',
			'enable'           => '1',
			'action_key'       => 'reference.create_ticket',
			'template'         => 'contact',
			'delete_entries'   => '1',
			'step'             => 'privacy',
			'columns'          => array( 'meta:id', 'field:f_mail01' ),
			'instance'         => 'a_0000000000',
		);
	}

	/**
	 * Calls a handler like admin-post.php would. Returns 'die', 'redirect' or 'returned'.
	 */
	private function call_handler( string $action, ?string $nonce_action, string $nonce_field, string $hook_prefix = 'admin_post_' ): string {
		$data = $this->request_data();
		if ( null !== $nonce_action ) {
			$data[ $nonce_field ] = 'token' === $nonce_field ? $this->fx['preview'] : wp_create_nonce( $nonce_action );
		}
		$_POST                     = $data;
		$_GET                      = $data;
		$_REQUEST                  = $data;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		ob_start();
		// Handlers send headers before exit/download; CLI output has started already, which is irrelevant here.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static fn( int $no, string $msg ): bool => str_contains( $msg, 'Cannot modify header information' ),
			E_WARNING
		);
		try {
			do_action( $hook_prefix . $action );
			$outcome = 'returned';
		} catch ( \WPDieException $e ) {
			$outcome = 'die';
		} catch ( RedirectIntercepted $e ) {
			$outcome = 'redirect';
		} finally {
			restore_error_handler();
			$output = (string) ob_get_clean();
		}
		$this->assertStringNotContainsString( 'route@example.org', $output, $action );
		return $outcome;
	}

	public function test_discovers_all_admin_post_handlers(): void {
		$handlers = $this->handlers();
		$this->assertGreaterThanOrEqual( 25, count( $handlers ) );
		foreach ( $handlers as $action ) {
			if ( in_array( $action, self::PUBLIC_HANDLERS, true ) ) {
				continue;
			}
			$this->assertNotNull( $this->nonce_for( $action ), 'New admin-post handler must be reviewed and added to nonce_for(): ' . $action );
		}
	}

	public function test_every_handler_refuses_low_roles_with_and_without_nonce(): void {
		foreach ( self::low_roles() as $role ) {
			foreach ( $this->handlers() as $action ) {
				if ( in_array( $action, self::PUBLIC_HANDLERS, true ) ) {
					continue;
				}
				list( $nonce_action, $field ) = (array) $this->nonce_for( $action ) + array( '', '_wpnonce' );
				foreach ( array( false, true ) as $with_nonce ) {
					$this->login_as( $role );
					$before  = $this->state();
					$outcome = $this->call_handler( $action, $with_nonce ? $nonce_action : null, $field );
					$label   = "$role $action " . ( $with_nonce ? 'with nonce' : 'without nonce' );
					$this->assertSame( 'die', $outcome, $label );
					$this->assertSame( $before, $this->state(), "State changed: $label" );
				}
			}
		}
	}

	public function test_anonymous_nopriv_hooks_are_only_the_public_submission(): void {
		global $wp_filter;
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( preg_match( '/^admin_post_nopriv_(form_captain_[a-z0-9_]+)$/', (string) $hook, $m ) ) {
				$this->assertContains( $m[1], self::PUBLIC_HANDLERS, 'Unexpected public admin-post handler ' . $m[1] );
			}
		}
	}

	public function test_admin_without_nonce_is_refused_by_every_handler(): void {
		foreach ( $this->handlers() as $action ) {
			if ( in_array( $action, self::PUBLIC_HANDLERS, true ) ) {
				continue;
			}
			list( , $field ) = (array) $this->nonce_for( $action ) + array( '', '_wpnonce' );
			$this->as_admin();
			$before = $this->state();
			$this->assertSame( 'die', $this->call_handler( $action, null, $field ), $action );
			$this->assertSame( $before, $this->state(), "State changed: admin $action without nonce" );
		}
	}

	public function test_admin_with_foreign_nonce_is_refused(): void {
		// A valid nonce for one action/object must not unlock another (no shared nonce actions).
		$this->as_admin();
		foreach ( $this->handlers() as $action ) {
			if ( in_array( $action, self::PUBLIC_HANDLERS, true ) || 'form_captain_preview' === $action ) {
				continue;
			}
			list( , $field ) = (array) $this->nonce_for( $action ) + array( '', '_wpnonce' );
			$before          = $this->state();
			$this->assertSame( 'die', $this->call_handler( $action, 'form_captain_some_other_action', $field ), $action );
			$this->assertSame( $before, $this->state(), $action );
		}
	}

	public function test_public_submission_handler_ignores_logged_in_capabilities(): void {
		// The public handler never acts with the user's rights: no entry without valid token, user_id stays 0.
		$this->as_admin();
		$before = $this->state();
		$res    = $this->plugin()->get( \FormCaptain\Http\AdminPostController::class )->process(
			array(
				'_fc_form' => (string) $this->fx['form']['public_id'],
				'fc'       => array( 'f_name01' => 'x', 'f_mail01' => 'x@example.org' ),
			),
			array( 'HTTP_REFERER' => 'https://evil.example/' )
		);
		$this->assertSame( 'invalid_token', $res['result']['code'] );
		$this->assertStringStartsWith( home_url( '/' ), $res['location'], 'No open redirect to the referer host' );
		$this->assertSame( $before, $this->state() );
	}
}
