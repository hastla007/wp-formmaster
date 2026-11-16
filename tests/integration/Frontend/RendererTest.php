<?php
/**
 * Renderer markup, accessibility, embedding and assets (P3-01…P3-04, P3-22, AC-059…AC-061).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Frontend\Assets;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Security\SpamGuard;

require_once __DIR__ . '/FrontendTestCase.php';

final class RendererTest extends FrontendTestCase {

	private function render( array $form, array $args = array() ): string {
		return $this->plugin()->get( Renderer::class )->render( $form, $args );
	}

	/** @return array<int,array<string,mixed>> */
	private function fields(): array {
		return array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true, 'help' => 'Vor- und Nachname' ),
			array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
			array( 'id' => 'f_topic1', 'type' => 'radio', 'key' => 'topic', 'label' => 'Thema', 'required' => true, 'options' => array( array( 'value' => 'quote', 'label' => 'Angebot' ), array( 'value' => 'other', 'label' => 'Sonstiges' ) ) ),
			array( 'id' => 'f_budget', 'type' => 'text', 'key' => 'budget', 'label' => 'Budget', 'condition' => array( 'action' => 'show', 'field' => 'f_topic1', 'operator' => 'equals', 'value' => 'quote' ) ),
			array( 'id' => 'f_cons01', 'type' => 'consent', 'key' => 'consent', 'label' => 'Datenschutz', 'required' => true, 'consent_text' => 'Ich stimme zu. <script>alert(1)</script>', 'default' => '1' ),
			array( 'id' => 'f_html01', 'type' => 'html', 'key' => 'note', 'label' => '', 'content' => '<p onclick="x()">Hinweis</p><iframe src="x"></iframe>' ),
		);
	}

	public function test_markup_is_semantic_and_accessible(): void {
		$form = $this->create_published_form( $this->fields() );
		$html = $this->render( $form );

		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="action" value="form_captain_submit">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_fc_form" value="' . $form['public_id'] . '">', $html );
		$this->assertMatchesRegularExpression( '/name="_fc_token" value="[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}"/', $html );
		$this->assertStringNotContainsString( '_wpnonce', $html, 'No WordPress nonce in cacheable HTML.' );

		$this->assertStringContainsString( '<label class="fc-label" for="fc1-f_name01">Name <span class="fc-required" aria-hidden="true">*</span></label>', $html );
		$this->assertStringContainsString( 'id="fc1-f_name01" name="fc[f_name01]" aria-describedby="fc1-f_name01-help fc1-f_name01-error" required aria-required="true"', $html );
		$this->assertStringContainsString( '<p class="fc-help" id="fc1-f_name01-help">Vor- und Nachname</p>', $html );
		$this->assertStringContainsString( 'Fields marked with', $html );
		$this->assertMatchesRegularExpression( '/<fieldset class="fc-field fc-field--radio fc-group" aria-describedby="fc1-f_topic1-error"[^>]*role="radiogroup" aria-required="true"><legend class="fc-label">Thema/', $html );
		$this->assertStringContainsString( 'data-fc-condition="', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'role="status"', $html );

		// Consent never pre-checked, script stripped; html note sanitized.
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="fc1-f_cons01" name="fc\[f_cons01\]" value="1"[^>]*>/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="fc1-f_cons01"[^>]*checked/', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z]+=/i', $html, 'No inline event handlers.' );

		// Honeypot.
		$hp = SpamGuard::honeypot_name( (string) $form['public_id'] );
		$this->assertMatchesRegularExpression( '/<div class="fc-hp" aria-hidden="true">.*name="' . $hp . '"[^>]*tabindex="-1" autocomplete="off"/', $html );
	}

	public function test_two_forms_get_unique_ids(): void {
		$form = $this->create_published_form();
		$html = $this->render( $form ) . $this->render( $form );
		preg_match_all( '/\sid="([^"]+)"/', $html, $m );
		$this->assertSame( count( $m[1] ), count( array_unique( $m[1] ) ) );
	}

	public function test_paused_archived_and_missing(): void {
		$form           = $this->create_published_form( null, array( 'paused_message' => 'Gerade Pause.' ) );
		$form['status'] = 'paused';
		wp_set_current_user( 0 );
		$html = $this->render( $form );
		$this->assertStringContainsString( 'Gerade Pause.', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'fc-notice--editor', $html );

		$form['status'] = 'archived';
		$this->assertSame( '', $this->render( $form ) );
		$this->assertSame( '', $this->render( array() ) );

		$this->as_admin();
		$this->assertStringContainsString( 'fc-notice--editor', $this->render( $form ) );
		$this->assertStringContainsString( 'fc-notice--editor', $this->render( array() ) );
	}

	public function test_preview_renders_working_schema_only_for_managers(): void {
		$repo = $this->plugin()->get( FormRepository::class );
		$id   = $repo->create( 'Entwurf', array( 'schema_version' => 1, 'fields' => array( array( 'id' => 'f_draft1', 'type' => 'text', 'key' => 'draft', 'label' => 'Entwurfsfeld' ) ) ), array() );
		$form = $repo->find( $id );
		$this->assertSame( '', $this->render( $form, array( 'preview' => true ) ) );
		$this->as_admin();
		$html = $this->render( $form, array( 'preview' => true ) );
		$this->assertStringContainsString( 'Entwurfsfeld', $html );
		$this->assertStringContainsString( 'data-fc-preview="1"', $html );
	}

	public function test_shortcode_ids(): void {
		$form = $this->create_published_form();
		$this->assertStringContainsString( '<form', do_shortcode( '[form_captain id="' . $form['public_id'] . '"]' ) );
		$this->assertStringContainsString( '<form', do_shortcode( '[form_captain id="' . $form['id'] . '"]' ) );
		$this->assertSame( '', do_shortcode( '[form_captain id="doesnotexist0000"]' ) );
		$this->assertSame( '', do_shortcode( '[form_captain id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[form_captain]' ) );
		$xss = do_shortcode( '[form_captain id="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"]' );
		$this->assertStringNotContainsString( '<script', $xss );
		$this->assertSame( '', $xss );
	}

	public function test_block_renders_same_form(): void {
		$form = $this->create_published_form();
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'form-captain/form' ) );
		$html = do_blocks( '<!-- wp:form-captain/form {"formId":"' . $form['public_id'] . '"} /-->' );
		$this->assertStringContainsString( 'data-fc-form="' . $form['public_id'] . '"', $html );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:form-captain/form {"formId":"gone000000000000"} /-->' ) ) );
	}

	public function test_assets_only_with_form(): void {
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$post = self::factory()->post->create( array( 'post_content' => 'Kein Formular' ) );
		$this->go_to( get_permalink( $post ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::HANDLE, 'enqueued' ) );

		$form = $this->create_published_form();
		$post = self::factory()->post->create( array( 'post_content' => '[form_captain id="' . $form['public_id'] . '"]' ) );
		$this->go_to( get_permalink( $post ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		// The inline config must never carry a nonce (cache safety, ADR 0003). The internal layout of
		// the "before" data differs between WordPress versions, so all inline parts are checked together.
		$inline = implode( "\n", array_filter( (array) wp_scripts()->get_data( Assets::HANDLE, 'before' ), 'is_string' ) );
		$this->assertNotSame( '', trim( $inline ), 'inline frontend config is present' );
		$this->assertStringNotContainsString( 'nonce', $inline );
	}

	public function test_renderer_marks_assets_and_base_mode(): void {
		$form = $this->create_published_form( null, array( 'appearance' => array( 'mode' => 'base' ) ) );
		$html = $this->render( $form );
		$this->assertTrue( Assets::was_rendered() );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'fc-mode-base', $html );
	}
}
