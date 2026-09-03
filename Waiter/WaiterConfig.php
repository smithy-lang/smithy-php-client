<?php

declare(strict_types=1);

namespace Smithy\Client\Waiter;

use Smithy\Client\RequestOverrideConfig;

/**
 * Configure one waiter invocation.
 */
final readonly class WaiterConfig
{
    /**
     * @param int<1, max>|null $minDelay Minimum retry delay in seconds.
     * @param int<1, max>|null $maxDelay Maximum retry delay in seconds.
     * @param RequestOverrideConfig|null $requestOverrides Request overrides
     *        applied to every polling request.
     *
     * @throws \InvalidArgumentException When delay bounds are invalid.
     */
    public function __construct(
        public ?int $minDelay = null,
        public ?int $maxDelay = null,
        public ?RequestOverrideConfig $requestOverrides = null
    )
    {
        if ($minDelay !== null && $minDelay < 1) {
            throw new \InvalidArgumentException(
                'Minimum waiter delay must be at least 1 second.'
            );
        }

        if ($maxDelay !== null && $maxDelay < 1) {
            throw new \InvalidArgumentException(
                'Maximum waiter delay must be at least 1 second.'
            );
        }

        if ($minDelay !== null
            && $maxDelay !== null
            && $maxDelay < $minDelay
        ) {
            throw new \InvalidArgumentException(
                'Maximum waiter delay must be at least the minimum delay.'
            );
        }
    }
}
