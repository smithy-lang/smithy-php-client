<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Immutable plugin registration and application state.
 */
final readonly class PluginState
{
    /**
     * @param array<class-string<PluginInterface>, true> $appliedClasses
     */
    public function __construct(
        public PluginCollection $plugins = new PluginCollection(),
        public array $appliedClasses = [],
        public ?\Closure $predicate = null
    ) {}

    /**
     * Create the initial state for a client configuration.
     */
    public static function defaults(): self
    {
        return new self(
            new PluginCollection(DefaultPlugin::instance())
        );
    }

    /**
     * Return state with newly registered plugins appended.
     */
    public function withPlugins(PluginCollection $plugins): self
    {
        if ($plugins->isEmpty()) {
            return $this;
        }

        $merged = $this->plugins->merge($plugins);
        if ($merged === $this->plugins) {
            return $this;
        }

        return new self(
            $merged,
            $this->appliedClasses,
            $this->predicate
        );
    }

    /**
     * Return state with the applied classes replaced.
     *
     * @param array<class-string<PluginInterface>, true> $appliedClasses
     */
    public function withAppliedClasses(array $appliedClasses): self
    {
        if ($appliedClasses === $this->appliedClasses) {
            return $this;
        }

        return new self(
            $this->plugins,
            $appliedClasses,
            $this->predicate
        );
    }

    /**
     * Return state with the plugin predicate replaced.
     */
    public function withPredicate(?\Closure $predicate): self
    {
        if ($predicate === $this->predicate) {
            return $this;
        }

        return new self(
            $this->plugins,
            $this->appliedClasses,
            $predicate
        );
    }

    /**
     * Return whether the plugin is accepted by the configured predicate.
     */
    public function accepts(PluginInterface $plugin): bool
    {
        return $this->predicate === null
            || ($this->predicate)($plugin);
    }
}
