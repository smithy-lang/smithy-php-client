<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\ClientConfig;

/**
 * Base class that supplies default plugin composition and ordering.
 */
abstract class AbstractPlugin implements PluginInterface
{
    /** @inheritDoc */
    public function configure(ClientConfig $config): ClientConfig
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
