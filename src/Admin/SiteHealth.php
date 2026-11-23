<?php
/**
 * Site Health tests and debug information based on aggregated status data only (P9-14).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

final class SiteHealth {

	public function __construct( private SystemStatus $status ) {}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	/**
	 * @param array<string,mixed> $tests
	 * @return array<string,mixed>
	 */
	public function tests( array $tests ): array {
		foreach ( array( 'queue', 'mail', 'cron', 'schema', 'database_version', 'retention' ) as $name ) {
			$tests['direct'][ 'form_captain_' . $name ] = array(
				'label' => __( 'Form Captain', 'form-captain' ),
				'test'  => array( $this, 'test_' . $name ),
			);
		}
		return $tests;
	}

	/** @return array<string,mixed> */
	private function result( string $test, bool $ok, string $label_ok, string $label_bad, string $description, string $status_bad = 'recommended' ): array {
		return array(
			'label'       => $ok ? $label_ok : $label_bad,
			'status'      => $ok ? 'good' : $status_bad,
			'badge'       => array(
				'label' => __( 'Form Captain', 'form-captain' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => current_user_can( 'form_captain_manage_settings' ) ? '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-settings&tab=status' ) ) . '">' . esc_html__( 'Open the Form Captain system status', 'form-captain' ) . '</a></p>' : '',
			'test'        => 'form_captain_' . $test,
		);
	}

	/** @return array<string,mixed> */
	public function test_queue(): array {
		$d    = $this->status->collect();
		$ok   = ! $d['queue']['delayed'] && 0 === (int) ( $d['queue']['counts']['dead'] ?? 0 );
		$desc = $ok
			? __( 'Notifications and integration jobs are processed in time.', 'form-captain' )
			: sprintf(
				/* translators: 1: minutes the oldest job waits, 2: number of dead jobs */
				__( 'The oldest due job waits %1$d minutes; %2$d jobs failed permanently. Check WP-Cron or run "wp form-captain jobs run".', 'form-captain' ),
				(int) floor( $d['queue']['oldest_due_age_s'] / 60 ),
				(int) ( $d['queue']['counts']['dead'] ?? 0 )
			);
		$paused = (int) ( $d['queue']['paused'] ?? 0 );
		if ( $paused > 0 ) {
			$desc .= ' ' . sprintf(
				/* translators: %d: number of paused jobs */
				_n( '%d integration job is paused because its connector is disabled or missing (not counted as backlog).', '%d integration jobs are paused because their connector is disabled or missing (not counted as backlog).', $paused, 'form-captain' ),
				$paused
			);
		}
		return $this->result( 'queue', $ok, __( 'Form Captain queue is healthy', 'form-captain' ), __( 'Form Captain queue has a backlog', 'form-captain' ), $desc );
	}

	/** @return array<string,mixed> */
	public function test_mail(): array {
		$d      = $this->status->collect();
		$failed = array_sum( $d['mail']['failed_24h'] );
		$desc   = 0 === $failed
			? __( 'No Form Captain e-mail failed in the last 24 hours.', 'form-captain' )
			: sprintf(
				/* translators: 1: number of failed mails, 2: error codes */
				__( '%1$d e-mail jobs failed in the last 24 hours (codes: %2$s). Send a test e-mail from the Form Captain settings and check your mail setup.', 'form-captain' ),
				$failed,
				implode( ', ', array_keys( $d['mail']['failed_24h'] ) )
			);
		return $this->result( 'mail', 0 === $failed, __( 'Form Captain e-mails are delivered', 'form-captain' ), __( 'Form Captain e-mails failed recently', 'form-captain' ), $desc, 'critical' );
	}

	/** @return array<string,mixed> */
	public function test_cron(): array {
		$d  = $this->status->collect();
		$ok = null !== $d['queue']['next_worker_run'] && ! ( $d['queue']['wp_cron_disabled'] && $d['queue']['delayed'] );
		return $this->result(
			'cron',
			$ok,
			__( 'Form Captain background processing is scheduled', 'form-captain' ),
			__( 'Form Captain background processing is not running', 'form-captain' ),
			$ok ? __( 'The job worker is scheduled via WP-Cron or a system cron.', 'form-captain' ) : __( 'The job worker is not scheduled or WP-Cron is disabled without a system cron. Run "wp form-captain jobs run" every minute.', 'form-captain' )
		);
	}

	/** @return array<string,mixed> */
	public function test_schema(): array {
		$d  = $this->status->collect();
		$ok = $d['schema']['ok'] && ! in_array( 'missing', $d['schema']['engines'], true );
		return $this->result(
			'schema',
			$ok,
			__( 'Form Captain database is up to date', 'form-captain' ),
			__( 'Form Captain database needs attention', 'form-captain' ),
			$ok ? __( 'All tables exist and the schema version matches.', 'form-captain' ) : __( 'The schema version does not match or a table is missing. Reactivate the plugin; data is never deleted by this.', 'form-captain' ),
			'critical'
		);
	}

	/**
	 * S9: reminds the operator as long as no retention period is set globally.
	 *
	 * @return array<string,mixed>
	 */
	public function test_retention(): array {
		$mode   = (string) \FormCaptain\Settings\Settings::get( 'privacy.retention' );
		$ok     = null !== \FormCaptain\Settings\RetentionPeriod::parts( $mode );
		$result = $this->result(
			'retention',
			$ok,
			__( 'Form Captain deletes submissions after a retention period', 'form-captain' ),
			__( 'Form Captain has no retention period set', 'form-captain' ),
			$ok
				/* translators: %s: retention period */
				? sprintf( __( 'Submissions are deleted automatically %s after they were received. Have the period checked legally.', 'form-captain' ), \FormCaptain\Settings\RetentionPeriod::label( $mode ) )
				: __( 'Submissions are kept until someone deletes them. Personal data should only be kept as long as needed: set a retention period (a country profile suggests one) and have it checked legally.', 'form-captain' )
		);
		if ( ! $ok && current_user_can( 'form_captain_manage_settings' ) ) {
			$result['actions'] = '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-settings&tab=privacy' ) ) . '">' . esc_html__( 'Set a retention period', 'form-captain' ) . '</a></p>';
		}
		return $result;
	}

	/** @return array<string,mixed> */
	public function test_database_version(): array {
		$d       = $this->status->collect();
		$warning = \FormCaptain\Support\Requirements::database_warning( (string) ( $d['environment']['database'] ?? '' ) );
		return $this->result(
			'database_version',
			null === $warning,
			__( 'The database server meets the Form Captain minimum', 'form-captain' ),
			__( 'The database server is older than the Form Captain minimum', 'form-captain' ),
			$warning ?? __( 'MySQL 9.7 or newer, or MariaDB 12.3 or newer.', 'form-captain' )
		);
	}

	/**
	 * @param array<string,mixed> $info
	 * @return array<string,mixed>
	 */
	public function debug_information( array $info ): array {
		$d      = $this->status->collect();
		$fields = array();
		foreach ( array( 'environment', 'schema', 'queue', 'settings' ) as $section ) {
			foreach ( $d[ $section ] as $k => $v ) {
				$fields[ $section . '_' . $k ] = array(
					'label' => $section . '.' . $k,
					'value' => SystemStatus::scrub( is_scalar( $v ) || null === $v ? var_export( $v, true ) : (string) wp_json_encode( $v ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				);
			}
		}
		$info['form-captain'] = array(
			'label'  => __( 'Form Captain', 'form-captain' ),
			'fields' => $fields,
		);
		return $info;
	}
}
