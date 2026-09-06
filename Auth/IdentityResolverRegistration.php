<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolver registered into an assembled identity chain.
 */
final class IdentityResolverRegistration
{
    /**
     * @param null|callable(): void $invalidator Optional invalidation hook for
     *                                          chain-level refresh events.
     */
    public function __construct(
        public readonly IdentityResolverInterface $resolver,
        public readonly ResolvedIdentityMetadata $metadata,
        public readonly bool $terminal = false,
        private readonly mixed $invalidator = null
    )
    {
        if ($invalidator !== null && !\is_callable($invalidator)) {
            throw new \InvalidArgumentException(
                'Identity resolver invalidator must be callable.'
            );
        }
    }

    /**
     * Run the invalidation hook when one is registered.
     *
     * @return void
     */
    public function invalidate(): void
    {
        if ($this->invalidator !== null) {
            ($this->invalidator)();
        }
    }
}
