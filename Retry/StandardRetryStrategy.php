<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Retry;

use Closure;
use InvalidArgumentException;
use Smithy\Core\Exception\ApiException;
use Smithy\Core\Retry\RetryInfoInterface;
use Smithy\Core\Retry\RetrySafety;
use Smithy\Http\Client\Exception\NetworkException;

/**
 * Retry failed attempts with exponential backoff, jitter and a token bucket.
 *
 * Behaves like smithy-java's `StandardRetryStrategy`:
 *
 *  - Each retry spends tokens from a per-scope bucket and a successful call
 *    returns tokens. When the bucket cannot cover a retry, the failure is
 *    surfaced instead of retried.
 *  - The delay before retry `n` is `min(base * 2^(n - 1), maxBackoff)`,
 *    scaled by a random jitter in `(0, 1]`. Throttling errors use a larger
 *    base delay and a lower token cost.
 *  - A server-suggested wait ({@see RetryInfoInterface::getRetryAfter()}) is
 *    clamped to between the computed delay and that delay plus five seconds.
 *
 * Classification: when an exception in the previous-chain implements
 * {@see RetryInfoInterface}, it decides. Otherwise a transport
 * {@see NetworkException} or a server-fault {@see ApiException} is retried
 * and everything else is not.
 *
 * Buckets are per instance. {@see \Smithy\Client\ClientConfig} creates one
 * strategy per config, so retries are budgeted per client and retry scope.
 */
final class StandardRetryStrategy implements RetryStrategyInterface
{
    public const MAX_ATTEMPTS = 3;
    /** Base backoff in seconds (50 ms). */
    public const BASE_DELAY = 0.05;
    /** Base backoff for throttling errors in seconds (1000 ms). */
    public const THROTTLING_BASE_DELAY = 1.0;
    /** Upper bound of a computed backoff in seconds. */
    public const MAX_BACKOFF = 20.0;
    public const RETRY_COST = 14;
    public const THROTTLING_RETRY_COST = 5;
    public const INITIAL_RETRY_TOKENS = 500;
    public const MAX_SCOPES = 128;

    /** Largest exponent applied to the base delay (floor(log2(2^31 - 1))). */
    private const RETRIES_ATTEMPTED_CEILING = 30;
    /** Largest amount, in seconds, a server hint may exceed the backoff. */
    private const RETRY_AFTER_WINDOW = 5.0;

    /** @var array<string, RetryTokenBucket> Least recently used first. */
    private array $buckets = [];

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param int $maxAttempts Total attempts, including the first.
     * @param float $baseDelay Base backoff in seconds.
     * @param float $throttlingBaseDelay Base backoff for throttling errors
     *                                   in seconds.
     * @param float $maxBackoff Maximum backoff in seconds.
     * @param int $retryCost Tokens spent by a retry.
     * @param int $throttlingRetryCost Tokens spent by a throttling retry.
     * @param int $initialRetryTokens Capacity of each scope's bucket.
     * @param int $maxScopes Number of scope buckets to keep.
     * @param (Closure(): float)|null $random Source of jitter returning a
     *        float in `[0, 1)`. Defaults to `mt_rand()`. Inject a fixed
     *        value to make delays deterministic in tests.
     */
    public function __construct(
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
        private readonly float $baseDelay = self::BASE_DELAY,
        private readonly float $throttlingBaseDelay =
            self::THROTTLING_BASE_DELAY,
        private readonly float $maxBackoff = self::MAX_BACKOFF,
        private readonly int $retryCost = self::RETRY_COST,
        private readonly int $throttlingRetryCost =
            self::THROTTLING_RETRY_COST,
        private readonly int $initialRetryTokens =
            self::INITIAL_RETRY_TOKENS,
        private readonly int $maxScopes = self::MAX_SCOPES,
        ?Closure $random = null
    )
    {
        if ($maxAttempts < 1 || $maxScopes < 1 || $initialRetryTokens < 0) {
            throw new InvalidArgumentException(
                'maxAttempts and maxScopes must be positive and '
                . 'initialRetryTokens must not be negative.'
            );
        }
        if ($baseDelay < 0 || $throttlingBaseDelay < 0 || $maxBackoff < 0
            || $retryCost < 0 || $throttlingRetryCost < 0
        ) {
            throw new InvalidArgumentException(
                'Delays and retry costs must not be negative.'
            );
        }
        $this->random = $random
            ?? static fn(): float => \mt_rand() / (\mt_getrandmax() + 1);
    }

    /** @inheritDoc */
    public function begin(string $scope, int $requestFlags = 0): RetryState
    {
        return new RetryState(
            $scope,
            $requestFlags,
            capacityRemaining: $this->bucket($scope)->capacity()
        );
    }

    /** @inheritDoc */
    public function refresh(RetryState $state, \Throwable $error): float
    {
        $bucket = $this->bucket($state->scope);
        [$retryable, $throttle, $retryAfter] = $this->classify($error);

        if (!$retryable) {
            $state->capacityRemaining = $bucket->capacity();
            throw new TokenAcquisitionFailedException(
                \sprintf(
                    'Request attempt %d encountered non-retryable failure.',
                    $state->attempt
                ),
                $error,
                $state
            );
        }

        if ($state->attempt >= $this->maxAttempts) {
            $state->capacityRemaining = $bucket->capacity();
            throw new TokenAcquisitionFailedException(
                'Request will not be retried. Retries have been exhausted.',
                $error,
                $state
            );
        }

        $cost = $throttle ? $this->throttlingRetryCost : $this->retryCost;
        if (!$bucket->tryAcquire($cost)) {
            $state->lastRetryCost = 0;
            $state->capacityRemaining = $bucket->capacity();
            $message = \sprintf(
                'Request will not be retried to protect the caller and '
                . 'downstream service. The cost of retrying (%d) exceeds the '
                . 'available retry capacity (%d/%d).',
                $cost,
                $bucket->capacity(),
                $this->initialRetryTokens
            );
            if (RetryRequestFlags::isLongPolling($state->requestFlags)) {
                // Long-polling calls still wait before surfacing the error.
                ++$state->attempt;
                throw new TokenAcquisitionFailedException(
                    $message,
                    $error,
                    $state,
                    $this->backoff($state->attempt, $throttle, $retryAfter)
                );
            }
            throw new TokenAcquisitionFailedException($message, $error, $state);
        }

        ++$state->attempt;
        $state->lastRetryCost = $cost;
        $state->capacityRemaining = $bucket->capacity();

        return $this->backoff($state->attempt, $throttle, $retryAfter);
    }

    /** @inheritDoc */
    public function recordSuccess(RetryState $state): void
    {
        // Release at least one token so the bucket can refill.
        $bucket = $this->bucket($state->scope);
        $bucket->release(\max($state->lastRetryCost, 1));
        $state->lastRetryCost = 0;
        $state->capacityRemaining = $bucket->capacity();
    }

    /** @inheritDoc */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Return the bucket for `$scope`, evicting the least recently used one
     * when more than `$maxScopes` exist.
     */
    private function bucket(string $scope): RetryTokenBucket
    {
        $bucket = $this->buckets[$scope]
            ?? new RetryTokenBucket($this->initialRetryTokens);
        unset($this->buckets[$scope]);
        $this->buckets[$scope] = $bucket;
        if (\count($this->buckets) > $this->maxScopes) {
            unset($this->buckets[\array_key_first($this->buckets)]);
        }

        return $bucket;
    }

    /**
     * Decide whether `$error` is retryable.
     *
     * @return array{bool, bool, float|null} Retryable, throttling and the
     *         server-suggested wait in seconds.
     */
    private function classify(\Throwable $error): array
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof RetryInfoInterface) {
                return [
                    $e->getRetrySafety() === RetrySafety::Yes,
                    $e->isThrottle(),
                    $e->getRetryAfter()
                ];
            }
        }

        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof NetworkException
                || ($e instanceof ApiException && $e->isServerFault())
            ) {
                return [true, false, null];
            }
        }

        return [false, false, null];
    }

    /**
     * Compute the seconds to wait before attempt number `$attempt`.
     */
    private function backoff(
        int $attempt,
        bool $throttle,
        ?float $retryAfter
    ): float
    {
        $base = $throttle ? $this->throttlingBaseDelay : $this->baseDelay;
        $exponent = \min($attempt, self::RETRIES_ATTEMPTED_CEILING) - 2;
        $delay = \min($base * (1 << \max($exponent, 0)), $this->maxBackoff);

        if ($delay > 0) {
            // Jitter in (0, 1], truncated to whole milliseconds, at least 1.
            $jitter = 1.0 - ($this->random)();
            $delay = \max(1, (int) ($jitter * $delay * 1000)) / 1000;
        }

        if ($retryAfter === null) {
            return $delay;
        }

        return \min(
            \max($retryAfter, $delay),
            $delay + self::RETRY_AFTER_WINDOW
        );
    }
}
