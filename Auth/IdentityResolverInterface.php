<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Produce an {@see IdentityInterface} from ambient context.
 *
 * Resolvers wrap credential sources. A chain resolver tries entries in order
 * and returns the first success.
 *
 * Properties passed to `resolveIdentity` are identity-specific defaults
 * supplied by the auth scheme. Signer properties and endpoint-selected
 * signing overrides are not included.
 */
interface IdentityResolverInterface
{
    /**
     * Resolve an identity from the supplied properties.
     *
     * @param array<string, mixed> $properties Identity-specific properties.
     * @throws \Throwable If no identity can be resolved.
     */
    public function resolveIdentity(array $properties = []): IdentityInterface;
}
