<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Register automatic client plugins through Composer autoload files.
 *
 * Plugin packages call {@see register()} from a file listed under Composer's
 * `autoload.files`. Registered plugins are instantiated once when automatic
 * plugins are first resolved.
 */
final class ComposerAutoPluginRegistry
{
    /** @var array<class-string<AutoPluginInterface>, true> */
    private static array $pluginClasses = [];

    /** @var list<AutoPluginInterface> */
    private static array $plugins = [];

    private static bool $loaded = false;

    private static ?\RuntimeException $loadError = null;

    private function __construct() {}

    /**
     * Register an automatic plugin class.
     *
     * The class must have a public constructor with no required arguments.
     * Re-registering the same class has no effect.
     *
     * @param class-string<AutoPluginInterface> $pluginClass
     */
    public static function register(string $pluginClass): void
    {
        if (!\class_exists($pluginClass)) {
            throw new \InvalidArgumentException(
                "Composer auto plugin class '{$pluginClass}' does not exist."
            );
        }
        if (!\is_subclass_of($pluginClass, AutoPluginInterface::class)) {
            throw new \InvalidArgumentException(
                "Composer auto plugin '{$pluginClass}' must implement "
                . AutoPluginInterface::class
                . '.'
            );
        }
        if (isset(self::$pluginClasses[$pluginClass])) {
            return;
        }
        if (self::$loaded) {
            throw new \LogicException(
                "Cannot register composer auto plugin '{$pluginClass}' "
                . 'after automatic plugins have been loaded.'
            );
        }

        self::$pluginClasses[$pluginClass] = true;
    }

    /**
     * Return shared instances in Composer registration order.
     *
     * @return list<AutoPluginInterface>
     */
    public static function getPlugins(): array
    {
        if (self::$loadError !== null) {
            throw self::$loadError;
        }
        if (self::$loaded) {
            return self::$plugins;
        }

        self::$loaded = true;
        $plugins = [];
        foreach (self::$pluginClasses as $pluginClass => $_) {
            try {
                $plugins[] = new $pluginClass();
            } catch (\Throwable $e) {
                self::$loadError = new \RuntimeException(
                    "Unable to instantiate composer auto plugin "
                    . "'{$pluginClass}'. Auto plugins must have a public "
                    . 'constructor with no required arguments.',
                    0,
                    $e
                );

                throw self::$loadError;
            }
        }

        self::$plugins = $plugins;

        return self::$plugins;
    }
}
