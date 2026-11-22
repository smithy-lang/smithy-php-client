<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\AbstractClientConfig;
use Smithy\Client\Interceptor\RetrySafetyInterceptor;

/**
 * Register modeled retry info: `@readonly`/`@idempotent` operations and
 * `@retryable` errors. On by default through {@see DefaultPlugin}.
 */
final class RetrySafetyPlugin extends AbstractPlugin
{
    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Access the shared plugin instance.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @inheritDoc */
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        if ($config->interceptorChain->contains(
            RetrySafetyInterceptor::class
        )) {
            return $config;
        }

        return $config->withInterceptor(new RetrySafetyInterceptor());
    }
}
