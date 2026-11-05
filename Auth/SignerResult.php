<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\Transport\TransportRequestInterface;

/**
 * Result of signing a transport request.
 *
 * The signature is empty when the selected auth scheme does not produce a
 * reusable signature value.
 */
final readonly class SignerResult
{
    public function __construct(
        public TransportRequestInterface $signedRequest,
        public string $signature = ''
    ) {}
}
