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
        $pending = $this->plugins;
        while ($pending !== []) {
            $plugin = \array_shift($pending);
            $state = $config->getPluginState();
            $config = $plugin->configure($config);
            if ($config->getPluginState() !== $state) {
                $resolved = (new PluginResolver())->resolve(
                    new PluginCollection(),
                    $config->getPluginState()
                );
                $config = $config->withResolvedPluginState($resolved->state);
                $pending = self::mergePlugins(
                    $pending,
                    $resolved->plugins
                );
            }
        }

        return $config;
    }

    /**
     * Merge newly registered plugins into the remaining phase-ordered queue.
     *
     * Existing plugins win equal-phase ties because they were registered
     * before plugins discovered during configuration.
     *
     * @param list<PluginInterface> $pending
     * @param list<PluginInterface> $added
     * @return list<PluginInterface>
     */
    private static function mergePlugins(
        array $pending,
        array $added
    ): array
    {
        $merged = [];
        $pendingIndex = 0;
        $addedIndex = 0;
        $pendingCount = \count($pending);
        $addedCount = \count($added);

        while ($pendingIndex < $pendingCount && $addedIndex < $addedCount) {
            $pendingPhase = $pending[$pendingIndex]
                ->getPluginPhase()
                ->value;
            $addedPhase = $added[$addedIndex]
                ->getPluginPhase()
                ->value;
            if ($pendingPhase <= $addedPhase) {
                $merged[] = $pending[$pendingIndex++];
            } else {
                $merged[] = $added[$addedIndex++];
            }
        }

        return [
            ...$merged,
            ...\array_slice($pending, $pendingIndex),
            ...\array_slice($added, $addedIndex)
        ];
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
