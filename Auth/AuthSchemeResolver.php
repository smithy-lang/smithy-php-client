<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Client\Endpoint\Endpoint;
use Smithy\Core\Shape\OperationInterface;

/**
 * Resolve the supported auth scheme used for an operation.
 *
 * The default implementation uses the operation's effective auth schemes.
 * Subclasses may override {@see resolveAuthSchemeIds()} to provide another
 * ordered source of modeled candidates.
 */
class AuthSchemeResolver implements AuthSchemeResolverInterface
{
    /**
     * Resolve the first supported auth scheme for an operation.
     *
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no scheme can be resolved.
     */
    final public function resolveAuthScheme(
        OperationInterface $operation,
        Endpoint $endpoint,
        ClientConfig $config,
        array $preference = []
    ): ResolvedAuthScheme
    {
        $modeledIds = $this->resolveAuthSchemeIds($operation, $config);
        foreach ($this->authCandidates(
            $modeledIds,
            $endpoint,
            $preference
        ) as [$id, $properties]) {
            $scheme = $config->getSupportedAuthScheme($id);
            if ($scheme !== null) {
                return new ResolvedAuthScheme($scheme, $properties);
            }
        }

        if ($endpoint->authSchemes !== []) {
            $requested = [];
            foreach ($endpoint->authSchemes as $endpointScheme) {
                $requested[] = $endpointScheme->schemeId;
            }
            throw new \RuntimeException(
                'Endpoint resolution requested unsupported auth schemes ['
                . \implode(', ', $requested)
                . '] for operation '
                . $operation->getName()
                . '.'
            );
        }

        throw new \RuntimeException(
            'No supported auth scheme is available for operation '
            . $operation->getName()
            . '.'
        );
    }

    /**
     * Return the modeled auth scheme IDs in priority order.
     *
     * @return list<string>
     */
    protected function resolveAuthSchemeIds(
        OperationInterface $operation,
        ClientConfig $config
    ): array
    {
        return $operation->getEffectiveAuthSchemes();
    }

    /**
     * Build auth candidates from endpoint and modeled schemes.
     *
     * @param list<string> $modeledIds
     * @param list<string> $preference
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private function authCandidates(
        array $modeledIds,
        Endpoint $endpoint,
        array $preference
    ): array
    {
        if ($endpoint->authSchemes === []) {
            return self::prioritizeModeledAuth($modeledIds, $preference);
        }

        $candidates = [];
        $seen = [];
        foreach ($preference as $preferredName) {
            foreach ($endpoint->authSchemes as $endpointScheme) {
                if (!isset($seen[$endpointScheme->schemeId])
                    && self::authSchemeName($endpointScheme->schemeId)
                        === $preferredName
                ) {
                    $candidates[] = [
                        $endpointScheme->schemeId,
                        $endpointScheme->properties
                    ];
                    $seen[$endpointScheme->schemeId] = true;
                }
            }
        }

        foreach ($endpoint->authSchemes as $endpointScheme) {
            if (!isset($seen[$endpointScheme->schemeId])) {
                $candidates[] = [
                    $endpointScheme->schemeId,
                    $endpointScheme->properties
                ];
                $seen[$endpointScheme->schemeId] = true;
            }
        }

        return $candidates;
    }

    /**
     * Prioritize modeled auth schemes.
     *
     * @param list<string> $modeledIds
     * @param list<string> $preference
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private static function prioritizeModeledAuth(
        array $modeledIds,
        array $preference
    ): array
    {
        $candidates = [];
        $seen = [];
        foreach ($preference as $preferredName) {
            foreach ($modeledIds as $modeledId) {
                if (!isset($seen[$modeledId])
                    && self::authSchemeName($modeledId) === $preferredName
                ) {
                    $candidates[] = [$modeledId, []];
                    $seen[$modeledId] = true;
                    break;
                }
            }
        }

        foreach ($modeledIds as $modeledId) {
            if (!isset($seen[$modeledId])) {
                $candidates[] = [$modeledId, []];
            }
        }

        return $candidates;
    }

    private static function authSchemeName(string $schemeId): string
    {
        $separator = \strrpos($schemeId, '#');

        return $separator === false
            ? $schemeId
            : \substr($schemeId, $separator + 1);
    }
}
