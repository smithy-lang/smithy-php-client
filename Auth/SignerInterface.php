<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Core\Transport\TransportRequestInterface;

/**
 * Sign a transport request using a resolved {@see IdentityInterface}.
 *
 * Called once per attempt from inside the retry loop, after any
 * `modifyBeforeSigning` interceptor has run. Retried attempts sign
 * fresh. Timestamps and nonces are recomputed each time.
 */
interface SignerInterface
{
    /**
     * @param array<string, mixed> $properties Effective signing properties
     *                                         from the auth scheme and
     *                                         endpoint.
     */
    public function sign(
        TransportRequestInterface $request,
        IdentityInterface $identity,
        array $properties = []
    ): SignerResult;
}
