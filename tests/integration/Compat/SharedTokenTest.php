<?php
/**
 * P3-14: two visitors submitting with the same cached token both get stored;
 * an identical retry is deduplicated.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Compat;

use FormCaptain\Security\SubmissionToken;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\Clock;
use FormCaptain\Tests\Integration\TestCase;

final class SharedTokenTest extends TestCase {

	public function test_shared_token_keeps_both_visitors_and_dedups_retry(): void {
		$form    = $this->create_published_form();
		$token   = $this->plugin()->get( SubmissionToken::class )->issue( (string) $form['public_id'] );
		$token   = is_array( $token ) ? (string) ( $token['token'] ?? '' ) : (string) $token;
		$service = $this->plugin()->get( SubmissionService::class );
		Clock::freeze( time() + 30 );
		$raw = static fn( string $name ) => array(
			'_fc_token' => $token,
			'fields'    => array( 'f_name01' => $name, 'f_mail01' => strtolower( $name ) . '@example.org' ),
		);
		$a  = $service->submit( $form, $raw( 'Anna' ), array( 'ip' => '203.0.113.1' ) );
		$b  = $service->submit( $form, $raw( 'Bert' ), array( 'ip' => '203.0.113.2' ) );
		$a2 = $service->submit( $form, $raw( 'Anna' ), array( 'ip' => '203.0.113.1' ) );
		$this->assertTrue( $a['success'], wp_json_encode( $a ) );
		$this->assertTrue( $b['success'], wp_json_encode( $b ) );
		$this->assertNotSame( $a['entry_id'], $b['entry_id'], 'second visitor is stored separately' );
		$this->assertSame( $a['entry_id'], $a2['entry_id'], 'identical retry is deduplicated' );
	}
}
