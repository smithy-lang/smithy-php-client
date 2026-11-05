<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Report a waiter that timed out or reached a terminal failure state.
 */
final class WaiterFailureException extends \RuntimeException
{
    /**
     * Create a waiter failure with polling diagnostics.
     *
     * @param int $attempts Number of polling attempts completed.
     * @param float $elapsedTime Total elapsed time in seconds.
     */
    public function __construct(
        string $message,
        public readonly int $attempts,
        public readonly float $elapsedTime,
        ?\Throwable $previous = null
    )
    {
        parent::__construct($message, 0, $previous);
    }
}
