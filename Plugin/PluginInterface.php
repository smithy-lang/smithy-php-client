<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\ClientConfig;

/**
 * Customize client configuration at construction or call time.
 *
 * Plugins receive a {@see ClientConfig} and return a modified copy. A plugin
 * that needs pipeline behavior registers an interceptor from `configure()`.
 *
 * ```php
 * final class UserAgentPlugin extends AbstractPlugin
 * {
 *     public function configure(ClientConfig $config): ClientConfig
 *     {
 *         return $config->withInterceptor(new UserAgentInterceptor());
 *     }
 * }
 * ```
 */
interface PluginInterface
{
    /**
     * Return a new config with this plugin's changes applied.
     */
    public function configure(ClientConfig $config): ClientConfig;

    /**
     * Return the plugins contributed by this plugin.
     *
     * Child plugins are resolved independently and may run in a different
     * phase than their parent.
     *
     * @return list<PluginInterface>
     */
    public function getChildPlugins(): array;

    /**
     * Return the phase in which this plugin is applied.
     */
    public function getPluginPhase(): PluginPhase;
}
