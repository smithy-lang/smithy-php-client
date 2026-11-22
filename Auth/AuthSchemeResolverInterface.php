<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Auth\ResolvedAuthScheme;
use Smithy\Client\AbstractClientConfig;
use Smithy\Core\Endpoint\EndpointAuthScheme;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve the supported auth scheme used for an operation.
 */
interface AuthSchemeResolverInterface
{
    /**
     * Resolve an auth scheme for an operation.
     *
     * The pipeline caches the result per operation when the request sets no
     * auth scheme preference override, so the result must depend only on the
     * arguments.
     *
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no scheme can be resolved.
     */
    public function resolveAuthScheme(
        OperationInterface $operation,
        AbstractClientConfig $config,
        array $preference = []
    ): ResolvedAuthScheme;

    /**
     * Reconcile a resolved auth scheme with endpoint auth options.
     *
     * @param list<EndpointAuthScheme> $endpointAuthSchemes
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no endpoint scheme can be resolved.
     */
    public function resolveEndpointAuthScheme(
        ResolvedAuthScheme $resolvedAuthScheme,
        OperationInterface $operation,
        AbstractClientConfig $config,
        array $endpointAuthSchemes,
        array $preference = []
    ): ResolvedAuthScheme;
}
