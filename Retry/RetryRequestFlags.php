<?php

declare(strict_types=1);

namespace Smithy\Client\Retry;

/**
 * Define bit flags that alter retry-strategy behavior.
 */
final class RetryRequestFlags
{
    public const LONG_POLLING = 1;

    /**
     * Return true when the long-polling flag is set.
     */
    public static function isLongPolling(int $flags): bool
    {
        return ($flags & self::LONG_POLLING) === self::LONG_POLLING;
    }

    private function __construct() {}
}
