<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * Carry an auth scheme override attached to a resolved {@see Endpoint}.
 *
 * Endpoint rulesets can direct the client to use a specific auth scheme
 * for a given endpoint. The pipeline consults endpoint-provided schemes
 * before falling back to the client's default
 * {@see \Smithy\Client\Auth\AuthSchemeResolverInterface}. The scheme ID is
 * the canonical Smithy shape ID used by
 * {@see \Smithy\Client\Auth\AuthSchemeInterface::getSchemeId()}.
 */
final class EndpointAuthScheme
{
    /**
     * Create an endpoint auth-scheme override.
     *
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public readonly string $schemeId,
        public readonly array $properties = []
    ) {}
}
