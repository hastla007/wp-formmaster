<?php
/**
 * Menu, capabilities, admin-post actions (nonce + capability) and preview tokens
 * (P2-01, P2-03, P2-14, P2-15, AC-027, AC-036).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Admin\DashboardPage;
use FormCaptain\Admin\EditorPage;
use FormCaptain\Admin\FormsPage;
use FormCaptain\Admin\Menu;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\PreviewTokens;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

final class AdminScreensTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->reset_menu();
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_REQUEST['form'], $_GET['activate-multi'], $_REQUEST['step'] );
		$this->reset_menu();
		parent::tear_down();
	}

	private function reset_menu(): void {
		global $menu, $submenu, $_registered_pages, $_parent_pages, $admin_page_hooks;
		$menu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$submenu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_parent_pages     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$admin_page_hooks  = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	/** @return string[] */
	private function build_menu(): array {
		global $submenu;
		$this->plugin()->get( Menu::class )->build();
		return array_column( $submenu['form-captain'] ?? array(), 2 );
	}

	public function test_admin_sees_all_pages_and_hook_fires(): void {
		$this->as_admin();
		$before = did_action( 'form_captain_admin_menu_built_v1' );
		$slugs  = $this->build_menu();
		$this->assertSame( array( 'form-captain', 'form-captain-forms', 'form-captain-edit', 'form-captain-entries', 'form-captain-integrations', 'form-captain-settings' ), $slugs );
		$this->assertSame( $before + 1, did_action( 'form_captain_admin_menu_built_v1' ) );
	}

	public function test_dashboard_renders_once(): void {
		$this->as_admin();
		$this->build_menu();
		$hook = (string) $this->plugin()->get( Menu::class )->hooks()['form-captain'];
		$this->assertSame( 'toplevel_page_form-captain', $hook );
		global $wp_filter;
		$this->assertCount( 1, $wp_filter[ $hook ]->callbacks[10] ?? array(), 'top-level page and submenu "Dashboard" share one callback' );
		remove_all_actions( $hook );
	}

	public function test_editor_role_sees_nothing(): void {
		$this->as_role( 'editor' );
		global $menu;
		$this->assertSame( array(), $this->build_menu() );
		$this->assertSame( array(), (array) $menu );
		$this->assertNull( Menu::menu_capability() );
	}

	public function test_role_with_entries_only_sees_dashboard_and_entries(): void {
		$this->as_role( 'editor' );
		wp_get_current_user()->add_cap( Capabilities::READ_ENTRIES );
		$this->assertSame( array( 'form-captain', 'form-captain-entries' ), $this->build_menu() );
	}

	public function test_activation_redirect_only_once_and_not_on_bulk(): void {
		$this->as_admin();
		$menu = $this->plugin()->get( Menu::class );
		update_option( Menu::REDIRECT_OPTION, 1 );
		$this->assertStringContainsString( 'page=form-captain', (string) $menu->activation_redirect_url() );
		$this->assertNull( $menu->activation_redirect_url() );

		update_option( Menu::REDIRECT_OPTION, 1 );
		$_GET['activate-multi'] = 'true';
		$this->assertNull( $menu->activation_redirect_url() );
		$this->assertFalse( get_option( Menu::REDIRECT_OPTION ) );
	}

	public function test_form_actions_require_nonce(): void {
		$this->as_admin();
		$form             = $this->create_published_form();
		$_REQUEST['form'] = (string) $form['id'];
		$page             = $this->plugin()->get( FormsPage::class );
		foreach ( array( 'pause', 'archive', 'delete', 'duplicate', 'export', 'preview' ) as $action ) {
			try {
				$page->dispatch( $action );
				$this->fail( "$action without nonce must die" );
			} catch ( \WPDieException $e ) {
				$this->assertSame( 'published', $this->plugin()->get( FormRepository::class )->find( $form['id'] )['status'] );
			}
		}
		// Nonce for another form ID is rejected.
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'pause', $form['id'] + 1 ) );
		$this->expectException( \WPDieException::class );
		$page->dispatch( 'pause' );
	}

	public function test_form_actions_require_capability(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$this->as_role( 'editor' );
		$_REQUEST['form']     = (string) $form['id'];
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'pause', $form['id'] ) );
		try {
			$this->plugin()->get( FormsPage::class )->dispatch( 'pause' );
			$this->fail( 'editor must not pause' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'published', $this->plugin()->get( FormRepository::class )->find( $form['id'] )['status'] );
		}
	}

	public function test_form_actions_with_nonce_and_capability(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		$page = $this->plugin()->get( FormsPage::class );
		$repo = $this->plugin()->get( FormRepository::class );

		$_REQUEST['form']     = (string) $form['id'];
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'pause', $form['id'] ) );
		$res                  = $page->dispatch( 'pause' );
		$this->assertStringContainsString( 'fc_notice=pause', $res['redirect'] );
		$this->assertSame( 'paused', $repo->find( $form['id'] )['status'] );

		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'export', $form['id'] ) );
		$res                  = $page->dispatch( 'export' );
		$data                 = json_decode( $res['download'], true );
		$this->assertSame( 'form-captain/form', $data['format'] );

		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'preview', $form['id'] ) );
		$res                  = $page->dispatch( 'preview' );
		$this->assertStringContainsString( 'action=form_captain_preview', $res['redirect'] );

		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'archive', $form['id'] ) );
		$page->dispatch( 'archive' );
		$this->assertSame( 'archived', $repo->find( $form['id'] )['status'] );

		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'delete', $form['id'] ) );
		$page->dispatch( 'delete' );
		$this->assertNull( $repo->find( $form['id'] ) );
	}

	public function test_import_report_url_and_create_action(): void {
		$this->as_admin();
		$page = $this->plugin()->get( FormsPage::class );
		unset( $_REQUEST['form'] );
		$_REQUEST['_wpnonce'] = wp_create_nonce( FormsPage::nonce_action( 'create' ) );
		$_REQUEST['template'] = 'quote';
		$res                  = $page->dispatch( 'create' );
		unset( $_REQUEST['template'] );
		$this->assertStringContainsString( 'page=form-captain-edit', $res['redirect'] );

		$url = $page->import_result_url( $this->plugin()->get( FormService::class )->import( '{"x":1}' ), 'http://example.org/list' );
		$this->assertStringContainsString( 'fc_error=schema_invalid', $url );
		$this->assertArrayHasKey( 'error', get_transient( 'form_captain_import_report_' . get_current_user_id() ) );
	}

	/** Other suites may leak committed rows (transactions inside services); start from empty tables. */
	private function empty_tables(): void {
		global $wpdb;
		foreach ( array( \FormCaptain\Database\Tables::FORMS, \FormCaptain\Database\Tables::FORM_VERSIONS, \FormCaptain\Database\Tables::ENTRIES ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . \FormCaptain\Database\Tables::name( $t ) ); // phpcs:ignore WordPress.DB
		}
		delete_option( DashboardPage::STEPS_OPTION );
	}

	public function test_dashboard_step_requires_nonce(): void {
		$this->as_admin();
		$this->empty_tables();
		$dash             = $this->plugin()->get( DashboardPage::class );
		$_REQUEST['step'] = 'privacy';
		try {
			$dash->process_step();
			$this->fail( 'must die without nonce' );
		} catch ( \WPDieException $e ) {
			$this->assertFalse( $dash->steps()['privacy'] );
		}
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'form_captain_setup_step' );
		$dash->process_step();
		$this->assertTrue( $dash->steps()['privacy'] );
		$this->assertFalse( $dash->steps()['form'] );
		$this->plugin()->get( FormService::class )->create_from_template( 'contact' );
		$this->assertTrue( $dash->steps()['form'] );
	}

	public function test_pro_hint_respects_setting_dismissal_and_pro(): void {
		$uid = $this->as_admin();
		$this->assertTrue( DashboardPage::should_show_pro_hint() );
		add_filter( 'form_captain_pro_active', '__return_true' );
		$this->assertFalse( DashboardPage::should_show_pro_hint() );
		remove_filter( 'form_captain_pro_active', '__return_true' );
		update_user_meta( $uid, DashboardPage::HINT_META, 1 );
		$this->assertFalse( DashboardPage::should_show_pro_hint() );
	}

	public function test_preview_token_bound_to_user_and_expires(): void {
		$owner  = $this->as_admin();
		$form   = $this->create_published_form();
		$tokens = $this->plugin()->get( PreviewTokens::class );
		$link   = $tokens->create( $form['id'], $owner );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $link['token'] );
		$this->assertSame( $form['id'], $tokens->verify( $link['token'], $owner ) );

		$editor = $this->plugin()->get( EditorPage::class );
		$html   = $editor->preview_html( $link['token'] );
		$this->assertIsString( $html );
		$this->assertStringContainsString( 'submissions are disabled', $html );
		$this->assertStringContainsString( 'noindex', $html );

		// Other admin: forbidden.
		$this->as_admin();
		$res = $editor->preview_html( $link['token'] );
		$this->assertWPError( $res );
		$this->assertSame( 'forbidden', $res->get_error_code() );

		// Editor role and anonymous: no access, no schema.
		$this->as_role( 'editor' );
		$this->assertWPError( $editor->preview_html( $link['token'] ) );
		wp_set_current_user( 0 );
		$this->assertWPError( $editor->preview_html( $link['token'] ) );

		// Expired after 30 min.
		wp_set_current_user( $owner );
		Clock::freeze( time() + PreviewTokens::TTL + 5 );
		$res = $tokens->verify( $link['token'], $owner );
		$this->assertSame( 'invalid_token', $res->get_error_code() );
		Clock::freeze( null );

		$this->assertSame( 'invalid_token', $tokens->verify( 'nothex', $owner )->get_error_code() );
	}

	public function test_preview_creates_no_entries_or_jobs(): void {
		global $wpdb;
		$owner = $this->as_admin();
		$form  = $this->create_published_form();
		$link  = $this->plugin()->get( PreviewTokens::class )->create( $form['id'], $owner );
		$count = static fn( string $t ): int => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \FormCaptain\Database\Tables::name( $t ) ); // phpcs:ignore
		$e     = $count( \FormCaptain\Database\Tables::ENTRIES );
		$j     = $count( \FormCaptain\Database\Tables::JOBS );
		$html  = $this->plugin()->get( EditorPage::class )->preview_html( $link['token'] );
		$this->assertIsString( $html );
		$this->assertDoesNotMatchRegularExpression( '/name="_fc_token" value="[^"]+"/', $html );
		$this->assertSame( $e, $count( \FormCaptain\Database\Tables::ENTRIES ) );
		$this->assertSame( $j, $count( \FormCaptain\Database\Tables::JOBS ) );
	}

	public function test_editor_bootstrap_lists_field_types_and_published_pages_only(): void {
		$this->as_admin();
		$form = $this->create_published_form();
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Hidden draft' ) );
		$pub  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Thanks' ) );
		$data = $this->plugin()->get( EditorPage::class )->bootstrap( $form['id'] );
		$this->assertCount( 12, array_filter( $data['fieldTypes'], static fn( $t ) => $t['core'] ) );
		$this->assertSame( array( $pub ), array_column( $data['pages'], 'id' ) );
		$this->assertIsArray( $data['actions'] );
	}
}
