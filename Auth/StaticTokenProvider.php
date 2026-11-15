<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolve one configured bearer token.
 *
 * @implements IdentityResolverInterface<BearerToken>
 */
final readonly class StaticTokenProvider implements IdentityResolverInterface
{
    /** @var IdentityResult<BearerToken> */
    private IdentityResult $result;

    /**
     * Create a static token provider.
     */
    public function __construct(
        #[\SensitiveParameter]
        BearerToken|string $token,
        ?float $expirationTime = null
    )
    {
        $this->result = IdentityResult::success(
            \is_string($token)
                ? new BearerToken($token, $expirationTime)
                : $token
        );
    }

    /** @inheritDoc */
    public function resolveIdentity(
        array $properties = []
    ): IdentityResult
    {
        return $this->result;
    }

    /** @inheritDoc */
    public function invalidate(IdentityInterface $rejectedIdentity): void {}
}
