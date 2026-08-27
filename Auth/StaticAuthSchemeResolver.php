<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Core\Shape\OperationInterface;

/**
 * Return a fixed list of scheme IDs for every operation.
 *
 * Constructed with the scheme IDs in the order the pipeline should try
 * them. Useful for explicit overrides and tests.
 */
final class StaticAuthSchemeResolver implements AuthSchemeResolverInterface
{
    /**
     * @param list<string> $schemeIds Scheme IDs in priority order.
     */
    public function __construct(
        private readonly array $schemeIds
    ) {}

    /**
     * Resolver preloaded with only the `smithy.api#noAuth` scheme.
     */
    public static function noAuth(): self
    {
        return new self([NoAuthScheme::ID]);
    }

    /** @inheritDoc */
    public function resolveAuthScheme(
        OperationInterface $operation,
        ClientConfig $config
    ): array
    {
        return $this->schemeIds;
    }
}
