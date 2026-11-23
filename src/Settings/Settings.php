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

	/** Retention default of installations before S9 (23.11.2026); kept on update. */
	public const LEGACY_RETENTION = '12m';

	/** @return array<string,array<string,mixed>> */
	public static function defaults(): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'example.com';
		return array(
			'general'    => array(
				'setup_completed'           => false,
				'show_pro_hints'            => true,
				'log_level'                 => 'info',
				'text_max'                  => 1000,
				'textarea_max'              => 10000,
				'country_profile'           => '',
				'entry_number_format'       => '',
				'entry_number_digits'       => 4,
				'entry_number_yearly_reset' => true,
			),
			'appearance' => array(
				'mode'         => 'inherit',
				'accent_color' => '#2271b1',
				'radius'       => 4,
				'spacing'      => 16,
				'font_size'    => 'theme',
				'custom_css'   => '',
			),
			'email'      => array(
				'from_name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'from_address' => 'wordpress@' . $host,
				'format'       => 'html',
			),
			'privacy'    => array(
				'retention'                 => RetentionPeriod::MANUAL,
				'trash_days'                => 30,
				'erasure_mode'              => 'delete',
				'delete_data_on_uninstall'  => false,
				'delete_data_confirmed_gmt' => '',
				'policy_page_hint'          => true,
				'change_log_months'         => 12,
				'grace_days'                => 14,
				'expiry_notice_days'        => 14,
				'log_days'                  => 30,
			),
			'spam'       => array(
				'honeypot'         => true,
				'min_seconds'      => 3,
				'rate_limit'       => 5,
				'blocklist'        => '',
				'captcha_provider' => 'none',
				'client_ip_source' => 'remote_addr',
				'global_per_ip'    => 20,
				'token_minutes'    => 120,
			),
			'export'     => array(
				'delimiter' => ',',
				'bom'       => true,
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
		self::flush_caches();
	}

	/** Clears caches derived from the settings (limits, log level). */
	public static function flush_caches(): void {
		\FormCaptain\Support\Limits::reset();
		\FormCaptain\Support\Logger::reset_level();
	}

	public static function reset_group( string $group ): void {
		$defaults = self::defaults();
		if ( isset( $defaults[ $group ] ) ) {
			$all           = self::all();
			$all[ $group ] = $defaults[ $group ];
			update_option( self::OPTION, $all, true );
			self::flush_caches();
		}
	}

	/**
	 * Adds new defaults without overwriting existing values (P5-21).
	 *
	 * S9: new installations start without automatic deletion (manual). Existing installations
	 * that never stored a retention value keep the earlier default of 12 months, so an update
	 * never changes behaviour.
	 */
	public static function ensure_defaults(): void {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
			return;
		}
		$all = self::all();
		if ( ! isset( $stored['privacy']['retention'] ) ) {
			$all['privacy']['retention'] = self::LEGACY_RETENTION;
		}
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Retention period in seconds (counted from now, calendar based) or null for manual deletion.
	 * Filter `form_captain_retention_seconds_v1` wins (see RetentionService::expires_for()).
	 */
	public static function retention_seconds( ?string $mode = null ): ?int {
		$mode    = $mode ?? (string) self::get( 'privacy.retention' );
		$now     = \FormCaptain\Support\Clock::now();
		$expires = \FormCaptain\Service\RetentionService::expires_for( $mode, $now );
		return null === $expires ? null : (int) strtotime( $expires . ' UTC' ) - (int) strtotime( $now . ' UTC' );
	}
}
