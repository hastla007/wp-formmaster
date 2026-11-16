<?php
/**
 * Structured consent withdrawal (product decision 4): ConsentService, job guards, admin action,
 * CSV marker, contract event and schema migration v1 → v2.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuerySchemaChange

namespace FormCaptain\Tests\Integration\Privacy;

use FormCaptain\Admin\EntriesPage;
use FormCaptain\Database\Migrator;
use FormCaptain\Database\Schema;
use FormCaptain\Database\Tables;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Install\Capabilities;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Service\ConsentService;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\OutboxPlanner;
use FormCaptain\Tests\Integration\Contract\ConnectorContractAssertions;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';
require_once dirname( __DIR__ ) . '/Contract/ConnectorContractAssertions.php';

final class ConsentWithdrawalTest extends TestCase {

	use EntryFixtures;
	use ConnectorContractAssertions {
		ConnectorContractAssertions::make_entry insteadof EntryFixtures;
		EntryFixtures::make_entry as fixture_entry;
	}

	private const INSTANCE = 'a_0c0ffee123';

	/** @var array<int,array<int,mixed>> */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		$this->events = array();
		add_action(
			ConsentService::EVENT,
			function ( ...$args ): void {
				$this->events[] = $args;
			},
			10,
			4
		);
	}

	private function consent(): ConsentService {
		return $this->plugin()->get( ConsentService::class );
	}

	private function page(): EntriesPage {
		return $this->plugin()->get( EntriesPage::class );
	}

	/** @return array<string,mixed> */
	private function form_with_action(): array {
		return $this->create_published_form(
			null,
			array(
				'confirmation_mail' => array( 'enabled' => false ),
				'actions'           => array( self::action_instance( 'core.set_status', self::INSTANCE, array(), array( 'status' => 'archived' ) ) ),
			)
		);
	}

	/** @return array{0:array<string,mixed>,1:int,2:int,3:int} form, entry, mail job, connector job */
	private function entry_with_jobs(): array {
		$form  = $this->form_with_action();
		$entry = $this->fixture_entry( $form );
		$mail  = $this->enqueue_mail( $entry, (int) $form['id'] );
		$conn  = $this->enqueue_action_job( $form, $entry, 'core.set_status', self::INSTANCE );
		return array( $form, $entry, $mail, $conn );
	}

	public function test_keep_marks_entry_cancels_jobs_audits_and_fires_event_without_values(): void {
		list( $form, $entry, $mail, $conn ) = $this->entry_with_jobs();
		$this->as_admin();

		$result = $this->consent()->withdraw( $entry, 'admin', 'keep' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['withdrawn'] );
		$this->assertSame( 'kept', $result['result'] );

		$row = $this->entries()->find( $entry );
		$this->assertNotEmpty( $row['consent_withdrawn_gmt'] );
		$this->assertSame( 'Max Müller', $this->entries()->values_for( $entry )['f_name01'], 'keep leaves values' );

		foreach ( array( $mail, $conn ) as $job_id ) {
			$job = $this->jobs()->find( $job_id );
			$this->assertSame( 'cancelled', $job['status'] );
			$this->assertSame( 'consent_withdrawn', $job['last_error_code'] );
		}

		$audit = array_values( array_filter( $this->entries()->notes_for( $entry ), static fn( array $n ): bool => 'audit' === $n['type'] ) );
		$this->assertSame( 'consent_withdrawn_admin', end( $audit )['message'] );
		$this->assertSame( 'Consent withdrawal recorded', EntriesPage::audit_label( 'consent_withdrawn_admin' ) );

		$this->assertCount( 1, $this->events );
		list( $id, $form_id, $source, $event ) = $this->events[0];
		$this->assertSame( array( $entry, (int) $form['id'], 'admin' ), array( $id, $form_id, $source ) );
		$this->assertConsentWithdrawnEventShape( $event );
		$this->assertSame( $form['public_id'], $event['form_public_id'] );
		$encoded = (string) wp_json_encode( $this->events );
		foreach ( array( 'Max', 'max@example.org', 'Hallo' ) as $value ) {
			$this->assertStringNotContainsString( $value, $encoded, 'event payload carries no values' );
		}
	}

	public function test_event_is_fired_after_the_marker_is_stored(): void {
		list( , $entry ) = $this->entry_with_jobs();
		$seen            = null;
		add_action(
			ConsentService::EVENT,
			function ( int $id ) use ( &$seen ): void {
				$seen = $this->entries()->find( $id )['consent_withdrawn_gmt'];
			}
		);
		$this->consent()->withdraw( $entry );
		$this->assertNotEmpty( $seen );
	}

	public function test_anonymize_variant(): void {
		list( , $entry, $mail ) = $this->entry_with_jobs();
		$result                 = $this->consent()->withdraw( $entry, 'admin', 'anonymize' );
		$this->assertSame( 'anonymized', $result['result'] );
		$row = $this->entries()->find( $entry );
		$this->assertNotEmpty( $row['anonymized_gmt'] );
		$this->assertNotEmpty( $row['consent_withdrawn_gmt'] );
		$this->assertSame( '[deleted]', $this->entries()->values_for( $entry )['f_mail01'] );
		$this->assertSame( 'consent_withdrawn', $this->jobs()->find( $mail )['last_error_code'], 'cancelled before anonymization' );
		$notes = $this->entries()->notes_for( $entry );
		$this->assertSame( array( 'consent_withdrawn_anonymized' ), array_column( $notes, 'message' ), 'only a value-free audit trace survives' );
		$this->assertCount( 1, $this->events );
	}

	public function test_delete_variant(): void {
		list( $form, $entry, $mail ) = $this->entry_with_jobs();
		$deleted                     = array();
		add_action(
			'form_captain_entry_deleted_v1',
			static function ( int $id, int $form_id, string $reason ) use ( &$deleted ): void {
				$deleted[] = array( $id, $form_id, $reason );
			},
			10,
			3
		);
		$result = $this->consent()->withdraw( $entry, 'api', 'delete' );
		$this->assertSame( 'deleted', $result['result'] );
		$this->assertNull( $this->entries()->find( $entry ) );
		$this->assertSame( array( array( $entry, (int) $form['id'], 'consent_withdrawn' ) ), $deleted );
		$this->assertSame( 'consent_withdrawn', $this->jobs()->find( $mail )['last_error_code'] );
		$this->assertSame( 'api', $this->events[0][2] );
	}

	public function test_second_call_changes_nothing(): void {
		list( , $entry ) = $this->entry_with_jobs();
		$first           = $this->consent()->withdraw( $entry );
		$notes           = count( $this->entries()->notes_for( $entry ) );
		$before          = $this->entries()->find( $entry );
		$second          = $this->consent()->withdraw( $entry, 'cli' );
		$this->assertFalse( $second['withdrawn'] );
		$this->assertSame( $first['withdrawn_gmt'], $second['withdrawn_gmt'] );
		$this->assertSame( $before, $this->entries()->find( $entry ) );
		$this->assertCount( $notes, $this->entries()->notes_for( $entry ) );
		$this->assertCount( 1, $this->events, 'event fires once' );
	}

	public function test_invalid_arguments(): void {
		$this->assertSame( 'entry_not_found', $this->consent()->withdraw( 999999 )->get_error_code() );
		list( , $entry ) = $this->entry_with_jobs();
		$this->assertSame( 'invalid_request', $this->consent()->withdraw( $entry, 'admin', 'purge' )->get_error_code() );
		$this->consent()->withdraw( $entry, 'unknown-source' );
		$this->assertSame( 'admin', $this->events[0][2], 'unknown source falls back to admin' );
	}

	public function test_no_new_jobs_and_handlers_stop_with_consent_withdrawn(): void {
		list( $form, $entry ) = $this->entry_with_jobs();
		$this->consent()->withdraw( $entry );

		// Planner: nothing is planned for a withdrawn entry.
		$before = count( $this->jobs()->for_entry( $entry ) );
		$this->plugin()->get( OutboxPlanner::class )->plan( $form, $entry );
		$this->assertCount( $before, $this->jobs()->for_entry( $entry ) );

		// Manual resend is refused.
		$this->as_admin();
		$this->assertSame( 'conflict', $this->plugin()->get( EntryService::class )->resend_notification( $entry )->get_error_code() );

		// Jobs that reach the queue anyway end with fail('consent_withdrawn').
		$mail = $this->plugin()->get( \FormCaptain\Repository\JobRepository::class )->enqueue( 'mail.admin', 'test:late-mail:' . $entry, array( 'notification' => 'admin' ), $entry, (int) $form['id'] );
		$conn = $this->plugin()->get( \FormCaptain\Repository\JobRepository::class )->enqueue( ConnectorRegistry::JOB_TYPE, 'test:late-conn:' . $entry, array( 'action_key' => 'core.set_status', 'instance_id' => self::INSTANCE ), $entry, (int) $form['id'] );
		$this->reset_mailer();
		$this->assertSame( 'dead', $this->runner()->run_one( $mail ) );
		$this->assertSame( 'dead', $this->runner()->run_one( $conn ) );
		$this->assertSame( 'consent_withdrawn', $this->jobs()->find( $mail )['last_error_code'] );
		$this->assertSame( 'consent_withdrawn', $this->jobs()->find( $conn )['last_error_code'] );
		$this->assertCount( 0, $this->sent_mails() );
		$this->assertSame( 'new', $this->entries()->find( $entry )['status'], 'connector action did not run' );
	}

	public function test_scheduled_non_delivery_jobs_are_not_cancelled(): void {
		list( $form, $entry ) = $this->entry_with_jobs();
		$other                = $this->jobs()->enqueue( 'core.anonymize', 'test:anon:' . $entry, array( 'days' => 30 ), $entry, (int) $form['id'], 3600 );
		$this->consent()->withdraw( $entry );
		$this->assertSame( 'pending', $this->jobs()->find( $other )['status'] );
	}

	public function test_admin_action_requires_nonce_capability_and_confirmation(): void {
		list( , $entry ) = $this->entry_with_jobs();
		$request         = array(
			'entry_id'           => $entry,
			'do'                 => 'withdraw_consent',
			'then'               => 'keep',
			'confirm_withdrawal' => '1',
		);

		// Reader only: forbidden.
		$this->role_with_caps( 'fc_reader', array( Capabilities::READ_ENTRIES ) );
		$this->assertSame( 'forbidden', $this->page()->process_entry_action( $request + array( '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_ENTRY . $entry ) ) )->get_error_code() );

		// Editor role: wrong nonce, missing confirmation, delete without delete cap.
		$this->role_with_caps( 'fc_editor', array( Capabilities::READ_ENTRIES, Capabilities::EDIT_ENTRIES ) );
		$this->assertSame( 'invalid_nonce', $this->page()->process_entry_action( $request + array( '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_ENTRY . ( $entry + 1 ) ) ) )->get_error_code() );
		$nonce = wp_create_nonce( EntriesPage::NONCE_ENTRY . $entry );
		$this->assertSame( 'confirmation_required', $this->page()->process_entry_action( array_diff_key( $request, array( 'confirm_withdrawal' => 1 ) ) + array( '_fc_nonce' => $nonce ) )->get_error_code() );
		$this->assertSame( 'forbidden', $this->page()->process_entry_action( array_merge( $request, array( 'then' => 'delete', '_fc_nonce' => $nonce ) ) )->get_error_code() );
		$this->assertSame( 'forbidden', $this->consent()->withdraw_as_current_user( $entry, 'delete' )->get_error_code() );
		// Decision 2026-11-16: anonymizing is irreversible and needs the delete capability as well.
		$this->assertSame( 'forbidden', $this->page()->process_entry_action( array_merge( $request, array( 'then' => 'anonymize', '_fc_nonce' => $nonce ) ) )->get_error_code() );
		$this->assertSame( 'forbidden', $this->consent()->withdraw_as_current_user( $entry, 'anonymize' )->get_error_code() );
		$this->assertSame( 'invalid_request', $this->page()->process_entry_action( array_merge( $request, array( 'then' => 'x', '_fc_nonce' => $nonce ) ) )->get_error_code() );
		$this->assertCount( 0, $this->events, 'nothing happened yet' );
		$this->assertEmpty( $this->entries()->find( $entry )['consent_withdrawn_gmt'] );

		// Valid: keep.
		$this->assertSame( 'consent_withdrawn', $this->page()->process_entry_action( $request + array( '_fc_nonce' => $nonce ) ) );
		$this->assertNotEmpty( $this->entries()->find( $entry )['consent_withdrawn_gmt'] );

		// Delete with delete capability.
		$other = $this->fixture_entry( $this->form_with_action() );
		$this->role_with_caps( 'fc_deleter', array( Capabilities::READ_ENTRIES, Capabilities::EDIT_ENTRIES, Capabilities::DELETE_ENTRIES ) );
		$this->assertSame(
			'consent_withdrawn_deleted',
			$this->page()->process_entry_action( array_merge( $request, array( 'entry_id' => $other, 'then' => 'delete', '_fc_nonce' => wp_create_nonce( EntriesPage::NONCE_ENTRY . $other ) ) ) )
		);
		$this->assertNull( $this->entries()->find( $other ) );
	}

	public function test_detail_badge_list_filter_and_csv_marker(): void {
		$form      = $this->form_with_action();
		$withdrawn = $this->fixture_entry( $form, array( 'f_name01' => 'Widerrufen' ) );
		$active    = $this->fixture_entry( $form, array( 'f_name01' => 'Aktiv' ) );
		$this->consent()->withdraw( $withdrawn );

		$list = $this->entries()->list( array( 'consent' => 'withdrawn' ) );
		$this->assertSame( array( $withdrawn ), array_map( 'intval', array_column( $list['items'], 'id' ) ) );
		$this->assertNotContains( $withdrawn, array_map( 'intval', array_column( $this->entries()->list( array( 'consent' => 'active' ) )['items'], 'id' ) ) );

		$this->as_admin();
		$_GET = array( 'page' => EntriesPage::SLUG, 'view' => (string) $withdrawn );
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'fc-consent-withdrawn', $html );
		$this->assertStringContainsString( 'Consent withdrawn on', $html );
		$this->assertStringNotContainsString( 'name="do" value="withdraw_consent"', $html, 'no second withdrawal form' );

		$_GET = array( 'page' => EntriesPage::SLUG, 'view' => (string) $active );
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="do" value="withdraw_consent"', $html );
		$this->assertStringContainsString( 'name="confirm_withdrawal"', $html );
		$this->assertStringContainsString( 'value="delete"', $html );

		$_GET = array( 'page' => EntriesPage::SLUG, 'consent' => 'withdrawn' );
		ob_start();
		$this->page()->render();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'Widerrufen', $html );
		$this->assertStringNotContainsString( '>Aktiv<', $html );
		$this->assertStringContainsString( 'id="fc-filter-consent"', $html );

		// CSV: marker column is selected by default and can be left out.
		$exporter = $this->plugin()->get( CsvExporter::class );
		$this->assertContains( 'meta:consent_withdrawn', CsvExporter::default_columns( $exporter->columns( $form ) ) );
		$stream = fopen( 'php://memory', 'w+b' );
		$exporter->export( array( 'form_id' => (int) $form['id'], 'columns' => array( 'meta:id', 'meta:consent_withdrawn' ) ), $stream );
		rewind( $stream );
		$lines = array_map(
			static fn( string $line ): array => str_getcsv( $line, ',', '"', '' ),
			array_filter( explode( "\r\n", (string) stream_get_contents( $stream ) ) )
		);
		$this->assertSame( array( 'Submission ID', 'Consent withdrawn' ), $lines[0] );
		$by_id = array_column( array_slice( $lines, 1 ), 1, 0 );
		$this->assertNotSame( '', $by_id[ (string) $withdrawn ] );
		$this->assertSame( '', $by_id[ (string) $active ] );
	}

	public function test_contract_fixture_and_entry_dto(): void {
		$this->assertConsentWithdrawnEventShape( self::contract_fixture( 'consent-withdrawn' ) );
		list( , $entry ) = $this->entry_with_jobs();
		$this->consent()->withdraw( $entry );
		$this->as_admin();
		$dto = form_captain_get_entry_v1( $entry );
		$this->assertTrue( $dto['consent_withdrawn'] );
		$this->assertMatchesRegularExpression( '/Z$/', (string) $dto['consent_withdrawn_gmt'] );
	}

	/** Columns and indexes of a table, comparable between installations. */
	private static function shape( string $table ): array {
		global $wpdb;
		$cols = array_map( static fn( array $c ): array => array( $c['Field'], strtolower( $c['Type'] ), $c['Null'], $c['Default'] ), $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ) );
		$keys = array_map( static fn( array $k ): string => $k['Key_name'] . ':' . $k['Seq_in_index'] . ':' . $k['Column_name'], $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) );
		sort( $keys );
		return array( $cols, $keys );
	}

	public function test_fresh_install_and_upgrade_from_v1_produce_the_same_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$real = $wpdb->prefix;
		try {
			// Fresh install of the current definition (temporary table in the test suite).
			$wpdb->prefix = $real . 'fcfresh_';
			dbDelta( Schema::definitions()[ Tables::ENTRIES ] );
			$fresh = self::shape( Tables::name( Tables::ENTRIES ) );

			// v1 table without the column, then migration step 2.
			$wpdb->prefix = $real . 'fcup_';
			$v1           = str_replace( array( "  consent_withdrawn_gmt datetime DEFAULT NULL,\n", ",\n  KEY consent_withdrawn (consent_withdrawn_gmt)" ), '', Schema::definitions()[ Tables::ENTRIES ] );
			$this->assertStringNotContainsString( 'consent_withdrawn', $v1 );
			$wpdb->query( $v1 );
			$this->assertNotContains( 'consent_withdrawn_gmt', array_column( self::shape( Tables::name( Tables::ENTRIES ) )[0], 0 ) );
			( new Migrator() )->migrate_v2();
			( new Migrator() )->migrate_v2(); // idempotent
			$upgraded = self::shape( Tables::name( Tables::ENTRIES ) );
		} finally {
			foreach ( array( 'fcfresh_', 'fcup_' ) as $p ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . $real . $p . Tables::ENTRIES );
			}
			$wpdb->prefix = $real;
		}
		$this->assertContains( 'consent_withdrawn_gmt', array_column( $fresh[0], 0 ) );
		$this->assertContains( 'consent_withdrawn:1:consent_withdrawn_gmt', $fresh[1] );
		$this->assertSame( $fresh, $upgraded );
	}

	public function test_migrator_upgrades_v1_installation_and_verifies_column(): void {
		global $wpdb;
		$table    = Tables::name( Tables::ENTRIES );
		$before   = self::shape( $table );
		$migrator = new Migrator();
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX consent_withdrawn, DROP COLUMN consent_withdrawn_gmt" );
			update_option( Migrator::OPTION_VERSION, 1 );
			$this->assertTrue( $migrator->needs_migration() );

			$verify = new \ReflectionMethod( Migrator::class, 'verify' );
			try {
				$verify->invoke( $migrator, 2 );
				$this->fail( 'verify(2) must detect the missing column' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'missing_column', $e->getMessage() );
			}

			$this->assertTrue( $migrator->migrate() );
			$this->assertSame( 2, $migrator->installed_version() );
			$this->assertSame( $before, self::shape( $table ) );
			$this->assertTrue( $migrator->migrate(), 'repeatable' );
		} finally {
			if ( ! in_array( 'consent_withdrawn_gmt', array_column( self::shape( $table )[0], 0 ), true ) ) {
				$migrator->migrate_v2();
			}
			update_option( Migrator::OPTION_VERSION, Migrator::TARGET_VERSION );
			delete_option( Migrator::OPTION_STATE );
			// The ALTER statements committed the test transaction implicitly (incl. the intermediate
			// version/state options); persist the restored state so later tests see a clean install.
			$wpdb->query( 'COMMIT' );
		}
	}

	public function test_downgrade_protection_stays(): void {
		update_option( Migrator::OPTION_VERSION, 3 );
		$this->assertFalse( ( new Migrator() )->migrate() );
		$this->assertSame( 'schema_newer_than_plugin', get_option( Migrator::OPTION_STATE )['error'] );
		$this->assertSame( 3, ( new Migrator() )->installed_version() );
		update_option( Migrator::OPTION_VERSION, Migrator::TARGET_VERSION );
		delete_option( Migrator::OPTION_STATE );
	}
}
