<?php

declare(strict_types=1);

namespace Smithy\Client\Retry;

/**
 * Track per-invocation retry state threaded through the pipeline.
 *
 * Strategies own the semantics of these fields; the pipeline only
 * passes the object through begin -> refresh -> recordSuccess.
 */
final class RetryState
{
    /**
     * Create retry state.
     */
    public function __construct(
        public readonly string $scope,
        public readonly int $requestFlags = 0,
        public int $attempt = 1,
        public int $lastRetryCost = 0,
        public int $capacityRemaining = 0
    ) {}
}
