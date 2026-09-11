<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Selected auth scheme and its effective identity and signer properties.
 */
final readonly class ResolvedAuthScheme
{
    /**
     * @param array<string, mixed> $identityProperties
     * @param array<string, mixed> $signerProperties
     */
    public function __construct(
        public AuthSchemeInterface $scheme,
        public array $identityProperties = [],
        public array $signerProperties = []
    ) {}
}
