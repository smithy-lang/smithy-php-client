<?php

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
        $resolved = [];
        $sequence = 0;

        foreach ($state->plugins->getPlugins() as $plugin) {
            $this->collect(
                $plugin,
                $state,
                $appliedClasses,
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
        array &$resolved,
        int &$sequence
    ): void
    {
        $class = $plugin::class;
        if (isset($appliedClasses[$class]) || !$state->accepts($plugin)) {
            return;
        }

        $appliedClasses[$class] = true;
        $resolved[] = [
            'plugin' => $plugin,
            'phase' => $plugin->getPluginPhase()->value,
            'sequence' => $sequence++
        ];

        foreach ($plugin->getChildPlugins() as $child) {
            $this->collect(
                $child,
                $state,
                $appliedClasses,
                $resolved,
                $sequence
            );
        }
    }
}
