<?php
/**
 * Integrations screen (P8-04, P8-10, P8-21, P8-26, P8-31, P8-33).
 *
 * Lists connectors with version, contract, state, requirements and capabilities; opt-in toggles,
 * safe test runs (test_mode = true, no entry, no job), connector job list with retry/cancel and
 * diagnostics with a next step. All writes need form_captain_manage_integrations and a nonce.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Install\Capabilities;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Plugin;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Logger;

final class IntegrationsPage {

	public const SLUG = 'form-captain-integrations';

	/** Rate limit for test runs: 5 per 10 minutes per user and action (RL-07). */
	private const TEST_LIMIT = 5;

	public function __construct( private ConnectorRegistry $registry, private JobRepository $jobs ) {}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_post_form_captain_connector_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_form_captain_connector_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_form_captain_job_retry', array( $this, 'handle_retry' ) );
		add_action( 'admin_post_form_captain_job_cancel', array( $this, 'handle_cancel' ) );
	}

	/* --- testable operations -------------------------------------------- */

	/** @return array{type:string,messages:string[]} */
	public function toggle( string $key, bool $enable ): array {
		if ( $enable ) {
			$res = $this->registry->activate( $key );
			if ( is_wp_error( $res ) ) {
				return self::notice( 'error', array( $res->get_error_message(), ConnectorRegistry::next_step( (string) $res->get_error_code() ) ) );
			}
			return self::notice( 'success', array( __( 'Connector activated. Its actions can now be enabled per form. Paused jobs continue automatically.', 'form-captain' ) ) );
		}
		$this->registry->deactivate( $key );
		return self::notice( 'success', array( __( 'Connector deactivated. Its open jobs are paused and continue when you activate it again; no data is deleted in the target system.', 'form-captain' ) ) );
	}

	/** @return array{type:string,messages:string[]} */
	public function run_test( string $action_key ): array {
		$bucket = 'form_captain_test_' . get_current_user_id() . '_' . md5( $action_key );
		$hits   = (int) get_transient( $bucket );
		if ( $hits >= self::TEST_LIMIT ) {
			return self::notice( 'error', array( __( 'Too many test runs. Please wait ten minutes.', 'form-captain' ) ) );
		}
		set_transient( $bucket, $hits + 1, 10 * MINUTE_IN_SECONDS );
		$res = $this->registry->test_action( $action_key );
		if ( is_wp_error( $res ) ) {
			return self::notice( 'error', array( $res->get_error_message() ) );
		}
		$messages = array(
			sprintf(
				/* translators: 1: status, 2: code */
				__( 'Test run finished with status "%1$s" (code: %2$s). No entry or job was created.', 'form-captain' ),
				$res['status'],
				$res['code']
			),
			/* translators: %s: list of field keys */
			sprintf( __( 'Sample fields sent: %s', 'form-captain' ), implode( ', ', array_keys( $res['values'] ) ) ?: '–' ),
		);
		if ( 'success' !== $res['status'] ) {
			$messages[] = ConnectorRegistry::next_step( $res['code'] );
		}
		return self::notice( 'success' === $res['status'] ? 'success' : 'error', $messages );
	}

	/** @return array{type:string,messages:string[]} */
	public function retry_job( int $id ): array {
		$job = $this->jobs->find( $id );
		if ( ! $job || ! str_starts_with( (string) $job['type'], 'connector.' ) ) {
			return self::notice( 'error', array( __( 'Job not found.', 'form-captain' ) ) );
		}
		if ( ! $this->jobs->retry( $id ) ) {
			return self::notice( 'error', array( __( 'Only failed, dead or cancelled jobs can be retried.', 'form-captain' ) ) );
		}
		Logger::log( 'info', 'job_manual_retry', 'user ' . get_current_user_id(), array( 'job_id' => $id ) );
		return self::notice( 'success', array( __( 'Job queued again. It runs with the next worker run.', 'form-captain' ) ) );
	}

	/** @return array{type:string,messages:string[]} */
	public function cancel_job( int $id ): array {
		$job = $this->jobs->find( $id );
		if ( ! $job || ! str_starts_with( (string) $job['type'], 'connector.' ) ) {
			return self::notice( 'error', array( __( 'Job not found.', 'form-captain' ) ) );
		}
		if ( ! $this->jobs->cancel( $id ) ) {
			return self::notice( 'error', array( __( 'This job cannot be cancelled.', 'form-captain' ) ) );
		}
		Logger::log( 'info', 'job_manual_cancel', 'user ' . get_current_user_id(), array( 'job_id' => $id ) );
		return self::notice( 'success', array( __( 'Job cancelled.', 'form-captain' ) ) );
	}

	/* --- admin-post ------------------------------------------------------- */

	private function guard( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage integrations.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	public function handle_toggle(): void {
		$key = isset( $_POST['connector'] ) ? sanitize_key( wp_unslash( $_POST['connector'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->guard( 'form_captain_connector_' . $key );
		$this->redirect( $this->toggle( $key, ! empty( $_POST['enable'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
	}

	public function handle_test(): void {
		$action = isset( $_POST['action_key'] ) ? preg_replace( '/[^a-z0-9_.]/', '', (string) wp_unslash( $_POST['action_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->guard( 'form_captain_test_' . $action );
		$this->redirect( $this->run_test( (string) $action ) );
	}

	public function handle_retry(): void {
		$id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->guard( 'form_captain_job_' . $id );
		$this->redirect( $this->retry_job( $id ) );
	}

	public function handle_cancel(): void {
		$id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->guard( 'form_captain_job_' . $id );
		$this->redirect( $this->cancel_job( $id ) );
	}

	/** @param array{type:string,messages:string[]} $notice */
	private function redirect( array $notice ): never {
		set_transient( 'form_captain_integrations_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @param string[] $messages
	 * @return array{type:string,messages:string[]}
	 */
	private static function notice( string $type, array $messages ): array {
		return array(
			'type'     => $type,
			'messages' => array_values( array_filter( $messages ) ),
		);
	}

	/* --- rendering ---------------------------------------------------------- */

	public static function state_label( string $state ): string {
		return match ( $state ) {
			ConnectorRegistry::STATE_ACTIVE => __( 'Active', 'form-captain' ),
			ConnectorRegistry::STATE_DISABLED => __( 'Disabled', 'form-captain' ),
			ConnectorRegistry::STATE_DETECTED => __( 'Detected – not activated', 'form-captain' ),
			ConnectorRegistry::STATE_INCOMPATIBLE => __( 'Incompatible', 'form-captain' ),
			ConnectorRegistry::STATE_MISSING => __( 'Plugin missing', 'form-captain' ),
			default => $state,
		};
	}

	public static function job_status_label( string $status ): string {
		return match ( $status ) {
			'pending' => __( 'Waiting', 'form-captain' ),
			'running' => __( 'Running', 'form-captain' ),
			'succeeded' => __( 'Delivered', 'form-captain' ),
			'failed' => __( 'Failed – will retry', 'form-captain' ),
			'dead' => __( 'Dead-letter', 'form-captain' ),
			'cancelled' => __( 'Cancelled', 'form-captain' ),
			'paused' => __( 'Paused (connector disabled)', 'form-captain' ),
			default => $status,
		};
	}

	/**
	 * Status label of a concrete job; paused jobs distinguish "connector disabled" and "connector missing".
	 *
	 * @param array<string,mixed> $job
	 */
	public function job_label_for( array $job ): string {
		if ( 'paused' === $job['status'] ) {
			return $this->registry->paused_label( $job );
		}
		return self::job_status_label( (string) $job['status'] );
	}

	public static function paused_hint( string $reason ): string {
		return 'connector_not_found' === $reason
			? __( 'Connector missing: the job stays paused until the connector plugin is installed and activated again. You can cancel it here.', 'form-captain' )
			: __( 'The job does not run while the connector is disabled and continues automatically when you activate it. Attempts are not counted.', 'form-captain' );
	}

	/**
	 * Paused jobs per connector key, plus '' for jobs whose connector is missing.
	 *
	 * @return array<string,int>
	 */
	public function paused_counts(): array {
		$out = array();
		foreach ( $this->jobs->paused_by_action() as $action_key => $count ) {
			$job         = array( 'payload' => array( 'action_key' => $action_key ) );
			$action      = $this->registry->action( (string) $action_key );
			$key         = 'connector_not_found' === $this->registry->paused_reason( $job ) ? '' : (string) ( $action['connector'] ?? '' );
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + $count;
		}
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage integrations.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		$notice = get_transient( 'form_captain_integrations_notice_' . get_current_user_id() );
		delete_transient( 'form_captain_integrations_notice_' . get_current_user_id() );
		$connectors = $this->registry->all();
		$actions    = $this->registry->actions();

		echo '<div class="wrap fc-integrations"><h1>' . esc_html__( 'Integrations', 'form-captain' ) . '</h1>';
		echo '<p class="fc-tab-intro">' . esc_html__( 'Connectors are separate plugins that pass selected form data to other systems. Installed connectors stay disabled until you activate them. Connectors receive only the fields you map in a form – sensitive fields are blocked.', 'form-captain' ) . '</p>';
		$pro = apply_filters( 'form_captain_pro_active', false );
		if ( $pro ) {
			$status = is_array( $pro ) ? (string) ( $pro['license_status'] ?? '' ) : '';
			/* translators: %s: licence status */
			echo '<p><strong>' . esc_html__( 'Form Captain Pro is active.', 'form-captain' ) . '</strong> ' . ( '' !== $status ? esc_html( sprintf( __( 'Licence status: %s', 'form-captain' ), $status ) ) : '' ) . '</p>';
		}
		if ( is_array( $notice ) && ! empty( $notice['messages'] ) ) {
			echo '<div class="notice notice-' . ( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible" role="status">';
			foreach ( (array) $notice['messages'] as $m ) {
				echo '<p>' . esc_html( (string) $m ) . '</p>';
			}
			echo '</div>';
		}

		$paused = $this->paused_counts();
		if ( ! empty( $paused[''] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of paused jobs */
					_n( '%d job is paused because its connector is missing.', '%d jobs are paused because their connector is missing.', (int) $paused[''], 'form-captain' ),
					(int) $paused['']
				)
			) . ' ' . esc_html( self::paused_hint( 'connector_not_found' ) ) . ' <a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'job_status' => 'paused' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Show paused jobs', 'form-captain' ) . '</a></p></div>';
		}

		echo '<h2>' . esc_html__( 'Connectors', 'form-captain' ) . '</h2>';
		if ( ! $connectors ) {
			echo '<div class="fc-empty"><p>' . esc_html__( 'No connector is installed. Connectors are installed as separate WordPress plugins; see the developer guide docs/CONNECTORS.md for building one.', 'form-captain' ) . '</p></div>';
		}
		foreach ( $connectors as $key => $c ) {
			$this->render_connector( (string) $key, $c, $actions, (int) ( $paused[ (string) $key ] ?? 0 ) );
		}

		$standalone = array_filter( $actions, static fn( $a ) => null === $a['connector'] );
		if ( $standalone ) {
			echo '<h2>' . esc_html__( 'Other form actions', 'form-captain' ) . '</h2><ul>';
			foreach ( $standalone as $akey => $a ) {
				echo '<li><code>' . esc_html( (string) $akey ) . '</code> – ' . esc_html( (string) $a['label'] ) . '</li>';
			}
			echo '</ul>';
		}

		$this->render_jobs();
		echo '</div>';
	}

	/**
	 * @param array<string,mixed>                $c
	 * @param array<string,array<string,mixed>>  $actions
	 */
	private function render_connector( string $key, array $c, array $actions, int $paused = 0 ): void {
		$state = (string) $c['state'];
		echo '<div class="fc-card fc-connector fc-state-' . esc_attr( $state ) . '">';
		echo '<h3>' . esc_html( (string) $c['label'] ) . ' <span class="fc-badge">' . esc_html( self::state_label( $state ) ) . '</span></h3>';
		echo '<table class="widefat fc-status-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Key', 'form-captain' ) . '</th><td><code>' . esc_html( $key ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Plugin version', 'form-captain' ) . '</th><td>' . esc_html( (string) $c['version'] ?: '–' ) . '</td></tr>';
		/* translators: %s: core contract version */
		echo '<tr><th scope="row">' . esc_html__( 'Contract', 'form-captain' ) . '</th><td>' . esc_html( (string) $c['contract'] ?: '–' ) . ' <span class="description">' . esc_html( sprintf( __( '(Form Captain provides %s)', 'form-captain' ), FORM_CAPTAIN_CONTRACT_VERSION ) ) . '</span></td></tr>';
		$req = (array) $c['requirements'];
		echo '<tr><th scope="row">' . esc_html__( 'Requirements', 'form-captain' ) . '</th><td>' . ( ! empty( $req['ok'] ) ? esc_html__( 'Met', 'form-captain' ) : esc_html__( 'Not met', 'form-captain' ) );
		foreach ( (array) ( $req['messages'] ?? array() ) as $msg ) {
			echo '<br><span class="description">' . esc_html( (string) $msg ) . '</span>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Capabilities', 'form-captain' ) . '</th><td>' . esc_html( implode( ', ', (array) $c['capabilities'] ) ?: '–' ) . '</td></tr>';
		if ( $paused > 0 ) {
			echo '<tr><th scope="row">' . esc_html__( 'Paused jobs', 'form-captain' ) . '</th><td>' . esc_html( (string) $paused ) . ' <span class="description">' . esc_html( self::job_status_label( 'paused' ) ) . '</span></td></tr>';
		}
		echo '</tbody></table>';

		if ( '' !== (string) $c['code'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( (string) $c['code'] ) . ':</strong> ' . esc_html( (string) $c['message'] ) . '</p><p>' . esc_html( ConnectorRegistry::next_step( (string) $c['code'] ) ) . '</p></div>';
		}

		if ( in_array( $state, array( ConnectorRegistry::STATE_ACTIVE, ConnectorRegistry::STATE_DISABLED, ConnectorRegistry::STATE_DETECTED ), true ) ) {
			$enable = ConnectorRegistry::STATE_ACTIVE !== $state;
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fc-inline-form"' . ( $enable ? '' : ' data-fc-confirm="' . esc_attr__( 'Deactivate this connector? Its open jobs are paused until you activate it again.', 'form-captain' ) . '"' ) . '>';
			echo '<input type="hidden" name="action" value="form_captain_connector_toggle"><input type="hidden" name="connector" value="' . esc_attr( $key ) . '"><input type="hidden" name="enable" value="' . ( $enable ? '1' : '0' ) . '">';
			wp_nonce_field( 'form_captain_connector_' . $key );
			submit_button( $enable ? __( 'Activate', 'form-captain' ) : __( 'Deactivate', 'form-captain' ), $enable ? 'primary' : 'secondary', 'submit', false, $enable && empty( $req['ok'] ) ? array( 'disabled' => 'disabled' ) : null );
			echo '</form>';
		}

		$own = array_filter( $actions, static fn( $a ) => $key === $a['connector'] );
		if ( $own ) {
			echo '<h4>' . esc_html__( 'Form actions', 'form-captain' ) . '</h4><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Action', 'form-captain' ) . '</th><th>' . esc_html__( 'Mapped fields', 'form-captain' ) . '</th><th>' . esc_html__( 'Test', 'form-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $own as $akey => $a ) {
				$fields = array();
				foreach ( (array) $a['mapping'] as $m ) {
					$fields[] = $m['label'] . ( $m['required'] ? ' *' : '' ) . ( $m['allow_sensitive'] ? ' (' . __( 'may be sensitive', 'form-captain' ) . ')' : '' );
				}
				echo '<tr><td><strong>' . esc_html( (string) $a['label'] ) . '</strong><br><code>' . esc_html( (string) $akey ) . '</code></td><td>' . esc_html( implode( ', ', $fields ) ?: '–' ) . '</td><td>';
				if ( ConnectorRegistry::STATE_ACTIVE === $state ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="form_captain_connector_test"><input type="hidden" name="action_key" value="' . esc_attr( (string) $akey ) . '">';
					wp_nonce_field( 'form_captain_test_' . $akey );
					submit_button( __( 'Run test', 'form-captain' ), 'secondary small', 'submit', false );
					echo '<p class="description">' . esc_html__( 'Uses sample values in test mode. No entry, no job.', 'form-captain' ) . '</p></form>';
				} else {
					echo '<span class="description">' . esc_html__( 'Activate the connector to run a test.', 'form-captain' ) . '</span>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private function render_jobs(): void {
		$status = isset( $_GET['job_status'] ) ? sanitize_key( wp_unslash( $_GET['job_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list   = $this->jobs->list(
			array(
				'type_prefix' => 'connector.',
				'status'      => in_array( $status, JobRepository::STATUSES, true ) ? $status : '',
				'per_page'    => 20,
				'page'        => $page,
			)
		);
		echo '<h2>' . esc_html__( 'Integration jobs', 'form-captain' ) . '</h2>';
		echo '<ul class="subsubsub">';
		$base = add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
		echo '<li><a href="' . esc_url( $base ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'form-captain' ) . '</a> | </li>';
		foreach ( array( 'pending', 'paused', 'failed', 'dead', 'succeeded', 'cancelled' ) as $s ) {
			echo '<li><a href="' . esc_url( add_query_arg( 'job_status', $s, $base ) ) . '"' . ( $s === $status ? ' class="current"' : '' ) . '>' . esc_html( self::job_status_label( $s ) ) . '</a>' . ( 'cancelled' !== $s ? ' | ' : '' ) . '</li>';
		}
		echo '</ul><br class="clear">';
		if ( ! $list['items'] ) {
			echo '<p>' . esc_html__( 'No integration jobs yet. Jobs appear here after a form with an enabled action receives a submission.', 'form-captain' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped fc-jobs"><thead><tr><th>ID</th><th>' . esc_html__( 'Action', 'form-captain' ) . '</th><th>' . esc_html__( 'Entry', 'form-captain' ) . '</th><th>' . esc_html__( 'Status', 'form-captain' ) . '</th><th>' . esc_html__( 'Attempts', 'form-captain' ) . '</th><th>' . esc_html__( 'Last run / next step', 'form-captain' ) . '</th><th>' . esc_html__( 'Manage', 'form-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $list['items'] as $job ) {
			$code = (string) ( $job['last_error_code'] ?? '' );
			echo '<tr><td>' . esc_html( (string) $job['id'] ) . '</td><td><code>' . esc_html( (string) ( $job['payload']['action_key'] ?? $job['type'] ) ) . '</code></td><td>' . esc_html( (string) ( $job['entry_id'] ?? '–' ) ) . '</td>';
			echo '<td>' . esc_html( $this->job_label_for( $job ) ) . '</td><td>' . esc_html( $job['attempts'] . ' / ' . $job['max_attempts'] ) . '</td><td>' . esc_html( (string) $job['updated_gmt'] ) . ' UTC';
			if ( 'succeeded' === $job['status'] && ! empty( $job['result']['remote_id'] ) ) {
				echo '<br>' . esc_html__( 'Remote ID:', 'form-captain' ) . ' <code>' . esc_html( (string) $job['result']['remote_id'] ) . '</code>';
			}
			if ( 'paused' === $job['status'] ) {
				echo '<br>' . esc_html( self::paused_hint( $this->registry->paused_reason( $job ) ) );
			} elseif ( '' !== $code && 'succeeded' !== $job['status'] ) {
				echo '<br><code>' . esc_html( $code ) . '</code> – ' . esc_html( ConnectorRegistry::next_step( $code ) );
			}
			echo '</td><td>';
			if ( in_array( $job['status'], array( 'failed', 'dead', 'cancelled' ), true ) ) {
				$this->job_button( (int) $job['id'], 'form_captain_job_retry', __( 'Retry', 'form-captain' ) );
			}
			if ( in_array( $job['status'], array( 'pending', 'failed', 'dead', 'paused' ), true ) ) {
				$this->job_button( (int) $job['id'], 'form_captain_job_cancel', __( 'Cancel', 'form-captain' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $list['total'] / 20 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'total' => $pages, 'current' => $page ) ) ) . '</div></div>';
		}
	}

	private function job_button( int $id, string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fc-inline-form"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="job_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'form_captain_job_' . $id );
		submit_button( $label, 'small', 'submit', false );
		echo '</form>';
	}
}
