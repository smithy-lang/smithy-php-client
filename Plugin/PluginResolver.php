<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Resolve plugins into deterministic application order.
 */
final class PluginResolver
{
    /**
     * Resolve newly registered plugins against prior application state.
     */
    public function resolve(
        PluginCollection $plugins,
        PluginState $state
    ): ResolvedPlugins
    {
        $state = $state->withPlugins($plugins);
        $appliedClasses = $state->appliedClasses;
        $visitedClasses = [];
        $resolved = [];
        $sequence = 0;

        foreach ($state->plugins->getPlugins() as $plugin) {
            $this->collect(
                $plugin,
                $state,
                $appliedClasses,
                $visitedClasses,
                $resolved,
                $sequence
            );
        }

        \usort(
            $resolved,
            static fn(array $a, array $b): int =>
                $a['phase'] <=> $b['phase']
                    ?: $a['sequence'] <=> $b['sequence']
        );

        return new ResolvedPlugins(
            \array_map(
                static fn(array $entry): PluginInterface => $entry['plugin'],
                $resolved
            ),
            $state->withAppliedClasses($appliedClasses)
        );
    }

    /**
     * @param array<class-string<PluginInterface>, true> $appliedClasses
     * @param array<class-string<PluginInterface>, true> $visitedClasses
     * @param list<array{
     *     plugin: PluginInterface,
     *     phase: int,
     *     sequence: int
     * }> $resolved
     */
    private function collect(
        PluginInterface $plugin,
        PluginState $state,
        array &$appliedClasses,
        array &$visitedClasses,
        array &$resolved,
        int &$sequence
    ): void
    {
        $class = $plugin::class;
        if (isset($visitedClasses[$class]) || !$state->accepts($plugin)) {
            return;
        }
        $visitedClasses[$class] = true;

        if (!isset($appliedClasses[$class])) {
            $appliedClasses[$class] = true;
            $resolved[] = [
                'plugin' => $plugin,
                'phase' => $plugin->getPluginPhase()->value,
                'sequence' => $sequence++
            ];
        }

        foreach ($plugin->getChildPlugins() as $child) {
            $this->collect(
                $child,
                $state,
                $appliedClasses,
                $visitedClasses,
                $resolved,
                $sequence
            );
        }
    }
}
