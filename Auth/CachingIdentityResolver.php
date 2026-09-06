<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Cache identities returned by another resolver until they require refresh.
 *
 * Cache entries are scoped to the resolution properties. Identities without
 * an expiration remain cached indefinitely.
 */
final class CachingIdentityResolver implements IdentityResolverInterface
{
    private ?IdentityInterface $cached = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedProperties = null;

    /**
     * @param int $refreshWindowSeconds Refresh this many seconds before
     *                                 expiration.
     */
    public function __construct(
        private readonly IdentityResolverInterface $inner,
        private readonly int $refreshWindowSeconds = 300
    )
    {
        if ($refreshWindowSeconds < 0) {
            throw new \InvalidArgumentException(
                'Refresh window must be non-negative.'
            );
        }
    }

    /** @inheritDoc */
    public function resolveIdentity(array $properties = []): IdentityInterface
    {
        if ($this->cached !== null
            && $this->cachedProperties === $properties
            && !$this->requiresRefresh($this->cached)
        ) {
            return $this->cached;
        }

        $this->cached = $this->inner->resolveIdentity($properties);
        $this->cachedProperties = $properties;

        return $this->cached;
    }

    /**
     * Return the wrapped resolver.
     */
    public function getInner(): IdentityResolverInterface
    {
        return $this->inner;
    }

    private function requiresRefresh(IdentityInterface $identity): bool
    {
        $expiry = $identity->getExpirationTime();
        if ($expiry === null) {
            return false;
        }

        return $expiry - \microtime(true) <= $this->refreshWindowSeconds;
    }
}
