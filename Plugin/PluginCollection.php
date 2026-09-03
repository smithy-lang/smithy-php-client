<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\ClientConfig;

/**
 * Apply an ordered collection of client plugins.
 *
 * The first instance of each concrete plugin class wins. Additions return a
 * new collection, leaving the original unchanged.
 */
final readonly class PluginCollection
{
    /** @var list<PluginInterface> */
    private array $plugins;

    /** @param PluginInterface ...$plugins Plugins in application order. */
    public function __construct(PluginInterface ...$plugins)
    {
        $unique = [];
        $seen = [];
        foreach ($plugins as $plugin) {
            $class = $plugin::class;
            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $unique[] = $plugin;
        }

        $this->plugins = $unique;
    }

    /**
     * Return a collection with the plugin appended if its class is absent.
     */
    public function add(PluginInterface $plugin): self
    {
        if ($this->contains($plugin)) {
            return $this;
        }

        return new self(...[...$this->plugins, $plugin]);
    }

    /**
     * Return a collection with the plugin prepended if its class is absent.
     */
    public function prepend(PluginInterface $plugin): self
    {
        if ($this->contains($plugin)) {
            return $this;
        }

        return new self($plugin, ...$this->plugins);
    }

    /**
     * Apply each plugin in collection order.
     */
    public function apply(ClientConfig $config): ClientConfig
    {
        foreach ($this->plugins as $plugin) {
            $config = $plugin->configure($config);
        }

        return $config;
    }

    private function contains(PluginInterface $plugin): bool
    {
        foreach ($this->plugins as $existing) {
            if ($existing::class === $plugin::class) {
                return true;
            }
        }

        return false;
    }
}
