<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

use Smithy\Client\ClientConfig;
use Smithy\Core\Endpoint\Endpoint;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve an endpoint for an operation.
 *
 * Called once for each request attempt inside the retry loop.
 *
 * Implementations:
 *  - {@see StaticEndpointResolver} always returns the same endpoint.
 *  - Generated ruleset resolvers evaluate Smithy BDD endpoint rules.
 */
interface EndpointResolverInterface
{
    /**
     * ClientConfig::context key containing endpoint parameter values.
     */
    public const CONTEXT_PARAMETERS = 'smithy.endpointParameters';

    /**
     * ClientConfig::context key containing an endpoint resolution trace sink.
     */
    public const CONTEXT_TRACE_SINK = 'smithy.endpointResolutionTraceSink';

    /**
     * Resolve the endpoint for a request attempt.
     *
     * Modeled endpoint resolvers combine operation and input traits with
     * endpoint parameters stored in `config`. Static resolvers ignore all
     * arguments.
     */
    public function resolveEndpoint(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ClientConfig $config
    ): Endpoint;
}
