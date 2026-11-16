<?php
/**
 * Job handler result value object.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Jobs;

final class JobResult {

	public const SUCCESS = 'success';
	public const RETRY   = 'retry';
	public const FAIL    = 'fail';
	public const PAUSE   = 'pause';

	/**
	 * @param array<string,mixed>|null $data
	 */
	private function __construct(
		public readonly string $status,
		public readonly string $code,
		public readonly string $message,
		public readonly ?array $data
	) {}

	/** @param array<string,mixed>|null $data */
	public static function success( ?array $data = null ): self {
		return new self( self::SUCCESS, 'ok', '', $data );
	}

	public static function retry( string $code, string $message = '' ): self {
		return new self( self::RETRY, $code, $message, null );
	}

	public static function fail( string $code, string $message = '' ): self {
		return new self( self::FAIL, $code, $message, null );
	}

	/** Back to `paused` without counting the attempt (e.g. connector disabled while claimed). */
	public static function pause( string $code, string $message = '' ): self {
		return new self( self::PAUSE, $code, $message, null );
	}
}
