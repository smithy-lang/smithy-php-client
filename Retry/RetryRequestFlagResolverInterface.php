<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Retry;

use Smithy\Client\AbstractClientConfig;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve retry-strategy request flags for an operation execution.
 *
 * Implementations run once per SDK operation invocation, before the
 * first attempt begins. The returned bitset is threaded into
 * {@see RetryStrategyInterface::begin()} via {@see RetryState}.
 */
interface RetryRequestFlagResolverInterface
{
    public const CONFIG_KEY = 'smithy.client.retryRequestFlagResolver';

    /**
     * Return retry-strategy flags for `$operation`.
     */
    public function resolve(
        OperationInterface $operation,
        AbstractClientConfig $config
    ): int;
}
