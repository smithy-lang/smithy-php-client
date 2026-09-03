<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * Resolve an endpoint for an operation.
 *
 * Called once per operation execution before the retry loop. Retried
 * attempts reuse the resolved endpoint.
 *
 * Implementations:
 *  - {@see StaticEndpointResolver} always returns the same endpoint.
 *  - Generated ruleset resolvers evaluate Smithy BDD endpoint rules.
 */
interface EndpointResolverInterface
{
    /**
     * Resolve the endpoint for an operation execution.
     */
    public function resolveEndpoint(EndpointResolverParams $params): Endpoint;
}
