<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Compute waiter retry delays using exponential backoff with full jitter.
 *
 * @internal
 */
final readonly class Backoff
{
    /**
     * Create a waiter backoff calculator.
     *
     * @param int<1, max> $minDelay Minimum delay in seconds.
     * @param int<1, max> $maxDelay Maximum delay in seconds.
     *
     * @throws \InvalidArgumentException When delay bounds are invalid.
     */
    public function __construct(
        public int $minDelay,
        public int $maxDelay
    )
    {
        if ($minDelay < 1) {
            throw new \InvalidArgumentException(
                'Minimum waiter delay must be at least 1 second.'
            );
        }

        if ($maxDelay < $minDelay) {
            throw new \InvalidArgumentException(
                'Maximum waiter delay must be at least the minimum delay.'
            );
        }
    }

    /**
     * Compute the delay before a numbered retry attempt.
     *
     * @param int<1, max> $attempt Retry attempt number.
     * @param float $remainingTime Seconds remaining before the deadline.
     *
     * @return float Delay in seconds.
     * @throws \InvalidArgumentException When `$attempt` or `$remainingTime`
     *                                   is invalid.
     */
    public function computeDelay(int $attempt, float $remainingTime): float
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException(
                'Waiter retry attempt must be at least 1.'
            );
        }

        if ($remainingTime <= 0.0 || !\is_finite($remainingTime)) {
            throw new \InvalidArgumentException(
                'Remaining waiter time must be finite and greater than zero.'
            );
        }

        $attemptCeiling = (
            \log($this->maxDelay / $this->minDelay) / \log(2)
        ) + 1;

        $upperBound = $attempt > $attemptCeiling
            ? $this->maxDelay
            : $this->minDelay * (2 ** ($attempt - 1));
        $upperBound = (int) \min($upperBound, $this->maxDelay);

        $delay = \random_int($this->minDelay, $upperBound);
        if ($remainingTime - $delay <= $this->minDelay) {
            return $remainingTime;
        }

        return (float) $delay;
    }
}
