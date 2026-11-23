<?php
/**
 * Input patterns for single-line text fields (SET-FC-04): letters, postal codes per country and a
 * checked custom pattern. Extensions add patterns with `form_captain_validation_patterns_v1`.
 *
 * Pure logic apart from translation and the filter – unit-testable.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Domain;

final class ValidationPatterns {

	/** Pattern key for the operator's own regular expression (stored in validation.custom_pattern). */
	public const CUSTOM = 'custom';

	/** Maximum length of a custom pattern. */
	public const CUSTOM_MAX = 200;

	/** Phone format "international with +" (validation.format on phone fields). */
	public const TEL_INTERNATIONAL = 'international';

	/**
	 * Built-in and registered patterns: key => label, regex (complete, with delimiters), message.
	 *
	 * @return array<string,array{label:string,regex:string,message:string}>
	 */
	public static function all(): array {
		$core  = array(
			'alpha'       => array(
				'label'   => __( 'Letters only', 'form-captain' ),
				'regex'   => '/^[\p{L}\s\'\-]+$/u',
				'message' => __( 'Please use letters only.', 'form-captain' ),
			),
			'alnum'       => array(
				'label'   => __( 'Letters and digits', 'form-captain' ),
				'regex'   => '/^[\p{L}\p{N}\s\'\-]+$/u',
				'message' => __( 'Please use letters and digits only.', 'form-captain' ),
			),
			'zip_de'      => array(
				'label'   => __( 'German postal code', 'form-captain' ),
				'regex'   => '/^\d{5}$/',
				'message' => __( 'Please enter a postal code with 5 digits.', 'form-captain' ),
			),
			'zip_at'      => array(
				'label'   => __( 'Austrian postal code', 'form-captain' ),
				'regex'   => '/^\d{4}$/',
				'message' => __( 'Please enter a postal code with 4 digits.', 'form-captain' ),
			),
			'zip_ch'      => array(
				'label'   => __( 'Swiss postal code', 'form-captain' ),
				'regex'   => '/^\d{4}$/',
				'message' => __( 'Please enter a postal code with 4 digits.', 'form-captain' ),
			),
			'zip_generic' => array(
				'label'   => __( 'Postal code (any country)', 'form-captain' ),
				'regex'   => '/^(?=.{2,10}$)[A-Za-z0-9]+(?:[ \-][A-Za-z0-9]+)*$/',
				'message' => __( 'Please enter a postal code (2 to 10 letters, digits, spaces or hyphens).', 'form-captain' ),
			),
		);
		$extra = apply_filters( 'form_captain_validation_patterns_v1', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $key => $def ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{1,30}$/', $key ) || isset( $core[ $key ] ) || self::CUSTOM === $key || ! is_array( $def ) ) {
				continue;
			}
			$regex = (string) ( $def['regex'] ?? '' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid regex of an extension is skipped, not fatal.
			if ( '' === $regex || false === @preg_match( $regex, '' ) ) {
				continue;
			}
			$core[ $key ] = array(
				'label'   => wp_strip_all_tags( (string) ( $def['label'] ?? $key ) ),
				'regex'   => $regex,
				'message' => wp_strip_all_tags( (string) ( $def['message'] ?? '' ) ),
			);
		}
		return $core;
	}

	/** @return array<int,array{value:string,label:string}> options for the editor (custom last) */
	public static function options(): array {
		$out = array();
		foreach ( self::all() as $key => $def ) {
			$out[] = array(
				'value' => $key,
				'label' => $def['label'],
			);
		}
		$out[] = array(
			'value' => self::CUSTOM,
			'label' => __( 'Own pattern (regular expression)', 'form-captain' ),
		);
		return $out;
	}

	/**
	 * Checks an operator-supplied pattern (body without delimiters). Returns null when it is safe,
	 * otherwise an error code. Rejected: constructs that can make matching extremely slow
	 * (nested or alternated repetition, back-references, look-arounds, recursion, inline flags),
	 * patterns that do not compile and patterns longer than CUSTOM_MAX characters.
	 */
	public static function check_custom( string $pattern ): ?string {
		if ( '' === $pattern ) {
			return 'pattern_required';
		}
		if ( mb_strlen( $pattern ) > self::CUSTOM_MAX || preg_match( '/[\x00-\x1F\x7F]/', $pattern ) ) {
			return 'pattern_invalid';
		}
		// Back-references (\1, \g, \k), recursion and \R-like escapes with unbounded effect.
		if ( preg_match( '/\\\\(?:[1-9]|g|k|R)/', $pattern ) ) {
			return 'pattern_unsafe';
		}
		// Every "(?" construct except a plain non-capturing group "(?:" (look-arounds, recursion, flags, conditionals).
		if ( preg_match( '/\(\?(?!:)/', $pattern ) ) {
			return 'pattern_unsafe';
		}
		if ( self::has_nested_repetition( $pattern ) ) {
			return 'pattern_unsafe';
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- compile check of user input.
		if ( false === @preg_match( self::custom_regex( $pattern ), '' ) ) {
			return 'pattern_invalid';
		}
		return null;
	}

	/** Complete, anchored regex for a checked custom pattern. */
	public static function custom_regex( string $pattern ): string {
		return '~^(?:' . str_replace( '~', '\~', $pattern ) . ')$~u';
	}

	/**
	 * True when a group that contains a repetition or an alternation is itself repeated, e.g.
	 * "(a+)+", "(\d*)*", "(a|aa)+" or "(x{1,})*" – the classic causes of catastrophic backtracking.
	 */
	private static function has_nested_repetition( string $pattern ): bool {
		$len   = strlen( $pattern );
		$stack = array();
		$risky = false; // Current group content repeats or alternates.
		$class = false;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '\\' === $c ) {
				++$i; // Skip escaped character.
				continue;
			}
			if ( $class ) {
				$class = ']' !== $c;
				continue;
			}
			if ( '[' === $c ) {
				$class = true;
				if ( isset( $pattern[ $i + 1 ] ) && ']' === $pattern[ $i + 1 ] ) {
					++$i; // A leading "]" is a literal.
				}
				continue;
			}
			if ( '(' === $c ) {
				$stack[] = $risky;
				$risky   = false;
				continue;
			}
			if ( ')' === $c ) {
				$inner = $risky;
				$risky = (bool) array_pop( $stack );
				if ( self::is_repeat( $pattern, $i + 1 ) ) {
					if ( $inner ) {
						return true;
					}
					$risky = true;
				} elseif ( $inner ) {
					$risky = true; // A bounded group with inner repetition still counts for outer groups.
				}
				continue;
			}
			if ( '|' === $c || self::is_repeat( $pattern, $i ) ) {
				$risky = true;
			}
		}
		return false;
	}

	/** True when position $i starts an unbounded or large repetition (*, +, {n,} or {n,m} with m > 1). */
	private static function is_repeat( string $pattern, int $i ): bool {
		$c = $pattern[ $i ] ?? '';
		if ( '*' === $c || '+' === $c ) {
			return true;
		}
		if ( '{' === $c && preg_match( '/^\{(\d*)(,(\d*))?\}/', substr( $pattern, $i ), $m ) ) {
			if ( ! isset( $m[2] ) ) {
				return (int) $m[1] > 1;
			}
			return '' === ( $m[3] ?? '' ) || (int) $m[3] > 1;
		}
		return false;
	}
}
