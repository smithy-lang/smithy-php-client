<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolve an {@see IdentityInterface} from ambient context.
 *
 * An expected source miss is returned as an {@see IdentityResult} without an
 * identity. Unexpected or unrecoverable failures are thrown.
 *
 * Properties passed to `resolveIdentity` are identity-specific defaults
 * supplied by the auth scheme. Signer properties and endpoint-selected
 * signing overrides are not included.
 */
interface IdentityResolverInterface
{
    /**
     * Resolve an identity result from the supplied properties.
     *
     * @param array<string, mixed> $properties Identity-specific properties.
     * @throws \Throwable On unexpected or unrecoverable failure.
     */
    public function resolveIdentity(array $properties = []): IdentityResult;
}
