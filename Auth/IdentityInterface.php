<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * The caller's identity, produced by an {@see IdentityResolverInterface}.
 *
 * Concrete identities carry the material a {@see SignerInterface} needs
 * to authenticate a request. Examples include `BearerToken`,
 * `AnonymousIdentity`, and vendor-defined credential types.
 *
 * An identity may have an expiration time; the pipeline (or a caching
 * layer wrapping the resolver) can refresh before it passes.
 */
interface IdentityInterface
{
    /**
     * The moment this identity stops being valid as a Unix epoch value
     * (seconds, float allows sub-second precision), or null if it never
     * expires.
     *
     * Float matches the rest of the runtime's timestamp convention.
     */
    public function getExpirationTime(): ?float;
}
