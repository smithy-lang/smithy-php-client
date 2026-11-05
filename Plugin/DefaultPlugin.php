<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\ClientConfig;

/**
 * Register the framework plugins applied to every client.
 */
final class DefaultPlugin extends AbstractPlugin
{
    private static ?self $instance = null;

    private function __construct() {}

    /**
     * Access the shared default plugin.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @inheritDoc */
    public function configure(ClientConfig $config): ClientConfig
    {
        return $config;
    }

    /** @inheritDoc */
    public function getChildPlugins(): array
    {
        return [
            IdempotencyTokenPlugin::instance(),
            AutoPlugin::instance()
        ];
    }

    /** @inheritDoc */
    public function getPluginPhase(): PluginPhase
    {
        return PluginPhase::First;
    }
}
