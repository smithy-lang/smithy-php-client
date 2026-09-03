<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

use Smithy\Client\ClientConfig;

/**
 * Customize client configuration at construction time.
 *
 * Plugins receive a {@see ClientConfig} and return a modified copy. A plugin
 * that needs per-call behavior registers an interceptor from `configure()`.
 *
 * ```php
 * final class UserAgentPlugin implements PluginInterface
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
}
