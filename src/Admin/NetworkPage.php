<?php
/**
 * Network admin page "Form Captain" (SET-FC-42, SET-FC-35).
 *
 * Shows every site with schema version, migration state and queue backlog (counts only, never
 * form or entry contents), starts the mass migration and keeps the network settings: super admin
 * access to entries (decision 23, default off), whether sites may delete their data on uninstall
 * and presets for new sites. Requires `manage_network_options`.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Database\Migrator;
use FormCaptain\Network\Network;
use FormCaptain\Network\NetworkMigration;
use FormCaptain\Network\NetworkSettings;
use FormCaptain\Plugin;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\RetentionPeriod;
use FormCaptain\Settings\SettingsSchema;

final class NetworkPage {

	public const SLUG = 'form-captain-network';

	public const CAPABILITY = 'manage_network_options';

	/** Sites per page in the overview. */
	public const PER_PAGE = 50;

	public function register( Plugin $plugin ): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_edit_form_captain_network_save', array( $this, 'handle_save' ) );
		add_action( 'network_admin_edit_form_captain_network_migrate', array( $this, 'handle_migrate' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Form Captain', 'form-captain' ),
			__( 'Form Captain', 'form-captain' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-feedback',
			26
		);
	}

	/* --- Data -------------------------------------------------------------- */

	/**
	 * Status of one site without any form or entry contents.
	 *
	 * @return array{site_id:int,url:string,schema:int,state:string,error:string,queue:array<string,int>,oldest_due_minutes:int}
	 */
	public static function site_status( int $site_id ): array {
		$row    = array(
			'site_id'            => $site_id,
			'url'                => '',
			'schema'             => 0,
			'state'              => 'not_installed',
			'error'              => '',
			'queue'              => array(),
			'oldest_due_minutes' => 0,
		);
		$failed = Network::in_site(
			$site_id,
			static function () use ( &$row ): void {
				global $wpdb;
				$migrator      = new Migrator();
				$row['url']    = home_url( '/' );
				$row['schema'] = $migrator->installed_version();
				$state         = get_option( Migrator::OPTION_STATE, null );
				$error         = is_array( $state ) ? (string) ( $state['error'] ?? '' ) : '';
				if ( '' !== $error ) {
					$row['state'] = 'failed';
					$row['error'] = $error;
				} elseif ( $migrator->is_running() ) {
					$row['state'] = 'running';
				} elseif ( $row['schema'] >= Migrator::TARGET_VERSION ) {
					$row['state'] = 'current';
				} elseif ( $row['schema'] > 0 ) {
					$row['state'] = 'outdated';
				} elseif ( get_option( Network::OPTION_PENDING ) ) {
					$row['state'] = 'pending';
				}
				if ( $row['schema'] < 1 ) {
					return;
				}
				$suppress = $wpdb->suppress_errors( true );
				try {
					$health = Plugin::instance()->get( JobRepository::class )->health();
				} finally {
					$wpdb->suppress_errors( $suppress );
				}
				$counts                    = (array) $health['counts'];
				$row['queue']              = array(
					'pending' => (int) ( $counts['pending'] ?? 0 ),
					'failed'  => (int) ( $counts['failed'] ?? 0 ),
					'paused'  => (int) ( $counts['paused'] ?? 0 ),
					'dead'    => (int) ( $counts['dead'] ?? 0 ),
				);
				$row['oldest_due_minutes'] = intdiv( (int) $health['oldest_due_age_s'], 60 );
			}
		);
		if ( null !== $failed ) {
			$row['state'] = 'failed';
			$row['error'] = $failed;
		}
		return $row;
	}

	/* --- Actions ----------------------------------------------------------- */

	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the network settings of Form Captain.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Saves the network settings.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,string> errors path => message
	 */
	public function save( array $input ): array {
		list( $values, $errors ) = NetworkSettings::sanitize( $input );
		if ( ! $errors ) {
			NetworkSettings::update( $values );
		}
		return $errors;
	}

	public function handle_save(): void {
		$this->guard( 'form_captain_network_save' );
		$input  = isset( $_POST['network'] ) && is_array( $_POST['network'] ) ? wp_unslash( $_POST['network'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(), sanitized in NetworkSettings::sanitize().
		$errors = $this->save( (array) $input );
		$this->redirect( $errors ? 'invalid' : 'saved', $errors ? implode( ' ', $errors ) : '' );
	}

	public function handle_migrate(): void {
		$this->guard( 'form_captain_network_migrate' );
		NetworkMigration::start( true );
		$this->redirect( 'migration_started' );
	}

	private function redirect( string $notice, string $detail = '' ): void {
		$args = array(
			'page'      => self::SLUG,
			'fc_notice' => $notice,
		);
		if ( '' !== $detail ) {
			$args['fc_detail'] = rawurlencode( mb_substr( $detail, 0, 300 ) );
		}
		wp_safe_redirect( add_query_arg( $args, network_admin_url( 'admin.php' ) ) );
		exit;
	}

	/* --- Rendering --------------------------------------------------------- */

	private static function state_label( string $state ): string {
		return match ( $state ) {
			'current' => __( 'Up to date', 'form-captain' ),
			'outdated' => __( 'Migration pending (runs on the next request)', 'form-captain' ),
			'running' => __( 'Migration running', 'form-captain' ),
			'failed' => __( 'Migration failed', 'form-captain' ),
			'pending' => __( 'Installation planned (runs on the first request)', 'form-captain' ),
			default => __( 'Not installed yet (installs on the first request)', 'form-captain' ),
		};
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the network settings of Form Captain.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		$settings = NetworkSettings::all();
		echo '<div class="wrap"><h1>' . esc_html__( 'Form Captain', 'form-captain' ) . '</h1>';
		$this->render_notice();
		echo '<p>' . esc_html__( 'Every site keeps its own forms, submissions, settings and queue. This page shows only the technical state of each site, never form or submission contents.', 'form-captain' ) . '</p>';
		$this->render_migration( (array) $settings['migration'] );
		$this->render_sites();
		$this->render_settings( $settings );
		echo '</div>';
	}

	private function render_notice(): void {
		$notice = isset( $_GET['fc_notice'] ) ? sanitize_key( wp_unslash( $_GET['fc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$detail = isset( $_GET['fc_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['fc_detail'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$text   = match ( $notice ) {
			'saved' => __( 'Network settings saved.', 'form-captain' ),
			'invalid' => __( 'The network settings were not saved. Please check your input.', 'form-captain' ) . ( '' !== $detail ? ' ' . $detail : '' ),
			'migration_started' => __( 'The migration of all sites has started. It runs in the background on the main site, 10 sites at a time.', 'form-captain' ),
			default => '',
		};
		if ( '' !== $text ) {
			$type = 'invalid' === $notice ? 'error' : 'success';
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	/** @param array<string,mixed> $migration */
	private function render_migration( array $migration ): void {
		echo '<h2>' . esc_html__( 'Database migration', 'form-captain' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: schema version */
				__( 'Each site migrates its own database tables on its next request. You can also migrate all sites now (current schema: %d).', 'form-captain' ),
				Migrator::TARGET_VERSION
			)
		) . '</p>';
		if ( 'idle' !== $migration['status'] ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: processed sites, 2: all sites, 3: failed sites */
					__( 'Last run: %1$d of %2$d sites processed, %3$d failed.', 'form-captain' ),
					(int) $migration['done'],
					(int) $migration['total'],
					count( (array) $migration['failed'] )
				)
			) . ' ' . esc_html( 'running' === $migration['status'] ? __( 'The run continues in the background.', 'form-captain' ) : __( 'The run is finished.', 'form-captain' ) ) . '</p>';
			if ( $migration['failed'] ) {
				echo '<p>' . esc_html__( 'Failed sites (site ID: error code):', 'form-captain' ) . '</p><ul class="ul-disc">';
				foreach ( (array) $migration['failed'] as $site_id => $code ) {
					echo '<li>' . esc_html( (int) $site_id . ': ' . (string) $code ) . '</li>';
				}
				echo '</ul>';
			}
		}
		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=form_captain_network_migrate' ) ) . '">';
		wp_nonce_field( 'form_captain_network_migrate' );
		submit_button( $migration['failed'] ? __( 'Migrate all sites again', 'form-captain' ) : __( 'Migrate all sites', 'form-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function render_sites(): void {
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total = Network::site_count();
		$ids   = Network::site_ids( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		echo '<h2>' . esc_html__( 'Sites', 'form-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Site', 'form-captain' ), __( 'Schema', 'form-captain' ), __( 'State', 'form-captain' ), __( 'Queue (open / failed / paused / dead)', 'form-captain' ), __( 'Oldest due job', 'form-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$sum = array_fill_keys( array( 'pending', 'failed', 'paused', 'dead' ), 0 );
		foreach ( $ids as $site_id ) {
			$row = self::site_status( $site_id );
			foreach ( $row['queue'] as $k => $n ) {
				$sum[ $k ] += $n;
			}
			echo '<tr><td>' . esc_html( '#' . $site_id . ' ' . $row['url'] ) . '</td>';
			echo '<td>' . esc_html( $row['schema'] . ' / ' . Migrator::TARGET_VERSION ) . '</td>';
			echo '<td>' . esc_html( self::state_label( $row['state'] ) . ( '' !== $row['error'] ? ' (' . $row['error'] . ')' : '' ) ) . '</td>';
			echo '<td>' . esc_html( $row['queue'] ? implode( ' / ', array_map( 'number_format_i18n', array_values( $row['queue'] ) ) ) : '–' ) . '</td>';
			/* translators: %s: minutes */
			echo '<td>' . esc_html( $row['oldest_due_minutes'] > 0 ? sprintf( __( '%s min', 'form-captain' ), number_format_i18n( $row['oldest_due_minutes'] ) ) : '–' ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th scope="row" colspan="3">' . esc_html__( 'Total on this page', 'form-captain' ) . '</th><td>' . esc_html( implode( ' / ', array_map( 'number_format_i18n', array_values( $sum ) ) ) ) . '</td><td></td></tr></tfoot></table>';
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</p>';
		}
	}

	/** @param array<string,mixed> $settings */
	private function render_settings( array $settings ): void {
		$defs    = SettingsSchema::definitions();
		$presets = (array) $settings['site_defaults'];
		echo '<h2>' . esc_html__( 'Network settings', 'form-captain' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=form_captain_network_save' ) ) . '">';
		wp_nonce_field( 'form_captain_network_save' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Super admin access to submissions', 'form-captain' ) . '</th><td><label><input type="checkbox" name="network[super_admin_entries]" value="1"' . checked( ! empty( $settings['super_admin_entries'] ), true, false ) . '> ' . esc_html__( 'Super admins may read, edit, export and delete submissions on sites where they have no role of their own', 'form-captain' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default: submissions often contain personal data that only the people responsible for a site should see. Super admins with a role on a site keep the access of that role.', 'form-captain' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Data deletion on uninstall', 'form-captain' ) . '</th><td><label><input type="checkbox" name="network[allow_site_data_deletion]" value="1"' . checked( ! empty( $settings['allow_site_data_deletion'] ), true, false ) . '> ' . esc_html__( 'Sites may choose to delete their Form Captain data when the plugin is deleted', 'form-captain' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Each site decides and confirms this itself in its privacy settings. Without this permission all data is kept on every site.', 'form-captain' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Presets for new sites', 'form-captain' ) . '</h3>';
		echo '<p>' . esc_html__( 'Used once, when a site sets up Form Captain for the first time. Existing sites keep their settings. Leave a field empty to use the plugin default.', 'form-captain' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( NetworkSettings::DEFAULT_PATHS as $path ) {
			$def   = $defs[ $path ] ?? null;
			$field = str_replace( '.', '__', $path );
			if ( ! is_array( $def ) ) {
				continue;
			}
			$id    = 'fc-network-' . $field;
			$value = array_key_exists( $path, $presets ) ? (string) $presets[ $path ] : '';
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( (string) $def['label'] ) . '</label></th><td>';
			$name = 'network[site_defaults][' . $field . ']';
			if ( 'enum' === $def['type'] ) {
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'Plugin default', 'form-captain' ) . '</option>';
				foreach ( (array) $def['options'] as $option => $label ) {
					echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( (string) $label ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
			if ( 'retention' === $def['type'] ) {
				echo '<p class="description">' . esc_html__( 'A period such as 30d, 6m or 10y, or "manual" for no automatic deletion.', 'form-captain' ) . ( '' !== $value ? ' ' . esc_html( RetentionPeriod::label( $value ) ) : '' ) . '</p>';
			} elseif ( 'int' === $def['type'] ) {
				/* translators: 1: minimum, 2: maximum */
				echo '<p class="description">' . esc_html( sprintf( __( 'A number between %1$d and %2$d.', 'form-captain' ), (int) $def['min'], (int) $def['max'] ) ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save network settings', 'form-captain' ) );
		echo '</form>';
	}
}
