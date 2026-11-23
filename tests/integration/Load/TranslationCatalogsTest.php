<?php
/**
 * German catalogs: complete, placeholders intact, the right form of address.
 *
 * de_DE and de_AT address the reader with "du", de_DE_formal and de_CH with
 * "Sie"; de_CH writes "ss" instead of "ß". Every catalog has a compiled .mo
 * with the same strings and a JSON file for every script that uses wp-i18n.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Load;

use FormCaptain\Tests\Integration\TestCase;

final class TranslationCatalogsTest extends TestCase {

	private const INFORMAL = array( 'de_DE', 'de_AT' );
	private const FORMAL   = array( 'de_DE_formal', 'de_CH' );

	/**
	 * "Sie" that means "it/they" (sentence start) or quotes the WordPress
	 * language name. Each entry is a msgid whose translation may contain it.
	 */
	private const SIE_THIRD_PERSON = array(
		'Read-only overview for troubleshooting. It never shows submitted values, email addresses, IP addresses or secrets.',
		'Retention period of this form: %s. It is calculated from the original submission time – rows whose period has already expired are not imported, others are deleted automatically when it ends.',
		'This action is currently not available (connector inactive or removed). It will not run.',
		'This website uses forms to receive inquiries. The data you enter is stored in our website database and used to process your request. It is deleted after %s unless longer storage is required. Form data is not passed to third parties unless stated in the form.',
		'German: the WordPress language "Deutsch (Sie)" addresses visitors formally, "Deutsch" informally. Choose it under Settings → General.',
	);

	private const SIE           = '/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen)\b/u';
	private const DU            = '/\b(du|dich|dir|dein|deine|deinen|deinem|deiner|deines)\b/iu';
	private const DU_IMPERATIVE = '/(?:^|[.:;!?–]\s+|\bBitte\s+)(gib|wähle|prüfe|lade|füge|bestätige|sende|verwende|nutze|lass|stell|erhöhe|tippe|öffne|klicke|aktiviere|lösche|versuche es)\b/iu';

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function locales(): array {
		$out = array();
		foreach ( array_merge( self::INFORMAL, self::FORMAL ) as $locale ) {
			$out[ $locale ] = array( $locale );
		}
		return $out;
	}

	/**
	 * @dataProvider locales
	 */
	public function test_catalog_is_complete_and_keeps_placeholders( string $locale ): void {
		$pot = $this->load_po( 'form-captain.pot' );
		$po  = $this->load_po( 'form-captain-' . $locale . '.po' );
		$this->assertSame( $locale, $po->headers['Language'] ?? '' );
		foreach ( $pot->entries as $key => $source ) {
			$this->assertArrayHasKey( $key, $po->entries, $locale . ' misses: ' . $source->singular );
			$entry = $po->entries[ $key ];
			$this->assertNotEmpty( $entry->translations, $locale . ' untranslated: ' . $source->singular );
			$this->assertNotContains( 'fuzzy', $entry->flags, $locale . ' fuzzy: ' . $source->singular );
			foreach ( $entry->translations as $translation ) {
				$this->assertNotSame( '', $translation, $locale . ' empty: ' . $source->singular );
				$this->assertSame( $this->placeholders( $source->singular ), $this->placeholders( $translation ), $locale . ' placeholders: ' . $source->singular );
			}
		}
	}

	/**
	 * @dataProvider locales
	 */
	public function test_mo_matches_po( string $locale ): void {
		$po = $this->load_po( 'form-captain-' . $locale . '.po' );
		$mo = new \MO();
		$this->assertTrue( $mo->import_from_file( FORM_CAPTAIN_DIR . 'languages/form-captain-' . $locale . '.mo' ), $locale . '.mo missing' );
		foreach ( $po->entries as $key => $entry ) {
			if ( $this->only_in_scripts( $entry ) ) {
				continue; // Scripts read these from the JSON files, not from the .mo.
			}
			$this->assertArrayHasKey( $key, $mo->entries, $locale . '.mo is older than the .po: ' . $entry->singular );
			$this->assertSame( $entry->translations, $mo->entries[ $key ]->translations, $locale . '.mo differs: ' . $entry->singular );
		}
		foreach ( array( 'assets/build/editor.js', 'assets/build/block-editor.js', 'assets/build/admin-settings.js' ) as $script ) {
			$this->assertFileExists( FORM_CAPTAIN_DIR . 'languages/form-captain-' . $locale . '-' . md5( $script ) . '.json' );
		}
	}

	public function test_informal_catalogs_say_du(): void {
		foreach ( self::INFORMAL as $locale ) {
			foreach ( $this->load_po( 'form-captain-' . $locale . '.po' )->entries as $entry ) {
				if ( in_array( $entry->singular, self::SIE_THIRD_PERSON, true ) ) {
					continue;
				}
				foreach ( $entry->translations as $translation ) {
					$this->assertDoesNotMatchRegularExpression( self::SIE, $translation, $locale . ' uses "Sie": ' . $entry->singular );
				}
			}
		}
	}

	public function test_formal_catalogs_say_sie(): void {
		$informal = $this->load_po( 'form-captain-de_DE.po' );
		foreach ( self::FORMAL as $locale ) {
			foreach ( $this->load_po( 'form-captain-' . $locale . '.po' )->entries as $key => $entry ) {
				foreach ( $entry->translations as $i => $translation ) {
					// Quotes the WordPress language names, which mention "du".
					$translation = str_replace( '„du“', '', $translation );
					$this->assertDoesNotMatchRegularExpression( self::DU, $translation, $locale . ' uses "du": ' . $entry->singular );
					$this->assertDoesNotMatchRegularExpression( self::DU_IMPERATIVE, $translation, $locale . ' uses a du imperative: ' . $entry->singular );
					if ( 'de_CH' === $locale ) {
						$this->assertStringNotContainsString( 'ß', $translation, 'de_CH uses ß: ' . $entry->singular );
					}
					// Every text that addresses the reader in de_DE has its own Sie version.
					$du = $informal->entries[ $key ]->translations[ $i ] ?? '';
					if ( 1 === preg_match( self::DU, $du ) ) {
						$this->assertNotSame( $du, $translation, $locale . ' copies the du text: ' . $entry->singular );
					}
				}
			}
		}
	}

	private function only_in_scripts( \Translation_Entry $entry ): bool {
		if ( array() === $entry->references ) {
			return false;
		}
		foreach ( $entry->references as $reference ) {
			if ( ! str_contains( $reference, '.js:' ) ) {
				return false;
			}
		}
		return true;
	}

	private function load_po( string $file ): \PO {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		$po = new \PO();
		$this->assertTrue( $po->import_from_file( FORM_CAPTAIN_DIR . 'languages/' . $file ), $file );
		return $po;
	}

	/**
	 * Printf placeholders, {tokens} and HTML tags, sorted.
	 *
	 * @return list<string>
	 */
	private function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sdfu]|\{[a-z_]+\}|<\/?[a-z]+/', $text, $m );
		$found = $m[0];
		sort( $found );
		return $found;
	}
}
