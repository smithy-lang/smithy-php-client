<?php

declare(strict_types=1);

namespace Smithy\Client\Dynamic;

use Smithy\Client\Auth\{
    AuthSchemeInterface,
    AuthSchemeResolverInterface
};
use Smithy\Client\Endpoint\{
    Bytecode\BytecodeEndpointResolver,
    Bytecode\Compiler\BddCompiler,
    Bytecode\RulesFunctionInterface,
    Endpoint,
    EndpointResolverInterface,
    EndpointResolverParams,
    StaticEndpointResolver
};
use Smithy\Client\Interceptor\InterceptorChain;
use Smithy\Client\Plugin\PluginCollection;
use Smithy\Client\Protocol\ProtocolResolver;
use Smithy\Client\Retry\RetryStrategyInterface;
use Smithy\Client\Transport\TransportInterface;
use Smithy\Core\Model\SmithyModel;
use Smithy\Http\Client\HttpClientInterface;
use Smithy\Protocol\ProtocolInterface;

/**
 * Resolve runtime dependencies and construct a dynamic client.
 *
 * @internal Called by {@see DynamicClient::create()}.
 */
final class DynamicClientFactory
{
    private const ENDPOINT_BDD = 'smithy.rules#endpointBdd';

    private function __construct() {}

    /**
     * Create a dynamic client from a model and runtime dependencies.
     *
     * @template T of DynamicClient
     *
     * @param SmithyModel|string $model Runtime model or JSON AST path.
     * @param array<string, ProtocolInterface> $protocols
     * @param list<string> $protocolPreference
     * @param list<AuthSchemeInterface> $supportedAuthSchemes
     * @param list<string>|null $authSchemePreference
     * @param array<string, RulesFunctionInterface> $endpointFunctions
     * @param array<string, string> $endpointAuthSchemeIds
     * @param array<string, mixed> $endpointParameters
     * @param array<string, mixed> $context
     * @param class-string<T> $clientClass
     *
     * @return T
     *
     * @throws \InvalidArgumentException When client configuration is invalid.
     * @throws \RuntimeException When no modeled protocol implementation is available.
     */
    public static function create(
        SmithyModel|string $model,
        DynamicClientRuntime $runtime,
        ?string $serviceId = null,
        ?string $protocolId = null,
        ?ProtocolInterface $protocol = null,
        array $protocols = [],
        ?HttpClientInterface $httpClient = null,
        ?TransportInterface $transport = null,
        ?EndpointResolverInterface $endpointResolver = null,
        ?string $endpoint = null,
        array $endpointFunctions = [],
        array $endpointAuthSchemeIds = [],
        array $endpointParameters = [],
        array $protocolPreference = [],
        InterceptorChain $interceptors = new InterceptorChain(),
        array $supportedAuthSchemes = [],
        ?AuthSchemeResolverInterface $authSchemeResolver = null,
        ?array $authSchemePreference = null,
        ?RetryStrategyInterface $retryStrategy = null,
        string $retryScope = '',
        array $context = [],
        PluginCollection $plugins = new PluginCollection(),
        string $clientClass = DynamicClient::class
    ): DynamicClient
    {
        if (!\is_a($clientClass, DynamicClient::class, true)) {
            throw new \InvalidArgumentException(
                'Dynamic client class must extend '
                . DynamicClient::class
                . '.'
            );
        }

        $model = \is_string($model)
            ? SmithyModel::fromFile($model)
            : $model;
        $serviceId = self::resolveServiceId($model, $serviceId);
        $serviceTraits = $model->getTraits($serviceId);
        $runtime = $runtime->merge(new DynamicClientRuntime(
            protocols: $protocols,
            authSchemes: $supportedAuthSchemes,
            endpointFunctions: $endpointFunctions,
            endpointAuthSchemeIds: $endpointAuthSchemeIds,
            protocolPreference: $protocolPreference
        ));
        $protocolResolver = ProtocolResolver::defaults()->merge(
            $runtime->getProtocolResolver()
        );
        $supportedAuthSchemes = $runtime->getAuthSchemes();
        $endpointFunctions = $runtime->getEndpointFunctions();
        $endpointAuthSchemeIds = $runtime->getEndpointAuthSchemeIds();

        if ($protocol !== null
            && $protocolId !== null
            && $protocol->getId() !== $protocolId
        ) {
            throw new \InvalidArgumentException(
                "Protocol implementation {$protocol->getId()} does not match "
                . "configured protocol {$protocolId}."
            );
        }
        if ($endpointResolver !== null && $endpoint !== null) {
            throw new \InvalidArgumentException(
                'Provide either an endpoint resolver or a static endpoint, '
                . 'not both.'
            );
        }
        if ($endpointResolver === null) {
            $endpointBdd = $serviceTraits[self::ENDPOINT_BDD] ?? null;
            if ($endpointBdd !== null && !\is_array($endpointBdd)) {
                throw new \InvalidArgumentException(
                    'The modeled smithy.rules#endpointBdd trait must be '
                    . 'an object.'
                );
            }

            $usesModeledEndpoint = \is_array($endpointBdd)
                && (
                    $endpoint === null
                    || isset($endpointBdd['parameters']['Endpoint'])
                );
            if ($usesModeledEndpoint) {
                if ($endpoint !== null && $endpoint !== '') {
                    $endpointParameters['Endpoint'] = $endpoint;
                }
                $endpointResolver = new BytecodeEndpointResolver(
                    (new BddCompiler($endpointFunctions))->compile(
                        $endpointBdd
                    ),
                    endpointAuthSchemeIds: $endpointAuthSchemeIds
                );
            } elseif ($endpoint !== null && $endpoint !== '') {
                $endpointResolver = new StaticEndpointResolver(
                    new Endpoint($endpoint)
                );
            } else {
                throw new \InvalidArgumentException(
                    'A dynamic client requires an endpoint resolver or '
                    . 'static endpoint when the service has no modeled '
                    . 'endpoint BDD.'
                );
            }
        }

        $context = self::withEndpointParameters(
            $context,
            $endpointParameters
        );
        $config = $clientClass::createClientConfig(
            model: $model,
            serviceId: $serviceId,
            protocolId: $protocolId,
            protocol: $protocol,
            protocolResolver: $protocolResolver,
            httpClient: $httpClient,
            transport: $transport,
            endpointResolver: $endpointResolver,
            interceptors: $interceptors,
            supportedAuthSchemes: $supportedAuthSchemes,
            authSchemeResolver: $authSchemeResolver,
            authSchemePreference: $authSchemePreference,
            retryStrategy: $retryStrategy,
            retryScope: $retryScope,
            context: $context
        );

        return new $clientClass(
            $model,
            $config,
            $serviceId,
            $protocolId,
            $plugins,
            $runtime->getTraitRegistry(),
            $runtime->getErrorDiscriminatorTraits()
        );
    }

    /**
     * Store client endpoint parameters in the generic config context.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private static function withEndpointParameters(
        array $context,
        array $parameters
    ): array
    {
        $configured = $context[
            EndpointResolverParams::CONTEXT_PARAMETERS
        ] ?? [];
        if (!\is_array($configured)) {
            throw new \InvalidArgumentException(
                'Endpoint parameters must be an associative array.'
            );
        }
        foreach ($parameters as $name => $_) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException(
                    'Endpoint parameter names must be strings.'
                );
            }
        }

        if ($configured === [] && $parameters === []) {
            return $context;
        }

        $context[EndpointResolverParams::CONTEXT_PARAMETERS] = [
            ...$configured,
            ...$parameters
        ];

        return $context;
    }

    /**
     * Resolve an explicit or unique modeled service.
     */
    private static function resolveServiceId(
        SmithyModel $model,
        ?string $serviceId
    ): string
    {
        if ($serviceId !== null) {
            $service = $model->expectShape($serviceId);
            if (($service['type'] ?? null) !== 'service') {
                throw new \InvalidArgumentException(
                    "Shape {$serviceId} is not a service."
                );
            }

            return $serviceId;
        }

        $serviceIds = $model->getServiceIds();
        if ($serviceIds === []) {
            throw new \InvalidArgumentException(
                'Smithy model contains no service shapes.'
            );
        }
        if (\count($serviceIds) > 1) {
            throw new \InvalidArgumentException(
                'Smithy model contains multiple service shapes: '
                . \implode(', ', $serviceIds)
            );
        }

        return $serviceIds[0];
    }

}
