<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * Return the same {@see Endpoint} for every operation.
 *
 * Used when a customer supplies an explicit endpoint override on the
 * client config, or in tests where no ruleset engine is available.
 */
final class StaticEndpointResolver implements EndpointResolverInterface
{
    /**
     * Create a static endpoint resolver.
     */
    public function __construct(
        private readonly Endpoint $endpoint
    ) {}

    /** @inheritDoc */
    public function resolveEndpoint(EndpointResolverParams $params): Endpoint
    {
        return $this->endpoint;
    }
}
