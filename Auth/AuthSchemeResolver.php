<?php

declare(strict_types=1);

namespace Smithy\Client\Auth;

use Smithy\Client\ClientConfig;
use Smithy\Client\Endpoint\EndpointAuthScheme;
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
        ClientConfig $config,
        array $preference = []
    ): ResolvedAuthScheme
    {
        $modeledIds = $this->resolveAuthSchemeIds($operation, $config);
        foreach (self::prioritizeModeledAuth(
            $modeledIds,
            $preference
        ) as $id) {
            $scheme = $config->getSupportedAuthScheme($id);
            if ($scheme !== null) {
                return new ResolvedAuthScheme(
                    $scheme,
                    $scheme->getIdentityProperties($config),
                    $scheme->getSignerProperties($config)
                );
            }
        }

        throw new \RuntimeException(
            'No supported auth scheme is available for operation '
            . $operation->getName()
            . '.'
        );
    }

    /**
     * Reconcile a resolved auth scheme with endpoint auth options.
     *
     * @param list<EndpointAuthScheme> $endpointAuthSchemes
     * @param list<string> $preference Auth scheme names in preferred order.
     *
     * @throws \RuntimeException If no endpoint scheme can be resolved.
     */
    final public function resolveEndpointAuthScheme(
        ResolvedAuthScheme $resolvedAuthScheme,
        OperationInterface $operation,
        ClientConfig $config,
        array $endpointAuthSchemes,
        array $preference = []
    ): ResolvedAuthScheme
    {
        if ($endpointAuthSchemes === []) {
            return $resolvedAuthScheme;
        }

        foreach (self::prioritizeEndpointAuthSchemes(
            $endpointAuthSchemes,
            $preference
        ) as $endpointScheme) {
            $scheme = $config->getSupportedAuthScheme(
                $endpointScheme->schemeId
            );
            if ($scheme === null) {
                continue;
            }

            if ($scheme === $resolvedAuthScheme->scheme) {
                return new ResolvedAuthScheme(
                    $scheme,
                    $resolvedAuthScheme->identityProperties,
                    [
                        ...$resolvedAuthScheme->signerProperties,
                        ...$endpointScheme->properties
                    ]
                );
            }

            return new ResolvedAuthScheme(
                $scheme,
                $scheme->getIdentityProperties($config),
                [
                    ...$scheme->getSignerProperties($config),
                    ...$endpointScheme->properties
                ]
            );
        }

        $requested = [];
        foreach ($endpointAuthSchemes as $endpointScheme) {
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
     * Prioritize modeled auth schemes.
     *
     * @param list<string> $modeledIds
     * @param list<string> $preference
     *
     * @return list<string>
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
                    $candidates[] = $modeledId;
                    $seen[$modeledId] = true;
                    break;
                }
            }
        }

        foreach ($modeledIds as $modeledId) {
            if (!isset($seen[$modeledId])) {
                $candidates[] = $modeledId;
            }
        }

        return $candidates;
    }

    /**
     * @param list<EndpointAuthScheme> $endpointAuthSchemes
     * @param list<string> $preference
     *
     * @return list<EndpointAuthScheme>
     */
    private static function prioritizeEndpointAuthSchemes(
        array $endpointAuthSchemes,
        array $preference
    ): array
    {
        $candidates = [];
        $seen = [];
        foreach ($preference as $preferredName) {
            foreach ($endpointAuthSchemes as $endpointScheme) {
                if (!isset($seen[$endpointScheme->schemeId])
                    && self::authSchemeName($endpointScheme->schemeId)
                        === $preferredName
                ) {
                    $candidates[] = $endpointScheme;
                    $seen[$endpointScheme->schemeId] = true;
                }
            }
        }

        foreach ($endpointAuthSchemes as $endpointScheme) {
            if (!isset($seen[$endpointScheme->schemeId])) {
                $candidates[] = $endpointScheme;
                $seen[$endpointScheme->schemeId] = true;
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
