<?php
/**
 * WooCommerce isolation (P6-03): Form Captain Free does not hook into WooCommerce, does not call
 * WooCommerce APIs and does not touch WooCommerce tables (cart, classic/block checkout, orders, HPOS).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions

namespace FormCaptain\Tests\Integration\Compat;

use FormCaptain\Jobs\JobRunner;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Tests\Integration\Frontend\FrontendTestCase;

require_once dirname( __DIR__ ) . '/Frontend/FrontendTestCase.php';

final class WooCommerceIsolationTest extends FrontendTestCase {

	private static function plugin_dir(): string {
		return (string) realpath( dirname( FORM_CAPTAIN_FILE ) );
	}

	/** True when a hook callback belongs to Form Captain (class in FormCaptain\ or code in the plugin dir). */
	private static function is_form_captain_callback( mixed $cb ): bool {
		try {
			if ( is_array( $cb ) && isset( $cb[0] ) ) {
				$class = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
				return str_starts_with( ltrim( $class, '\\' ), 'FormCaptain\\' );
			}
			if ( is_string( $cb ) ) {
				if ( str_contains( $cb, '::' ) ) {
					return str_starts_with( ltrim( $cb, '\\' ), 'FormCaptain\\' );
				}
				if ( ! function_exists( $cb ) ) {
					return false;
				}
				$file = ( new \ReflectionFunction( $cb ) )->getFileName();
			} elseif ( $cb instanceof \Closure ) {
				$file = ( new \ReflectionFunction( $cb ) )->getFileName();
			} elseif ( is_object( $cb ) ) {
				return str_starts_with( get_class( $cb ), 'FormCaptain\\' );
			} else {
				return false;
			}
			return is_string( $file ) && str_starts_with( (string) realpath( $file ), self::plugin_dir() . DIRECTORY_SEPARATOR );
		} catch ( \ReflectionException $e ) {
			return false;
		}
	}

	/** @return string[] hook names with Form Captain callbacks that match $pattern */
	private static function form_captain_hooks( string $pattern ): array {
		global $wp_filter;
		$found = array();
		foreach ( (array) $wp_filter as $hook => $wp_hook ) {
			if ( ! preg_match( $pattern, (string) $hook ) || ! $wp_hook instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $item ) {
					if ( self::is_form_captain_callback( $item['function'] ) ) {
						$found[] = (string) $hook;
					}
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	public function test_detector_finds_form_captain_callbacks(): void {
		// Sanity check of the scanner itself: known core hooks are detected.
		$this->assertContains( 'form_captain_job_handlers', self::form_captain_hooks( '/^form_captain_job_handlers$/' ) );
		$this->assertNotEmpty( self::form_captain_hooks( '/^rest_api_init$/' ) );
	}

	public function test_no_hooks_on_woocommerce_actions_or_filters(): void {
		$this->assertTrue( did_action( 'init' ) > 0, 'Plugin booted' );
		$this->assertSame( array(), self::form_captain_hooks( '/^(woocommerce|wc)[_\-]/i' ) );

		// Also after the admin and frontend hooks ran once.
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->assertSame( array(), self::form_captain_hooks( '/^(woocommerce|wc)[_\-]/i' ) );
	}

	public function test_works_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'WooCommerce', false ), 'Test environment runs without WooCommerce' );
		$this->assertFalse( function_exists( 'WC' ) );
		$form = $this->create_published_form();
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( 'fc-form', $html );
		$res = $this->plugin()->get( SubmissionService::class )->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertTrue( $res['success'] );
	}

	public function test_runtime_queries_do_not_touch_woocommerce_tables(): void {
		$queries = array();
		$capture = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $capture );
		$form = $this->create_published_form();
		$this->plugin()->get( Renderer::class )->render( $form );
		$res = $this->plugin()->get( SubmissionService::class )->submit( $form, $this->payload( $form, $this->valid_fields() ) );
		$this->assertTrue( $res['success'] );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->plugin()->get( JobRunner::class )->run();
		remove_filter( 'query', $capture );

		$this->assertNotEmpty( $queries );
		foreach ( $queries as $sql ) {
			$this->assertDoesNotMatchRegularExpression( '/\b\w*(wc_orders|wc_order_|woocommerce_|wc_product|wc_customer|wc_reserved_stock|actionscheduler_)\w*/i', $sql );
			$this->assertDoesNotMatchRegularExpression( '/shop_order/i', $sql );
		}
	}

	/** Static check over the plugin source (P6-03): no WooCommerce APIs, hooks or tables. */
	public function test_source_has_no_woocommerce_access(): void {
		$patterns = array(
			'hook on woocommerce_/wc_' => '/\b(add_action|add_filter|remove_action|remove_filter|has_action|has_filter|do_action|apply_filters)\s*\(\s*[\'"](woocommerce|wc)[_\-]/i',
			'wc_* function call'       => '/(?<![\w$>:])wc_[a-z0-9_]+\s*\(/i',
			'WC() singleton'           => '/(?<![\w$>:])WC\s*\(\s*\)/',
			'WC_* class'               => '/\b(new\s+\\\\?WC_[A-Za-z_]+|\\\\?WC_[A-Z][A-Za-z_]+::)/',
			'Automattic\\WooCommerce'  => '/Automattic\\\\+WooCommerce/',
			'WooCommerce tables'       => '/\b(wc_orders|wc_order_[a-z_]+|woocommerce_[a-z_]+_items|woocommerce_sessions|woocommerce_order_itemmeta|wc_product_meta_lookup|wc_customer_lookup)\b/i',
			'order post type'          => '/[\'"]shop_order(_placehold)?[\'"]/',
			'cart/checkout blocks'     => '/woocommerce\/(cart|checkout)/i',
		);
		$dirs     = array( 'src', 'includes', 'blocks', 'templates' );
		$files    = array( self::plugin_dir() . '/form-captain.php', self::plugin_dir() . '/uninstall.php' );
		foreach ( $dirs as $dir ) {
			$path = self::plugin_dir() . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( in_array( $file->getExtension(), array( 'php', 'js', 'json' ), true ) && ! str_contains( $file->getPathname(), '/build/' ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		$this->assertGreaterThan( 20, count( $files ) );
		$violations = array();
		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			foreach ( $patterns as $label => $pattern ) {
				if ( preg_match( $pattern, $code, $m ) ) {
					$violations[] = $label . ' in ' . str_replace( self::plugin_dir() . '/', '', $file ) . ': ' . $m[0];
				}
			}
		}
		$this->assertSame( array(), $violations );

		// The only WooCommerce reference allowed is the informational plugin detection on the dashboard.
		$mentions = array();
		foreach ( $files as $file ) {
			if ( preg_match( '/woocommerce/i', (string) file_get_contents( $file ) ) ) {
				$mentions[] = str_replace( self::plugin_dir() . '/', '', $file );
			}
		}
		$this->assertSame( array( 'src/Admin/DashboardPage.php' ), $mentions );
	}
}
