<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Client\Auth\{
    IdentityInterface,
    IdentityResolverInterface,
    ResolvedAuthScheme
};
use Smithy\Client\Transport\TransportRequestInterface;

/**
 * Values resolved while preparing one transmission attempt.
 *
 * @internal
 */
final readonly class PreparedAttempt
{
    public function __construct(
        public TransportRequestInterface $request,
        public ResolvedAuthScheme $authScheme,
        public IdentityResolverInterface $identityResolver,
        public IdentityInterface $identity
    ) {}
}
