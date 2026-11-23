<?php
/**
 * Contract documentation against the code (SET-FC-P6): every hook the core fires is listed in
 * docs/spec/api-v1.md, every name listed there exists in the code, and the documented contract
 * version is the one the plugin reports.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Tests\Integration\TestCase;

final class HookDocumentationTest extends TestCase {

	private static function root(): string {
		return (string) realpath( FORM_CAPTAIN_DIR );
	}

	private static function code(): string {
		$code = (string) file_get_contents( self::root() . '/form-captain.php' );
		foreach ( array( 'src', 'includes' ) as $dir ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$code .= "\n" . (string) file_get_contents( $file->getPathname() );
				}
			}
		}
		return $code;
	}

	private static function api_doc(): string {
		return (string) file_get_contents( self::root() . '/docs/spec/api-v1.md' );
	}

	public function test_every_hook_fired_by_the_core_is_documented(): void {
		preg_match_all( '/\b(?:apply_filters|do_action)\(\s*\'(form_captain_[a-z0-9_]+)\'/', self::code(), $m );
		$hooks = array_unique( $m[1] );
		$this->assertGreaterThan( 40, count( $hooks ), 'scanner finds the hooks' );
		$doc = self::api_doc();
		foreach ( $hooks as $hook ) {
			$this->assertStringContainsString( '`' . $hook . '`', $doc, "$hook is missing in docs/spec/api-v1.md" );
		}
	}

	public function test_every_name_in_the_api_document_exists_in_the_code(): void {
		preg_match_all( '/`(form_captain_[a-z0-9_]+)(?:\(\))?`/', self::api_doc(), $m );
		$code = self::code();
		foreach ( array_unique( $m[1] ) as $name ) {
			$this->assertStringContainsString( $name, $code, "$name is documented but not in the code" );
		}
	}

	public function test_documented_contract_version_is_the_current_one(): void {
		$this->assertStringContainsString( "FORM_CAPTAIN_CONTRACT_VERSION // '" . FORM_CAPTAIN_CONTRACT_VERSION . "'", self::api_doc() );
		$this->assertStringContainsString( '**' . FORM_CAPTAIN_CONTRACT_VERSION . '**', self::api_doc(), 'the history names the current version' );
	}
}
