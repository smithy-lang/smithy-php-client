<?php

declare(strict_types=1);

namespace Smithy\Client\Retry;

use Smithy\Core\Exception\ApiException;
use Smithy\Core\Exception\ErrorFault;

/**
 * Thrown when a retry strategy cannot issue a token.
 *
 * Two cases:
 *  - Initial acquisition fails because the token bucket is empty (client-side
 *    rate limiting has kicked in).
 *  - Refresh fails because retries are exhausted or the last error is
 *    classified as non-retryable.
 *
 * The pipeline surfaces this to the caller as-is; the wrapped error (if any)
 * is available via `getPrevious()`. `delay` is non-zero for cases like
 * long-polling exhaustion where the strategy wants the pipeline to wait
 * before returning the terminal failure.
 */
class TokenAcquisitionFailedException extends ApiException
{
    /**
     * Create a token acquisition failure.
     */
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        public readonly ?RetryState $state = null,
        public readonly float $delay = 0.0
    )
    {
        parent::__construct($message, ErrorFault::Client, $previous);
    }
}
