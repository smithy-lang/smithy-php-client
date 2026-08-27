<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

/**
 * A resolved endpoint for an operation.
 *
 * Produced by an {@see EndpointResolverInterface} inside the attempt loop.
 * The pipeline uses `url` as the base for request URI construction; the
 * protocol layer prepends any endpoint path onto the operation's HTTP path.
 *
 * Auth schemes carried on the endpoint override the client's default
 * auth-scheme resolver decision for the execution. Headers are merged
 * into the request before signing.
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
