<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Resolve the singleton {@see AnonymousIdentity}.
 *
 * @implements IdentityResolverInterface<AnonymousIdentity>
 */
final class AnonymousIdentityResolver implements IdentityResolverInterface
{
    private static ?self $instance = null;

    /**
     * Return the shared anonymous identity resolver.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    /** @inheritDoc */
    public function resolveIdentity(array $properties = []): IdentityResult
    {
        return IdentityResult::success(AnonymousIdentity::instance());
    }

    /** @inheritDoc */
    public function invalidate(IdentityInterface $rejectedIdentity): void {}
}
