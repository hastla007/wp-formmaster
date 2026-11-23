<?php
/**
 * Field rules for other countries and better input (SET-FC-04, SET-FC-30, SET-FC-33):
 * postal code patterns, checked own pattern, international phone numbers, minimum age and
 * the autofill purpose (autocomplete) per field.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Domain\SubmissionValidator;
use FormCaptain\Domain\ValidationPatterns;
use FormCaptain\Frontend\Renderer;

require_once __DIR__ . '/FrontendTestCase.php';

final class FieldRulesTest extends FrontendTestCase {

	/** @param array<string,mixed> $field */
	private function check( array $field, string $value, string $today = '2026-11-23' ): array {
		$field = array_merge( array( 'id' => 'f_test01', 'key' => 'test', 'label' => 'Test' ), $field );
		$norm  = ( new SchemaValidator() )->validate(
			array(
				'schema_version' => 1,
				'fields'         => array( $field ),
			)
		);
		$this->assertSame( array(), $norm['errors'], 'schema is valid' );
		return ( new SubmissionValidator() )->validate( $norm['schema']['fields'], array( 'f_test01' => $value ), array( 'today' => $today ) );
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function postal_codes(): array {
		return array(
			'DE ok'           => array( 'zip_de', '10115', true ),
			'DE too short'    => array( 'zip_de', '1011', false ),
			'AT ok'           => array( 'zip_at', '1010', true ),
			'AT five digits'  => array( 'zip_at', '10100', false ),
			'CH ok'           => array( 'zip_ch', '8001', true ),
			'CH letters'      => array( 'zip_ch', '80A1', false ),
			'NL ok'           => array( 'zip_generic', '1012 AB', true ),
			'UK ok'           => array( 'zip_generic', 'SW1A 1AA', true ),
			'PL ok'           => array( 'zip_generic', '00-950', true ),
			'generic 1 char'  => array( 'zip_generic', '1', false ),
			'generic 11 char' => array( 'zip_generic', '12345678901', false ),
			'generic symbol'  => array( 'zip_generic', '12#45', false ),
			'letters only'    => array( 'alpha', "Anna-Lena O'Neil", true ),
			'letters digits'  => array( 'alnum', 'Weg 12', true ),
		);
	}

	/** @dataProvider postal_codes */
	public function test_patterns_per_country( string $pattern, string $value, bool $valid ): void {
		$res = $this->check(
			array(
				'type'       => 'text',
				'validation' => array( 'pattern' => $pattern ),
			),
			$value
		);
		if ( $valid ) {
			$this->assertSame( array(), $res['errors'] );
			$this->assertSame( $value, $res['values']['f_test01'] );
		} else {
			$this->assertSame( 'invalid_pattern', $res['codes']['f_test01'] );
			$this->assertSame( ValidationPatterns::all()[ $pattern ]['message'], $res['errors']['f_test01'], 'message per pattern' );
		}
	}

	public function test_own_pattern_is_checked_and_applied(): void {
		$res = $this->check(
			array(
				'type'       => 'text',
				'validation' => array(
					'pattern'        => 'custom',
					'custom_pattern' => '[A-Z]{2}-\d{4}',
				),
			),
			'AB-1234'
		);
		$this->assertSame( array(), $res['errors'] );
		$res = $this->check(
			array(
				'type'       => 'text',
				'validation' => array(
					'pattern'        => 'custom',
					'custom_pattern' => '[A-Z]{2}-\d{4}',
				),
			),
			'AB-1234x'
		);
		$this->assertSame( 'invalid_pattern', $res['codes']['f_test01'], 'anchored' );
		$this->assertSame( 'Please check the format of this field.', $res['errors']['f_test01'] );
	}

	/** @return array<string,array{0:string,1:?string}> */
	public static function custom_patterns(): array {
		return array(
			'simple'               => array( '[0-9]{3,5}', null ),
			'group repeated once'  => array( '(?:ab){2}', null ),
			'optional group'       => array( '(?:\+49)?\d+', null ),
			'nested plus'          => array( '(a+)+', 'pattern_unsafe' ),
			'nested star'          => array( '(?:\d*)*', 'pattern_unsafe' ),
			'alternation repeated' => array( '(a|aa)+', 'pattern_unsafe' ),
			'brace repeat'         => array( '(x{1,})*', 'pattern_unsafe' ),
			'deep nesting'         => array( '((ab)+c)+', 'pattern_unsafe' ),
			'back reference'       => array( '(a)\1', 'pattern_unsafe' ),
			'look ahead'           => array( '(?=a)a', 'pattern_unsafe' ),
			'recursion'            => array( 'a(?R)?b', 'pattern_unsafe' ),
			'inline flag'          => array( '(?i)abc', 'pattern_unsafe' ),
			'does not compile'     => array( '[a-', 'pattern_invalid' ),
			'too long'             => array( str_repeat( 'a', 201 ), 'pattern_invalid' ),
			'empty'                => array( '', 'pattern_required' ),
		);
	}

	/** @dataProvider custom_patterns */
	public function test_dangerous_own_patterns_are_rejected( string $pattern, ?string $error ): void {
		$this->assertSame( $error, ValidationPatterns::check_custom( $pattern ) );
		$res = ( new SchemaValidator() )->validate(
			array(
				'schema_version' => 1,
				'fields'         => array(
					array(
						'id'         => 'f_test01',
						'type'       => 'text',
						'key'        => 'test',
						'label'      => 'Test',
						'validation' => array(
							'pattern'        => 'custom',
							'custom_pattern' => $pattern,
						),
					),
				),
			)
		);
		if ( null === $error ) {
			$this->assertSame( $pattern, $res['schema']['fields'][0]['validation']['custom_pattern'] );
		} else {
			$this->assertSame( $error, $res['errors']['fields.0.validation.custom_pattern'] );
			$this->assertArrayNotHasKey( 'pattern', $res['schema']['fields'][0]['validation'], 'unsafe pattern is never stored' );
		}
	}

	public function test_unknown_pattern_is_an_error_and_extensions_can_add_patterns(): void {
		$res = ( new SchemaValidator() )->validate(
			array(
				'schema_version' => 1,
				'fields'         => array(
					array( 'id' => 'f_test01', 'type' => 'text', 'key' => 'test', 'label' => 'Test', 'validation' => array( 'pattern' => 'zip_xx' ) ),
				),
			)
		);
		$this->assertSame( 'invalid_pattern', $res['errors']['fields.0.validation.pattern'] );

		$add = static fn( array $p ): array => $p + array(
			'zip_nl' => array(
				'label'   => 'Dutch postal code',
				'regex'   => '/^\d{4} ?[A-Z]{2}$/',
				'message' => 'Bitte eine niederländische Postleitzahl eingeben.',
			),
			'broken' => array(
				'label' => 'Broken',
				'regex' => '/[a-/',
			),
		);
		add_filter( 'form_captain_validation_patterns_v1', $add );
		try {
			$this->assertArrayNotHasKey( 'broken', ValidationPatterns::all(), 'invalid regex of an extension is skipped' );
			$this->assertContains( 'zip_nl', array_column( ValidationPatterns::options(), 'value' ) );
			$res = $this->check( array( 'type' => 'text', 'validation' => array( 'pattern' => 'zip_nl' ) ), '1012XX1' );
			$this->assertSame( 'Bitte eine niederländische Postleitzahl eingeben.', $res['errors']['f_test01'] );
			$this->assertSame( array(), $this->check( array( 'type' => 'text', 'validation' => array( 'pattern' => 'zip_nl' ) ), '1012 AB' )['errors'] );
		} finally {
			remove_filter( 'form_captain_validation_patterns_v1', $add );
		}
	}

	public function test_international_phone_format(): void {
		$intl = array(
			'type'       => 'tel',
			'validation' => array( 'format' => 'international' ),
		);
		foreach ( array( '+49 30 1234567', '+43 1 234 56 78', '+41 44 668 18 00', '+1 (212) 555-0100' ) as $ok ) {
			$this->assertSame( array(), $this->check( $intl, $ok )['errors'], $ok );
		}
		foreach ( array( '030 1234567', '0049 30 1234567', '+0 30 1234567', '+49 12', '+49 1234 5678 9012 3456' ) as $bad ) {
			$this->assertSame( 'invalid_tel_international', $this->check( $intl, $bad )['codes']['f_test01'], $bad );
		}
		$this->assertStringContainsString( '+49', SubmissionValidator::message( 'invalid_tel_international' ) );
		$this->assertSame( array(), $this->check( array( 'type' => 'tel' ), '030 1234567' )['errors'], 'default stays loose' );

		$res = ( new SchemaValidator() )->validate( array( 'schema_version' => 1, 'fields' => array( array( 'id' => 'f_test01', 'type' => 'tel', 'key' => 't', 'label' => 'T', 'validation' => array( 'format' => 'us' ) ) ) ) );
		$this->assertSame( 'invalid_pattern', $res['errors']['fields.0.validation.format'] );
	}

	public function test_minimum_age_on_date_fields(): void {
		$field = array(
			'type'       => 'date',
			'validation' => array( 'min_age' => 18 ),
		);
		$this->assertSame( array(), $this->check( $field, '2008-11-23' )['errors'], 'exactly 18 today' );
		$this->assertSame( 'too_young', $this->check( $field, '2008-11-24' )['codes']['f_test01'], 'one day short' );
		$this->assertSame( 'You must be at least 18 years old.', $this->check( $field, '2010-01-01' )['errors']['f_test01'] );
		// Born on 29 February: 18 on 28 February of a non-leap year.
		$leap = array(
			'type'       => 'date',
			'validation' => array( 'min_age' => 18 ),
		);
		$this->assertSame( array(), $this->check( $leap, '2008-02-28', '2026-02-28' )['errors'] );
		$this->assertSame( '2008-02-28', SubmissionValidator::birthdate_limit( '2026-02-28', 18 ) );
		$this->assertSame( '2003-02-28', SubmissionValidator::birthdate_limit( '2024-02-29', 21 ) );

		foreach ( array( 0, -1, 121, 'abc', '18.5' ) as $bad ) {
			$res = ( new SchemaValidator() )->validate( array( 'schema_version' => 1, 'fields' => array( array( 'id' => 'f_test01', 'type' => 'date', 'key' => 'd', 'label' => 'D', 'validation' => array( 'min_age' => $bad ) ) ) ) );
			$this->assertSame( 'invalid_min_age', $res['errors']['fields.0.validation.min_age'] ?? null, (string) $bad );
		}
		$res = ( new SchemaValidator() )->validate( array( 'schema_version' => 1, 'fields' => array( array( 'id' => 'f_test01', 'type' => 'date', 'key' => 'd', 'label' => 'D' ) ) ) );
		$this->assertArrayNotHasKey( 'min_age', $res['schema']['fields'][0]['validation'], 'no age check by default' );
	}

	public function test_minimum_age_limits_the_date_picker(): void {
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_bday01', 'type' => 'date', 'key' => 'birthday', 'label' => 'Geburtstag', 'validation' => array( 'min_age' => 16 ) ),
			)
		);
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( 'max="' . SubmissionValidator::birthdate_limit( (string) wp_date( 'Y-m-d' ), 16 ) . '"', $html );
	}

	public function test_autocomplete_purpose_per_field(): void {
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_first1', 'type' => 'text', 'key' => 'first', 'label' => 'Vorname', 'autocomplete' => 'given-name' ),
				array( 'id' => 'f_zip001', 'type' => 'text', 'key' => 'zip', 'label' => 'PLZ', 'autocomplete' => 'postal-code' ),
				array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
				array( 'id' => 'f_work01', 'type' => 'email', 'key' => 'work', 'label' => 'Firmen-E-Mail', 'autocomplete' => 'off' ),
				array( 'id' => 'f_land01', 'type' => 'select', 'key' => 'country', 'label' => 'Land', 'autocomplete' => 'country-name', 'options' => array( array( 'value' => 'de', 'label' => 'Deutschland' ) ) ),
				array( 'id' => 'f_addr01', 'type' => 'textarea', 'key' => 'address', 'label' => 'Adresse', 'autocomplete' => 'street-address' ),
			)
		);
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertMatchesRegularExpression( '/id="fc1-f_first1"[^>]*autocomplete="given-name"/', $html );
		$this->assertMatchesRegularExpression( '/id="fc1-f_zip001"[^>]*autocomplete="postal-code"/', $html );
		$this->assertMatchesRegularExpression( '/id="fc1-f_mail01"[^>]*autocomplete="email"/', $html, 'type default stays' );
		$this->assertMatchesRegularExpression( '/id="fc1-f_work01"[^>]*autocomplete="off"/', $html );
		$this->assertMatchesRegularExpression( '/<select[^>]*autocomplete="country-name"/', $html );
		$this->assertMatchesRegularExpression( '/<textarea[^>]*autocomplete="street-address"/', $html );

		$res = ( new SchemaValidator() )->validate(
			array(
				'schema_version' => 1,
				'fields'         => array(
					array( 'id' => 'f_test01', 'type' => 'text', 'key' => 'a', 'label' => 'A', 'autocomplete' => 'cc-number' ),
					array( 'id' => 'f_test02', 'type' => 'radio', 'key' => 'b', 'label' => 'B', 'autocomplete' => 'name', 'options' => array( array( 'value' => 'x', 'label' => 'X' ) ) ),
				),
			)
		);
		$this->assertSame( 'invalid_autocomplete', $res['errors']['fields.0.autocomplete'], 'only listed purposes' );
		$this->assertArrayNotHasKey( 'autocomplete', $res['schema']['fields'][1], 'not for radio buttons' );
	}
}
