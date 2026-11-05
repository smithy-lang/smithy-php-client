<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use JsonSerializable;
use Smithy\Core\Shape\SensitiveDataRedactor;

/**
 * Represent an opaque bearer token identity.
 */
final readonly class BearerToken implements IdentityInterface, JsonSerializable
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
     * Return a redacted representation for JSON serialization.
     *
     * @return array{token: string, expirationTime: ?float}
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Return a redacted representation for PHP serialization.
     *
     * @return array{token: string, expirationTime: ?float}
     */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Reject restoration from a redacted serialized representation.
     *
     * @param array{token?: string, expirationTime?: ?float} $_data
     */
    public function __unserialize(array $_data): void
    {
        throw new \LogicException(
            'Bearer tokens cannot be restored from PHP serialization.'
        );
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
