<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Represent an opaque bearer token identity.
 */
final readonly class BearerToken implements IdentityInterface
{
    /**
     * Create a bearer token.
     *
     * @param non-empty-string $token
     */
    public function __construct(
        public string $token,
        private ?float $expirationTime = null
    )
    {
        if ($token === '') {
            throw new \InvalidArgumentException(
                'Bearer token must not be empty.'
            );
        }
    }

    /** @inheritDoc */
    public function getExpirationTime(): ?float
    {
        return $this->expirationTime;
    }
}
