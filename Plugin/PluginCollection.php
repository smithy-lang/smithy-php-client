<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\AbstractClientConfig;

/**
 * Store client plugins in registration order.
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
     * Append plugins whose concrete classes are not already registered.
     */
    public function merge(self $plugins): self
    {
        $merged = $this;
        foreach ($plugins->plugins as $plugin) {
            $merged = $merged->add($plugin);
        }

        return $merged;
    }

    /**
     * Return whether the collection contains no plugins.
     */
    public function isEmpty(): bool
    {
        return $this->plugins === [];
    }

    /**
     * Return plugins in registration order.
     *
     * @return list<PluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Resolve and apply this collection to a client configuration.
     */
    public function apply(AbstractClientConfig $config): AbstractClientConfig
    {
        return (new PluginResolver())
            ->resolve($this, $config->getPluginState())
            ->apply($config);
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
