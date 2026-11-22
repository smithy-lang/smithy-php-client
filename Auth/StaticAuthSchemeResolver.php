<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Auth\{
    AuthSchemeOption,
    NoAuthScheme
};
use Smithy\Client\AbstractClientConfig;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve from a fixed list of auth scheme IDs or options for every operation.
 *
 * Constructed with candidates in priority order. Useful for explicit
 * overrides and tests.
 */
final class StaticAuthSchemeResolver extends AuthSchemeResolver
{
    /**
     * @param list<string|AuthSchemeOption> $candidates
     */
    public function __construct(
        private readonly array $candidates
    ) {}

    /**
     * Resolver preloaded with only the `smithy.api#noAuth` scheme.
     */
    public static function noAuth(): self
    {
        return new self([NoAuthScheme::ID]);
    }

    /** @return list<AuthSchemeOption> */
    protected function resolveAuthSchemeOptions(
        OperationInterface $operation,
        AbstractClientConfig $config
    ): array
    {
        $options = [];
        foreach ($this->candidates as $candidate) {
            $options[] = \is_string($candidate)
                ? new AuthSchemeOption($candidate)
                : $candidate;
        }

        return $options;
    }
}
