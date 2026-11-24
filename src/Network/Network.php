<?php
/**
 * Multisite support (SET-FC-44, docs/planung/multisite.md).
 *
 * Data stays strictly per site: tables use the site prefix (`$wpdb->prefix`), options are site
 * options, every entry and form ID is resolved in the current site only. This module adds the
 * network parts: lazy installation for new sites, clean removal of deleted sites, the super admin
 * rule for entries (decision 23) and a helper that walks all sites for network tools, where
 * `switch_to_blog()` is always paired with `restore_current_blog()` in `finally`.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Network;

use FormCaptain\Database\Tables;
use FormCaptain\Install\Activator;
use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Settings\Settings;

final class Network {

	/** Single cron event on a new site that completes the installation (if no request did it before). */
	public const HOOK_INSTALL = 'form_captain_install_site';

	/** Site option set while the installation of a new site is pending. */
	public const OPTION_PENDING = 'form_captain_install_pending';

	/** Entry capabilities a super admin without a role on the site does not get by default. */
	public const ENTRY_CAPS = array(
		Capabilities::READ_ENTRIES,
		Capabilities::EDIT_ENTRIES,
		Capabilities::EXPORT_ENTRIES,
		Capabilities::DELETE_ENTRIES,
	);

	/** Sites per batch when network tools walk all sites. */
	public const BATCH = 100;

	public function register( Plugin $plugin ): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_filter( 'map_meta_cap', array( self::class, 'filter_entry_caps' ), 10, 3 );
		add_action( 'wp_initialize_site', array( self::class, 'on_initialize_site' ), 100 );
		add_action( 'wp_uninitialize_site', array( self::class, 'on_uninitialize_site' ), 5 );
		add_filter( 'wpmu_drop_tables', array( self::class, 'drop_tables' ), 10, 2 );
		add_action( 'switch_blog', array( self::class, 'on_switch_blog' ), 10, 2 );
		add_action( self::HOOK_INSTALL, array( Activator::class, 'maybe_upgrade' ) );
	}

	/** True when Form Captain is activated for the whole network. */
	public static function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( 'network' === NetworkSettings::get( 'activation' ) ) {
			return true;
		}
		$plugins = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $plugins ) && isset( $plugins[ plugin_basename( FORM_CAPTAIN_FILE ) ] );
	}

	/**
	 * Decision 23: super admins who have no role of their own on a site get no access to its
	 * entries unless the network allows it (`form_captain_network.super_admin_entries`).
	 * `do_not_allow` is the only way to deny a super admin a capability in WordPress.
	 *
	 * @param string[] $caps
	 * @return string[]
	 */
	public static function filter_entry_caps( array $caps, string $cap, int $user_id = 0 ): array {
		if ( ! in_array( $cap, self::ENTRY_CAPS, true ) || $user_id <= 0 || ! is_multisite() || ! is_super_admin( $user_id ) ) {
			return $caps;
		}
		if ( NetworkSettings::super_admin_entries() || self::has_own_role( $user_id ) ) {
			return $caps;
		}
		$caps[] = 'do_not_allow';
		return $caps;
	}

	/** True when the user holds at least one role on the current site. */
	public static function has_own_role( int $user_id, ?int $site_id = null ): bool {
		global $wpdb;
		$roles = get_user_meta( $user_id, $wpdb->get_blog_prefix( $site_id ?? get_current_blog_id() ) . 'capabilities', true );
		return is_array( $roles ) && array() !== array_filter( $roles );
	}

	/**
	 * A new site joins a network where Form Captain is network-active: plan its installation.
	 * Tables are created by the first request of the site (lazy, `Activator::maybe_upgrade()`)
	 * or by the single cron event planned here, whichever comes first.
	 */
	public static function on_initialize_site( \WP_Site $site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			update_option( self::OPTION_PENDING, gmdate( 'Y-m-d H:i:s' ), false );
			if ( ! wp_next_scheduled( self::HOOK_INSTALL ) ) {
				wp_schedule_single_event( time(), self::HOOK_INSTALL );
			}
		} finally {
			restore_current_blog();
		}
	}

	/** A site is deleted: remove its private uploads (tables follow via `wpmu_drop_tables`). */
	public static function on_uninitialize_site( \WP_Site $site ): void {
		switch_to_blog( (int) $site->blog_id );
		try {
			$uploads = wp_upload_dir( null, false );
			if ( ! empty( $uploads['basedir'] ) ) {
				self::delete_dir( trailingslashit( (string) $uploads['basedir'] ) . 'form-captain-private' );
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Adds the Form Captain tables of a deleted site to the tables WordPress drops.
	 *
	 * @param string[] $tables
	 * @return string[]
	 */
	public static function drop_tables( array $tables, int $site_id = 0 ): array {
		global $wpdb;
		if ( $site_id <= 0 ) {
			return $tables;
		}
		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( Tables::ALL as $table ) {
			$tables[ $table ] = $prefix . $table;
		}
		return $tables;
	}

	/** Settings-derived caches (limits, log level) belong to one site. */
	public static function on_switch_blog( int $new_blog_id = 0, int $prev_blog_id = 0 ): void {
		if ( $new_blog_id !== $prev_blog_id ) {
			Settings::flush_caches();
		}
	}

	/**
	 * Runs $callback in the context of each site of the current network, in batches. An error on
	 * one site is recorded and never stops the others.
	 *
	 * @param callable(int):void $callback receives the site ID
	 * @param int[]|null         $site_ids only these sites (null: all)
	 * @return array<int,string> site ID => error code for sites that failed
	 */
	public static function each_site( callable $callback, ?array $site_ids = null ): array {
		$errors = array();
		$ids    = null === $site_ids ? null : array_values( array_map( 'intval', $site_ids ) );
		$offset = 0;
		do {
			$batch   = null === $ids ? self::site_ids( self::BATCH, $offset ) : array_slice( $ids, $offset, self::BATCH );
			$size    = count( $batch );
			$offset += self::BATCH;
			foreach ( $batch as $site_id ) {
				$error = self::in_site( $site_id, $callback );
				if ( null !== $error ) {
					$errors[ $site_id ] = $error;
				}
			}
		} while ( self::BATCH === $size );
		return $errors;
	}

	/**
	 * Runs $callback for one site and returns null or the error code.
	 *
	 * @param callable(int):void $callback
	 */
	public static function in_site( int $site_id, callable $callback ): ?string {
		$failure = null;
		switch_to_blog( $site_id );
		try {
			$callback( $site_id );
		} catch ( \Throwable $e ) {
			$failure = $e;
		} finally {
			restore_current_blog();
		}
		if ( null === $failure ) {
			return null;
		}
		// Logged in the calling site: the failing site may lack its tables.
		\FormCaptain\Support\Logger::log( 'error', 'network_site_failed', 'site ' . $site_id . ': ' . get_class( $failure ) );
		return $failure instanceof NetworkSiteException ? $failure->getMessage() : 'site_failed';
	}

	/**
	 * Site IDs of the current network in ascending order.
	 *
	 * @return int[]
	 */
	public static function site_ids( int $number = 100, int $offset = 0, int $after_id = 0 ): array {
		$args = array(
			'fields'     => 'ids',
			'number'     => $number,
			'offset'     => $offset,
			'orderby'    => 'id',
			'order'      => 'ASC',
			'network_id' => get_current_network_id(),
		);
		if ( $after_id > 0 ) {
			// get_sites() has no "ID greater than"; the ID column alone is cheap even for large networks.
			$all = array_map( 'intval', get_sites( array_merge( $args, array( 'number' => 0, 'offset' => 0 ) ) ) );
			return array_slice( array_values( array_filter( $all, static fn( int $id ): bool => $id > $after_id ) ), 0, $number );
		}
		return array_map( 'intval', get_sites( $args ) );
	}

	public static function site_count(): int {
		return (int) get_sites(
			array(
				'count'      => true,
				'network_id' => get_current_network_id(),
			)
		);
	}

	/** Removes a directory with its content (private uploads of a deleted site). */
	private static function delete_dir( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $path );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
