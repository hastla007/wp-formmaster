<?php
/**
 * Decision #3 (2026-11-16): internal notes are part of the personal data export,
 * audit entries are not.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Privacy;

use FormCaptain\Privacy\PrivacyIntegration;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Tests\Integration\TestCase;

final class ExportNotesTest extends TestCase {

	public function test_export_contains_notes_but_not_audit_entries(): void {
		$form    = $this->create_published_form();
		$entries = $this->plugin()->get( EntryRepository::class );
		$id      = $entries->insert(
			array(
				'form_id'         => (int) $form['id'],
				'form_version_id' => (int) $form['published_version_id'],
				'request_key'     => hash( 'sha256', 'export-notes-' . wp_generate_password( 8, false ) ),
			)
		);
		$entries->insert_values( $id, array( 'f_name01' => 'Anna', 'f_mail01' => 'anna@example.org' ) );
		$entries->add_note( $id, 1, 'Rückruf am Dienstag vereinbart.' );
		$entries->add_note( $id, 1, 'status_changed:new>read', 'audit' );

		$items  = $this->plugin()->get( PrivacyIntegration::class )->export_item( $entries->find( $id ) );
		$values = array_column( $items, 'value' );
		$this->assertContains( 'Rückruf am Dienstag vereinbart.', $values );
		$this->assertNotContains( 'status_changed:new>read', $values );
	}
}
