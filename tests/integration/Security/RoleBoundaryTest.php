<?php
/**
 * P5-11: role boundaries (rechte.md, rechtevertrag.md 2). Only administrators hold Form Captain
 * capabilities by default; every capability unlocks exactly its operations and nothing more;
 * role management needs promote_users; uninstall/reset only through the guarded paths.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions

namespace FormCaptain\Tests\Integration\Security;

use FormCaptain\Admin\SettingsPage;
use FormCaptain\Api\ContractApi;
use FormCaptain\Export\CsvExporter;
use FormCaptain\Install\Capabilities;
use FormCaptain\Integrations\ConnectorRegistry;
use FormCaptain\Service\EntryService;
use FormCaptain\Service\FormService;
use FormCaptain\Settings\AccessControl;
use FormCaptain\Settings\Settings;
use FormCaptain\Tests\Integration\Entries\EntryFixtures;
use FormCaptain\Tests\Integration\TestCase;

require_once __DIR__ . '/SecurityFixtures.php';
require_once dirname( __DIR__ ) . '/Entries/EntryFixtures.php';

final class RoleBoundaryTest extends TestCase {

	use SecurityFixtures;
	use EntryFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->fresh_rest_server();
	}

	public function tear_down(): void {
		foreach ( array( 'fc_reader', 'fc_editor_only', 'fc_forms', 'fc_exporter', 'fc_settings' ) as $role ) {
			remove_role( $role );
		}
		$this->drop_rest_server();
		parent::tear_down();
	}

	private function rest( string $method, string $path, array $body = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/form-captain/v1' . $path );
		if ( $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_only_administrator_holds_capabilities_by_default(): void {
		// Core roles only: other tests add custom roles to the in-memory WP_Roles object.
		foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ) as $slug ) {
			$role = get_role( $slug );
			foreach ( Capabilities::ALL as $cap ) {
				$this->assertSame( 'administrator' === $slug, $role->has_cap( $cap ), "$slug / $cap" );
			}
		}
		$this->as_role( 'editor' );
		$this->assertFalse( Capabilities::current_user_has_any(), 'Editors get nothing (konzept 4.1)' );
	}

	public function test_reader_can_read_but_not_change_export_or_delete(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$entry = $this->make_entry( $form );
		$this->role_with_caps( 'fc_reader', array( Capabilities::READ_ENTRIES ) );
		$service = $this->plugin()->get( EntryService::class );
		$this->assertIsArray( $service->notes( $entry ) );
		$this->assertTrue( $service->mark_read( $entry ), 'Auto-read needs read_entries only' );
		$this->assertSame( 'forbidden', $service->set_status( $entry, 'archived' )->get_error_code() );
		$this->assertSame( 'forbidden', $service->add_note( $entry, 'x' )->get_error_code() );
		$this->assertSame( 'forbidden', $service->set_status( $entry, 'trash' )->get_error_code() );
		$this->assertSame( 'forbidden', $service->delete( $entry )->get_error_code() );
		$this->assertSame( 'forbidden', $this->plugin()->get( CsvExporter::class )->check_request( 'POST', array( '_wpnonce' => wp_create_nonce( CsvExporter::NONCE_ACTION ) ) )->get_error_code() );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/forms' )->get_status(), 'read_entries does not open form management' );
		$api = $this->plugin()->get( ContractApi::class );
		$this->assertIsArray( $api->get_entry( $entry, array( 'meta', 'values' ) ) );
		$this->assertSame( 'forbidden', $api->get_entry( $entry, array( 'sensitive' ) )->get_error_code(), 'sensitive needs export_entries' );
	}

	public function test_write_capabilities_require_read_entries(): void {
		$this->as_admin();
		$form  = $this->create_published_form();
		$entry = $this->make_entry( $form );
		$this->role_with_caps( 'fc_editor_only', array( Capabilities::EDIT_ENTRIES, Capabilities::DELETE_ENTRIES, Capabilities::EXPORT_ENTRIES ) );
		$service = $this->plugin()->get( EntryService::class );
		$this->assertSame( 'forbidden', $service->set_status( $entry, 'archived' )->get_error_code() );
		$this->assertSame( 'forbidden', $service->delete( $entry )->get_error_code() );
		$this->assertSame( 'forbidden', $service->add_note( $entry, 'x' )->get_error_code() );
		$this->assertSame( 'forbidden', $this->plugin()->get( CsvExporter::class )->check_request( 'POST', array( '_wpnonce' => wp_create_nonce( CsvExporter::NONCE_ACTION ) ) )->get_error_code() );
		$this->assertNotNull( $this->entries()->find( $entry ) );
	}

	public function test_manage_forms_cannot_release_actions_or_delete_entries(): void {
		// SR-01: actions are only released with manage_forms + manage_integrations (OP-FRM-10).
		$this->role_with_caps( 'fc_forms', array( Capabilities::MANAGE_FORMS ) );
		$res = $this->rest(
			'POST',
			'/admin/forms',
			array(
				'title'    => 'Sneaky',
				'schema'   => array(
					'schema_version' => 1,
					'fields'         => array(
						array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ),
						array( 'id' => 'f_iban01', 'type' => 'text', 'key' => 'iban', 'label' => 'IBAN', 'sensitive' => true ),
					),
				),
				'settings' => array(
					'actions' => array(
						array(
							'instance_id' => 'a_0123456789',
							'action_key'  => 'fake.send',
							'enabled'     => true,
							'settings'    => array( 'list' => '<b>x</b>', 'api_key' => 'k' ),
							'mapping'     => array( 'email' => 'f_mail01', 'name' => 'f_iban01', '../evil' => 'f_mail01' ),
						),
					),
				),
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$actions = $res->get_data()['form']['settings']['actions'];
		$this->assertCount( 1, $actions );
		$this->assertFalse( $actions[0]['enabled'], 'New forms never start with enabled actions' );
		$this->assertArrayNotHasKey( 'name', $actions[0]['mapping'], 'Sensitive field mapping removed' );
		$this->assertArrayNotHasKey( '../evil', $actions[0]['mapping'] );
		$this->assertStringNotContainsString( '<b>', (string) wp_json_encode( $actions ) );

		$id = (int) $res->get_data()['form']['id'];
		$this->assertSame( 403, $this->rest( 'PUT', "/admin/forms/$id/actions", array( 'revision' => 1, 'actions' => array() ) )->get_status() );

		$this->as_admin();
		$form = $this->create_published_form();
		$this->make_entry( $form );
		$this->role_with_caps( 'fc_forms', array( Capabilities::MANAGE_FORMS ) );
		$req = new \WP_REST_Request( 'DELETE', '/form-captain/v1/admin/forms/' . (int) $form['id'] );
		$req->set_query_params( array( 'delete_entries' => true ) );
		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 403, $res->get_status(), 'Deleting a form with entries needs delete_entries' );
		$this->assertNotNull( $this->forms()->find( (int) $form['id'] ) );
	}

	public function test_duplicate_and_import_never_enable_actions(): void {
		$this->as_admin();
		$form    = $this->create_published_form( null, array( 'actions' => array( array( 'instance_id' => 'a_0123456789', 'action_key' => 'fake.send', 'enabled' => true, 'settings' => array(), 'mapping' => array() ) ) ) );
		$service = $this->plugin()->get( FormService::class );
		$copy    = $this->forms()->find( (int) $service->duplicate( (int) $form['id'] ) );
		$this->assertFalse( $copy['settings']['actions'][0]['enabled'] );
		$this->assertNotSame( 'a_0123456789', $copy['settings']['actions'][0]['instance_id'] );
		$imported = $service->import( (string) wp_json_encode( $service->export( (int) $form['id'] ) ) );
		$this->assertFalse( $this->forms()->find( $imported['form_id'] )['settings']['actions'][0]['enabled'] );
	}

	public function test_role_management_needs_promote_users_and_keeps_admin_lockout_protection(): void {
		$this->role_with_caps( 'fc_settings', array( Capabilities::MANAGE_SETTINGS ) );
		$page   = $this->plugin()->get( SettingsPage::class );
		$before = $this->state();
		$this->assertSame( 'error', $page->save_tab( 'access', array( 'roles' => array( 'fc_settings' => array_fill_keys( Capabilities::ALL, '1' ) ) ) )['type'] );
		$this->assertSame( 'error', $page->reset_tab( 'access' )['type'] );
		$this->assertSame( $before, $this->state(), 'No privilege escalation via the access tab' );

		$this->as_admin();
		AccessControl::apply( array() );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_SETTINGS ), 'Lock-out protection' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::READ_ENTRIES ) );
		AccessControl::reset();
		foreach ( Capabilities::ALL as $cap ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $cap ) );
			$this->assertFalse( get_role( 'editor' )->has_cap( $cap ) );
		}
	}

	public function test_integrations_capability_is_separate(): void {
		$this->role_with_caps( 'fc_forms', array( Capabilities::MANAGE_FORMS ) );
		$before = $this->state();
		$this->assertSame( 403, $this->rest( 'PUT', '/admin/forms/1/actions', array( 'revision' => 1, 'actions' => array() ) )->get_status() );
		$this->assertSame( $before, $this->state() );
		$this->assertFalse( current_user_can( Capabilities::MANAGE_INTEGRATIONS ) );
		$this->assertFalse( current_user_can( Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( $this->plugin()->get( ConnectorRegistry::class )->is_enabled( 'fake' ) );
	}

	public function test_uninstall_deletes_only_after_confirmation_and_removes_every_option(): void {
		$entry = (string) file_get_contents( FORM_CAPTAIN_DIR . 'uninstall.php' );
		$this->assertMatchesRegularExpression( "/if \\( ! defined\\( 'WP_UNINSTALL_PLUGIN' \\) \\) \\{\\s*exit;/", $entry, 'Direct calls exit immediately' );
		$this->assertStringContainsString( 'Uninstaller::run()', $entry );
		// The per-site logic lives in Install\Uninstaller (one run per site in a network, SET-FC-44).
		$file = (string) file_get_contents( FORM_CAPTAIN_DIR . 'src/Install/Uninstaller.php' );
		$this->assertStringContainsString( "['privacy']['delete_data_on_uninstall']", $file );
		$this->assertStringContainsString( "['privacy']['delete_data_confirmed_gmt']", $file );
		$this->assertLessThan( strpos( $file, 'DROP TABLE' ), strpos( $file, 'if ( ! $delete )' ), 'Data is only deleted after the flag check' );

		// Every option the plugin stores (constants and literals in src/) is removed on uninstall.
		$options = array();
		$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( FORM_CAPTAIN_DIR . 'src' ) );
		foreach ( $it as $php ) {
			if ( ! $php->isFile() || 'php' !== $php->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $php->getPathname() );
			preg_match_all( "/(?:const\\s+[A-Z_]*OPTION[A-Z_]*\\s*=\\s*|update_option\\(\\s*|add_option\\(\\s*)'(form_captain_[a-z0-9_]+)'/", $code, $m );
			foreach ( $m[1] as $name ) {
				$options[ $name ] = true;
			}
		}
		$this->assertArrayHasKey( 'form_captain_secret', $options );
		foreach ( array_keys( $options ) as $name ) {
			// Names ending in "_" are prefixes of per-object options and must be removed via LIKE.
			$needle = str_ends_with( $name, '_' ) ? str_replace( '_', '\\\\_', $name ) : "'$name'";
			$this->assertStringContainsString( $needle, $file, "uninstall.php does not delete option $name" );
		}

		// Delete flag cannot be set without the confirmation word.
		$this->as_admin();
		$this->plugin()->get( SettingsPage::class )->save_tab( 'privacy', array_merge( Settings::all()['privacy'], array( 'delete_data_on_uninstall' => '1' ) ) );
		$this->assertEmpty( Settings::get( 'privacy.delete_data_confirmed_gmt' ) );
	}
}
