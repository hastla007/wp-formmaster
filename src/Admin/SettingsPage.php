<?php
/**
 * Settings screen with tabs (docs/spec/einstellungen.md; P2-17, P2-19…P2-24, P5-06, P5-10, P5-16,
 * P5-18…P5-22, P6-19).
 *
 * Every write goes through admin-post.php with capability form_captain_manage_settings and a nonce
 * per tab. Values are sanitized and validated by SettingsSchema; errors are reported per field.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Install\Capabilities;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Mail\MailJobHandler;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Settings\AccessControl;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Support\Asset;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;
use FormCaptain\Support\Secret;

final class SettingsPage {

	public const SLUG = 'form-captain-settings';

	private const RETENTION_SERVICE = 'FormCaptain\\Service\\RetentionService';
	private const GRACE_SECONDS     = 14 * DAY_IN_SECONDS;

	public function __construct(
		private Plugin $plugin,
		private FormRepository $forms,
		private EntryRepository $entries,
		private SystemStatus $status
	) {}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_post_form_captain_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_form_captain_reset_settings', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_form_captain_test_mail', array( $this, 'handle_test_mail' ) );
		add_action( 'admin_post_form_captain_rotate_secret', array( $this, 'handle_rotate_secret' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'form_captain_cleanup', array( self::class, 'expire_previous_secret' ) );
		add_action( 'after_plugin_row_' . plugin_basename( FORM_CAPTAIN_FILE ), array( $this, 'plugin_row_notice' ) );
		$plugin->get( SiteHealth::class )->register();
	}

	public function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( self::SLUG, IntegrationsPage::SLUG ), true ) ) {
			return;
		}
		wp_enqueue_style( 'form-captain-admin-settings', Asset::url( 'admin-settings.css' ), array(), Asset::version( 'admin-settings.css' ) );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page && 'email' === $tab && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media(); // Logo picker (SET-FC-03); the number field works without it.
		}
		wp_enqueue_script( 'form-captain-admin-settings', Asset::url( 'admin-settings.js' ), array( 'wp-i18n' ), Asset::version( 'admin-settings.js' ), true );
		wp_set_script_translations( 'form-captain-admin-settings', 'form-captain', FORM_CAPTAIN_DIR . 'languages' );
	}

	// ---------------------------------------------------------------------
	// Actions (testable without redirects)
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input
	 * @return array{type:string,messages:string[],errors:array<string,string>}
	 */
	public function save_tab( string $tab, array $input ): array {
		if ( 'access' === $tab ) {
			return $this->save_access( (array) ( $input['roles'] ?? array() ) );
		}
		if ( ! in_array( $tab, SettingsSchema::GROUPS, true ) ) {
			return self::notice( 'error', array( __( 'This section cannot be saved.', 'form-captain' ) ) );
		}
		$before                  = Settings::all()[ $tab ];
		list( $values, $errors ) = SettingsSchema::sanitize_group( $tab, $input );
		$retention_note          = ( 'privacy' === $tab && ( $values['retention'] ?? '' ) !== ( $before['retention'] ?? '' ) ) ? $this->apply_retention_change( (string) $values['retention'] ) : '';
		Settings::set_group( $tab, $values );
		$messages = array();
		$changed  = array_keys( array_diff_assoc( array_map( 'strval', $values ), array_map( 'strval', $before ) ) );
		if ( $changed ) {
			$messages[] = __( 'Settings saved.', 'form-captain' );
			Logger::log( 'info', 'settings_saved', $tab . ': ' . implode( ',', $changed ) );
		} elseif ( ! $errors ) {
			$messages[] = __( 'No changes.', 'form-captain' );
		}
		if ( '' !== $retention_note ) {
			$messages[] = $retention_note;
		}
		if ( 'privacy' === $tab && in_array( 'delete_data_on_uninstall', $changed, true ) ) {
			$messages[] = ! empty( $values['delete_data_on_uninstall'] )
				? __( 'Deleting the plugin will now also delete all forms and submissions.', 'form-captain' )
				: __( 'Forms and submissions will be kept when the plugin is deleted.', 'form-captain' );
		}
		if ( in_array( $tab, array( 'email', 'appearance' ), true ) && $changed ) {
			$messages[] = $this->own_values_hint( $tab );
		}
		if ( $errors ) {
			array_unshift( $messages, __( 'Some settings were not saved. Please check the highlighted fields.', 'form-captain' ) );
		}
		return array(
			'type'     => $errors ? 'error' : 'success',
			'messages' => array_values( array_filter( $messages ) ),
			'errors'   => $errors,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $roles
	 * @return array{type:string,messages:string[],errors:array<string,string>}
	 */
	public function save_access( array $roles ): array {
		if ( ! current_user_can( 'promote_users' ) ) {
			return self::notice( 'error', array( __( 'Changing role permissions requires the capability to promote users.', 'form-captain' ) ) );
		}
		$result = AccessControl::apply( $roles );
		Logger::log( 'info', 'roles_changed', 'changes=' . $result['changed'] );
		return self::notice( 'success', array( $result['changed'] ? __( 'Permissions saved.', 'form-captain' ) : __( 'No changes.', 'form-captain' ), self::access_summary() ) );
	}

	public static function access_summary(): string {
		return sprintf(
			/* translators: 1: number of users who can read entries, 2: number of users who can export entries */
			__( '%1$s users can read entries, %2$s users can export them.', 'form-captain' ),
			number_format_i18n( AccessControl::users_with_cap( Capabilities::READ_ENTRIES ) ),
			number_format_i18n( AccessControl::users_with_cap( Capabilities::EXPORT_ENTRIES ) )
		);
	}

	/** @return array{type:string,messages:string[],errors:array<string,string>} */
	public function reset_tab( string $tab ): array {
		if ( 'access' === $tab ) {
			if ( ! current_user_can( 'promote_users' ) ) {
				return self::notice( 'error', array( __( 'Changing role permissions requires the capability to promote users.', 'form-captain' ) ) );
			}
			AccessControl::reset();
			return self::notice( 'success', array( __( 'Permissions were reset: only administrators have access.', 'form-captain' ), self::access_summary() ) );
		}
		if ( ! in_array( $tab, SettingsSchema::GROUPS, true ) ) {
			return self::notice( 'error', array( __( 'This section cannot be reset.', 'form-captain' ) ) );
		}
		$before   = Settings::all()[ $tab ];
		$default  = Settings::defaults()[ $tab ];
		$messages = array( __( 'The section was reset to the defaults. Other sections were not changed.', 'form-captain' ) );
		if ( 'privacy' === $tab && ( $before['retention'] ?? '' ) !== $default['retention'] ) {
			$messages[] = $this->apply_retention_change( (string) $default['retention'] );
		}
		Settings::reset_group( $tab );
		Logger::log( 'info', 'settings_reset', $tab );
		return self::notice( 'success', $messages );
	}

	/**
	 * Explains a saved retention change. The recalculation itself is triggered by RetentionService
	 * (update_option hook) so that every change path behaves the same.
	 */
	private function apply_retention_change( string $mode ): string {
		$preview = $this->retention_preview( $mode );
		if ( null === $preview['earliest'] ) {
			/* translators: %s: number of submissions */
			return sprintf( __( 'Automatic deletion is off. %s submissions are kept until you delete them.', 'form-captain' ), number_format_i18n( $preview['count'] ) );
		}
		return sprintf(
			/* translators: 1: number of submissions, 2: date */
			__( 'This change affects %1$s existing submissions. The earliest automatic deletion will be on %2$s. Submissions are never deleted sooner than 14 days after this change.', 'form-captain' ),
			number_format_i18n( $preview['count'] ),
			self::local_date( $preview['earliest'] )
		);
	}

	/**
	 * Entries following the global retention (forms without own override) and earliest deletion date.
	 *
	 * @return array{count:int,earliest:?string}
	 */
	public function retention_preview( string $mode ): array {
		if ( class_exists( self::RETENTION_SERVICE ) ) {
			try {
				$service = $this->plugin->get( self::RETENTION_SERVICE );
				if ( method_exists( $service, 'preview' ) ) {
					$p = (array) $service->preview( $mode );
					return array(
						'count'    => (int) ( $p['affected'] ?? 0 ),
						'earliest' => isset( $p['earliest'] ) ? (string) $p['earliest'] : null,
					);
				}
			} catch ( \Throwable $e ) {
				Logger::log( 'warning', 'retention_preview_failed', get_class( $e ) );
			}
		}
		return $this->retention_estimate( $mode );
	}

	/**
	 * Cheap estimate for the settings screen (two aggregate queries): entries of forms following
	 * the global retention and the earliest possible deletion date (14-day grace included).
	 *
	 * @return array{count:int,earliest:?string}
	 */
	public function retention_estimate( string $mode ): array {
		$ids = array();
		foreach ( $this->forms->all_settings() as $id => $form ) {
			$own = (string) ( $form['settings']['retention']['mode'] ?? 'global' );
			if ( in_array( $own, array( '', 'global', 'inherit' ), true ) ) {
				$ids[] = (int) $id;
			}
		}
		$stats   = $this->entries->count_for_forms( $ids );
		$seconds = Settings::retention_seconds( $mode );
		if ( null === $seconds || ! $stats['count'] || null === $stats['oldest_created'] ) {
			return array(
				'count'    => $stats['count'],
				'earliest' => null,
			);
		}
		$earliest = max( (int) strtotime( $stats['oldest_created'] . ' UTC' ) + $seconds, Clock::timestamp() + self::GRACE_SECONDS );
		return array(
			'count'    => $stats['count'],
			'earliest' => Clock::at( $earliest ),
		);
	}

	/**
	 * Number of forms whose copied values differ from the current global defaults (P2-20).
	 *
	 * @return array{appearance:int,email:int,retention:int}
	 */
	public function forms_with_own_values(): array {
		$out = array(
			'appearance' => 0,
			'email'      => 0,
			'retention'  => 0,
		);
		$g   = Settings::all();
		foreach ( $this->forms->all_settings() as $form ) {
			$s = $form['settings'];
			if ( isset( $s['appearance']['mode'] ) && $s['appearance']['mode'] !== $g['appearance']['mode'] ) {
				++$out['appearance'];
			}
			foreach ( array( 'email', 'notification' ) as $section ) {
				$name = $s[ $section ]['from_name'] ?? null;
				$addr = $s[ $section ]['from_address'] ?? null;
				if ( ( null !== $name && $name !== $g['email']['from_name'] ) || ( null !== $addr && $addr !== $g['email']['from_address'] ) ) {
					++$out['email'];
					break;
				}
			}
			if ( ! in_array( (string) ( $s['retention']['mode'] ?? 'global' ), array( '', 'global', 'inherit' ), true ) ) {
				++$out['retention'];
			}
		}
		return $out;
	}

	private function own_values_hint( string $tab ): string {
		$n = $this->forms_with_own_values()[ $tab ] ?? 0;
		return sprintf(
			/* translators: %s: number of forms */
			_n( 'Defaults apply to new forms only. %s existing form keeps its own value.', 'Defaults apply to new forms only. %s existing forms keep their own values.', $n, 'form-captain' ),
			number_format_i18n( $n )
		);
	}

	/** @return array{type:string,messages:string[],errors:array<string,string>} */
	public function send_test_mail(): array {
		$user = wp_get_current_user();
		$to   = $user && $user->exists() && is_email( $user->user_email ) ? $user->user_email : (string) get_option( 'admin_email' );
		$key  = 'form_captain_testmail_' . get_current_user_id();
		$hits = (int) get_transient( $key );
		if ( $hits >= 3 ) {
			return self::notice( 'error', array( __( 'Too many test e-mails. Please wait ten minutes.', 'form-captain' ) ) );
		}
		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
		$error = '';
		$intro = __( 'This is a test e-mail from Form Captain. If you received it, your website can send e-mails. Form notifications use the same way of delivery.', 'form-captain' );
		$frame = MailBuilder::frame( 'test' );
		$title = __( 'Form Captain test e-mail', 'form-captain' );
		$ok    = Plugin::instance()->get( MailJobHandler::class )->send(
			array(
				'to'         => array( $to ),
				'subject'    => $title,
				'reply_to'   => MailBuilder::visitor_reply_to(),
				'from_email' => (string) Settings::get( 'email.from_address' ),
				'from_name'  => MailBuilder::strip_header( (string) Settings::get( 'email.from_name' ) ),
				'format'     => 'text' === Settings::get( 'email.format' ) ? 'text' : 'html',
				'html'       => MailBuilder::wrap_html( '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;color:#1d2327;"><h2 style="font-size:18px;">' . esc_html( $title ) . '</h2><p>' . esc_html( $intro ) . '</p></body></html>', $frame ),
				'text'       => MailBuilder::wrap_text( $intro . "\n", $frame ),
			),
			$error
		);
		if ( $ok ) {
			Logger::log( 'info', 'mail_test_sent' );
			return self::notice( 'success', array( __( 'Test e-mail sent to your user e-mail address. If it does not arrive within a few minutes, check the spam folder and consider an SMTP plugin.', 'form-captain' ) ) );
		}
		Logger::log( 'warning', 'mail_test_failed', Logger::redact( $error ) );
		return self::notice( 'error', array( __( 'The test e-mail could not be sent. WordPress reported an error; an SMTP plugin usually solves delivery problems.', 'form-captain' ) . ( '' !== $error ? ' (' . $error . ')' : '' ) ) );
	}

	/**
	 * Rotates the signing key with a 26 h grace window for the previous key (suite-kontext.md 6).
	 *
	 * @return array<string,mixed>
	 */
	public function rotate_secret( bool $discard_previous = false ): array {
		$old = get_option( Secret::OPTION );
		if ( is_string( $old ) && ! $discard_previous ) {
			update_option( 'form_captain_secret_previous', $old, false );
			update_option( 'form_captain_secret_previous_until', Clock::timestamp() + 26 * HOUR_IN_SECONDS, false );
		} else {
			delete_option( 'form_captain_secret_previous' );
			delete_option( 'form_captain_secret_previous_until' );
		}
		Secret::rotate();
		Logger::log( 'warning', 'secret_rotated', $discard_previous ? 'previous discarded' : 'grace 26h' );
		return self::notice(
			'success',
			array(
				$discard_previous
					? __( 'The signing key was renewed. The previous key was discarded immediately; open forms and links signed before are invalid.', 'form-captain' )
					: __( 'The signing key was renewed. The previous key stays valid for 26 hours.', 'form-captain' ),
			)
		);
	}

	public static function expire_previous_secret(): void {
		$until = (int) get_option( 'form_captain_secret_previous_until', 0 );
		if ( $until && $until < Clock::timestamp() ) {
			delete_option( 'form_captain_secret_previous' );
			delete_option( 'form_captain_secret_previous_until' );
		}
	}

	// ---------------------------------------------------------------------
	// admin-post handlers
	// ---------------------------------------------------------------------

	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to change Form Captain settings.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function posted_tab(): string {
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return in_array( $tab, SettingsSchema::TABS, true ) ? $tab : 'general';
	}

	public function handle_save(): void {
		$tab = self::posted_tab();
		$this->guard( 'form_captain_settings_' . $tab );
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); sanitized by SettingsSchema.
		if ( 'access' === $tab ) {
			$input = array( 'roles' => isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked in guard(); only keys/booleans are read.
		}
		$this->redirect( $tab, $this->save_tab( $tab, (array) $input ) );
	}

	public function handle_reset(): void {
		$tab = self::posted_tab();
		$this->guard( 'form_captain_reset_' . $tab );
		if ( empty( $_POST['confirm_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$this->redirect( $tab, self::notice( 'error', array( __( 'Please confirm that you want to reset this section.', 'form-captain' ) ) ) );
		}
		$this->redirect( $tab, $this->reset_tab( $tab ) );
	}

	public function handle_test_mail(): void {
		$this->guard( 'form_captain_test_mail' );
		$this->redirect( 'email', $this->send_test_mail() );
	}

	public function handle_rotate_secret(): void {
		$this->guard( 'form_captain_rotate_secret' );
		if ( empty( $_POST['confirm_rotate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			$this->redirect( 'status', self::notice( 'error', array( __( 'Please confirm the key renewal.', 'form-captain' ) ) ) );
		}
		$this->redirect( 'status', $this->rotate_secret( ! empty( $_POST['discard_previous'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
	}

	/** @param array{type:string,messages:string[],errors?:array<string,string>} $notice */
	private function redirect( string $tab, array $notice ): never {
		set_transient( 'form_captain_settings_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @param string[] $messages
	 * @return array{type:string,messages:string[],errors:array<string,string>}
	 */
	private static function notice( string $type, array $messages ): array {
		return array(
			'type'     => $type,
			'messages' => $messages,
			'errors'   => array(),
		);
	}

	public function plugin_row_notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! Settings::get( 'privacy.delete_data_on_uninstall' ) || ! Settings::get( 'privacy.delete_data_confirmed_gmt' ) ) {
			return;
		}
		echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>' . esc_html__( 'Deleting this plugin will also delete all forms and submissions.', 'form-captain' ) . '</p></div></td></tr>';
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, SettingsSchema::TABS, true ) ? $tab : 'general';
	}

	private static function local_date( string $gmt ): string {
		return (string) get_date_from_gmt( $gmt, (string) get_option( 'date_format' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view Form Captain settings.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		$tab    = self::current_tab();
		$notice = get_transient( 'form_captain_settings_notice_' . get_current_user_id() );
		delete_transient( 'form_captain_settings_notice_' . get_current_user_id() );
		$errors = is_array( $notice ) ? (array) ( $notice['errors'] ?? array() ) : array();

		echo '<div class="wrap fc-settings">';
		echo '<h1>' . esc_html__( 'Form Captain settings', 'form-captain' ) . '</h1>';
		echo '<p class="fc-breadcrumb"><a href="' . esc_url( admin_url( 'admin.php?page=form-captain' ) ) . '">' . esc_html__( 'Form Captain', 'form-captain' ) . '</a> › ' . esc_html( SettingsSchema::tab_labels()[ $tab ] ) . '</p>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'form-captain' ) . '">';
		foreach ( SettingsSchema::tab_labels() as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ),
				$key === $tab ? ' nav-tab-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( is_array( $notice ) && ! empty( $notice['messages'] ) ) {
			echo '<div class="notice notice-' . ( 'error' === $notice['type'] ? 'error' : 'success' ) . ' is-dismissible" role="status">';
			foreach ( (array) $notice['messages'] as $m ) {
				echo '<p>' . esc_html( (string) $m ) . '</p>';
			}
			if ( $errors ) {
				echo '<ul class="fc-error-list">';
				foreach ( $errors as $path => $msg ) {
					$def = SettingsSchema::definitions()[ $path ] ?? null;
					echo '<li><a href="#fc-' . esc_attr( str_replace( '.', '-', (string) $path ) ) . '">' . esc_html( (string) ( $def['label'] ?? $path ) ) . '</a>: ' . esc_html( (string) $msg ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}

		match ( $tab ) {
			'access' => $this->render_access(),
			'status' => $this->render_status(),
			default => $this->render_group( $tab, $errors ),
		};
		echo '</div>';
	}

	/** @param array<string,string> $errors */
	private function render_group( string $tab, array $errors ): void {
		$values = Settings::all()[ $tab ];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fc-settings-form" novalidate>';
		echo '<input type="hidden" name="action" value="form_captain_save_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'form_captain_settings_' . $tab );
		$this->render_tab_intro( $tab );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( SettingsSchema::group( $tab ) as $key => $def ) {
			if ( ! empty( $def['internal'] ) ) {
				continue;
			}
			$this->render_field( $tab, $key, $def, $values[ $key ] ?? $def['default'], $errors[ $tab . '.' . $key ] ?? '' );
		}
		echo '</tbody></table>';
		$this->render_tab_extras( $tab, $values );
		submit_button( __( 'Save changes', 'form-captain' ) );
		echo '</form>';
		if ( 'email' === $tab ) {
			$this->render_test_mail();
		}
		$this->render_reset( $tab );
	}

	/**
	 * @param array<string,mixed> $def
	 */
	private function render_field( string $tab, string $key, array $def, mixed $value, string $error ): void {
		$id   = 'fc-' . $tab . '-' . str_replace( '_', '-', $key );
		$name = 'settings[' . $key . ']';
		$aria = $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '';
		$help = (string) ( $def['help'] ?? '' );
		echo '<tr id="fc-' . esc_attr( $tab . '-' . $key ) . '"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( (string) $def['label'] ) . '</label></th><td>';
		switch ( $def['type'] ) {
			case 'bool':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . $aria . '> ' . esc_html__( 'Enabled', 'form-captain' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $aria built from escaped parts.
				break;
			case 'enum':
				if ( count( (array) $def['options'] ) <= 3 ) {
					echo '<fieldset id="' . esc_attr( $id ) . '"' . $aria . '><legend class="screen-reader-text">' . esc_html( (string) $def['label'] ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					foreach ( (array) $def['options'] as $opt => $label ) {
						echo '<label class="fc-radio"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $opt ) . '"' . checked( (string) $value, (string) $opt, false ) . '> ' . esc_html( (string) $label ) . '</label><br>';
					}
					echo '</fieldset>';
				} else {
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					foreach ( (array) $def['options'] as $opt => $label ) {
						echo '<option value="' . esc_attr( (string) $opt ) . '"' . selected( (string) $value, (string) $opt, false ) . '>' . esc_html( (string) $label ) . '</option>';
					}
					echo '</select>';
				}
				break;
			case 'int':
				echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $def['min'] ) . '" max="' . esc_attr( (string) $def['max'] ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'hex':
				echo '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $aria . '> <code>' . esc_html( (string) $value ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'text':
				echo '<textarea class="large-text code" rows="6" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" maxlength="' . esc_attr( (string) $def['max_length'] ) . '"' . $aria . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'attachment':
				$image = (int) $value > 0 ? wp_get_attachment_image_url( (int) $value, 'medium' ) : false;
				echo '<div class="fc-media-field" data-fc-media="' . esc_attr( $id ) . '">';
				echo '<img class="fc-media-preview" alt="" src="' . esc_url( $image ? $image : '' ) . '"' . ( $image ? '' : ' hidden' ) . '>';
				echo '<input type="number" min="0" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) (int) $value ) . '"' . $aria . '> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<button type="button" class="button fc-media-choose" hidden>' . esc_html__( 'Choose image', 'form-captain' ) . '</button> ';
				echo '<button type="button" class="button-link fc-media-remove" hidden>' . esc_html__( 'Remove image', 'form-captain' ) . '</button>';
				echo '<p class="description">' . esc_html__( 'Media library ID of the image (0 = no logo).', 'form-captain' ) . '</p>';
				echo '</div>';
				break;
			default:
				echo '<input type="' . ( 'email' === $def['type'] ? 'email' : 'text' ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( isset( $def['max_length'] ) ? ' maxlength="' . esc_attr( (string) $def['max_length'] ) . '"' : '' ) . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $error ) {
			echo '<p class="fc-field-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $error ) . '</p>';
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		$this->render_field_after( $tab, $key, $value );
		echo '</td></tr>';
	}

	private function render_field_after( string $tab, string $key, mixed $value ): void {
		if ( 'privacy' === $tab && 'retention' === $key ) {
			echo '<ul class="fc-retention-preview">';
			foreach ( array( '90d', '12m', 'manual' ) as $mode ) {
				$p    = $this->retention_estimate( $mode );
				$text = match ( $mode ) {
					'90d' => __( 'Submissions are deleted permanently 90 days after they were received.', 'form-captain' ),
					'12m' => __( 'Submissions are deleted permanently 12 months after they were received.', 'form-captain' ),
					default => __( 'Submissions are kept until you delete them. Make sure you delete data you no longer need.', 'form-captain' ),
				};
				$suffix = null !== $p['earliest']
					/* translators: 1: number of submissions, 2: date */
					? sprintf( __( 'Affects %1$s submissions; earliest deletion on %2$s.', 'form-captain' ), number_format_i18n( $p['count'] ), self::local_date( $p['earliest'] ) )
					/* translators: %s: number of submissions */
					: sprintf( __( 'Affects %s submissions; nothing is deleted automatically.', 'form-captain' ), number_format_i18n( $p['count'] ) );
				echo '<li><strong>' . esc_html( (string) SettingsSchema::definitions()['privacy.retention']['options'][ $mode ] ) . ':</strong> ' . esc_html( $text ) . ' <em>' . esc_html( $suffix ) . '</em></li>';
			}
			echo '</ul>';
			$own = $this->forms_with_own_values()['retention'];
			if ( $own ) {
				/* translators: %s: number of forms */
				echo '<p class="description">' . esc_html( sprintf( _n( '%s form uses its own retention period and is not affected.', '%s forms use their own retention period and are not affected.', $own, 'form-captain' ), number_format_i18n( $own ) ) ) . '</p>';
			}
			echo '<p><label><input type="checkbox" name="settings[retention_confirm]" value="1"> ' . esc_html__( 'I understand that changing the retention period schedules the deletion of existing submissions (never sooner than 14 days after this change).', 'form-captain' ) . '</label></p>';
		}
		if ( 'privacy' === $tab && 'delete_data_on_uninstall' === $key ) {
			$counts = $this->entries->count_for_forms( null );
			echo '<div class="fc-uninstall">';
			if ( $value && Settings::get( 'privacy.delete_data_confirmed_gmt' ) ) {
				/* translators: %s: date */
				echo '<p class="fc-warning">' . esc_html( sprintf( __( 'Confirmed on %s. Deleting the plugin will also delete all forms and submissions.', 'form-captain' ), self::local_date( (string) Settings::get( 'privacy.delete_data_confirmed_gmt' ) ) ) ) . '</p>';
			} else {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: number of forms, 2: number of submissions */
						__( 'When you delete the plugin, %1$s forms, %2$s submissions and all uploaded files will be removed permanently. Type DELETE to confirm.', 'form-captain' ),
						number_format_i18n( count( $this->forms->all_settings() ) ),
						number_format_i18n( $counts['count'] )
					)
				) . '</p>';
				echo '<label for="fc-uninstall-word" class="screen-reader-text">' . esc_html__( 'Confirmation word', 'form-captain' ) . '</label><input type="text" id="fc-uninstall-word" name="settings[uninstall_confirm_word]" class="regular-text" autocomplete="off" placeholder="DELETE">';
			}
			echo '</div>';
		}
		if ( 'email' === $tab && 'from_address' === $key && SettingsSchema::from_domain_mismatch( (string) $value ) ) {
			echo '<p class="fc-warning">' . esc_html__( 'The sender domain differs from your website domain. Many mail servers reject or mark such messages as spam. Use an address of your own domain or configure SPF/DKIM with your mail provider.', 'form-captain' ) . '</p>';
		}
		if ( 'spam' === $tab && 'captcha_provider' === $key ) {
			foreach ( SettingsSchema::captcha_providers() as $p ) {
				echo '<p class="fc-dataflow">' . esc_html(
					sprintf(
						/* translators: 1: provider, 2: data, 3: purpose */
						__( '%1$s will receive: %2$s. Purpose: %3$s. Update your privacy policy before enabling this service.', 'form-captain' ),
						$p['label'],
						$p['data'],
						$p['purpose']
					)
				);
				if ( '' !== $p['privacy_url'] ) {
					echo ' <a href="' . esc_url( $p['privacy_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Provider privacy policy', 'form-captain' ) . '</a>';
				}
				echo '</p>';
			}
			if ( SettingsSchema::captcha_providers() ) {
				echo '<p><label><input type="checkbox" name="settings[captcha_confirm]" value="1"> ' . esc_html__( 'I have read the data flow and updated the privacy policy.', 'form-captain' ) . '</label></p>';
			}
		}
	}

	private function render_tab_intro( string $tab ): void {
		$text = match ( $tab ) {
			'general' => __( 'Form-specific options such as recipients, reply-to, subject, confirmation message, redirect and actions are set in each form, not here. Form import/export is in the form list, CSV export in Entries.', 'form-captain' ),
			'appearance' => __( 'Your theme styles forms by default. Base styles only use local CSS – no external fonts or files are loaded.', 'form-captain' ),
			'email' => __( 'Form Captain sends e-mails with WordPress (wp_mail). It does not manage SMTP servers or mail passwords; use an SMTP plugin of your choice for reliable delivery.', 'form-captain' ),
			'privacy' => __( 'Submissions are stored only in this WordPress database. Form Captain does not control backups. Deleted submissions remain in existing backups until those backups expire. After restoring a backup, expired submissions are deleted again automatically; repeat completed erasure requests.', 'form-captain' ),
			'spam' => __( 'Honeypot, minimum fill-in time and rate limiting work without cookies and without sending data to third parties.', 'form-captain' ),
			default => '',
		};
		if ( '' !== $text ) {
			echo '<p class="fc-tab-intro">' . esc_html( $text ) . '</p>';
		}
		if ( in_array( $tab, array( 'email', 'appearance' ), true ) ) {
			echo '<p class="description">' . esc_html( $this->own_values_hint( $tab ) ) . '</p>';
		}
	}

	/** @param array<string,mixed> $values */
	private function render_tab_extras( string $tab, array $values ): void {
		if ( 'appearance' === $tab ) {
			echo '<h2>' . esc_html__( 'Preview', 'form-captain' ) . '</h2>';
			echo '<div class="fc-appearance-preview fc-mode-' . esc_attr( (string) $values['mode'] ) . '" style="--fc-accent:' . esc_attr( (string) $values['accent_color'] ) . '" aria-hidden="true">';
			echo '<span class="fc-preview-label">' . esc_html__( 'Email', 'form-captain' ) . '</span><span class="fc-preview-input"></span><span class="fc-preview-button">' . esc_html__( 'Send', 'form-captain' ) . '</span>';
			echo '</div><p class="description">' . esc_html__( 'Schematic preview. The real look depends on your theme.', 'form-captain' ) . '</p>';
		}
		if ( 'privacy' === $tab ) {
			$guide = admin_url( 'options-privacy.php?tab=policyguide' );
			echo '<h2>' . esc_html__( 'Privacy policy text', 'form-captain' ) . '</h2>';
			echo '<p>' . esc_html__( 'Suggested text (not legal advice). It is also available in the WordPress privacy policy guide.', 'form-captain' ) . ' <a href="' . esc_url( $guide ) . '">' . esc_html__( 'Open the policy guide', 'form-captain' ) . '</a></p>';
			echo '<blockquote class="fc-policy-text">' . esc_html( \FormCaptain\Privacy\PrivacyIntegration::policy_text() ) . '</blockquote>';
		}
	}

	private function render_test_mail(): void {
		echo '<h2>' . esc_html__( 'Test e-mail', 'form-captain' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="form_captain_test_mail">';
		wp_nonce_field( 'form_captain_test_mail' );
		echo '<p>' . esc_html__( 'Sends a short test message to your own user e-mail address with the default sender.', 'form-captain' ) . '</p>';
		submit_button( __( 'Send test e-mail', 'form-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function render_reset( string $tab ): void {
		$label = SettingsSchema::tab_labels()[ $tab ];
		echo '<details class="fc-reset"><summary>' . esc_html__( 'Reset this section', 'form-captain' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-fc-confirm="' . esc_attr__( 'Reset this section to the defaults?', 'form-captain' ) . '">';
		echo '<input type="hidden" name="action" value="form_captain_reset_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'form_captain_reset_' . $tab );
		/* translators: %s: section name */
		echo '<p>' . esc_html( sprintf( __( 'Only the section "%s" is reset to the defaults. Other sections and existing forms keep their values.', 'form-captain' ), $label ) ) . '</p>';
		if ( 'privacy' === $tab ) {
			echo '<p class="fc-warning">' . esc_html__( 'Resetting sets the retention to 12 months and disables data deletion on uninstall. Existing submissions are rescheduled accordingly.', 'form-captain' ) . '</p>';
		}
		if ( 'access' === $tab ) {
			echo '<p class="fc-warning">' . esc_html__( 'Only administrators keep access. All other roles lose their Form Captain permissions.', 'form-captain' ) . '</p>';
		}
		echo '<p><label><input type="checkbox" name="confirm_reset" value="1" required> ' . esc_html__( 'I understand the consequences.', 'form-captain' ) . '</label></p>';
		submit_button( __( 'Reset section', 'form-captain' ), 'delete', 'submit', false );
		echo '</form></details>';
	}

	private function render_access(): void {
		$matrix = AccessControl::matrix();
		$labels = Capabilities::labels();
		echo '<p class="fc-tab-intro">' . esc_html__( 'Choose which roles may use Form Captain. Entries can contain personal data – grant reading and exporting only to people who need it.', 'form-captain' ) . '</p>';
		echo '<p><strong>' . esc_html( self::access_summary() ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="form_captain_save_settings"><input type="hidden" name="tab" value="access">';
		wp_nonce_field( 'form_captain_settings_access' );
		echo '<div class="fc-table-scroll"><table class="widefat striped fc-role-matrix"><thead><tr><th scope="col">' . esc_html__( 'Role', 'form-captain' ) . '</th>';
		foreach ( $labels as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $matrix as $slug => $role ) {
			/* translators: %s: number of users */
			echo '<tr><th scope="row">' . esc_html( $role['label'] ) . '<br><span class="description">' . esc_html( sprintf( _n( '%s user', '%s users', $role['users'], 'form-captain' ), number_format_i18n( $role['users'] ) ) ) . '</span></th>';
			foreach ( $labels as $cap => $label ) {
				$locked = AccessControl::is_locked( (string) $slug, $cap );
				$name   = 'roles[' . $slug . '][' . $cap . ']';
				echo '<td><label><span class="screen-reader-text">' . esc_html( $role['label'] . ': ' . $label ) . '</span><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $role['caps'][ $cap ] || $locked, true, false ) . disabled( $locked, true, false ) . '></label>';
				if ( $locked ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="1"><span class="description">' . esc_html__( 'always', 'form-captain' ) . '</span>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'Administrators always keep "Manage settings" so nobody can lock themselves out. Changing permissions also requires the WordPress capability to promote users.', 'form-captain' ) . '</p>';
		submit_button( __( 'Save permissions', 'form-captain' ) );
		echo '</form>';
		$this->render_reset( 'access' );
	}

	private function render_status(): void {
		$d        = $this->status->collect();
		$problems = $this->status->problems( $d );
		echo '<p class="fc-tab-intro">' . esc_html__( 'Read-only overview for troubleshooting. It never shows submitted values, e-mail addresses, IP addresses or secrets.', 'form-captain' ) . '</p>';
		if ( $problems ) {
			echo '<div class="notice notice-warning inline"><ul>';
			foreach ( $problems as $msg ) {
				echo '<li>' . esc_html( $msg ) . '</li>';
			}
			echo '</ul></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No problems detected.', 'form-captain' ) . '</p></div>';
		}
		$sections = array(
			'environment'  => __( 'Environment', 'form-captain' ),
			'schema'       => __( 'Database', 'form-captain' ),
			'queue'        => __( 'Queue and cron', 'form-captain' ),
			'mail'         => __( 'E-mail', 'form-captain' ),
			'integrations' => __( 'Integrations and Captain Suite', 'form-captain' ),
		);
		foreach ( $sections as $key => $title ) {
			echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped fc-status-table"><tbody>';
			foreach ( $d[ $key ] as $k => $v ) {
				echo '<tr><th scope="row">' . esc_html( (string) $k ) . '</th><td><code>' . esc_html( SystemStatus::scrub( is_scalar( $v ) || null === $v ? var_export( $v, true ) : (string) wp_json_encode( $v ) ) ) . '</code></td></tr>'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			}
			echo '</tbody></table>';
		}
		if ( $d['queue']['wp_cron_disabled'] ) {
			echo '<p class="fc-warning">' . esc_html__( 'WP-Cron is disabled (DISABLE_WP_CRON). Run this command every minute from your system cron:', 'form-captain' ) . ' <code>wp form-captain jobs run</code></p>';
		}
		echo '<h2>' . esc_html__( 'Recent errors', 'form-captain' ) . '</h2>';
		if ( $d['recent_errors'] ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'form-captain' ) . '</th><th>' . esc_html__( 'Level', 'form-captain' ) . '</th><th>' . esc_html__( 'Code', 'form-captain' ) . '</th><th>' . esc_html__( 'Correlation ID', 'form-captain' ) . '</th></tr></thead><tbody>';
			foreach ( $d['recent_errors'] as $e ) {
				echo '<tr><td>' . esc_html( $e['time'] ) . '</td><td>' . esc_html( $e['level'] ) . '</td><td><code>' . esc_html( $e['code'] ) . '</code></td><td><code>' . esc_html( $e['correlation'] ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No warnings or errors recorded.', 'form-captain' ) . '</p>';
		}
		echo '<h2>' . esc_html__( 'Diagnostics report', 'form-captain' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy this report into a support request. It contains status data, error codes and correlation IDs only.', 'form-captain' ) . '</p>';
		echo '<label for="fc-report" class="screen-reader-text">' . esc_html__( 'Diagnostics report', 'form-captain' ) . '</label><textarea id="fc-report" class="large-text code" rows="14" readonly>' . esc_textarea( $this->status->report_text( $d ) ) . '</textarea>';
		echo '<p><button type="button" class="button" data-fc-copy="fc-report">' . esc_html__( 'Copy report', 'form-captain' ) . '</button></p>';

		echo '<h2>' . esc_html__( 'Signing key', 'form-captain' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-fc-confirm="' . esc_attr__( 'Renewing the signing key invalidates links and open forms older than 26 hours. Continue?', 'form-captain' ) . '">';
		echo '<input type="hidden" name="action" value="form_captain_rotate_secret">';
		wp_nonce_field( 'form_captain_rotate_secret' );
		echo '<p>' . esc_html__( 'The signing key protects submission tokens and signed suite contexts. It is never displayed. Renew it if you suspect it was exposed.', 'form-captain' ) . '</p>';
		echo '<p><label><input type="checkbox" name="discard_previous" value="1"> ' . esc_html__( 'Discard the previous key immediately (suspected compromise)', 'form-captain' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="confirm_rotate" value="1" required> ' . esc_html__( 'Renewing the signing key invalidates links and open forms older than 26 hours. Continue?', 'form-captain' ) . '</label></p>';
		submit_button( __( 'Renew signing key', 'form-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
