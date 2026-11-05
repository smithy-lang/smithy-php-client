<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

use Smithy\Client\Endpoint\Endpoint;

/**
 * Records one endpoint rules-engine evaluation.
 *
 * A trace is created for one resolution and receives callbacks in evaluation
 * order. Implementations may retain these values for diagnostics, but should
 * avoid logging endpoint parameters indiscriminately.
 */
interface EndpointResolutionTraceInterface
{
    /**
     * Record the effective modeled input parameters after defaults.
     *
     * @param array<string, mixed> $parameters
     */
    public function parameters(array $parameters): void;

    /**
     * Record a visited BDD node and the selected branch.
     */
    public function node(
        int $nodeReference,
        int $conditionIndex,
        bool $satisfied,
        bool $branch
    ): void;

    /**
     * Record the terminal result of the evaluation.
     *
     * `$resultIndex` is -1 when no endpoint matched. `$endpoint` is null when
     * no endpoint was produced.
     */
    public function result(int $resultIndex, ?Endpoint $endpoint): void;
}
