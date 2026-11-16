<?php
/**
 * Base class for integration tests with helpers.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration;

use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Support\Clock;

abstract class TestCase extends \WP_UnitTestCase {

	protected function plugin(): Plugin {
		return Plugin::instance();
	}

	public function tear_down(): void {
		Clock::freeze( null );
		parent::tear_down();
	}

	/**
	 * Creates and publishes a simple contact form; returns the form row.
	 *
	 * @param array<int,array<string,mixed>>|null $fields
	 * @param array<string,mixed>                 $settings
	 * @return array<string,mixed>
	 */
	protected function create_published_form( ?array $fields = null, array $settings = array() ): array {
		$fields = $fields ?? array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			array( 'id' => 'f_mail01', 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
			array( 'id' => 'f_msg001', 'type' => 'textarea', 'key' => 'message', 'label' => 'Nachricht', 'required' => false ),
		);
		$repo   = $this->plugin()->get( FormRepository::class );
		$schema = array( 'schema_version' => 1, 'fields' => $fields );
		$id     = $repo->create( 'Kontakt', $schema, array_merge( array( 'notification' => array( 'enabled' => true, 'to' => array( 'admin@example.org' ), 'subject' => 'Neu', 'fields' => 'all_non_sensitive' ), 'confirmation' => array( 'type' => 'message', 'message' => 'Danke' ) ), $settings ) );
		$repo->publish_version( $id, $schema );
		return $repo->find( $id );
	}

	protected function as_admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	protected function as_role( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}
}
