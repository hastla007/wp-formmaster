<?php
/**
 * Schema of every global option (docs/spec/einstellungen.md, P2-17, P2-19, P5-18, P5-19, P5-21).
 *
 * Each option has type, default (from Settings::defaults()), sanitizer/validator, tab and capability.
 * Unknown keys are dropped; invalid values are rejected with a translated error and the stored value
 * stays unchanged.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Install\Capabilities;
use FormCaptain\Support\Clock;

final class SettingsSchema {

	/** Tabs in display order. `access` and `status` are not stored in the settings option. */
	public const TABS = array( 'general', 'appearance', 'email', 'privacy', 'spam', 'access', 'status' );

	/** Option-backed groups. */
	public const GROUPS = array( 'general', 'appearance', 'email', 'privacy', 'spam' );

	/** Accepted confirmation words for "delete data on uninstall" (datenschutz.md 10). */
	public const CONFIRM_WORDS = array( 'DELETE', 'LÖSCHEN', 'LOESCHEN' );

	public static function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/** @return array<string,string> tab => label */
	public static function tab_labels(): array {
		return array(
			'general'    => __( 'General', 'form-captain' ),
			'appearance' => __( 'Appearance', 'form-captain' ),
			'email'      => __( 'Email', 'form-captain' ),
			'privacy'    => __( 'Privacy & storage', 'form-captain' ),
			'spam'       => __( 'Spam protection', 'form-captain' ),
			'access'     => __( 'Access & roles', 'form-captain' ),
			'status'     => __( 'System status', 'form-captain' ),
		);
	}

	/**
	 * Definitions keyed by "group.key".
	 *
	 * Keys: type (bool|int|enum|string|email|hex|text|datetime), default, tab, label, help,
	 * options (enum), min/max (int), max_length (string/text), internal (not user editable).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$d   = Settings::defaults();
		$out = array(
			'general.setup_completed'           => array(
				'type'  => 'bool',
				'label' => __( 'Initial setup completed', 'form-captain' ),
				'help'  => __( 'Hides the setup assistant on the dashboard.', 'form-captain' ),
			),
			'general.show_pro_hints'            => array(
				'type'  => 'bool',
				'label' => __( 'Show occasional hints about Form Captain Pro', 'form-captain' ),
				'help'  => __( 'Hints are rare and can be dismissed. Free features never depend on Pro.', 'form-captain' ),
			),
			'appearance.mode'                   => array(
				'type'    => 'enum',
				'options' => array(
					'inherit' => __( 'Use theme styles (recommended)', 'form-captain' ),
					'base'    => __( 'Form Captain base styles', 'form-captain' ),
				),
				'label'   => __( 'Form styling', 'form-captain' ),
				'help'    => __( 'Theme styling only loads layout and error styles. Base styles add a neutral look without loading external fonts or files. Forms keep their own setting.', 'form-captain' ),
			),
			'appearance.accent_color'           => array(
				'type'  => 'hex',
				'label' => __( 'Accent colour', 'form-captain' ),
				'help'  => __( 'Used for buttons and focus outlines with base styles only.', 'form-captain' ),
			),
			'email.from_name'                   => array(
				'type'       => 'string',
				'max_length' => 190,
				'label'      => __( 'Default sender name', 'form-captain' ),
				'help'       => __( 'Copied into new forms. Existing forms keep their own value.', 'form-captain' ),
			),
			'email.from_address'                => array(
				'type'  => 'email',
				'label' => __( 'Default sender address', 'form-captain' ),
				'help'  => __( 'Use an address of your website domain so that messages are not marked as spam.', 'form-captain' ),
			),
			'email.format'                      => array(
				'type'    => 'enum',
				'options' => array(
					'html' => __( 'HTML', 'form-captain' ),
					'text' => __( 'Plain text', 'form-captain' ),
				),
				'label'   => __( 'Email format', 'form-captain' ),
			),
			'privacy.retention'                 => array(
				'type'    => 'enum',
				'options' => array(
					'90d'    => __( '90 days', 'form-captain' ),
					'12m'    => __( '12 months (recommended)', 'form-captain' ),
					'manual' => __( 'Keep until deleted manually', 'form-captain' ),
				),
				'label'   => __( 'Keep submissions for', 'form-captain' ),
				'help'    => __( 'Submissions are stored only in this WordPress database. Choose how long they are kept. You can change this later.', 'form-captain' ),
			),
			'privacy.trash_days'                => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 90,
				'label' => __( 'Delete trash and spam after (days)', 'form-captain' ),
			),
			'privacy.erasure_mode'              => array(
				'type'    => 'enum',
				'options' => array(
					'delete'    => __( 'Delete matching submissions completely', 'form-captain' ),
					'anonymize' => __( 'Anonymize them (keep choices and consent records, remove personal values)', 'form-captain' ),
				),
				'label'   => __( 'Personal data erasure requests', 'form-captain' ),
				'help'    => __( 'Applies when you process an erasure request under Tools → Erase Personal Data.', 'form-captain' ),
			),
			'privacy.delete_data_on_uninstall'  => array(
				'type'  => 'bool',
				'label' => __( 'Delete all Form Captain data when the plugin is deleted', 'form-captain' ),
				'help'  => __( 'By default, forms and submissions are kept when the plugin is deleted.', 'form-captain' ),
			),
			'privacy.delete_data_confirmed_gmt' => array(
				'type'     => 'datetime',
				'internal' => true,
				'label'    => __( 'Uninstall deletion confirmed', 'form-captain' ),
			),
			'privacy.policy_page_hint'          => array(
				'type'  => 'bool',
				'label' => __( 'Offer a link to the privacy policy page in consent texts', 'form-captain' ),
			),
			'spam.honeypot'                     => array(
				'type'  => 'bool',
				'label' => __( 'Hidden honeypot field', 'form-captain' ),
				'help'  => __( 'Invisible for visitors, catches simple bots. No data leaves your site.', 'form-captain' ),
			),
			'spam.min_seconds'                  => array(
				'type'  => 'int',
				'min'   => 0,
				'max'   => 60,
				'label' => __( 'Minimum time to fill in a form (seconds)', 'form-captain' ),
			),
			'spam.rate_limit'                   => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 100,
				'label' => __( 'Submissions per form and visitor in 10 minutes', 'form-captain' ),
				'help'  => __( 'Visitors are recognised by a daily changing pseudonymous hash, never by the stored IP address.', 'form-captain' ),
			),
			'spam.blocklist'                    => array(
				'type'       => 'text',
				'max_length' => 10000,
				'label'      => __( 'Blocklist', 'form-captain' ),
				'help'       => __( 'One word or e-mail domain per line. Matching submissions are stored as spam.', 'form-captain' ),
			),
			'spam.captcha_provider'             => array(
				'type'    => 'enum',
				'options' => self::captcha_options(),
				'label'   => __( 'External captcha', 'form-captain' ),
				'help'    => __( 'Form Captain does not include an external captcha. Extensions can add one; activation is opt-in and shows which data the provider receives.', 'form-captain' ),
			),
		);
		foreach ( $out as $path => $def ) {
			list( $group, $key )     = explode( '.', $path, 2 );
			$out[ $path ]['default'] = $d[ $group ][ $key ] ?? null;
			$out[ $path ]['tab']     = $group;
			$out[ $path ]['group']   = $group;
			$out[ $path ]['key']     = $key;
			$out[ $path ]['cap']     = self::capability();
		}
		return $out;
	}

	/**
	 * Captcha providers: always "none"; extensions add opt-in providers via form_captain_spam_checks_v1
	 * with `type = captcha`, `label`, `data` (what is sent), `purpose`, `privacy_url`.
	 *
	 * @return array<string,string>
	 */
	public static function captcha_options(): array {
		$opts = array( 'none' => __( 'None (recommended)', 'form-captain' ) );
		foreach ( self::captcha_providers() as $key => $p ) {
			$opts[ $key ] = (string) $p['label'];
		}
		return $opts;
	}

	/** @return array<string,array<string,string>> */
	public static function captcha_providers(): array {
		$out = array();
		foreach ( (array) apply_filters( 'form_captain_spam_checks_v1', array() ) as $key => $def ) {
			if ( is_string( $key ) && preg_match( '/^[a-z0-9_]{2,40}$/', $key ) && 'none' !== $key && is_array( $def ) && 'captcha' === ( $def['type'] ?? '' ) ) {
				$out[ $key ] = array(
					'label'       => (string) ( $def['label'] ?? $key ),
					'data'        => (string) ( $def['data'] ?? '' ),
					'purpose'     => (string) ( $def['purpose'] ?? '' ),
					'privacy_url' => (string) ( $def['privacy_url'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> key => definition for one group */
	public static function group( string $group ): array {
		$out = array();
		foreach ( self::definitions() as $def ) {
			if ( $def['group'] === $group ) {
				$out[ $def['key'] ] = $def;
			}
		}
		return $out;
	}

	/**
	 * Sanitizes and validates the submitted values of one group.
	 *
	 * Special input keys (not stored): `retention_confirm` (required when the retention changes),
	 * `uninstall_confirm_word` (required to enable delete_data_on_uninstall), `captcha_confirm`.
	 *
	 * @param array<string,mixed> $input raw input (e.g. wp_unslash( $_POST['settings'] ))
	 * @return array{0:array<string,mixed>,1:array<string,string>} [values, errors path => message]
	 */
	public static function sanitize_group( string $group, array $input ): array {
		$defs = self::group( $group );
		if ( ! $defs ) {
			return array( array(), array( $group => __( 'Unknown settings section.', 'form-captain' ) ) );
		}
		$current = Settings::all()[ $group ] ?? array();
		$values  = $current;
		$errors  = array();
		foreach ( $defs as $key => $def ) {
			if ( ! empty( $def['internal'] ) ) {
				continue;
			}
			$path = $group . '.' . $key;
			if ( 'bool' === $def['type'] ) {
				$values[ $key ] = ! empty( $input[ $key ] ) && '0' !== $input[ $key ];
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			list( $value, $error ) = self::sanitize_value( $def, $input[ $key ] );
			if ( null !== $error ) {
				$errors[ $path ] = $error;
				continue;
			}
			$values[ $key ] = $value;
		}

		if ( 'privacy' === $group ) {
			if ( ( $values['retention'] ?? '' ) !== ( $current['retention'] ?? '' ) && empty( $input['retention_confirm'] ) ) {
				$errors['privacy.retention'] = __( 'Changing the retention period affects existing submissions. Please confirm the change.', 'form-captain' );
				$values['retention']         = $current['retention'];
			}
			if ( empty( $values['delete_data_on_uninstall'] ) ) {
				$values['delete_data_on_uninstall']  = false;
				$values['delete_data_confirmed_gmt'] = '';
			} elseif ( empty( $current['delete_data_confirmed_gmt'] ) || empty( $current['delete_data_on_uninstall'] ) ) {
				$word = isset( $input['uninstall_confirm_word'] ) && is_scalar( $input['uninstall_confirm_word'] ) ? trim( (string) $input['uninstall_confirm_word'] ) : '';
				if ( in_array( mb_strtoupper( $word ), self::CONFIRM_WORDS, true ) ) {
					$values['delete_data_confirmed_gmt'] = Clock::now();
				} else {
					$errors['privacy.delete_data_on_uninstall'] = __( 'To delete all data on uninstall, type DELETE into the confirmation field.', 'form-captain' );
					$values['delete_data_on_uninstall']         = false;
					$values['delete_data_confirmed_gmt']        = '';
				}
			}
		}

		if ( 'spam' === $group && 'none' !== ( $values['captcha_provider'] ?? 'none' ) && ( $values['captcha_provider'] ?? '' ) !== ( $current['captcha_provider'] ?? '' ) && empty( $input['captcha_confirm'] ) ) {
			$errors['spam.captcha_provider'] = __( 'An external captcha sends visitor data to a third party. Confirm that you have read the data flow and updated your privacy policy.', 'form-captain' );
			$values['captcha_provider']      = $current['captcha_provider'] ?? 'none';
		}

		return array( array_intersect_key( $values, $defs ), $errors );
	}

	/**
	 * @param array<string,mixed> $def
	 * @return array{0:mixed,1:?string} [value, error]
	 */
	public static function sanitize_value( array $def, mixed $raw ): array {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return array( null, __( 'Invalid value.', 'form-captain' ) );
		}
		$raw = (string) $raw;
		switch ( $def['type'] ) {
			case 'bool':
				return array( '' !== $raw && '0' !== $raw, null );
			case 'int':
				$raw = trim( $raw );
				if ( ! preg_match( '/^-?\d+$/', $raw ) ) {
					return array( null, __( 'Please enter a whole number.', 'form-captain' ) );
				}
				$int = (int) $raw;
				if ( $int < (int) $def['min'] || $int > (int) $def['max'] ) {
					/* translators: 1: minimum, 2: maximum */
					return array( null, sprintf( __( 'Please enter a number between %1$d and %2$d.', 'form-captain' ), (int) $def['min'], (int) $def['max'] ) );
				}
				return array( $int, null );
			case 'enum':
				return array_key_exists( $raw, (array) $def['options'] ) ? array( $raw, null ) : array( null, __( 'Please choose one of the offered options.', 'form-captain' ) );
			case 'hex':
				$raw = strtolower( trim( $raw ) );
				return preg_match( '/^#[0-9a-f]{6}$/', $raw ) ? array( $raw, null ) : array( null, __( 'Please enter a colour in the format #RRGGBB.', 'form-captain' ) );
			case 'email':
				$email = sanitize_email( trim( $raw ) );
				return ( '' !== $email && is_email( $email ) ) ? array( strtolower( $email ), null ) : array( null, __( 'Please enter a valid e-mail address.', 'form-captain' ) );
			case 'string':
				$value = sanitize_text_field( $raw );
				if ( mb_strlen( $value ) > (int) ( $def['max_length'] ?? 190 ) ) {
					/* translators: %d: maximum number of characters */
					return array( null, sprintf( __( 'Please use at most %d characters.', 'form-captain' ), (int) ( $def['max_length'] ?? 190 ) ) );
				}
				if ( '' === $value ) {
					return array( null, __( 'This field cannot be empty.', 'form-captain' ) );
				}
				return array( $value, null );
			case 'text':
				$value = sanitize_textarea_field( $raw );
				if ( mb_strlen( $value ) > (int) ( $def['max_length'] ?? 10000 ) ) {
					/* translators: %d: maximum number of characters */
					return array( null, sprintf( __( 'Please use at most %d characters.', 'form-captain' ), (int) ( $def['max_length'] ?? 10000 ) ) );
				}
				$lines = array_filter( array_map( 'trim', preg_split( '/\R/', $value ) ?: array() ), static fn( $l ) => '' !== $l );
				return array( implode( "\n", array_unique( $lines ) ), null );
			default:
				return array( null, __( 'Invalid value.', 'form-captain' ) );
		}
	}

	/** True when the sender domain differs from the site domain (deliverability hint, P2-22). */
	public static function from_domain_mismatch( ?string $address = null ): bool {
		$address = $address ?? (string) Settings::get( 'email.from_address' );
		$domain  = strtolower( (string) substr( strrchr( $address, '@' ) ?: '', 1 ) );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$host    = is_string( $host ) ? strtolower( (string) preg_replace( '/^www\./', '', $host ) ) : '';
		if ( '' === $domain || '' === $host ) {
			return false;
		}
		return $domain !== $host && ! str_ends_with( $host, '.' . $domain ) && ! str_ends_with( $domain, '.' . $host );
	}
}
