<?php
/**
 * Minimum platform (decision 21: PHP 8.5, WordPress 7.0) is the same in the plugin header, the
 * constants, readme.txt, composer.json and the CI matrix (SET-FC-P6).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions

namespace FormCaptain\Tests\Integration\Compat;

use FormCaptain\Tests\Integration\TestCase;

final class PlatformVersionsTest extends TestCase {

	private static function file( string $rel ): string {
		return (string) file_get_contents( FORM_CAPTAIN_DIR . $rel );
	}

	public function test_minimum_versions_agree_everywhere(): void {
		$this->assertSame( '8.5', FORM_CAPTAIN_MIN_PHP );
		$this->assertSame( '7.0', FORM_CAPTAIN_MIN_WP );

		$header = get_file_data( FORM_CAPTAIN_FILE, array( 'php' => 'Requires PHP', 'wp' => 'Requires at least' ) );
		$this->assertSame( FORM_CAPTAIN_MIN_PHP, $header['php'] );
		$this->assertSame( FORM_CAPTAIN_MIN_WP, $header['wp'] );

		$readme = self::file( 'readme.txt' );
		$this->assertMatchesRegularExpression( '/^Requires PHP: ' . preg_quote( FORM_CAPTAIN_MIN_PHP, '/' ) . '$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires at least: ' . preg_quote( FORM_CAPTAIN_MIN_WP, '/' ) . '$/m', $readme );

		$composer = json_decode( self::file( 'composer.json' ), true );
		$this->assertSame( '>=' . FORM_CAPTAIN_MIN_PHP, $composer['require']['php'] );
		$this->assertStringStartsWith( FORM_CAPTAIN_MIN_PHP . '.', $composer['config']['platform']['php'] );

		$ci = self::file( '.github/workflows/ci.yml' );
		$this->assertMatchesRegularExpression( "/php: \\[ '" . preg_quote( FORM_CAPTAIN_MIN_PHP, '/' ) . "' \\]/", $ci, 'CI tests the minimum PHP' );
		$this->assertMatchesRegularExpression( "/wp: \\[ '" . preg_quote( FORM_CAPTAIN_MIN_WP, '/' ) . "\\.[0-9]+', 'latest' \\]/", $ci, 'CI tests the minimum WordPress and the latest' );
		$this->assertStringContainsString( "'mariadb:12.3', 'mysql:9.7'", $ci, 'CI tests the minimum database servers' );
	}

	/**
	 * The plugin's own autoloader runs before Composer's: a site whose plugin folder has vendor/ as a
	 * link to another checkout (worktree, E2E site) still runs the classes of this folder.
	 */
	public function test_own_autoloader_runs_before_composer(): void {
		$own      = null;
		$composer = null;
		foreach ( spl_autoload_functions() as $i => $loader ) {
			if ( $loader instanceof \Closure && str_ends_with( (string) ( new \ReflectionFunction( $loader ) )->getFileName(), 'src/Autoloader.php' ) ) {
				$own = $own ?? $i;
			}
			if ( is_array( $loader ) && $loader[0] instanceof \Composer\Autoload\ClassLoader ) {
				$composer = $composer ?? $i;
			}
		}
		$this->assertNotNull( $own );
		if ( null !== $composer ) {
			$this->assertLessThan( $composer, $own );
		}
	}
}
