<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\AbstractClientConfig;
use Smithy\Client\Interceptor\IdempotencyTokenInterceptor;

/**
 * Register idempotency-token autofill for modeled request inputs.
 */
final class IdempotencyTokenPlugin extends AbstractPlugin
{
    public const CONFIG_IDEMPOTENCY_TOKEN_PROVIDER = 'IdempotencyTokenProvider';

    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Access the shared plugin instance.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @inheritDoc
     *
     * @throws \InvalidArgumentException If the configured provider is invalid.
     */
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        if ($config->interceptorChain->contains(
            IdempotencyTokenInterceptor::class
        )) {
            return $config;
        }

        return $config->withInterceptor(
            new IdempotencyTokenInterceptor(
                $this->resolveTokenProvider($config)
            )
        );
    }

    private function resolveTokenProvider(AbstractClientConfig $config): ?\Closure
    {
        $provider = $config->context[self::CONFIG_IDEMPOTENCY_TOKEN_PROVIDER]
            ?? null;

        if ($provider === null) {
            return null;
        }

        if (\is_string($provider)) {
            if ($provider === '') {
                throw new \InvalidArgumentException(
                    self::CONFIG_IDEMPOTENCY_TOKEN_PROVIDER
                    . ' must not be an empty string.'
                );
            }

            return static fn(): string => $provider;
        }

        if (\is_callable($provider)) {
            return \Closure::fromCallable($provider);
        }

        throw new \InvalidArgumentException(
            self::CONFIG_IDEMPOTENCY_TOKEN_PROVIDER
            . ' must be a non-empty string or callable.'
        );
    }
}
