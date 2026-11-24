<?php
/**
 * Multisite (SET-FC-44, SET-FC-35, docs/planung/multisite.md section 7): MS-01 to MS-10 and
 * MS-12 as far as the core is concerned (MS-11 is Pro licensing).
 *
 * Runs only in the multisite test run (`vendor/bin/phpunit -c phpunit-multisite.xml.dist`).
 * Sites created here get temporary tables (WordPress test suite), so every test stays isolated.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Multisite;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Admin\NetworkPage;
use FormCaptain\Api\ContractApi;
use FormCaptain\Cli\Commands;
use FormCaptain\Database\Migrator;
use FormCaptain\Database\Tables;
use FormCaptain\Install\Activator;
use FormCaptain\Install\Capabilities;
use FormCaptain\Install\Deactivator;
use FormCaptain\Install\Uninstaller;
use FormCaptain\Jobs\JobResult;
use FormCaptain\Jobs\JobRunner;
use FormCaptain\Jobs\Scheduler;
use FormCaptain\Network\Network;
use FormCaptain\Network\NetworkMigration;
use FormCaptain\Network\NetworkSettings;
use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Settings\Settings;
use FormCaptain\Settings\SettingsSchema;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';

/**
 * @group multisite
 */
final class MultisiteTest extends TestCase {

	use EntryFixtures;

	/** @var string[] */
	private array $dirs = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite test run only (phpunit-multisite.xml.dist).' );
		}
		Activator::activate( true ); // Network activation: records the mode only.
	}

	public function tear_down(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		foreach ( $this->dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		delete_site_option( NetworkSettings::OPTION );
		Settings::flush_caches();
		parent::tear_down();
	}

	/* --- Helpers ---------------------------------------------------------- */

	/** @param array<string,mixed> $args */
	private function site( array $args = array() ): int {
		return (int) self::factory()->blog->create( $args );
	}

	private function has_tables( int $site_id ): bool {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( Tables::ALL as $table ) {
			if ( ! Tables::exists( $prefix . $table ) ) {
				return false;
			}
		}
		return true;
	}

	/** Simulates the first request of a site (plugins_loaded → lazy installation). */
	private function first_request( int $site_id ): void {
		switch_to_blog( $site_id );
		Activator::maybe_upgrade();
		restore_current_blog();
	}

	private function private_file( int $site_id, string $name ): string {
		switch_to_blog( $site_id );
		$base = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] ) . 'form-captain-private';
		restore_current_blog();
		wp_mkdir_p( $base );
		$this->dirs[] = $base;
		$file         = $base . '/' . $name;
		file_put_contents( $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			is_dir( (string) $file ) ? $this->remove_dir( (string) $file ) : unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Makes the creation of the Form Captain tables fail for the given sites. */
	private function break_sites( array $site_ids ): callable {
		global $wpdb;
		$prefixes = array_map( static fn( int $id ): string => $wpdb->get_blog_prefix( $id ) . 'fc_', $site_ids );
		$filter   = static function ( string $query ) use ( $prefixes ): string {
			foreach ( $prefixes as $prefix ) {
				if ( preg_match( '/^\s*CREATE\s+(TEMPORARY\s+)?TABLE\s+`?' . preg_quote( $prefix, '/' ) . '/i', $query ) ) {
					return 'SELECT fc_forced_failure FROM fc_missing_table';
				}
			}
			return $query;
		};
		add_filter( 'query', $filter, 20 );
		return $filter;
	}

	/* --- MS-01 / MS-02 ------------------------------------------------------ */

	public function test_ms01_network_activation_installs_lazily_and_only_the_requested_site(): void {
		$this->assertTrue( Network::is_network_active() );
		$two   = $this->site();
		$three = $this->site();
		$this->assertFalse( $this->has_tables( $two ), 'Network activation creates no tables' );
		$this->assertFalse( $this->has_tables( $three ) );

		$this->first_request( $two );

		$this->assertTrue( $this->has_tables( $two ), 'First request of site 2 installs site 2' );
		$this->assertFalse( $this->has_tables( $three ), 'Site 3 stays untouched' );
		$this->assertSame( Migrator::TARGET_VERSION, (int) get_blog_option( $two, Migrator::OPTION_VERSION ) );
		$this->assertSame( 0, (int) get_blog_option( $three, Migrator::OPTION_VERSION, 0 ) );
		switch_to_blog( $two );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::READ_ENTRIES ), 'Capabilities per site' );
		restore_current_blog();
		switch_to_blog( $three );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::READ_ENTRIES ) );
		restore_current_blog();
	}

	public function test_ms02_new_site_is_planned_and_complete_after_its_first_request(): void {
		$site = $this->site();
		switch_to_blog( $site );
		$this->assertNotEmpty( get_option( Network::OPTION_PENDING ), 'Installation is planned' );
		$this->assertNotFalse( wp_next_scheduled( Network::HOOK_INSTALL ) );
		$this->assertFalse( get_option( Settings::OPTION ) );
		do_action( Network::HOOK_INSTALL ); // The planned event (or any first request) installs the site.
		$this->assertFalse( get_option( Network::OPTION_PENDING ) );
		$this->assertIsArray( get_option( Settings::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ) );
		restore_current_blog();
		$this->assertTrue( $this->has_tables( $site ) );
		$this->assertSame( 'current', NetworkPage::site_status( $site )['state'] );
	}

	public function test_new_site_is_not_planned_without_network_activation(): void {
		delete_site_option( NetworkSettings::OPTION );
		$this->assertFalse( Network::is_network_active() );
		$site = $this->site();
		$this->assertFalse( get_blog_option( $site, Network::OPTION_PENDING ) );
	}

	/* --- SET-FC-35: presets for new sites ---------------------------------- */

	public function test_network_presets_apply_to_new_sites_only(): void {
		$this->as_admin();
		$before = Settings::get( 'privacy.retention' );
		grant_super_admin( get_current_user_id() );
		$page   = $this->plugin()->get( NetworkPage::class );
		$errors = $page->save(
			array(
				'site_defaults' => array(
					'privacy__retention'    => '6m',
					'privacy__trash_days'   => '10',
					'privacy__erasure_mode' => 'anonymize',
					'privacy__log_days'     => '',
				),
			)
		);
		$this->assertSame( array(), $errors );
		$this->assertSame( array( 'privacy.retention' => '6m', 'privacy.trash_days' => 10, 'privacy.erasure_mode' => 'anonymize' ), NetworkSettings::site_defaults() );

		$site = $this->site();
		$this->first_request( $site );
		switch_to_blog( $site );
		$this->assertSame( '6m', Settings::get( 'privacy.retention' ) );
		$this->assertSame( 10, Settings::get( 'privacy.trash_days' ) );
		$this->assertSame( 'anonymize', Settings::get( 'privacy.erasure_mode' ) );
		$this->assertSame( 30, Settings::get( 'privacy.log_days' ), 'Empty preset: plugin default' );
		restore_current_blog();

		Settings::flush_caches();
		$this->assertSame( $before, Settings::get( 'privacy.retention' ), 'Existing site is never overwritten' );
		Settings::ensure_defaults();
		$this->assertSame( $before, Settings::get( 'privacy.retention' ) );

		// Invalid presets are refused and nothing is stored.
		$errors = $page->save( array( 'site_defaults' => array( 'privacy__trash_days' => '999' ) ) );
		$this->assertArrayHasKey( 'privacy.trash_days', $errors );
		$this->assertSame( 10, NetworkSettings::site_defaults()['privacy.trash_days'] );
	}

	public function test_network_can_forbid_data_deletion_on_uninstall(): void {
		NetworkSettings::update( array( 'allow_site_data_deletion' => false ) );
		list( $values, $errors ) = SettingsSchema::sanitize_group(
			'privacy',
			array_merge(
				Settings::all()['privacy'],
				array(
					'delete_data_on_uninstall' => '1',
					'uninstall_confirm_word'   => 'DELETE',
				)
			)
		);
		$this->assertArrayHasKey( 'privacy.delete_data_on_uninstall', $errors );
		$this->assertFalse( $values['delete_data_on_uninstall'] );
		$this->assertSame( '', $values['delete_data_confirmed_gmt'] );
	}

	/* --- MS-03 ------------------------------------------------------------- */

	public function test_ms03_deleting_a_site_removes_its_tables_and_private_uploads_only(): void {
		$two   = $this->site();
		$three = $this->site();
		$this->first_request( $two );
		$this->first_request( $three );
		$file2 = $this->private_file( $two, 'upload.txt' );
		$file3 = $this->private_file( $three, 'upload.txt' );

		$drop = apply_filters( 'wpmu_drop_tables', array(), $two );
		global $wpdb;
		$this->assertContains( $wpdb->get_blog_prefix( $two ) . 'fc_entries', $drop );
		$this->assertNotContains( $wpdb->get_blog_prefix( $three ) . 'fc_entries', $drop );

		wp_delete_site( $two );

		$this->assertFalse( $this->has_tables( $two ) );
		$this->assertFileDoesNotExist( $file2 );
		$this->assertTrue( $this->has_tables( $three ), 'Other sites are untouched' );
		$this->assertFileExists( $file3 );
		$this->assertTrue( Tables::exists( Tables::name( Tables::ENTRIES ) ), 'Main site untouched' );
	}

	/* --- MS-04 / MS-05 ------------------------------------------------------ */

	public function test_ms04_entry_id_of_site_two_is_not_found_on_site_three(): void {
		$two   = $this->site();
		$three = $this->site();
		$this->first_request( $two );
		$this->first_request( $three );
		$admin = $this->as_admin();
		grant_super_admin( $admin );
		NetworkSettings::update( array( 'super_admin_entries' => true ) );

		switch_to_blog( $two );
		$form = $this->create_published_form();
		$this->make_entry( $form );
		$this->make_entry( $form );
		$entry = $this->make_entry( $form, array( 'f_name01' => 'Site Two Person', 'f_mail01' => 'two@example.org' ) );
		restore_current_blog();

		switch_to_blog( $three );
		$this->assertNull( $this->entries()->find( $entry ) );
		$this->assertSame( 'entry_not_found', $this->plugin()->get( ContractApi::class )->get_entry( $entry, array( 'meta' ) )->get_error_code() );
		$result = $this->plugin()->get( EntryService::class )->set_status( $entry, 'archived' );
		$this->assertWPError( $result );
		$_GET = array( 'page' => EntriesPage::SLUG, 'view' => (string) $entry );
		ob_start();
		try {
			$this->plugin()->get( EntriesPage::class )->render();
		} catch ( \WPDieException $e ) {
			echo esc_html( $e->getMessage() );
		}
		$out  = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringNotContainsString( 'Site Two Person', $out );
		$this->assertStringNotContainsString( 'two@example.org', $out );

		// The public form ID of site 2 does not exist on site 3 (REST).
		$res = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/form-captain/v1/forms/' . $form['public_id'] . '/token' ) );
		$this->assertSame( 404, $res->get_status() );
		restore_current_blog();

		switch_to_blog( $two );
		$this->assertSame( 'new', $this->entries()->find( $entry )['status'], 'Nothing changed on site 2' );
		restore_current_blog();
	}

	public function test_ms05_admin_of_site_two_has_no_access_on_site_three(): void {
		$two   = $this->site();
		$three = $this->site();
		$this->first_request( $two );
		$this->first_request( $three );
		$user = self::factory()->user->create();
		add_user_to_blog( $two, $user, 'administrator' );
		wp_set_current_user( $user );

		switch_to_blog( $two );
		wp_set_current_user( $user );
		$this->assertTrue( current_user_can( Capabilities::READ_ENTRIES ) );
		restore_current_blog();
		switch_to_blog( $three );
		wp_set_current_user( $user );
		foreach ( Capabilities::ALL as $cap ) {
			$this->assertFalse( current_user_can( $cap ), $cap );
		}
		restore_current_blog();
		$this->assertFalse( current_user_can( 'manage_network_options' ) );
	}

	/* --- MS-06 ------------------------------------------------------------- */

	public function test_ms06_super_admin_without_role_sees_entries_only_when_the_network_allows_it(): void {
		$two = $this->site();
		$this->first_request( $two );
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		$member = self::factory()->user->create();
		grant_super_admin( $member );
		add_user_to_blog( $two, $member, 'editor' );

		switch_to_blog( $two );
		wp_set_current_user( $super );
		$this->assertFalse( NetworkSettings::super_admin_entries(), 'Default off (decision 23)' );
		foreach ( Network::ENTRY_CAPS as $cap ) {
			$this->assertFalse( current_user_can( $cap ), "$cap denied by default" );
		}
		$this->assertTrue( current_user_can( Capabilities::MANAGE_FORMS ), 'Other capabilities stay' );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		wp_set_current_user( $member );
		$this->assertTrue( current_user_can( Capabilities::READ_ENTRIES ), 'Super admin with an own role on the site keeps access' );

		NetworkSettings::update( array( 'super_admin_entries' => true ) );
		wp_set_current_user( $super );
		foreach ( Network::ENTRY_CAPS as $cap ) {
			$this->assertTrue( current_user_can( $cap ), "$cap allowed by the switch" );
		}
		restore_current_blog();
	}

	/* --- MS-07 ------------------------------------------------------------- */

	public function test_ms07_mass_migration_with_failing_sites_continues_and_retry_succeeds(): void {
		$count  = max( 12, (int) ( getenv( 'FC_MS_SITES' ) ?: 200 ) );
		$sites  = array();
		$broken = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$sites[] = $this->site();
		}
		foreach ( array( 2, 7, 11, (int) ( $count / 2 ), $count - 1 ) as $index ) {
			$broken[] = $sites[ $index ];
		}
		$broken = array_values( array_unique( $broken ) );
		$filter = $this->break_sites( $broken );
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true ); // The forced failures would print database errors.

		// Queue mode on the main site: one job per 10 sites.
		$progress = NetworkMigration::start( true );
		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( Network::site_count(), $progress['total'] );
		$runner = $this->plugin()->get( JobRunner::class );
		$runs   = 0;
		while ( 'running' === NetworkSettings::get( 'migration' )['status'] && $runs < 200 ) {
			$runner->run( 5 );
			++$runs;
		}
		$progress = NetworkSettings::get( 'migration' );
		$this->assertSame( 'done', $progress['status'] );
		$this->assertSame( $progress['total'], $progress['done'] );
		$this->assertEqualsCanonicalizing( $broken, array_map( 'intval', array_keys( $progress['failed'] ) ) );
		$this->assertSame( array( 'migration_failed' ), array_values( array_unique( $progress['failed'] ) ) );
		foreach ( $sites as $site ) {
			$this->assertSame( ! in_array( $site, $broken, true ), $this->has_tables( $site ), "Site $site" );
		}
		$this->assertSame( 'failed', NetworkPage::site_status( $broken[0] )['state'] );
		$this->assertSame( 'migration_failed', NetworkPage::site_status( $broken[0] )['error'] );

		// Cause fixed: running again (here per WP-CLI, without queue) migrates the rest.
		remove_filter( 'query', $filter, 20 );
		$wpdb->suppress_errors( $suppress );
		$result = $this->plugin()->get( Commands::class )->migrate( true );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame( Network::site_count(), $result['migrated'] );
		foreach ( $broken as $site ) {
			$this->assertTrue( $this->has_tables( $site ), "Site $site after retry" );
			$this->assertSame( 'current', NetworkPage::site_status( $site )['state'] );
		}
	}

	public function test_network_overview_shows_no_contents(): void {
		$two = $this->site();
		$this->first_request( $two );
		switch_to_blog( $two );
		$form = $this->create_published_form();
		$this->make_entry( $form, array( 'f_name01' => 'Hidden Person', 'f_mail01' => 'hidden@example.org' ) );
		$this->enqueue_mail( 1, (int) $form['id'] );
		restore_current_blog();
		$status = NetworkPage::site_status( $two );
		$this->assertSame( 1, $status['queue']['pending'] );
		$this->assertSame( Migrator::TARGET_VERSION, $status['schema'] );

		$admin = $this->as_admin();
		grant_super_admin( $admin );
		ob_start();
		$this->plugin()->get( NetworkPage::class )->render();
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( 'Migrate all sites', $out );
		$this->assertStringNotContainsString( 'Hidden Person', $out );
		$this->assertStringNotContainsString( 'hidden@example.org', $out );
		$this->assertStringNotContainsString( 'Kontakt', $out, 'No form titles either' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( \WPDieException::class );
		$this->plugin()->get( NetworkPage::class )->render();
	}

	/* --- MS-08 ------------------------------------------------------------- */

	public function test_ms08_network_uninstall_deletes_only_the_site_that_confirmed(): void {
		$two   = $this->site();
		$three = $this->site();
		$this->first_request( $two );
		$this->first_request( $three );
		switch_to_blog( $three );
		$settings                                         = get_option( Settings::OPTION );
		$settings['privacy']['delete_data_on_uninstall']  = true;
		$settings['privacy']['delete_data_confirmed_gmt'] = '2026-11-24 10:00:00';
		update_option( Settings::OPTION, $settings );
		restore_current_blog();
		NetworkSettings::update( array( 'super_admin_entries' => true ) );

		$this->assertSame( array(), Uninstaller::run() );

		$this->assertFalse( $this->has_tables( $three ), 'Confirmed site deleted' );
		$this->assertFalse( get_blog_option( $three, Settings::OPTION ) );
		$this->assertTrue( $this->has_tables( $two ), 'Unconfirmed site kept' );
		$this->assertIsArray( get_blog_option( $two, Settings::OPTION ) );
		$this->assertTrue( Tables::exists( Tables::name( Tables::ENTRIES ) ), 'Main site kept' );
		$this->assertFalse( get_site_option( NetworkSettings::OPTION ), 'Network option removed' );
		switch_to_blog( $two );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_JOBS ), 'Schedules removed on every site' );
		restore_current_blog();
	}

	public function test_network_deactivation_removes_schedules_per_site_and_keeps_data(): void {
		$two = $this->site();
		$this->first_request( $two );
		switch_to_blog( $two );
		Scheduler::schedule();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_JOBS ) );
		restore_current_blog();

		Deactivator::deactivate( true );

		$this->assertFalse( Network::is_network_active() );
		switch_to_blog( $two );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_JOBS ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_CLEANUP ) );
		restore_current_blog();
		$this->assertTrue( $this->has_tables( $two ), 'Data stays' );
	}

	/* --- MS-09 ------------------------------------------------------------- */

	public function test_ms09_blocked_queue_of_one_site_does_not_affect_another(): void {
		$two   = $this->site();
		$three = $this->site();
		$this->first_request( $two );
		$this->first_request( $three );
		$runner = $this->plugin()->get( JobRunner::class );
		$runner->set_handler( 'test.block', static fn(): JobResult => JobResult::retry( 'remote_down' ) );
		$runner->set_handler( 'test.ok', static fn(): JobResult => JobResult::success() );

		switch_to_blog( $two );
		Scheduler::schedule();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->jobs()->enqueue( 'test.block', 'block-' . $i, array() );
		}
		$runner->run( 20 );
		$blocked = $this->jobs()->health()['counts'];
		restore_current_blog();

		switch_to_blog( $three );
		Scheduler::schedule();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_JOBS ), 'Each site has its own schedule' );
		$job = $this->jobs()->enqueue( 'test.ok', 'ok-1', array() );
		$this->assertSame( 0, $this->jobs()->health()['counts']['failed'], 'Site 3 does not see the jobs of site 2' );
		$stats = $runner->run( 20 );
		$this->assertSame( 1, $stats['succeeded'] );
		$this->assertSame( 'succeeded', $this->jobs()->find( $job )['status'] );
		restore_current_blog();

		switch_to_blog( $two );
		$this->assertSame( $blocked, $this->jobs()->health()['counts'], 'Site 2 queue unchanged by site 3' );
		$this->assertSame( 5, $blocked['failed'] );
		restore_current_blog();
	}

	/* --- MS-10 ------------------------------------------------------------- */

	public function test_ms10_privacy_tools_find_only_entries_of_the_current_site(): void {
		$two   = $this->site();
		$three = $this->site();
		$this->first_request( $two );
		$this->first_request( $three );
		$values = array( 'f_name01' => 'Same Person', 'f_mail01' => 'same@example.org' );

		switch_to_blog( $three );
		$form3 = $this->create_published_form();
		$this->make_entry( $form3, $values );
		$this->make_entry( $form3, $values );
		restore_current_blog();

		switch_to_blog( $two );
		$form2 = $this->create_published_form();
		$mine  = $this->make_entry( $form2, $values );
		$res   = $this->plugin()->get( PrivacyIntegration::class )->export( 'same@example.org' );
		$this->assertCount( 1, $res['data'] );
		$this->assertSame( 'form-captain-entry-' . $mine, $res['data'][0]['item_id'] );
		$erase = $this->plugin()->get( PrivacyIntegration::class )->erase( 'same@example.org' );
		$this->assertTrue( $erase['items_removed'] );
		restore_current_blog();

		switch_to_blog( $three );
		$this->assertCount( 2, $this->plugin()->get( PrivacyIntegration::class )->export( 'same@example.org' )['data'], 'Site 3 untouched' );
		restore_current_blog();
	}

	/* --- MS-12 ------------------------------------------------------------- */

	public function test_ms12_tokens_redirects_and_uploads_are_per_site_with_mapped_domains(): void {
		$sub    = $this->site( array( 'domain' => WP_TESTS_DOMAIN, 'path' => '/sub/' ) );
		$mapped = $this->site( array( 'domain' => 'shop.example.net', 'path' => '/' ) );
		$this->first_request( $sub );
		$this->first_request( $mapped );

		switch_to_blog( $sub );
		$form  = $this->create_published_form();
		$token = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] )['token'];
		$this->assertTrue( $this->plugin()->get( SubmissionToken::class )->verify( $token, (string) $form['public_id'] )['ok'] );
		$this->assertStringEndsWith( '/sites/' . $sub, (string) wp_upload_dir( null, false )['basedir'] );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$conf = $this->plugin()->get( SubmissionService::class )->confirmation( array_merge( $form, array( 'settings' => array( 'confirmation' => array( 'type' => 'page', 'page_id' => $page ) ) ) ) );
		$this->assertStringStartsWith( 'http://' . WP_TESTS_DOMAIN . '/sub/', (string) ( $conf['redirect_url'] ?? '' ), 'Redirect stays on the site' );
		restore_current_blog();

		switch_to_blog( $mapped );
		$this->assertSame( 'signature', $this->plugin()->get( SubmissionToken::class )->verify( $token, (string) $form['public_id'] )['code'], 'A token of another site is invalid' );
		$this->assertStringEndsWith( '/sites/' . $mapped, (string) wp_upload_dir( null, false )['basedir'] );
		$this->assertStringStartsWith( 'http://shop.example.net/', home_url( '/' ), 'Mapped domain is the home of the site' );
		$this->assertSame( 'http://shop.example.net/danke/', wp_validate_redirect( 'http://shop.example.net/danke/', '' ) );
		restore_current_blog();
	}
}
