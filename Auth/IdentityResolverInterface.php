<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolve an {@see IdentityInterface} from ambient context.
 *
 * An expected source miss is returned as an {@see IdentityResult} without an
 * identity. Unexpected or unrecoverable failures are thrown.
 *
 * Properties passed to `resolveIdentity` are identity-specific defaults
 * supplied by the auth scheme. Signer properties and endpoint-selected
 * signing overrides are not included.
 *
 * @template-covariant TIdentity of IdentityInterface
 */
interface IdentityResolverInterface
{
    /**
     * Resolve an identity result from the supplied properties.
     *
     * @param array<string, mixed> $properties Identity-specific properties.
     * @return IdentityResult<TIdentity>
     * @throws \Throwable On unexpected or unrecoverable failure.
     */
    public function resolveIdentity(array $properties = []): IdentityResult;

    /**
     * Invalidate an identity rejected by a service if it is still current.
     *
     * Resolvers without mutable identity state should implement this as a
     * no-op.
     *
     * @return void
     */
    public function invalidate(IdentityInterface $rejectedIdentity): void;
}
