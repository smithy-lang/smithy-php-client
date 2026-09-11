<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Client\Endpoint\EndpointAuthScheme;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve the supported auth scheme used for an operation.
 */
interface AuthSchemeResolverInterface
{
    /**
     * Resolve an auth scheme for an operation.
     *
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no scheme can be resolved.
     */
    public function resolveAuthScheme(
        OperationInterface $operation,
        ClientConfig $config,
        array $preference = []
    ): ResolvedAuthScheme;

    /**
     * Reconcile a resolved auth scheme with endpoint auth options.
     *
     * @param list<EndpointAuthScheme> $endpointAuthSchemes
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no endpoint scheme can be resolved.
     */
    public function resolveEndpointAuthScheme(
        ResolvedAuthScheme $resolvedAuthScheme,
        OperationInterface $operation,
        ClientConfig $config,
        array $endpointAuthSchemes,
        array $preference = []
    ): ResolvedAuthScheme;
}
