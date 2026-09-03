<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve from a fixed list of auth scheme IDs for every operation.
 *
 * Constructed with scheme IDs in priority order. Useful for explicit
 * overrides and tests.
 */
final class StaticAuthSchemeResolver extends AuthSchemeResolver
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

    /** @return list<string> */
    protected function resolveAuthSchemeIds(
        OperationInterface $operation,
        ClientConfig $config
    ): array
    {
        return $this->schemeIds;
    }
}
