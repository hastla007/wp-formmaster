<?php
/**
 * Parser for shortcode form format type A (P9-06, IMP-01 … IMP-04, IMP-06 … IMP-08).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Import;

use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Import\ShortcodeFormatParser;
use FormCaptain\Tests\Integration\TestCase;

final class ShortcodeFormatParserTest extends TestCase {

	private ShortcodeFormatParser $parser;

	public function set_up(): void {
		parent::set_up();
		$this->parser = new ShortcodeFormatParser();
	}

	/** @return array<string,mixed> */
	private function parse( string $source ): array {
		return $this->parser->parse( $source );
	}

	/** @return array<string,array<string,mixed>> */
	private static function by_key( array $result ): array {
		return array_column( $result['fields'], null, 'key' );
	}

	/** @return string[] */
	private static function codes( array $result ): array {
		return array_column( $result['findings'], 'code' );
	}

	private function assert_valid_schema( array $result ): void {
		$res = ( new SchemaValidator() )->validate( array( 'schema_version' => 1, 'fields' => $result['fields'] ) );
		$this->assertSame( array(), $res['errors'] );
	}

	public function test_tokenizer_handles_options_quotes_pipes_and_escapes(): void {
		$tokens = ShortcodeFormatParser::tokenize( 'A [select* anliegen class:wide "Angebot" "Sup]port|support" \'Sing \\\' le\' "Quo\\"te"] B [/acceptance] [[text literal]]' );
		$this->assertSame( 'text', $tokens[0]['kind'] );
		$tag = $tokens[1];
		$this->assertSame( 'tag', $tag['kind'] );
		$this->assertSame( 'select', $tag['type'] );
		$this->assertTrue( $tag['required'] );
		$this->assertSame( 'anliegen', $tag['name'] );
		$this->assertSame( array( 'class:wide' ), $tag['options'] );
		$this->assertSame( array( 'Angebot', 'Sup]port|support', "Sing ' le", 'Quo"te' ), $tag['values'] );
		$this->assertTrue( $tokens[3]['closing'] );
		$this->assertSame( ' [text literal]', $tokens[4]['text'] );
	}

	public function test_full_contact_form_maps_all_field_types(): void {
		$src = <<<'SRC'
<label> Ihr Name
    [text* your-name minlength:2 maxlength:80 class:wide placeholder "Max Mustermann"] </label>
<label> E-Mail
    [email* your-email] </label>
<label> Zweite E-Mail [email cc-mail] </label>
<label> Website [url website] </label>
<label> Telefon [tel phone placeholder "+49 …"] </label>
<label> Anzahl [number menge min:1 max:10 step:1] </label>
<label> Bewertung [range score min:0 max:5] </label>
<label> Termin [date termin min:today max:2030-12-31] </label>
<label> Nachricht [textarea* your-message 40x8 maxlength:2000] </label>
<label> Anliegen [select* anliegen include_blank "Angebot" "Support|support" "Für Firmen"] </label>
<label> Themen [select themen multiple "A|a" "B|b"] </label>
<label> Interessen [checkbox interessen "Web|web" "App|app"] </label>
<label> Größe [checkbox groesse exclusive "S" "M" "L"] </label>
<label> Kontaktweg [radio kontaktweg default:2 "E-Mail|mail" "Telefon|phone"] </label>
[hidden quelle "landingpage"]
<p>Bitte beachten Sie unsere <a href="https://example.org/datenschutz">Datenschutzhinweise</a>.</p>
[acceptance dsgvo] Ich stimme der <strong>Verarbeitung</strong> zu. [/acceptance]
[submit "Senden"]
SRC;
		$r   = $this->parse( $src );
		$f   = self::by_key( $r );
		$this->assertNotSame( 'blocked', $r['status'] );
		$this->assert_valid_schema( $r );

		$this->assertSame( 'text', $f['your_name']['type'] );
		$this->assertSame( 'Ihr Name', $f['your_name']['label'] );
		$this->assertTrue( $f['your_name']['required'] );
		$this->assertSame( 'Max Mustermann', $f['your_name']['placeholder'] );
		$this->assertSame( array( 'max_length' => 80, 'min_length' => 2 ), $f['your_name']['validation'] );
		$this->assertSame( 'wide', $f['your_name']['css_class'] );
		$this->assertMatchesRegularExpression( '/^f_[a-z0-9]{6,32}$/', $f['your_name']['id'] );

		$this->assertSame( 'email', $f['your_email']['type'] );
		$this->assertTrue( $f['your_email']['privacy_lookup'] );
		$this->assertFalse( $f['cc_mail']['privacy_lookup'] );

		$this->assertSame( 'text', $f['website']['type'] );
		$this->assertSame( 1000, $f['website']['validation']['max_length'] );
		$this->assertSame( 'tel', $f['phone']['type'] );
		$this->assertSame( array( 'min' => '1', 'max' => '10', 'step' => '1' ), $f['menge']['validation'] );
		$this->assertSame( 'number', $f['score']['type'] );
		$this->assertSame( array( 'min' => 'today', 'max' => '2030-12-31' ), $f['termin']['validation'] );
		$this->assertSame( 8, $f['your_message']['rows'] );
		$this->assertSame( 2000, $f['your_message']['validation']['max_length'] );

		$this->assertSame( 'select', $f['anliegen']['type'] );
		$this->assertSame( array( 'Angebot', 'support', 'Fur-Firmen' ), array_column( $f['anliegen']['options'], 'value' ) );
		$this->assertSame( 'Für Firmen', $f['anliegen']['options'][2]['label'] );
		$this->assertArrayNotHasKey( 'default', $f['anliegen'] );
		$this->assertTrue( $f['themen']['multiple'] );
		$this->assertSame( 'checkbox', $f['interessen']['type'] );
		$this->assertSame( 'radio', $f['groesse']['type'] );
		$this->assertSame( 'radio', $f['kontaktweg']['type'] );
		$this->assertSame( 'phone', $f['kontaktweg']['default'] );
		$this->assertSame( 'hidden', $f['quelle']['type'] );
		$this->assertSame( 'landingpage', $f['quelle']['default'] );

		$html = array_values( array_filter( $r['fields'], static fn( $x ) => 'html' === $x['type'] ) );
		$this->assertCount( 1, $html );
		$this->assertStringContainsString( 'href="https://example.org/datenschutz"', $html[0]['content'] );

		$this->assertSame( 'consent', $f['dsgvo']['type'] );
		$this->assertTrue( $f['dsgvo']['required'] );
		$this->assertArrayNotHasKey( 'default', $f['dsgvo'] );
		$this->assertSame( 1, $f['dsgvo']['consent_revision'] );
		$this->assertStringContainsString( '<strong>Verarbeitung</strong>', $f['dsgvo']['consent_text'] );

		$this->assertSame( 'Senden', $r['submit_label'] );
		$this->assertSame( $f['your_name']['id'], $r['source_map']['your-name'] );
		$codes = self::codes( $r );
		$this->assertContains( 'type_mapped', $codes );
		$this->assertContains( 'option_value_normalized', $codes );
	}

	public function test_required_flag_and_single_checkbox(): void {
		$r = $this->parse( "Newsletter [checkbox newsletter]\nName [text name]" );
		$f = self::by_key( $r );
		$this->assertFalse( $f['name']['required'] );
		$this->assertSame( array( array( 'value' => '1', 'label' => 'Newsletter' ) ), $f['newsletter']['options'] );
		$this->assert_valid_schema( $r );
	}

	public function test_acceptance_is_never_prechecked_and_optional_becomes_checkbox(): void {
		$r = $this->parse( '[acceptance a1 default:on] Ja, ich stimme zu. [/acceptance] [acceptance a2 optional] Newsletter abonnieren [/acceptance] [text name]' );
		$f = self::by_key( $r );
		$this->assertSame( 'consent', $f['a1']['type'] );
		$this->assertArrayNotHasKey( 'default', $f['a1'] );
		$this->assertContains( 'prechecked_removed', self::codes( $r ) );
		$this->assertSame( 'checkbox', $f['a2']['type'] );
		$this->assertFalse( $f['a2']['required'] );
		$this->assert_valid_schema( $r );
	}

	public function test_key_normalization_and_collisions(): void {
		$this->assertSame( 'your_name', ShortcodeFormatParser::normalize_key( 'Your-Name' ) );
		$this->assertSame( 'abc', ShortcodeFormatParser::normalize_key( '123abc' ) );
		$this->assertSame( 40, strlen( ShortcodeFormatParser::normalize_key( str_repeat( 'a', 60 ) ) ) );
		$r    = $this->parse( 'A [text name] B [text name] C [text Name!] D [text 99]' );
		$keys = array_column( $r['fields'], 'key' );
		$this->assertSame( array( 'name', 'name_2', 'name_3', 'text' ), $keys );
		$this->assertContains( 'key_renamed', self::codes( $r ) );
		$this->assert_valid_schema( $r );
	}

	public function test_label_derivation_and_fallbacks(): void {
		$r = $this->parse( "<p>Vorname</p>\n[text vorname]\n[text firma placeholder \"Firma GmbH\"]\n[text plz]" );
		$f = self::by_key( $r );
		$this->assertSame( 'Vorname', $f['vorname']['label'] );
		$this->assertSame( 'Firma GmbH', $f['firma']['label'] );
		$this->assertSame( 'plz', $f['plz']['label'] );
		$this->assertContains( 'label_derived', self::codes( $r ) );
		$this->assertCount( 0, array_filter( $r['fields'], static fn( $x ) => 'html' === $x['type'] ) );
	}

	public function test_unsupported_and_unknown_tags_are_reported_not_converted(): void {
		$r     = $this->parse( '<label>Name [text name]</label> [file anhang limit:1mb] [quiz q1 "1+1?|2"] [captchac cap] [captchar cap] [calculated summe "a+b"] [mystery x] [submit "Go"]' );
		$types = array_column( $r['fields'], 'type' );
		$this->assertSame( array( 'text' ), $types );
		$this->assertSame( 'lossy', $r['status'] );
		$dropped = array_values( array_filter( $r['elements'], static fn( $e ) => 'dropped' === $e['outcome'] ) );
		$this->assertSame( array( 'file', 'quiz', 'captchac', 'captchar', 'calculated', 'mystery' ), array_column( $dropped, 'source_type' ) );
		$this->assertSame( '[file anhang limit:1mb]', $dropped[0]['tag'] );
		$codes = self::codes( $r );
		$this->assertContains( 'field_unsupported', $codes );
		$this->assertContains( 'spam_protection_dropped', $codes );
		$this->assertSame( 6, $r['counts']['dropped'] );
	}

	public function test_select_free_text_and_dynamic_default_are_lost(): void {
		$r = $this->parse( 'Land [select land free_text "DE" "AT"] Mail [email mail default:user_email]' );
		$this->assertSame( 'lossy', $r['status'] );
		$f = self::by_key( $r );
		$this->assertArrayNotHasKey( 'default', $f['mail'] );
	}

	public function test_only_unknown_tags_or_empty_source_is_not_importable(): void {
		$this->assertSame( 'blocked', $this->parse( '[foo bar] [baz]' )['status'] );
		$this->assertSame( array(), $this->parse( '[foo bar] [baz]' )['fields'] );
		$this->assertSame( 'blocked', $this->parse( "  \n " )['status'] );
		$this->assertContains( 'empty_source', self::codes( $this->parse( '' ) ) );
	}

	public function test_limits_block_without_partial_import(): void {
		$src = '';
		for ( $i = 1; $i <= 101; $i++ ) {
			$src .= "Feld $i [text f$i]\n";
		}
		$r = $this->parse( $src );
		$this->assertSame( 'blocked', $r['status'] );
		$this->assertSame( array(), $r['fields'] );
		$this->assertContains( 'limit_exceeded', self::codes( $r ) );

		$opts = implode( ' ', array_map( static fn( $i ) => '"O' . $i . '"', range( 1, 201 ) ) );
		$r    = $this->parse( "Name [text name] Wahl [select wahl $opts]" );
		$this->assertSame( 'blocked', $r['status'] );
		$this->assertSame( array(), $r['fields'] );
	}

	public function test_malicious_html_and_script_are_removed(): void {
		$src = '<script>alert(1)</script><p onclick="steal()">Hinweis <a href="javascript:alert(2)">Link</a></p>'
			. "\n<label>Name<img src=x onerror=alert(3)> [text name placeholder \"<b>x</b>\"]</label>"
			. '[acceptance ok]Ich <a href="javascript:evil()" onmouseover="x()">stimme</a> zu<script>bad()</script>[/acceptance]';
		$r   = $this->parse( $src );
		$this->assertNotSame( 'blocked', $r['status'] );
		$json = (string) wp_json_encode( $r['fields'] );
		foreach ( array( '<script', 'alert(1)', 'javascript:', 'onclick', 'onerror', 'onmouseover', 'bad()', '<img' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
		$codes = self::codes( $r );
		$this->assertContains( 'html_removed', $codes );
		$this->assertContains( 'html_sanitized', $codes );
		$this->assert_valid_schema( $r );
	}

	public function test_extremely_long_labels_are_truncated(): void {
		$r = $this->parse( '<label>' . str_repeat( 'Sehr lang ', 5000 ) . '[text name]</label> [select s "' . str_repeat( 'x', 500 ) . '"]' );
		$f = self::by_key( $r );
		$this->assertLessThanOrEqual( 190, mb_strlen( $f['name']['label'] ) );
		$this->assertLessThanOrEqual( 190, mb_strlen( $f['s']['options'][0]['label'] ) );
		$this->assertLessThanOrEqual( 64, strlen( $f['s']['options'][0]['value'] ) );
		$this->assertContains( 'label_truncated', self::codes( $r ) );
		$this->assert_valid_schema( $r );
	}

	public function test_nested_and_unterminated_shortcodes_are_reported(): void {
		$r = $this->parse( 'Name [text name] Außen [text outer [text inner]] Offen [text open "nicht geschlossen]' );
		$this->assertContains( 'tag_malformed', self::codes( $r ) );
		$keys = array_column( $r['fields'], 'key' );
		$this->assertContains( 'name', $keys );
		$this->assertContains( 'inner', $keys );
		$this->assertNotContains( 'outer', $keys );
		$this->assertNotContains( 'open', $keys );
		$this->assert_valid_schema( array( 'fields' => array_values( array_filter( $r['fields'], static fn( $x ) => 'html' !== $x['type'] ) ) ) );
	}

	public function test_too_large_source_is_rejected(): void {
		$r = $this->parse( str_repeat( 'a', ShortcodeFormatParser::MAX_BYTES + 1 ) );
		$this->assertSame( 'blocked', $r['status'] );
		$this->assertContains( 'payload_too_large', self::codes( $r ) );
	}

	public function test_findings_contain_no_field_values(): void {
		$r    = $this->parse( 'Geheim [hidden token "s3cr3t-value"] Name [text name "Vorbelegung123"]' );
		$json = (string) wp_json_encode( array( $r['findings'], $r['elements'] ) );
		$this->assertStringNotContainsString( 'Vorbelegung123', (string) wp_json_encode( $r['findings'] ) );
		$this->assertStringNotContainsString( 's3cr3t-value', (string) wp_json_encode( $r['findings'] ) );
		$this->assertNotEmpty( $json );
	}
}
