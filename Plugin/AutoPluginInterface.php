<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Marker interface for plugins applied automatically to clients.
 *
 * Implementations are registered through
 * {@see ComposerAutoPluginRegistry}.
 */
interface AutoPluginInterface extends PluginInterface {}
