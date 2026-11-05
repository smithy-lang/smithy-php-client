<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Pair a waiter state with the condition that selects it.
 */
final readonly class Acceptor
{
    /** Transition the waiter to terminal success. */
    public const SUCCESS = 0;
    /** Transition the waiter to terminal failure. */
    public const FAILURE = 1;
    /** Continue polling after the configured delay. */
    public const RETRY = 2;

    /**
     * Create a waiter acceptor.
     *
     * @param self::SUCCESS|self::FAILURE|self::RETRY $state
     * @param string|null $message Failure-message JMESPath expression.
     *
     * @throws \InvalidArgumentException When `$state` is not recognized or
     *                                   `$message` is used on another state.
     */
    public function __construct(
        public int $state,
        public Matcher $matcher,
        public ?string $message = null
    )
    {
        if ($state < self::SUCCESS || $state > self::RETRY) {
            throw new \InvalidArgumentException(
                "Unknown waiter acceptor state: {$state}."
            );
        }

        if ($message !== null && $state !== self::FAILURE) {
            throw new \InvalidArgumentException(
                'Waiter acceptor messages require a failure state.'
            );
        }
    }
}
