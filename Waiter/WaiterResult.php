<?php

declare(strict_types=1);

namespace Smithy\Client\Waiter;

use Smithy\Core\Exception\ApiException;
use Smithy\Core\Serde\SerializableStructInterface;

/**
 * Preserve the response that satisfied a successful waiter acceptor.
 */
final readonly class WaiterResult
{
    private function __construct(
        public ?SerializableStructInterface $output,
        public (ApiException&SerializableStructInterface)|null $error
    ) {}

    /**
     * Create a result from a successful polling output.
     */
    public static function fromOutput(
        SerializableStructInterface $output
    ): self
    {
        return new self($output, null);
    }

    /**
     * Create a result from a modeled error accepted as success.
     */
    public static function fromError(
        ApiException&SerializableStructInterface $error
    ): self
    {
        return new self(null, $error);
    }
}
