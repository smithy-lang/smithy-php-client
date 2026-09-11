<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Candidate auth scheme and its identity and signer property overrides.
 */
final readonly class AuthSchemeOption
{
    /**
     * @param array<string, mixed> $identityPropertyOverrides
     * @param array<string, mixed> $signerPropertyOverrides
     */
    public function __construct(
        public string $schemeId,
        public array $identityPropertyOverrides = [],
        public array $signerPropertyOverrides = []
    ) {}
}
