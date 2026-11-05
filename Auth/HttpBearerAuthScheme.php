<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;

/**
 * Provide the Smithy HTTP bearer authentication scheme.
 */
final readonly class HttpBearerAuthScheme implements AuthSchemeInterface
{
    public const ID = 'smithy.api#httpBearerAuth';

    /**
     * Create an HTTP bearer auth scheme.
     */
    public function __construct(
        private IdentityResolverInterface $tokenProvider
    ) {}

    /** @inheritDoc */
    public function getSchemeId(): string
    {
        return self::ID;
    }

    /** @inheritDoc */
    public function getIdentityProperties(ClientConfig $config): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getIdentityResolver(
        ClientConfig $config
    ): IdentityResolverInterface
    {
        return $this->tokenProvider;
    }

    /** @inheritDoc */
    public function getSignerProperties(ClientConfig $config): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getSigner(): SignerInterface
    {
        return HttpBearerAuthSigner::instance();
    }
}
