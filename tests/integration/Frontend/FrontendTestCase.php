<?php
/**
 * Shared helpers for frontend/submission integration tests.
 *
 * The submission service uses real transactions (START TRANSACTION/COMMIT). Inside the WordPress test
 * suite every test already runs in a transaction that is rolled back afterwards; a nested
 * START TRANSACTION would implicitly commit test data. We therefore map the service's transaction
 * statements to savepoints for the duration of each test.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Frontend;

use FormCaptain\Frontend\Assets;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Security\SpamGuard;
use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\FormSchemaLoader;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Limits;
use FormCaptain\Tests\Integration\TestCase;

abstract class FrontendTestCase extends TestCase {

	/** @var callable|null */
	private $tx_filter = null;

	public function set_up(): void {
		parent::set_up();
		$this->tx_filter = static function ( string $query ): string {
			$q = strtoupper( trim( $query ) );
			return match ( $q ) {
				'START TRANSACTION' => 'SAVEPOINT fc_test_tx',
				'COMMIT' => 'RELEASE SAVEPOINT fc_test_tx',
				'ROLLBACK' => 'ROLLBACK TO SAVEPOINT fc_test_tx',
				default => $query,
			};
		};
		add_filter( 'query', $this->tx_filter );
		Limits::reset();
		Renderer::reset_counters();
		Assets::reset();
		$this->plugin()->get( FormSchemaLoader::class )->flush();
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		$_GET                  = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function tear_down(): void {
		if ( $this->tx_filter ) {
			remove_filter( 'query', $this->tx_filter );
		}
		$_GET = array();
		parent::tear_down();
	}

	/** Token issued $age seconds ago (passes the minimum fill time). */
	protected function token( array $form, int $age = 30 ): string {
		Clock::freeze( time() - $age );
		$token = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] )['token'];
		Clock::freeze( null );
		return $token;
	}

	/**
	 * Public raw payload as sent by frontend.js.
	 *
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	protected function payload( array $form, array $fields, ?string $token = null ): array {
		return array(
			'_fc_form'  => $form['public_id'],
			'_fc_token' => $token ?? $this->token( $form ),
			'fields'    => $fields,
			SpamGuard::honeypot_name( (string) $form['public_id'] ) => '',
		);
	}

	/** @return array<string,string> */
	protected function valid_fields(): array {
		return array(
			'f_name01' => 'Erika Muster',
			'f_mail01' => ' Erika@Example.ORG ',
			'f_msg001' => "Hallo\nWelt",
		);
	}

	protected function set_spam_setting( string $key, mixed $value ): void {
		$spam         = Settings::all()['spam'];
		$spam[ $key ] = $value;
		Settings::set_group( 'spam', $spam );
	}

	protected function count_rows( string $table, string $where = '1=1' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . $table . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB
	}
}
