<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Client\Interceptor\OutputContext;

/**
 * Result of executing one transmission attempt.
 *
 * @internal
 */
final readonly class AttemptResult
{
    public function __construct(
        public OutputContext $outputContext,
        public ?\Throwable $error
    ) {}
}
