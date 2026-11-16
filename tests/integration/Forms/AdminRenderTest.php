<?php
/**
 * Smoke tests: admin screens render without notices and escape titles (P2-02, P2-05).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Forms;

use FormCaptain\Admin\DashboardPage;
use FormCaptain\Admin\EditorPage;
use FormCaptain\Admin\FormsPage;
use FormCaptain\Service\FormService;
use FormCaptain\Tests\Integration\TestCase;

final class AdminRenderTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'toplevel_page_form-captain' );
	}

	public function tear_down(): void {
		unset( $_GET['form'], $_GET['status'], $_GET['s'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function render( object $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_empty_states(): void {
		$this->as_admin();
		$dash = $this->render( $this->plugin()->get( DashboardPage::class ) );
		$this->assertStringContainsString( 'Get started', $dash );
		$this->assertStringContainsString( 'No Captain Suite plugins detected', $dash );
		$list = $this->render( $this->plugin()->get( FormsPage::class ) );
		$this->assertStringContainsString( 'Create your first form', $list );
		$this->assertStringContainsString( 'form_captain_form_import', $list );
		$chooser = $this->render( $this->plugin()->get( EditorPage::class ) );
		$this->assertStringContainsString( 'template=quote', $chooser );
	}

	public function test_list_and_editor_with_forms(): void {
		$this->as_admin();
		$service = $this->plugin()->get( FormService::class );
		$id      = $service->create_blank( 'Hello <script>alert(1)</script>' );
		$service->create_from_template( 'contact' );
		$list = $this->render( $this->plugin()->get( FormsPage::class ) );
		$this->assertStringContainsString( '[form_captain id=', $list );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $list );
		$this->assertStringContainsString( 'Export JSON', $list );
		$_GET['form'] = (string) $id;
		$editor       = $this->render( $this->plugin()->get( EditorPage::class ) );
		$this->assertStringContainsString( 'id="form-captain-editor"', $editor );
		$_GET['status'] = 'archived';
		$this->assertStringContainsString( 'No forms match', $this->render( $this->plugin()->get( FormsPage::class ) ) );
	}

	public function test_pages_refuse_editor_role(): void {
		$this->as_role( 'editor' );
		$this->expectException( \WPDieException::class );
		$this->plugin()->get( FormsPage::class )->render();
	}
}
