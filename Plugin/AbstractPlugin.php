<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Base class that supplies default plugin composition and ordering.
 */
abstract class AbstractPlugin implements PluginInterface
{
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
