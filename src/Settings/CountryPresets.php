<?php
/**
 * Country profiles (SET-FC-12): suggested settings per country with the suite-wide keys
 * DE, AT, CH, EU (general) and OTHER (international).
 *
 * A profile is only a suggestion. The settings screen shows every value before and after and
 * changes nothing until the operator selects the rows and confirms. Values with legal weight
 * (retention, deletion periods) carry a review date; without one they are shown as
 * "not legally reviewed". Every profile suggests 12 months retention for contact enquiries
 * (owner decision E6); new installations start with manual deletion (S9).
 * Review status per profile (S8): DE, AT and CH are reviewed legally before the sales start
 * (`pending` until the owner sets the dates), EU and OTHER carry neutral values and stay
 * `unreviewed`. Filter `form_captain_country_presets_v1` can change or add profiles.
 * Each profile also suggests input patterns for NEW form fields (`fields`: postal code pattern of
 * ValidationPatterns and phone format); the form editor offers them for the last applied profile.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Domain\ValidationPatterns;

final class CountryPresets {

	/** Suite-wide profile keys. */
	public const KEYS = array( 'DE', 'AT', 'CH', 'EU', 'OTHER' );

	/** Settings with legal weight: shown as "not legally reviewed" unless the profile has a review date. */
	public const LEGAL_PATHS = array( 'privacy.retention', 'privacy.trash_days', 'privacy.grace_days', 'privacy.log_days' );

	/**
	 * Profiles: key => label, values (settings path => value), reviewed (path => Y-m-d), note,
	 * review (`pending` = legal review planned, `unreviewed` = neutral values, `reviewed`),
	 * fields (suggestions for new form fields: zip_pattern = key of ValidationPatterns or '',
	 * tel_format = '' for any phone number or `international`).
	 *
	 * @return array<string,array{label:string,values:array<string,mixed>,reviewed:array<string,string>,note:string,review:string,fields:array{zip_pattern:string,tel_format:string}}>
	 */
	public static function all(): array {
		$common   = array(
			'privacy.retention'  => '12m',
			'privacy.trash_days' => 30,
			'export.bom'         => true,
		);
		$german   = __( 'German: the WordPress language "Deutsch (Sie)" addresses visitors formally, "Deutsch" informally. Choose it under Settings → General.', 'form-captain' );
		$out      = array(
			'DE'    => array(
				'fields'   => array(
					'zip_pattern' => 'zip_de',
					'tel_format'  => '',
				),
				'review'   => 'pending',
				'label'    => __( 'Germany', 'form-captain' ),
				'values'   => $common + array( 'export.delimiter' => ';' ),
				'reviewed' => array(),
				'note'     => $german,
			),
			'AT'    => array(
				'fields'   => array(
					'zip_pattern' => 'zip_at',
					'tel_format'  => '',
				),
				'review'   => 'pending',
				'label'    => __( 'Austria', 'form-captain' ),
				'values'   => $common + array( 'export.delimiter' => ';' ),
				'reviewed' => array(),
				'note'     => $german,
			),
			'CH'    => array(
				'fields'   => array(
					'zip_pattern' => 'zip_ch',
					'tel_format'  => '',
				),
				'review'   => 'pending',
				'label'    => __( 'Switzerland', 'form-captain' ),
				'values'   => $common + array( 'export.delimiter' => ';' ),
				'reviewed' => array(),
				'note'     => __( 'Switzerland: the Federal Act on Data Protection (FADP) applies in addition to or instead of the GDPR. Swiss German texts usually use "ss" instead of "ß".', 'form-captain' ),
			),
			'EU'    => array(
				'fields'   => array(
					'zip_pattern' => 'zip_generic',
					'tel_format'  => ValidationPatterns::TEL_INTERNATIONAL,
				),
				'review'   => 'unreviewed',
				'label'    => __( 'European Union (general)', 'form-captain' ),
				'values'   => $common + array( 'export.delimiter' => ',' ),
				'reviewed' => array(),
				'note'     => __( 'Many spreadsheet programs in EU countries with a decimal comma expect a semicolon as CSV separator; you can change it after applying the profile.', 'form-captain' ),
			),
			'OTHER' => array(
				'fields'   => array(
					'zip_pattern' => 'zip_generic',
					'tel_format'  => ValidationPatterns::TEL_INTERNATIONAL,
				),
				'review'   => 'unreviewed',
				'label'    => __( 'International (other countries)', 'form-captain' ),
				'values'   => $common + array( 'export.delimiter' => ',' ),
				'reviewed' => array(),
				'note'     => __( 'Neutral defaults. Check which retention periods and privacy rules apply in your country.', 'form-captain' ),
			),
		);
		$filtered = apply_filters( 'form_captain_country_presets_v1', $out );
		return is_array( $filtered ) ? self::clean( $filtered ) : $out;
	}

	/**
	 * Suggestions for new form fields from the last applied profile (general.country_profile),
	 * or an empty array when no profile was applied. Used by the form editor (SET-FC-12/SET-FC-04).
	 *
	 * @return array{profile:string,label:string,zip_pattern:string,zip_label:string,tel_format:string}|array{}
	 */
	public static function field_suggestions(): array {
		$key     = (string) Settings::get( 'general.country_profile' );
		$profile = '' !== $key ? self::get( $key ) : null;
		if ( null === $profile || ( '' === $profile['fields']['zip_pattern'] && '' === $profile['fields']['tel_format'] ) ) {
			return array();
		}
		$patterns = ValidationPatterns::all();
		$zip      = $profile['fields']['zip_pattern'];
		return array(
			'profile'     => $key,
			'label'       => $profile['label'],
			'zip_pattern' => $zip,
			'zip_label'   => '' !== $zip ? $patterns[ $zip ]['label'] : '',
			'tel_format'  => $profile['fields']['tel_format'],
		);
	}

	/**
	 * Field suggestions of a (filtered) profile; unknown patterns are dropped.
	 *
	 * @return array{zip_pattern:string,tel_format:string}
	 */
	private static function clean_fields( mixed $fields ): array {
		$fields = is_array( $fields ) ? $fields : array();
		$zip    = is_string( $fields['zip_pattern'] ?? null ) ? $fields['zip_pattern'] : '';
		$tel    = $fields['tel_format'] ?? '';
		return array(
			'zip_pattern' => ValidationPatterns::CUSTOM !== $zip && isset( ValidationPatterns::all()[ $zip ] ) ? $zip : '',
			'tel_format'  => ValidationPatterns::TEL_INTERNATIONAL === $tel ? $tel : '',
		);
	}

	/** Readable review status of a profile (S8). */
	public static function review_label( string $review ): string {
		return match ( $review ) {
			'reviewed' => __( 'Legally reviewed', 'form-captain' ),
			'pending' => __( 'Not legally reviewed yet – review before the sales start is planned', 'form-captain' ),
			default => __( 'Not legally reviewed – neutral values', 'form-captain' ),
		};
	}

	/** @return array{label:string,values:array<string,mixed>,reviewed:array<string,string>,note:string,review:string,fields:array{zip_pattern:string,tel_format:string}}|null */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Rows for the before/after view. Invalid values of a filtered profile are reported, never applied.
	 *
	 * @return array<int,array{path:string,label:string,before:mixed,after:mixed,changed:bool,legal:bool,reviewed:string,error:string}>
	 */
	public static function diff( string $key ): array {
		$profile = self::get( $key );
		if ( null === $profile ) {
			return array();
		}
		$defs = SettingsSchema::definitions();
		$rows = array();
		foreach ( $profile['values'] as $path => $value ) {
			$def = $defs[ $path ] ?? null;
			if ( null === $def || ! empty( $def['internal'] ) ) {
				continue;
			}
			list( $after, $error ) = SettingsSchema::sanitize_value( $def, is_bool( $value ) ? ( $value ? '1' : '0' ) : $value );
			$before                = Settings::get( $path );
			$rows[]                = array(
				'path'     => $path,
				'label'    => (string) $def['label'],
				'before'   => $before,
				'after'    => null === $error ? $after : $value,
				'changed'  => null === $error && $after !== $before,
				'legal'    => in_array( $path, self::LEGAL_PATHS, true ),
				'reviewed' => (string) ( $profile['reviewed'][ $path ] ?? '' ),
				'error'    => (string) $error,
			);
		}
		return $rows;
	}

	/**
	 * Applies the selected rows of a profile. Only rows that are valid and differ are written.
	 *
	 * @param string[] $paths selected settings paths
	 * @return array{applied:string[],errors:array<string,string>}
	 */
	public static function apply( string $key, array $paths ): array {
		$applied = array();
		$errors  = array();
		$groups  = array();
		foreach ( self::diff( $key ) as $row ) {
			if ( ! in_array( $row['path'], $paths, true ) ) {
				continue;
			}
			if ( '' !== $row['error'] ) {
				$errors[ $row['path'] ] = $row['error'];
				continue;
			}
			if ( $row['changed'] ) {
				list( $group, $k )      = explode( '.', $row['path'], 2 );
				$groups[ $group ][ $k ] = $row['after'];
				$applied[]              = $row['path'];
			}
		}
		foreach ( $groups as $group => $values ) {
			Settings::set_group( $group, $values );
		}
		if ( null !== self::get( $key ) ) {
			Settings::set_group( 'general', array( 'country_profile' => $key ) );
		}
		return array(
			'applied' => $applied,
			'errors'  => $errors,
		);
	}

	/**
	 * Keeps well-formed profiles of the filter result.
	 *
	 * @param array<mixed> $profiles
	 * @return array<string,array{label:string,values:array<string,mixed>,reviewed:array<string,string>,note:string,review:string,fields:array{zip_pattern:string,tel_format:string}}>
	 */
	private static function clean( array $profiles ): array {
		$out = array();
		foreach ( $profiles as $key => $p ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Z]{2,5}$/', $key ) || ! is_array( $p ) || ! is_array( $p['values'] ?? null ) ) {
				continue;
			}
			$reviewed = array();
			foreach ( (array) ( $p['reviewed'] ?? array() ) as $path => $date ) {
				if ( is_string( $path ) && is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$reviewed[ $path ] = $date;
				}
			}
			$out[ $key ] = array(
				'label'    => is_string( $p['label'] ?? null ) ? $p['label'] : $key,
				'values'   => array_filter( $p['values'], static fn( $v, $path ): bool => is_string( $path ) && ( is_scalar( $v ) ), ARRAY_FILTER_USE_BOTH ),
				'reviewed' => $reviewed,
				'note'     => is_string( $p['note'] ?? null ) ? $p['note'] : '',
				'review'   => in_array( $p['review'] ?? '', array( 'pending', 'reviewed' ), true ) ? (string) $p['review'] : 'unreviewed',
				'fields'   => self::clean_fields( $p['fields'] ?? array() ),
			);
		}
		return $out;
	}
}
