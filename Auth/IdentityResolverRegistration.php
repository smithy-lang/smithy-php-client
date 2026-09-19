<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolver registered into an assembled identity chain.
 */
final class IdentityResolverRegistration
{
    public function __construct(
        public readonly IdentityResolverInterface $resolver,
        public readonly ResolvedIdentityMetadata $metadata,
        public readonly bool $terminal = false
    ) {}

    /**
     * Forward a rejected identity to the registered resolver.
     *
     * @return void
     */
    public function invalidate(IdentityInterface $rejectedIdentity): void
    {
        $this->resolver->invalidate($rejectedIdentity);
    }
}
