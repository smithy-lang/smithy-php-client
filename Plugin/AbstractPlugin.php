<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\AbstractClientConfig;

/**
 * Base class that supplies default plugin composition and ordering.
 */
abstract class AbstractPlugin implements PluginInterface
{
    /** @inheritDoc */
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        return $config;
    }

    /** @inheritDoc */
    public function getChildPlugins(): array
    {
        return [];
    }

    /** @inheritDoc */
    public function getPluginPhase(): PluginPhase
    {
        return PluginPhase::Apply;
    }
}
