<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

/**
 * Declare a provider's position relative to an ordered slot list.
 *
 * The generic Smithy layer intentionally treats slots as opaque strings.
 * Concrete implementations define the canonical slot names and their
 * global order.
 */
final class OrderingConstraint
{
    private const STANDARD = 'standard';
    private const BEFORE = 'before';
    private const AFTER = 'after';

    private function __construct(
        private readonly string $kind,
        public readonly string $slot
    )
    {
        if ($slot === '') {
            throw new \InvalidArgumentException('slot must not be empty.');
        }
    }

    /**
     * Create a constraint for the provider owning `$slot`.
     */
    public static function standard(string $slot): self
    {
        return new self(self::STANDARD, $slot);
    }

    /**
     * Create a constraint that places the provider before `$slot`.
     */
    public static function before(string $slot): self
    {
        return new self(self::BEFORE, $slot);
    }

    /**
     * Create a constraint that places the provider after `$slot`.
     */
    public static function after(string $slot): self
    {
        return new self(self::AFTER, $slot);
    }

    /**
     * Return true when the provider owns its slot.
     */
    public function isStandard(): bool
    {
        return $this->kind === self::STANDARD;
    }

    /**
     * Return true when the provider runs before its slot.
     */
    public function isBefore(): bool
    {
        return $this->kind === self::BEFORE;
    }

    /**
     * Return true when the provider runs after its slot.
     */
    public function isAfter(): bool
    {
        return $this->kind === self::AFTER;
    }
}
