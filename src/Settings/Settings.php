<?php
/**
 * Global settings access (docs/spec/einstellungen.md). Schema lives in SettingsSchema.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

final class Settings {

	public const OPTION = 'form_captain_settings';

	/** @return array<string,array<string,mixed>> */
	public static function defaults(): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'example.com';
		return array(
			'general'    => array(
				'setup_completed'           => false,
				'show_pro_hints'            => true,
				'entry_number_format'       => '',
				'entry_number_digits'       => 4,
				'entry_number_yearly_reset' => true,
			),
			'appearance' => array(
				'mode'         => 'inherit',
				'accent_color' => '#2271b1',
			),
			'email'      => array(
				'from_name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'from_address' => 'wordpress@' . $host,
				'format'       => 'html',
			),
			'privacy'    => array(
				'retention'                 => '12m',
				'trash_days'                => 30,
				'erasure_mode'              => 'delete',
				'delete_data_on_uninstall'  => false,
				'delete_data_confirmed_gmt' => '',
				'policy_page_hint'          => true,
			),
			'spam'       => array(
				'honeypot'         => true,
				'min_seconds'      => 3,
				'rate_limit'       => 5,
				'blocklist'        => '',
				'captcha_provider' => 'none',
			),
		);
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( self::defaults() as $group => $values ) {
			$out[ $group ] = array_merge( $values, is_array( $stored[ $group ] ?? null ) ? array_intersect_key( $stored[ $group ], $values ) : array() );
		}
		return $out;
	}

	public static function get( string $path ): mixed {
		list( $group, $key ) = array_pad( explode( '.', $path, 2 ), 2, '' );
		$all                 = self::all();
		return $all[ $group ][ $key ] ?? null;
	}

	/**
	 * Replaces a whole group with already sanitized values (SettingsSchema does sanitizing).
	 *
	 * @param array<string,mixed> $values
	 */
	public static function set_group( string $group, array $values ): void {
		$all = self::all();
		if ( ! isset( $all[ $group ] ) ) {
			return;
		}
		$all[ $group ] = array_merge( $all[ $group ], array_intersect_key( $values, $all[ $group ] ) );
		update_option( self::OPTION, $all, true );
	}

	public static function reset_group( string $group ): void {
		$defaults = self::defaults();
		if ( isset( $defaults[ $group ] ) ) {
			$all           = self::all();
			$all[ $group ] = $defaults[ $group ];
			update_option( self::OPTION, $all, true );
		}
	}

	/** Adds new defaults without overwriting existing values (P5-21). */
	public static function ensure_defaults(): void {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
			return;
		}
		update_option( self::OPTION, self::all(), true );
	}

	/** Retention period in seconds or null for manual deletion. */
	public static function retention_seconds( ?string $mode = null ): ?int {
		$mode = $mode ?? (string) self::get( 'privacy.retention' );
		return match ( $mode ) {
			'90d' => 90 * DAY_IN_SECONDS,
			'12m' => 365 * DAY_IN_SECONDS,
			default => null,
		};
	}
}
