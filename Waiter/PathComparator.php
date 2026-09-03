<?php

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Compare a JMESPath result with a modeled waiter expectation.
 */
enum PathComparator
{
    case StringEquals;
    case BooleanEquals;
    case AllStringEquals;
    case AnyStringEquals;

    /**
     * Return true when the actual value meets this comparator.
     */
    public function compare(mixed $actual, string $expected): bool
    {
        return match ($this) {
            self::StringEquals => self::stringEquals($actual, $expected),
            self::BooleanEquals => self::booleanEquals($actual, $expected),
            self::AllStringEquals => self::allStringEquals(
                $actual,
                $expected
            ),
            self::AnyStringEquals => self::anyStringEquals(
                $actual,
                $expected
            )
        };
    }

    private static function stringEquals(
        mixed $actual,
        string $expected
    ): bool
    {
        return \is_string($actual) && $actual === $expected;
    }

    private static function booleanEquals(
        mixed $actual,
        string $expected
    ): bool
    {
        if (!\is_bool($actual)
            || ($expected !== 'true' && $expected !== 'false')
        ) {
            return false;
        }

        return $actual === ($expected === 'true');
    }

    private static function allStringEquals(
        mixed $actual,
        string $expected
    ): bool
    {
        if (!\is_array($actual) || !\array_is_list($actual)
            || $actual === []
        ) {
            return false;
        }

        foreach ($actual as $value) {
            if (!self::stringEquals($value, $expected)) {
                return false;
            }
        }

        return true;
    }

    private static function anyStringEquals(
        mixed $actual,
        string $expected
    ): bool
    {
        if (!\is_array($actual) || !\array_is_list($actual)) {
            return false;
        }

        foreach ($actual as $value) {
            if (self::stringEquals($value, $expected)) {
                return true;
            }
        }

        return false;
    }
}
