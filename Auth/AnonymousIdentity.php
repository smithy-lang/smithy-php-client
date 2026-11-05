<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Empty identity used for unsigned or anonymous operations.
 *
 * Paired with {@see NullSigner} in the {@see NoAuthScheme}.
 */
final class AnonymousIdentity implements IdentityInterface
{
    private static ?self $instance = null;

    /**
     * Return the shared anonymous identity.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    /** @inheritDoc */
    public function getExpirationTime(): ?float
    {
        return null;
    }
}
