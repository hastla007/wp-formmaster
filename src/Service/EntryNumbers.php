<?php
/**
 * Reference numbers for submissions (SET-FC-29), e.g. "REQ-2026-0042".
 *
 * Format from the global setting general.entry_number_format with the tokens {year} and {number}
 * (required); {number} is padded to general.entry_number_digits. The counter restarts every year
 * (site time zone) when general.entry_number_yearly_reset is on. An empty format switches the
 * numbers off; mails and messages then show the entry ID for {entry_number}.
 *
 * The counter is a database row that is incremented atomically inside the entry transaction, so
 * parallel submissions never get the same number and a rolled back submission gives its number back.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;

final class EntryNumbers {

	/** Option name prefix of the counters: one per year ("…_2026") or one without restart ("…_all"). */
	public const COUNTER_OPTION = 'form_captain_entry_seq_';

	/** Maximum length of a generated number (column fc_entries.entry_number). */
	public const MAX_LENGTH = 40;

	/** Maximum length of the format. */
	public const FORMAT_MAX = 30;

	/**
	 * Checks a format; returns null when valid, otherwise a translated error message.
	 * Allowed: letters, digits, "-", "_", "/", ".", "#" and the tokens {year} and {number}.
	 */
	public static function validate_format( string $format ): ?string {
		if ( '' === $format ) {
			return null;
		}
		if ( mb_strlen( $format ) > self::FORMAT_MAX ) {
			/* translators: %d: maximum number of characters */
			return sprintf( __( 'Please use at most %d characters.', 'form-captain' ), self::FORMAT_MAX );
		}
		if ( 1 !== substr_count( $format, '{number}' ) ) {
			return __( 'The format must contain {number} exactly once.', 'form-captain' );
		}
		$rest = str_replace( array( '{number}', '{year}' ), '', $format );
		if ( ! preg_match( '/^[A-Za-z0-9\-_\/.#]*$/', $rest ) ) {
			return __( 'Use only letters, digits, - _ / . # and the placeholders {year} and {number}.', 'form-captain' );
		}
		return null;
	}

	/**
	 * Draws the next number for a new entry, or null when numbering is off.
	 * Filter `form_captain_entry_number_v1` ( string $number, array $form, int $sequence ) may change it.
	 *
	 * @param array<string,mixed> $form
	 */
	public static function next( array $form, ?int $timestamp = null ): ?string {
		$format = (string) Settings::get( 'general.entry_number_format' );
		if ( '' === $format || null !== self::validate_format( $format ) ) {
			return null;
		}
		$year = (string) wp_date( 'Y', $timestamp ?? Clock::timestamp() );
		// Without {year} in the format a restart on 1 January would repeat last year's numbers;
		// the counter then keeps running (SET-FC-P2-4).
		$reset  = (bool) Settings::get( 'general.entry_number_yearly_reset' );
		$yearly = $reset && str_contains( $format, '{year}' );
		$option = self::COUNTER_OPTION . ( $yearly ? $year : 'all' );
		if ( $reset && ! $yearly ) {
			self::seed_running_counter( $option );
		}
		$seq = self::increment( $option );
		if ( $seq <= 0 ) {
			return null;
		}
		$number   = self::format( $format, $year, $seq, (int) Settings::get( 'general.entry_number_digits' ) );
		$filtered = apply_filters( 'form_captain_entry_number_v1', $number, $form, $seq );
		$number   = is_string( $filtered ) ? trim( wp_strip_all_tags( $filtered ) ) : $number;
		return '' !== $number ? mb_substr( $number, 0, self::MAX_LENGTH ) : null;
	}

	/** Builds a number from format, year, sequence and padding (1–10 digits). */
	public static function format( string $format, string $year, int $seq, int $digits ): string {
		$digits = max( 1, min( 10, $digits ) );
		return str_replace( array( '{year}', '{number}' ), array( $year, str_pad( (string) $seq, $digits, '0', STR_PAD_LEFT ) ), $format );
	}

	/**
	 * Text for the placeholder {entry_number}: the stored number, otherwise the entry ID.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function display( array $entry ): string {
		$number = (string) ( $entry['entry_number'] ?? '' );
		return '' !== $number ? $number : (string) (int) ( $entry['id'] ?? 0 );
	}

	/**
	 * Creates the counter without restart once, after the highest yearly counter, so numbers
	 * already given by the yearly counters with a format without {year} are not given again.
	 */
	private static function seed_running_counter( string $option ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $option ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$highest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(CAST(option_value AS UNSIGNED)) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::COUNTER_OPTION ) . '%' ) );
		add_option( $option, (string) $highest, '', false );
	}

	/** Atomic +1 on a counter row; returns the new value or 0 on failure. */
	private static function increment( string $option ): int {
		global $wpdb;
		add_option( $option, '0', '', false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s", $option ) );
		wp_cache_delete( $option, 'options' );
		if ( 1 !== (int) $updated ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
