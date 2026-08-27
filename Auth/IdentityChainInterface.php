<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Long-lived chain of ordered identity resolvers.
 *
 * Implementations assemble provider registrations once, then reuse the same
 * chain across requests. The chain itself remains an
 * {@see IdentityResolverInterface}; callers need not know whether the
 * underlying identity came from a single resolver or a composed chain.
 */
interface IdentityChainInterface extends IdentityResolverInterface
{
    /**
     * Invalidate any chain-level caches or registered refresh hooks.
     *
     * @return void
     */
    public function invalidate(): void;

    /**
     * Metadata for the most recent successful resolution, or null when the
     * chain has not yet resolved an identity or has been invalidated.
     */
    public function getLastResolvedMetadata(): ?ResolvedIdentityMetadata;
}
