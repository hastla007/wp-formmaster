<?php
/**
 * Minimum versions of the database server (decision 21, 17.11.2026): MySQL 9.7 LTS and
 * MariaDB 12.3 LTS. An older server is not blocked – forms and submissions keep working – but
 * the system status and Site Health show a warning. PHP and WordPress are checked in
 * form-captain.php before the plugin loads.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

defined( 'ABSPATH' ) || exit;

final class Requirements {

	/** Oldest supported MySQL version (LTS). */
	public const MYSQL = '9.7';

	/** Oldest supported MariaDB version (LTS). */
	public const MARIADB = '12.3';

	/**
	 * Minimum versions; filter `form_captain_minimum_db_versions_v1` lets an operator lower them
	 * for a server they have tested.
	 *
	 * @return array{mysql:string,mariadb:string}
	 */
	public static function minimum_db_versions(): array {
		$defaults = array(
			'mysql'   => self::MYSQL,
			'mariadb' => self::MARIADB,
		);
		$filtered = apply_filters( 'form_captain_minimum_db_versions_v1', $defaults );
		$out      = $defaults;
		foreach ( array_keys( $defaults ) as $key ) {
			if ( is_array( $filtered ) && isset( $filtered[ $key ] ) && is_scalar( $filtered[ $key ] ) && 1 === preg_match( '/^\d+(\.\d+){0,2}$/', (string) $filtered[ $key ] ) ) {
				$out[ $key ] = (string) $filtered[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Server type and version from a version string such as "9.7.4", "12.3.1-MariaDB-ubu2404" or
	 * "5.5.5-10.11.6-MariaDB" (the replication prefix of older MariaDB servers is skipped).
	 *
	 * @param string $info Version string.
	 * @return array{type:string,version:string}|null Null when no version can be read.
	 */
	public static function parse_db_server( string $info ): ?array {
		$info    = trim( $info );
		$mariadb = false !== stripos( $info, 'mariadb' );
		if ( $mariadb ) {
			$info = (string) preg_replace( '/^5\.5\.5-/', '', $info );
		}
		if ( 1 !== preg_match( '/(\d+)\.(\d+)(?:\.(\d+))?/', $info, $m, PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}
		return array(
			'type'    => $mariadb ? 'mariadb' : 'mysql',
			'version' => $m[1] . '.' . $m[2] . '.' . ( $m[3] ?? '0' ),
		);
	}

	/**
	 * Warning text when the server is older than the minimum; null when it is new enough or its
	 * version cannot be read.
	 *
	 * @param string $info Version string of the server.
	 */
	public static function database_warning( string $info ): ?string {
		$server = self::parse_db_server( $info );
		if ( null === $server ) {
			return null;
		}
		$minimum = self::minimum_db_versions();
		if ( ! version_compare( $server['version'], $minimum[ $server['type'] ], '<' ) ) {
			return null;
		}
		return sprintf(
			/* translators: 1: minimum MySQL version, 2: minimum MariaDB version, 3: MySQL or MariaDB, 4: version of this server */
			__( 'Form Captain supports MySQL %1$s or newer and MariaDB %2$s or newer; this site uses %3$s %4$s. Forms keep working and nothing is blocked, but older database servers are no longer tested. Ask your host for an update.', 'form-captain' ),
			$minimum['mysql'],
			$minimum['mariadb'],
			'mariadb' === $server['type'] ? 'MariaDB' : 'MySQL',
			$server['version']
		);
	}
}
