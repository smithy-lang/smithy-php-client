<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
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
}
