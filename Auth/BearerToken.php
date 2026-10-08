<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Core\Shape\SensitiveDataRedactor;

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
        #[\SensitiveParameter]
        private string $token,
        private ?float $expirationTime = null
    )
    {
        if ($token === '') {
            throw new \InvalidArgumentException(
                'Bearer token must not be empty.'
            );
        }
    }

    /**
     * Return a redacted representation for PHP debugging functions.
     *
     * @return array{token: string, expirationTime: ?float}
     */
    public function __debugInfo(): array
    {
        return [
            'token' => SensitiveDataRedactor::REDACTED,
            'expirationTime' => $this->expirationTime
        ];
    }

    /**
     * Return the bearer token value.
     *
     * @return non-empty-string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /** @inheritDoc */
    public function getExpirationTime(): ?float
    {
        return $this->expirationTime;
    }
}
