<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\Transport\TransportRequestInterface;

/**
 * No-op signer. Returns the request unchanged.
 *
 * Used by {@see NoAuthScheme} for anonymous operations, and by tests that
 * exercise the pipeline without exercising signing logic.
 */
final class NullSigner implements SignerInterface
{
    private static ?self $instance = null;

    /**
     * Return the shared null signer.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    /** @inheritDoc */
    public function sign(
        TransportRequestInterface $request,
        IdentityInterface $identity,
        array $properties = []
    ): SignerResult
    {
        return new SignerResult($request);
    }

    /** @inheritDoc */
    public function close(): void {}
}
