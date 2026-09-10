<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\ClientConfig;

/**
 * An ordered set of plugins and the state produced while resolving it.
 */
final readonly class ResolvedPlugins
{
    /**
     * @param list<PluginInterface> $plugins
     */
    public function __construct(
        private array $plugins,
        public PluginState $state
    ) {}

    /**
     * Apply the resolved plugins to an immutable client configuration.
     */
    public function apply(ClientConfig $config): ClientConfig
    {
        $config = $config->withResolvedPluginState($this->state);
        foreach ($this->plugins as $plugin) {
            $config = $plugin->configure($config);
        }

        return $config;
    }

    /**
     * Return the plugins in application order.
     *
     * @return list<PluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }
}
