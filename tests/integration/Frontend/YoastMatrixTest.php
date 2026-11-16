<?php
/**
 * P6-20/P6-21: SEO output of a page is identical with and without an embedded form,
 * whether or not an SEO plugin hooks into the document head. Yoast itself is
 * simulated (wordpress.org downloads are unavailable in CI sandboxes); the real
 * Yoast version matrix is a manual release check (docs/RELEASE.md).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

require_once __DIR__ . '/FrontendTestCase.php';

final class YoastMatrixTest extends FrontendTestCase {

	/** Captures wp_head for a singular page request. */
	private function head_for( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();
		// Remove our own asset tags; only SEO-relevant markup is compared.
		$head = (string) preg_replace( '#<(?:link|script)[^>]+form-captain[^>]*>(?:</script>)?#', '', $head );
		return $head;
	}

	/** @return array<string,array{0:bool}> */
	public function seo_plugin_states(): array {
		return array(
			'no SEO plugin'         => array( false ),
			'simulated Yoast hooks' => array( true ),
		);
	}

	/**
	 * @dataProvider seo_plugin_states
	 */
	public function test_head_meta_identical_with_and_without_form( bool $simulate_yoast ): void {
		if ( $simulate_yoast ) {
			add_action(
				'wp_head',
				static function (): void {
					echo '<meta name="description" content="Yoast description" />' . "\n";
					echo '<link rel="canonical" href="' . esc_url( get_permalink() ) . '" />' . "\n";
					echo '<script type="application/ld+json" class="yoast-schema-graph">{"@graph":[]}</script>' . "\n";
				},
				1
			);
		}
		$form    = $this->create_published_form();
		$plain   = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Kontakt', 'post_content' => '<p>Text</p>' ) );
		$with    = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Kontakt', 'post_content' => '<p>Text</p>[form_captain id="' . $form['public_id'] . '"]' ) );
		$a       = str_replace( (string) get_permalink( $plain ), 'URL', $this->head_for( $plain ) );
		$b       = str_replace( (string) get_permalink( $with ), 'URL', $this->head_for( $with ) );
		$extract = static function ( string $html ): array {
			preg_match_all( '#<(?:meta|link rel="canonical")[^>]*>|<script type="application/ld\+json".*?</script>|<title>.*?</title>#s', $html, $m );
			return $m[0];
		};
		$this->assertSame( $extract( $a ), $extract( $b ), 'form must not change title, meta, canonical, robots or JSON-LD' );
		$this->assertStringNotContainsString( 'noindex', $b );
		$this->assertSame( $simulate_yoast ? 1 : 0, substr_count( $b, 'application/ld+json' ), 'no competing JSON-LD graph from Form Captain' );
	}

	public function test_form_is_server_rendered_once_and_crawlable(): void {
		$form = $this->create_published_form();
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[form_captain id="' . $form['public_id'] . '"]' ) );
		$this->go_to( get_permalink( $page ) );
		$html = apply_filters( 'the_content', get_post( $page )->post_content );
		$this->assertSame( 1, substr_count( $html, '<form' ), 'rendered exactly once' );
		$this->assertStringContainsString( 'Name', $html, 'labels visible in server HTML' );
		$this->assertStringContainsString( 'E-Mail', $html );
	}

	public function test_no_public_form_urls_or_sitemap_entries(): void {
		$public_types = get_post_types( array( 'public' => true ) );
		foreach ( $public_types as $type ) {
			$this->assertStringNotContainsString( 'form_captain', $type );
			$this->assertStringNotContainsString( 'fc_', $type );
		}
		$this->assertEmpty( array_filter( array_keys( (array) get_taxonomies( array( 'public' => true ) ) ), static fn( $t ) => str_contains( $t, 'form_captain' ) ) );
	}
}
