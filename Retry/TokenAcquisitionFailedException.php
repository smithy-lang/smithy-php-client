<?php

declare(strict_types=1);

namespace Smithy\Client\Retry;

use Smithy\Core\Exception\ApiException;
use Smithy\Core\Exception\ErrorFault;

/**
 * Thrown when a retry strategy cannot grant another attempt.
 *
 * The previous exception identifies the failed attempt, while `state`
 * captures the strategy state at exhaustion. `delay` is non-zero when
 * the strategy wants the pipeline to wait before returning the terminal
 * failure, such as when long-polling retry capacity is exhausted.
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
