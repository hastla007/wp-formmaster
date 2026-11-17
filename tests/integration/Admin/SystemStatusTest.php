<?php
/**
 * System status, diagnostics report, Site Health, WP-CLI logic and integrations page operations
 * (P1-11, P5-10, P5-22, P6-19, P8-04, P9-14).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Admin;

use FormCaptain\Admin\IntegrationsPage;
use FormCaptain\Admin\SiteHealth;
use FormCaptain\Admin\SystemStatus;
use FormCaptain\Cli\Commands;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;
use FormCaptain\Support\Requirements;
use FormCaptain\Tests\Integration\Contract\FakeConnector;
use FormCaptain\Tests\Integration\TestCase;

require_once dirname( __DIR__ ) . '/Contract/FakeConnector.php';

final class SystemStatusTest extends TestCase {

	private function status(): SystemStatus {
		return $this->plugin()->get( SystemStatus::class );
	}

	public function test_report_contains_no_personal_data(): void {
		$form = $this->create_published_form();
		$repo = $this->plugin()->get( EntryRepository::class );
		$id   = $repo->insert( array( 'form_id' => $form['id'], 'form_version_id' => $form['published_version_id'], 'request_key' => hash( 'sha256', 'status' ) ) );
		$repo->insert_values( $id, array( 'f_mail01' => 'secret.person@example.org', 'f_name01' => 'Very Secret Name' ) );
		Logger::log( 'error', 'mail_failed', 'Could not send to secret.person@example.org from 203.0.113.9', array( 'entry_id' => $id ) );
		update_option( 'form_captain_settings', array_merge( get_option( 'form_captain_settings' ), array( 'email' => array( 'from_name' => 'X', 'from_address' => 'owner@example.org', 'format' => 'html' ) ) ) );

		$report = $this->status()->report_text();
		$this->assertStringContainsString( '### Form Captain diagnostics ###', $report );
		$this->assertStringContainsString( 'mail_failed', $report );
		$this->assertStringContainsString( Logger::correlation_id(), $report, 'Correlation IDs allow support' );
		foreach ( array( 'secret.person@example.org', 'owner@example.org', 'Very Secret Name', '203.0.113.9', (string) get_option( 'form_captain_secret' ) ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $report );
		}
		$this->assertDoesNotMatchRegularExpression( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $report );
		$this->assertStringNotContainsString( 'secret.person', (string) wp_json_encode( apply_filters( 'debug_information', array() )['form-captain'] ?? array() ) );
	}

	/** P9-12: the schema problem tells the admin what to do for a failed migration and for a downgrade. */
	public function test_schema_problem_messages_name_the_cause(): void {
		update_option( 'form_captain_migration_state', array( 'target' => 2, 'started_gmt' => '2026-01-01 00:00:00', 'error' => 'migration_failed', 'detail' => 'missing_column:entries.consent_withdrawn_gmt' ), false );
		$problems = $this->status()->problems();
		$this->assertStringContainsString( 'ALTER', $problems['schema'] );
		$this->assertStringContainsString( 'Submissions are still saved', $problems['schema'] );

		update_option( 'form_captain_migration_state', array( 'error' => 'schema_newer_than_plugin', 'installed' => 9 ), false );
		$problems = $this->status()->problems();
		$this->assertStringContainsString( 'newer Form Captain version (schema', $problems['schema'] );
		$this->assertStringNotContainsString( 'Deactivate', $problems['schema'] );
		delete_option( 'form_captain_migration_state' );
	}

	public function test_queue_delay_is_reported(): void {
		$jobs = $this->plugin()->get( JobRepository::class );
		$jobs->enqueue( 'mail.admin', 'status:delay:' . uniqid(), array() );
		Clock::freeze( time() + 3600 );
		$d = $this->status()->collect();
		$this->assertTrue( $d['queue']['delayed'] );
		$this->assertArrayHasKey( 'queue', $this->status()->problems( $d ) );
		$health = $this->plugin()->get( SiteHealth::class )->test_queue();
		$this->assertSame( 'recommended', $health['status'] );
		$this->assertSame( 'form_captain_queue', $health['test'] );
	}

	/** Decision 21: an older database server is reported (never blocked); the filter lowers the minimum. */
	public function test_database_version_warning(): void {
		$this->assertSame( array( 'type' => 'mariadb', 'version' => '10.11.6' ), Requirements::parse_db_server( '5.5.5-10.11.6-MariaDB-ubu2204' ) );
		$this->assertSame( array( 'type' => 'mariadb', 'version' => '12.3.1' ), Requirements::parse_db_server( '12.3.1-MariaDB-ubu2404' ) );
		$this->assertSame( array( 'type' => 'mysql', 'version' => '9.7.0' ), Requirements::parse_db_server( '9.7' ) );
		$this->assertNull( Requirements::parse_db_server( 'unknown' ) );

		$this->assertNull( Requirements::database_warning( '9.7.4' ) );
		$this->assertNull( Requirements::database_warning( '12.3.3-MariaDB' ) );
		$this->assertNull( Requirements::database_warning( '' ) );
		$this->assertStringContainsString( 'MySQL 8.4.2', (string) Requirements::database_warning( '8.4.2' ) );
		$this->assertStringContainsString( 'MariaDB 11.4.5', (string) Requirements::database_warning( '11.4.5-MariaDB' ) );

		$d                            = $this->status()->collect();
		$d['environment']['database'] = '8.0.36';
		$this->assertArrayHasKey( 'database_version', $this->status()->problems( $d ) );

		$lower = static fn() => array( 'mysql' => '8.0', 'mariadb' => 'not-a-version' );
		add_filter( 'form_captain_minimum_db_versions_v1', $lower );
		$this->assertArrayNotHasKey( 'database_version', $this->status()->problems( $d ) );
		$this->assertNotNull( Requirements::database_warning( '11.4.5-MariaDB' ) );
		remove_filter( 'form_captain_minimum_db_versions_v1', $lower );
	}

	public function test_site_health_tests_are_registered(): void {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		foreach ( array( 'queue', 'mail', 'cron', 'schema', 'database_version' ) as $t ) {
			$this->assertArrayHasKey( 'form_captain_' . $t, $tests['direct'] );
		}
		$this->assertSame( 'good', $this->plugin()->get( SiteHealth::class )->test_schema()['status'] );
	}

	public function test_cli_logic(): void {
		$cli  = $this->plugin()->get( Commands::class );
		$jobs = $this->plugin()->get( JobRepository::class );
		$id   = $jobs->enqueue( 'unknown.type', 'cli:' . uniqid(), array( 'secret' => 'no' ) );
		$cli->run_jobs( 50 );
		$this->assertSame( 'dead', $jobs->find( $id )['status'] );
		$rows = $cli->list_jobs( 'dead', 'unknown.' );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertArrayNotHasKey( 'payload', $rows[0] );
		$this->assertTrue( $cli->retry_job( $id ) );
		$this->assertSame( 'job_not_found', $cli->retry_job( 99999999 )->get_error_code() );
		$this->assertSame( 'job_not_retryable', $cli->retry_job( $id )->get_error_code() );
		$this->assertStringContainsString( 'schema', $cli->status_report() );
	}

	public function test_integrations_page_operations(): void {
		$registry = $this->plugin()->get( ConnectorRegistry::class );
		$registry->reset();
		delete_option( ConnectorRegistry::OPTION );
		$fake = new FakeConnector( 'pagefake' );
		add_filter( 'form_captain_connectors_v1', static fn( array $l ): array => $l + array( 'pagefake' => $fake ) );
		$registry->reload();
		$this->as_admin();
		$page = $this->plugin()->get( IntegrationsPage::class );
		$this->assertSame( 'error', $page->run_test( 'pagefake.send' )['type'], 'Disabled connector cannot be tested' );
		$this->assertSame( 'success', $page->toggle( 'pagefake', true )['type'] );
		$this->assertSame( 'success', $page->run_test( 'pagefake.send' )['type'] );
		for ( $i = 0; $i < 4; $i++ ) {
			$page->run_test( 'pagefake.send' );
		}
		$this->assertSame( 'error', $page->run_test( 'pagefake.send' )['type'], 'Test runs are rate limited' );
		$this->assertSame( 'error', $page->toggle( 'missing', true )['type'] );

		$jobs = $this->plugin()->get( JobRepository::class );
		$id   = $jobs->enqueue( 'connector.action', 'page:' . uniqid(), array( 'action_key' => 'pagefake.send' ) );
		$this->assertSame( 'error', $page->retry_job( $id )['type'], 'Pending jobs cannot be retried' );
		$this->assertSame( 'success', $page->cancel_job( $id )['type'] );
		$this->assertSame( 'success', $page->retry_job( $id )['type'] );
		$mail = $jobs->enqueue( 'mail.admin', 'page:mail:' . uniqid(), array() );
		$this->assertSame( 'error', $page->cancel_job( $mail )['type'], 'Only connector jobs are managed here' );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'pagefake.send', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$registry->reset();
	}

	public function test_settings_page_renders_every_tab(): void {
		$this->as_admin();
		$page = $this->plugin()->get( \FormCaptain\Admin\SettingsPage::class );
		foreach ( \FormCaptain\Settings\SettingsSchema::TABS as $tab ) {
			$_GET['tab'] = $tab;
			ob_start();
			$page->render();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'nav-tab-active', $html, $tab );
			if ( 'status' !== $tab ) {
				$this->assertStringContainsString( '_wpnonce', $html, $tab );
			}
		}
		unset( $_GET['tab'] );
	}
}
