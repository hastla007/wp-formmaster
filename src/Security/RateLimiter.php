<?php
/**
 * Fixed-window rate limiting in fc_rate_limits (P3-09, RL-01…RL-04, RL-09; datenschutz.md 9).
 *
 * Buckets never contain raw IP addresses: bucket = HMAC( scope | form | ip | day salt ) via Support\Secret.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Security;

use FormCaptain\Database\Tables;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Support\Secret;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

final class RateLimiter {

	public const SALT_OPTION = 'form_captain_rl_salt';

	/** Token route limit per IP hash (RL-01). */
	public const TOKEN_LIMIT = 30;

	/** Flood brake per form (RL-04). */
	public const FORM_TOTAL_LIMIT = 300;

	/** form_captain_submit_v1 per form + source (RL-09). */
	public const API_LIMIT = 120;

	/** Client IP from REMOTE_ADDR only; proxies via filter `form_captain_client_ip`. */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'form_captain_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/** IPv6 shortened to /64, IPv4 kept. */
	public static function normalize_ip( string $ip ): string {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			if ( false !== $bin ) {
				return (string) inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
			}
		}
		return $ip;
	}

	/** Daily rotating salt (00:00 UTC). Only current and previous are kept. */
	public static function day_salt(): string {
		$today = gmdate( 'Y-m-d', Clock::timestamp() );
		$state = get_option( self::SALT_OPTION );
		if ( ! is_array( $state ) || ( $state['date'] ?? '' ) !== $today || empty( $state['current'] ) ) {
			$state = array(
				'date'     => $today,
				'current'  => bin2hex( random_bytes( 32 ) ),
				'previous' => is_array( $state ) ? (string) ( $state['current'] ?? '' ) : '',
			);
			update_option( self::SALT_OPTION, $state, false );
		}
		return (string) $state['current'];
	}

	public static function bucket( string $scope, int $form_id, string $subject ): string {
		return Secret::sign( 'form-captain/rate-limit/v1', $scope . '|' . $form_id . '|' . $subject . '|' . self::day_salt() );
	}

	/** Bucket key for an IP-based scope. */
	public static function ip_bucket( string $scope, int $form_id, string $ip ): string {
		return self::bucket( $scope, $form_id, self::normalize_ip( $ip ) );
	}

	/**
	 * Registers one hit and returns whether it is within the limit.
	 *
	 * @return array{allowed:bool,hits:int,retry_after:int}
	 */
	public function hit( string $bucket, int $form_id, int $limit, ?int $window = null ): array {
		global $wpdb;
		$window = $window ?? Limits::get( 'rate_window_seconds' );
		$table  = Tables::name( Tables::RATE_LIMITS );
		$now    = Clock::now();
		$cutoff = Clock::plus( -$window );
		// Column order matters: hits is evaluated against the old window_start_gmt.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket, form_id, hits, window_start_gmt) VALUES (%s, %d, 1, %s)
				ON DUPLICATE KEY UPDATE hits = IF(window_start_gmt < %s, 1, hits + 1), window_start_gmt = IF(window_start_gmt < %s, %s, window_start_gmt)",
				$bucket,
				$form_id,
				$now,
				$cutoff,
				$cutoff,
				$now
			)
		);
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT hits, window_start_gmt FROM {$table} WHERE bucket = %s", $bucket ), ARRAY_A );
		$hits  = $row ? (int) $row['hits'] : 1;
		$start = $row ? (int) strtotime( $row['window_start_gmt'] . ' UTC' ) : Clock::timestamp();
		if ( 0 === wp_rand( 0, 99 ) ) {
			$this->purge_old();
		}
		return array(
			'allowed'     => $hits <= $limit,
			'hits'        => $hits,
			'retry_after' => max( 1, $start + $window - Clock::timestamp() ),
		);
	}

	/**
	 * Applies RL-02, RL-03 and RL-04 for a public submission.
	 *
	 * @return array{allowed:bool,retry_after:int}
	 */
	public function check_submission( int $form_id, string $ip ): array {
		$per_form = max( 1, min( 100, (int) Settings::get( 'spam.rate_limit' ) ) );
		$checks   = array(
			array( self::ip_bucket( 'submit', $form_id, $ip ), $per_form ),
			array( self::ip_bucket( 'submit_global', 0, $ip ), Limits::get( 'rate_global_per_ip' ) ),
			array( self::bucket( 'submit_form_total', $form_id, '' ), self::FORM_TOTAL_LIMIT ),
		);
		foreach ( $checks as $check ) {
			$r = $this->hit( $check[0], $form_id, $check[1] );
			if ( ! $r['allowed'] ) {
				return array( 'allowed' => false, 'retry_after' => $r['retry_after'] );
			}
		}
		return array( 'allowed' => true, 'retry_after' => 0 );
	}

	/** @return array{allowed:bool,retry_after:int} RL-01 */
	public function check_token( string $ip ): array {
		$r = $this->hit( self::ip_bucket( 'token', 0, $ip ), 0, self::TOKEN_LIMIT );
		return array( 'allowed' => $r['allowed'], 'retry_after' => $r['retry_after'] );
	}

	/** @return array{allowed:bool,retry_after:int} RL-09 */
	public function check_api( int $form_id, string $source ): array {
		$r = $this->hit( self::bucket( 'api', $form_id, $source ), $form_id, self::API_LIMIT );
		return array( 'allowed' => $r['allowed'], 'retry_after' => $r['retry_after'] );
	}

	/**
	 * Deletes expired windows. Context-nonce rows (window_start in the future = exp + 60 s)
	 * are therefore only removed after the context has expired.
	 */
	public function purge_old(): int {
		global $wpdb;
		$table = Tables::name( Tables::RATE_LIMITS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE window_start_gmt < %s LIMIT 5000", Clock::plus( -Limits::get( 'rate_window_seconds' ) ) ) );
	}
}
