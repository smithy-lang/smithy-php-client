<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * Carry an auth scheme override attached to a resolved {@see Endpoint}.
 *
 * Endpoint rulesets can augment or replace the auth scheme selected before
 * endpoint resolution. This is deprecated compatibility behavior; new
 * services should select schemes through an
 * {@see \Smithy\Client\Auth\AuthSchemeResolverInterface}. The scheme ID is
 * the canonical Smithy shape ID used by
 * {@see \Smithy\Client\Auth\AuthSchemeInterface::getSchemeId()}.
 */
final class EndpointAuthScheme
{
    /**
     * Create an endpoint auth-scheme override.
     *
     * @param array<string, mixed> $properties Signer property overrides.
     */
    public function __construct(
        public readonly string $schemeId,
        public readonly array $properties = []
    ) {}
}
