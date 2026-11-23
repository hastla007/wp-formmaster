<?php
/**
 * Change log of the global settings and role permissions (SET-FC-15).
 *
 * Every change of the option `form_captain_settings` is recorded with time, user ID, source
 * (ui, cli, import, system), key and old/new value, whatever path changed it (settings screen,
 * WP-CLI, import, code). Secret values are masked, long values shortened. Stored in its own
 * option (not autoloaded), at most MAX_ENTRIES entries, kept for `privacy.change_log_months`
 * (default 12 months; filter `form_captain_change_log_months_v1` wins) and pruned by the daily
 * cleanup. Unlike the technical log it is not deleted after 30 days.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Support\Clock;

final class ChangeLog {

	public const OPTION = 'form_captain_settings_changelog';

	/** Technical cap of stored entries (oldest are dropped first). */
	public const MAX_ENTRIES = 1000;

	/** Longest stored value representation. */
	private const MAX_VALUE_CHARS = 200;

	public const SOURCES = array( 'ui', 'cli', 'import', 'system' );

	private static ?string $source = null;

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'on_update' ), 10, 2 );
		add_action( 'form_captain_cleanup', array( self::class, 'prune' ) );
	}

	/**
	 * Runs $fn with a fixed source for all changes it makes.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function with_source( string $source, callable $fn ): mixed {
		$previous     = self::$source;
		self::$source = in_array( $source, self::SOURCES, true ) ? $source : 'system';
		try {
			return $fn();
		} finally {
			self::$source = $previous;
		}
	}

	public static function current_source(): string {
		if ( null !== self::$source ) {
			return self::$source;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		return is_admin() ? 'ui' : 'system';
	}

	/**
	 * Hook on update_option_form_captain_settings: records every changed key.
	 */
	public static function on_update( mixed $old, mixed $new ): void {
		$old     = is_array( $old ) ? $old : array();
		$new     = is_array( $new ) ? $new : array();
		$defs    = SettingsSchema::definitions();
		$changes = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $group ) {
			$o = is_array( $old[ $group ] ?? null ) ? $old[ $group ] : array();
			$n = is_array( $new[ $group ] ?? null ) ? $new[ $group ] : array();
			foreach ( array_unique( array_merge( array_keys( $o ), array_keys( $n ) ) ) as $key ) {
				$before = $o[ $key ] ?? null;
				$after  = $n[ $key ] ?? null;
				$path = $group . '.' . $key;
				// Not a change by anyone: a new key first written with its default, or a key that no longer exists.
				$filled  = null === $before && isset( $defs[ $path ] ) && $after === $defs[ $path ]['default'];
				$dropped = null === $after && ! isset( $defs[ $path ] );
				if ( $before !== $after && ! $filled && ! $dropped ) {
					$changes[ $path ] = array( $before, $after );
				}
			}
		}
		self::record( $changes );
	}

	/**
	 * Records role permission changes from AccessControl::apply().
	 *
	 * @param array{granted?:array<int,string>,revoked?:array<int,string>} $result
	 */
	public static function record_roles( array $result ): void {
		$changes = array();
		foreach ( (array) ( $result['granted'] ?? array() ) as $pair ) {
			$changes[ 'access.' . str_replace( ':', '.', (string) $pair ) ] = array( false, true );
		}
		foreach ( (array) ( $result['revoked'] ?? array() ) as $pair ) {
			$changes[ 'access.' . str_replace( ':', '.', (string) $pair ) ] = array( true, false );
		}
		self::record( $changes );
	}

	/**
	 * Records changes of add-on fields (form_captain_settings_fields_v1, SET-FC-P4-03). They live in the
	 * add-on's own option, so the hook on Form Captain's option never sees them. Key = field ID; a field
	 * marked `secret` (or with a secret-looking ID) is masked.
	 *
	 * @param array<string,array{0:mixed,1:mixed}> $changed id => [old, new]
	 * @param array<string,array<string,mixed>>    $fields  id => normalized field
	 */
	public static function record_extension( array $changed, array $fields ): void {
		$secret = array();
		foreach ( array_keys( $changed ) as $id ) {
			if ( ! empty( $fields[ $id ]['secret'] ) ) {
				$secret[] = (string) $id;
			}
		}
		self::record( $changed, $secret );
	}

	/**
	 * @param array<string,array{0:mixed,1:mixed}> $changes path => [old, new]
	 * @param string[]                             $secret  further paths to mask
	 */
	public static function record( array $changes, array $secret = array() ): void {
		if ( ! $changes ) {
			return;
		}
		$entries = self::all();
		$now     = Clock::now();
		$user    = get_current_user_id();
		$source  = self::current_source();
		foreach ( $changes as $path => $pair ) {
			$masked    = in_array( (string) $path, $secret, true ) || SettingsSchema::is_secret( (string) $path );
			$entries[] = array(
				'time'   => $now,
				'user'   => $user,
				'source' => $source,
				'key'    => (string) $path,
				'old'    => $masked ? self::mask( $pair[0] ) : self::shorten( $pair[0] ),
				'new'    => $masked ? self::mask( $pair[1] ) : self::shorten( $pair[1] ),
			);
		}
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $entries, false );
	}

	/** @return array<int,array{time:string,user:int,source:string,key:string,old:string,new:string}> oldest first */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * Newest entries first.
	 *
	 * @return array<int,array{time:string,user:int,source:string,key:string,old:string,new:string}>
	 */
	public static function latest( int $limit = 50 ): array {
		return array_slice( array_reverse( self::all() ), 0, max( 1, $limit ) );
	}

	/** Kept months: setting `privacy.change_log_months`, the filter wins (S2). */
	public static function months(): int {
		$months = (int) Settings::get( 'privacy.change_log_months' );
		$months = (int) apply_filters( 'form_captain_change_log_months_v1', $months > 0 ? $months : 12 );
		return max( 1, min( 120, $months ) );
	}

	/** Removes entries older than the kept period. */
	public static function prune(): void {
		$entries = self::all();
		if ( ! $entries ) {
			return;
		}
		$cutoff = Clock::timestamp() - self::months() * MONTH_IN_SECONDS;
		$kept   = array_values( array_filter( $entries, static fn( array $e ): bool => (int) strtotime( (string) $e['time'] . ' UTC' ) >= $cutoff ) );
		if ( count( $kept ) !== count( $entries ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}

	private static function mask( mixed $value ): string {
		return ( null === $value || '' === $value ) ? '' : '••••••';
	}

	private static function shorten( mixed $value ): string {
		if ( is_bool( $value ) ) {
			$text = $value ? 'true' : 'false';
		} elseif ( null === $value ) {
			$text = '';
		} elseif ( is_scalar( $value ) ) {
			$text = (string) $value;
		} else {
			$text = (string) wp_json_encode( $value );
		}
		$text = str_replace( array( "\r\n", "\n", "\r" ), ' ⏎ ', $text );
		return mb_strlen( $text ) > self::MAX_VALUE_CHARS ? mb_substr( $text, 0, self::MAX_VALUE_CHARS ) . '…' : $text;
	}
}
