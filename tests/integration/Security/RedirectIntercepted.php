<?php
/**
 * Control-flow exception for the security suite: thrown from the wp_redirect filter so that
 * admin-post handlers never reach exit during tests.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Security;

/** Thrown by the wp_redirect filter instead of letting a handler reach exit. */
final class RedirectIntercepted extends \RuntimeException {

	public function __construct( public string $location, public int $status ) {
		parent::__construct( 'redirect: ' . $location );
	}
}
