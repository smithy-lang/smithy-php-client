<?php

declare(strict_types=1);

namespace Smithy\Client\Retry;

/**
 * Strategy for retrying failed attempts.
 *
 * The pipeline calls {@see begin()} once before the first attempt and
 * threads the returned {@see RetryState} through the loop. After each
 * failure, it hands the state and error to {@see refresh()}, which
 * either returns the backoff delay before the next attempt or throws
 * {@see TokenAcquisitionFailedException} to indicate no more retries.
 *
 * On success, the pipeline calls {@see recordSuccess()} so the strategy
 * can restore capacity to its token bucket.
 *
 * Smithy PHP provides {@see NoRetryStrategy} as an explicit single-attempt
 * implementation. Integrations can provide retrying strategies by
 * implementing this interface.
 */
interface RetryStrategyInterface
{
    /**
     * @param string $scope Retry scope (e.g., an ARN or service name).
     *               Multiple executions with the same scope share a token
     *               bucket.
     * @param int $requestFlags Strategy-specific request flags.
     */
    public function begin(string $scope, int $requestFlags = 0): RetryState;

    /**
     * Refresh the retry state after a failed attempt.
     *
     * @return float The number of seconds to wait before retrying.
     * @throws TokenAcquisitionFailedException If retries are exhausted or
     *                                         the error is non-retryable.
     */
    public function refresh(RetryState $state, \Throwable $error): float;

    /**
     * Record a successful attempt so the strategy can return capacity
     * to its token bucket. No-op for strategies without a bucket.
     */
    public function recordSuccess(RetryState $state): void;

    /**
     * Return the maximum number of attempts, including the initial attempt.
     */
    public function maxAttempts(): int;
}
