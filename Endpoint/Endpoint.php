<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * A resolved endpoint for an operation.
 *
 * Produced by an {@see EndpointResolverInterface} before the attempt loop
 * and reused for retries. The pipeline uses `url` as the base for request URI
 * construction; the protocol layer prepends any endpoint path onto the
 * operation's HTTP path.
 *
 * The auth-scheme resolver considers schemes carried on the endpoint when
 * selecting authentication for the execution. Headers are merged into the
 * request before signing.
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
