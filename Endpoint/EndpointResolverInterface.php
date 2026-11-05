<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

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
     * Resolve the endpoint for a request attempt.
     */
    public function resolveEndpoint(EndpointResolverParams $params): Endpoint;
}
