<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Selected auth scheme and its per-request properties.
 */
final readonly class ResolvedAuthScheme
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public AuthSchemeInterface $scheme,
        public array $properties = []
    ) {}
}
