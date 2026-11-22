<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\AbstractClientConfig;
use Smithy\Client\Interceptor\RequestCompressionInterceptor;

/**
 * Register modeled request compression.
 */
final class RequestCompressionPlugin extends AbstractPlugin
{
    public const CONFIG_DISABLE_REQUEST_COMPRESSION =
        'DisableRequestCompression';
    public const CONFIG_REQUEST_MIN_COMPRESSION_SIZE_BYTES =
        'RequestMinCompressionSizeBytes';
    public const DEFAULT_MIN_COMPRESSION_SIZE_BYTES = 10240;
    public const MAX_MIN_COMPRESSION_SIZE_BYTES = 10485760;

    /**
     * @param bool|null $disabled Override the configured disable setting.
     * @param int|null $minCompressionSizeBytes Override the minimum body size.
     */
    public function __construct(
        private readonly ?bool $disabled = null,
        private readonly ?int $minCompressionSizeBytes = null
    ) {}

    /**
     * @inheritDoc
     *
     * @throws \InvalidArgumentException If compression settings are invalid.
     */
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        return $config->withInterceptor(
            new RequestCompressionInterceptor(
                disabled: $this->resolveDisableRequestCompression($config),
                minCompressionSizeBytes:
                    $this->resolveRequestMinCompressionSizeBytes($config)
            )
        );
    }

    /** @inheritDoc */
    public function getPluginPhase(): PluginPhase
    {
        return PluginPhase::BeforeApply;
    }

    private function resolveDisableRequestCompression(
        AbstractClientConfig $config
    ): bool
    {
        if ($this->disabled !== null) {
            return $this->disabled;
        }

        $value = $config->context[
            self::CONFIG_DISABLE_REQUEST_COMPRESSION
        ] ?? null;
        if ($value === null) {
            return false;
        }

        if ($value === true || $value === 'true') {
            return true;
        }

        if ($value === false || $value === 'false') {
            return false;
        }

        throw new \InvalidArgumentException(
            self::CONFIG_DISABLE_REQUEST_COMPRESSION
            . ' must be true or false.'
        );
    }

    private function resolveRequestMinCompressionSizeBytes(
        AbstractClientConfig $config
    ): int
    {
        $value = $this->minCompressionSizeBytes
            ?? $config->context[self::CONFIG_REQUEST_MIN_COMPRESSION_SIZE_BYTES]
            ?? self::DEFAULT_MIN_COMPRESSION_SIZE_BYTES;

        if (!\is_int($value)) {
            if (!\is_string($value) || $value === '' || !\ctype_digit($value)) {
                throw new \InvalidArgumentException(
                    self::CONFIG_REQUEST_MIN_COMPRESSION_SIZE_BYTES
                    . ' must be an integer between 0 and '
                    . self::MAX_MIN_COMPRESSION_SIZE_BYTES
                    . '.'
                );
            }

            $value = (int) $value;
        }

        if ($value < 0 || $value > self::MAX_MIN_COMPRESSION_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                self::CONFIG_REQUEST_MIN_COMPRESSION_SIZE_BYTES
                . ' must be an integer between 0 and '
                . self::MAX_MIN_COMPRESSION_SIZE_BYTES
                . '.'
            );
        }

        return $value;
    }
}
