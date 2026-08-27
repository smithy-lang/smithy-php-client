<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Core\Shape\OperationInterface;

/**
 * Choose which auth schemes to try for an operation, in priority order.
 *
 * Returns a list of scheme IDs. The pipeline walks the list and uses the
 * first scheme it finds in the client's supported schemes. Endpoint-
 * supplied {@see \Smithy\Client\Endpoint\EndpointAuthScheme}s take
 * precedence over this resolver for the attempts that use that endpoint.
 */
interface AuthSchemeResolverInterface
{
    /**
     * Resolve auth schemes for an operation.
     *
     * @return list<string> Auth scheme shape IDs in priority order.
     */
    public function resolveAuthScheme(
        OperationInterface $operation,
        ClientConfig $config
    ): array;
}
