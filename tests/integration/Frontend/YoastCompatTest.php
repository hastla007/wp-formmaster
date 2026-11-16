<?php
/**
 * Yoast SEO compatibility without runtime dependency (P3-18 … P3-21).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Frontend\YoastCompat;

require_once __DIR__ . '/FrontendTestCase.php';

final class YoastCompatTest extends FrontendTestCase {

	public function test_without_yoast_nothing_breaks(): void {
		$this->assertFalse( YoastCompat::yoast_active() );
		$this->assertFalse( YoastCompat::is_analysis_request() );
		$compat = new YoastCompat();
		$compat->enqueue_editor_script( 'post.php' );
		$this->assertFalse( wp_script_is( YoastCompat::HANDLE, 'registered' ) );
		$form = $this->create_published_form();
		$this->assertStringContainsString( '<form', do_shortcode( '[form_captain id="' . $form['public_id'] . '"]' ) );
	}

	public function test_no_seo_hooks_registered(): void {
		$hooks = array(
			'wpseo_title', 'wpseo_metadesc', 'wpseo_canonical', 'wpseo_robots', 'wpseo_opengraph_title', 'wpseo_opengraph_desc',
			'wpseo_opengraph_url', 'wpseo_twitter_title', 'wpseo_twitter_description', 'wpseo_schema_graph', 'wpseo_schema_graph_pieces',
			'wpseo_sitemap_index', 'wpseo_sitemap_exclude_post_type', 'wpseo_exclude_from_sitemap_by_post_ids', 'wpseo_frontend_presenters',
			'document_title_parts', 'pre_get_document_title', 'wp_robots',
		);
		global $wp_filter;
		foreach ( $hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$fn = $cb['function'];
					if ( is_array( $fn ) ) {
						$owner = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
					} elseif ( $fn instanceof \Closure ) {
						$owner = (string) ( new \ReflectionFunction( $fn ) )->getFileName();
					} else {
						$owner = is_string( $fn ) ? $fn : '';
					}
					$this->assertStringNotContainsString( 'FormCaptain', $owner, $hook );
					$this->assertStringNotContainsString( FORM_CAPTAIN_DIR . 'src', $owner, $hook );
				}
			}
		}
	}

	public function test_analysis_markup_only_public_labels(): void {
		$fields = array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Ihr Name', 'help' => 'Vor- und Nachname' ),
			array( 'id' => 'f_hidden', 'type' => 'hidden', 'key' => 'campaign', 'label' => '', 'default' => 'secret-campaign' ),
		);
		$form = $this->create_published_form( $fields );
		$html = YoastCompat::analysis_markup( $form, $this->plugin() );
		$this->assertStringContainsString( 'Ihr Name', $html );
		$this->assertStringContainsString( 'Vor- und Nachname', $html );
		$this->assertStringNotContainsString( 'secret-campaign', $html );
		$this->assertStringNotContainsString( '_fc_token', $html );
		$this->assertStringContainsString( "yoast.analysis.shortcodes", YoastCompat::inline_script() );
	}
}
