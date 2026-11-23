<?php
/**
 * Admin menu (P2-01, P2-18 menu part) and activation redirect to the setup checklist.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;

final class Menu {

	public const SLUG = 'form-captain';

	public const REDIRECT_OPTION = 'form_captain_activation_redirect';

	/** Submenu slug => [page class, capability]. */
	private const PAGES = array(
		'form-captain'              => array( DashboardPage::class, '' ),
		'form-captain-forms'        => array( FormsPage::class, Capabilities::MANAGE_FORMS ),
		'form-captain-edit'         => array( EditorPage::class, Capabilities::MANAGE_FORMS ),
		'form-captain-entries'      => array( 'FormCaptain\\Admin\\EntriesPage', Capabilities::READ_ENTRIES ),
		'form-captain-integrations' => array( 'FormCaptain\\Admin\\IntegrationsPage', Capabilities::MANAGE_INTEGRATIONS ),
		'form-captain-settings'     => array( 'FormCaptain\\Admin\\SettingsPage', Capabilities::MANAGE_SETTINGS ),
	);

	/** @var array<string,string> slug => hook suffix */
	private array $hooks = array();

	public function register( Plugin $plugin ): void {
		add_action( 'admin_menu', array( $this, 'build' ) );
		add_action( 'admin_head', array( $this, 'hide_editor_item' ) );
		add_filter( 'submenu_file', array( $this, 'highlight' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/** First Form Captain capability of the current user, or null. */
	public static function menu_capability(): ?string {
		foreach ( Capabilities::ALL as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}
		return null;
	}

	public function build(): void {
		$menu_cap = self::menu_capability();
		if ( null === $menu_cap ) {
			return;
		}
		// The submenu entry "Dashboard" shares the hook of the top-level page; the same callable keeps
		// WordPress from calling it twice (the page was rendered twice, with duplicate ids).
		$dashboard                 = $this->callback( self::SLUG );
		$this->hooks[ self::SLUG ] = (string) add_menu_page(
			__( 'Form Captain', 'form-captain' ),
			__( 'Form Captain', 'form-captain' ),
			$menu_cap,
			self::SLUG,
			$dashboard,
			'dashicons-feedback',
			26
		);
		$titles                    = array(
			'form-captain'              => __( 'Dashboard', 'form-captain' ),
			'form-captain-forms'        => __( 'Forms', 'form-captain' ),
			'form-captain-edit'         => __( 'Edit form', 'form-captain' ),
			'form-captain-entries'      => __( 'Entries', 'form-captain' ),
			'form-captain-integrations' => __( 'Integrations', 'form-captain' ),
			'form-captain-settings'     => __( 'Settings', 'form-captain' ),
		);
		foreach ( self::PAGES as $slug => $def ) {
			$cap = '' === $def[1] ? $menu_cap : $def[1];
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			$this->hooks[ $slug ] = (string) add_submenu_page( self::SLUG, $titles[ $slug ] . ' ‹ ' . __( 'Form Captain', 'form-captain' ), $titles[ $slug ], $cap, $slug, self::SLUG === $slug ? $dashboard : $this->callback( $slug ) );
		}
		do_action( 'form_captain_admin_menu_built_v1', self::SLUG );
	}

	/** @return array<string,string> slug => hook suffix (for asset loading). */
	public function hooks(): array {
		return $this->hooks;
	}

	private function callback( string $slug ): callable {
		return static function () use ( $slug ): void {
			$class = self::PAGES[ $slug ][0];
			if ( class_exists( $class ) ) {
				$page = Plugin::instance()->get( $class );
				if ( method_exists( $page, 'render' ) ) {
					$page->render();
					return;
				}
			}
			echo '<div class="wrap"><h1>' . esc_html__( 'Form Captain', 'form-captain' ) . '</h1><div class="notice notice-info inline"><p>'
				. esc_html__( 'This area is not available yet.', 'form-captain' ) . '</p></div></div>';
		};
	}

	/** The editor is reachable via links only; it stays registered for access checks. */
	public function hide_editor_item(): void {
		remove_submenu_page( self::SLUG, 'form-captain-edit' );
	}

	public function highlight( ?string $submenu_file, string $parent_file ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $parent_file && isset( $_GET['page'] ) && 'form-captain-edit' === $_GET['page'] ) {
			return 'form-captain-forms';
		}
		return $submenu_file;
	}

	/**
	 * URL to redirect to after activation, or null. Consumes the one-time flag.
	 * Not on bulk activation, AJAX, network admin or for users without capability.
	 */
	public function activation_redirect_url(): ?string {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			delete_option( self::REDIRECT_OPTION );
			return null;
		}
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return null;
		}
		delete_option( self::REDIRECT_OPTION );
		return admin_url( 'admin.php?page=' . self::SLUG . '&fc_setup=1' );
	}

	public function maybe_redirect(): void {
		global $pagenow;
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || headers_sent() || ! in_array( $pagenow, array( 'plugins.php', 'index.php', 'admin.php' ), true ) ) {
			return;
		}
		$url = $this->activation_redirect_url();
		if ( null !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}
}
