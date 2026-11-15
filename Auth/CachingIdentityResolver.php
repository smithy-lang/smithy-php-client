<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Cache identities returned by another resolver until they require refresh.
 *
 * Cache entries are scoped to the resolution properties. Identities without
 * an expiration remain cached indefinitely unless a matching rejected
 * identity marks the entry for refresh.
 *
 * @template TIdentity of IdentityInterface
 * @implements IdentityResolverInterface<TIdentity>
 */
final class CachingIdentityResolver implements IdentityResolverInterface
{
    /** @var IdentityResult<TIdentity>|null */
    private ?IdentityResult $cached = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedProperties = null;

    private bool $refreshRequired = false;

    /** @var \Closure(TIdentity, IdentityInterface): bool */
    private readonly \Closure $identityMatcher;

    /**
     * @param IdentityResolverInterface<TIdentity> $inner
     * @param int $refreshWindowSeconds Refresh this many seconds before
     *                                 expiration.
     * @param null|callable(TIdentity, IdentityInterface): bool
     *     $identityMatcher Compare the cached identity to a rejected identity.
     */
    public function __construct(
        private readonly IdentityResolverInterface $inner,
        private readonly int $refreshWindowSeconds = 300,
        ?callable $identityMatcher = null
    )
    {
        if ($refreshWindowSeconds < 0) {
            throw new \InvalidArgumentException(
                'Refresh window must be non-negative.'
            );
        }

        $this->identityMatcher = $identityMatcher === null
            ? static fn(
                IdentityInterface $cached,
                IdentityInterface $rejected
            ): bool => $cached === $rejected
            : \Closure::fromCallable($identityMatcher);
    }

    /** @inheritDoc */
    public function resolveIdentity(array $properties = []): IdentityResult
    {
        if ($this->cached !== null
            && $this->cachedProperties === $properties
            && !$this->refreshRequired
            && !$this->requiresRefresh($this->cached->identity)
        ) {
            return $this->cached;
        }

        $result = $this->inner->resolveIdentity($properties);
        if ($result->identity === null) {
            if (!$this->refreshRequired) {
                $this->cached = null;
                $this->cachedProperties = null;
            }

            return $result;
        }

        $this->cached = $result;
        $this->cachedProperties = $properties;
        $this->refreshRequired = false;

        return $result;
    }

    /**
     * Return the wrapped resolver.
     *
     * @return IdentityResolverInterface<TIdentity>
     */
    public function getInner(): IdentityResolverInterface
    {
        return $this->inner;
    }

    /** @inheritDoc */
    public function invalidate(IdentityInterface $rejectedIdentity): void
    {
        if ($this->cached !== null
            && ($this->identityMatcher)(
                $this->cached->identity,
                $rejectedIdentity
            )
        ) {
            $this->refreshRequired = true;
        }

        $this->inner->invalidate($rejectedIdentity);
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
