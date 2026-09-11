<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * A resolved endpoint for an operation.
 *
 * Produced by an {@see EndpointResolverInterface} for each request attempt.
 * The protocol layer combines `url` with the operation-relative URI created
 * during serialization.
 *
 * Endpoint auth schemes provide deprecated compatibility overrides for the
 * resolver-selected scheme. Headers are merged into the request before
 * signing.
 */
final class Endpoint
{
    /**
     * Create a resolved endpoint.
     *
     * @param list<EndpointAuthScheme> $authSchemes
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $authSchemes = [],
        public readonly array $headers = []
    ) {}
}
