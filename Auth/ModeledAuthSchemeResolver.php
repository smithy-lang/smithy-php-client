<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve the authentication schemes modeled for each operation.
 */
final class ModeledAuthSchemeResolver implements AuthSchemeResolverInterface
{
    private static ?self $instance = null;

    /**
     * Return the shared modeled auth-scheme resolver.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    /** @inheritDoc */
    public function resolveAuthScheme(
        OperationInterface $operation,
        ClientConfig $config
    ): array
    {
        return $operation->getEffectiveAuthSchemes();
    }
}
