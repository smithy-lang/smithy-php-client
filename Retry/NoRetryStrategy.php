<?php

declare(strict_types=1);

namespace Smithy\Client\Retry;

/**
 * Single-attempt strategy. Never retries.
 *
 * Serves as the default when no strategy is configured and as a test
 * fixture that exercises the pipeline's attempt loop exactly once.
 * The first refresh call throws {@see TokenAcquisitionFailedException}.
 */
final class NoRetryStrategy implements RetryStrategyInterface
{
    private static ?self $instance = null;

    /**
     * Return the shared no-retry strategy.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    /** @inheritDoc */
    public function begin(string $scope, int $requestFlags = 0): RetryState
    {
        return new RetryState($scope, $requestFlags);
    }

    /** @inheritDoc */
    public function refresh(RetryState $state, \Throwable $error): float
    {
        throw new TokenAcquisitionFailedException(
            'Retries are disabled for this client.',
            previous: $error,
            state: $state
        );
    }

    /** @inheritDoc */
    public function recordSuccess(RetryState $state): void
    {
        // No bucket state to update.
    }

    /** @inheritDoc */
    public function maxAttempts(): int
    {
        return 1;
    }
}
