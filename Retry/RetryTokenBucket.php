<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Retry;

use InvalidArgumentException;

/**
 * Hold the retry capacity shared by operations in one retry scope.
 *
 * @internal
 */
final class RetryTokenBucket
{
    private int $capacity;

    /**
     * @param int $maxCapacity Initial and maximum capacity.
     */
    public function __construct(private readonly int $maxCapacity)
    {
        $this->capacity = $maxCapacity;
    }

    /**
     * Remove `$amount` tokens.
     *
     * @return bool False, leaving the bucket unchanged, when the bucket
     *              cannot cover `$amount`.
     */
    public function tryAcquire(int $amount): bool
    {
        if ($amount < 0) {
            throw new InvalidArgumentException(
                'Amount to acquire cannot be negative.'
            );
        }
        if ($amount > $this->capacity) {
            return false;
        }
        $this->capacity -= $amount;

        return true;
    }

    /**
     * Return `$amount` tokens, up to the maximum capacity.
     */
    public function release(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Amount to release must be positive.'
            );
        }
        $this->capacity = \min($this->capacity + $amount, $this->maxCapacity);
    }

    /**
     * Return the tokens currently available.
     */
    public function capacity(): int
    {
        return $this->capacity;
    }
}
