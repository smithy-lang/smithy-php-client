<?php

declare(strict_types=1);

namespace Smithy\Client\Plugin;

/**
 * Order in which client plugins are applied.
 */
enum PluginPhase: int
{
    case First = 0;
    case BeforeDefaults = 1;
    case Defaults = 2;
    case AfterDefaults = 3;
    case BeforeApply = 4;
    case Apply = 5;
    case AfterApply = 6;
    case Last = 7;
}
