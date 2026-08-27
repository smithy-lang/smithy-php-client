<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Describe which provider resolved an identity.
 *
 * Future auth/reporting layers use this to surface the winning provider
 * name and emit provider-specific feature IDs into user-agent telemetry
 * without changing {@see IdentityResolverInterface}'s return type.
 */
final class ResolvedIdentityMetadata
{
    /**
     * @param non-empty-string $providerName Canonical provider name.
     * @param list<string> $featureIds Provider feature IDs.
     */
    public function __construct(
        public readonly string $providerName,
        public readonly array $featureIds = []
    )
    {
        if ($providerName === '') {
            throw new \InvalidArgumentException(
                'providerName must not be empty.'
            );
        }
    }
}
