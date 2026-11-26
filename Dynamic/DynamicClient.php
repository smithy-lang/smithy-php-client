<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Dynamic;

use Smithy\Auth\AuthSchemeInterface;
use Smithy\Client\{
    AbstractClient,
    AbstractClientConfig,
    ClientConfig,
    RequestOverrideConfig
};
use Smithy\Client\Auth\AuthSchemeResolverInterface;
use Smithy\Client\Endpoint\{
    Bytecode\RulesFunctionInterface,
    Bytecode\StdFunctions,
    EndpointResolverInterface
};
use Smithy\Client\Interceptor\InterceptorChain;
use Smithy\Client\Pagination\Paginator;
use Smithy\Client\Plugin\PluginCollection;
use Smithy\Client\Protocol\ProtocolResolverInterface;
use Smithy\Client\Retry\RetryStrategyInterface;
use Smithy\Core\Transport\TransportInterface;
use Smithy\Client\Waiter\{
    WaiterConfig,
    WaiterResult
};
use Smithy\Core\Model\SmithyModel;
use Smithy\Core\Serde\{
    SchemaConverter,
    SchemaTraitRegistry
};
use Smithy\Core\Shape\Document\{
    DocumentInterface,
    StructDocument
};
use Smithy\Http\Client\{
    CurlClient,
    HttpClientInterface,
    HttpClientTransport
};
use Smithy\Protocol\ProtocolInterface;

/**
 * Execute a runtime-loaded Smithy service without generated types.
 */
class DynamicClient extends AbstractClient
{
    private readonly SchemaConverter $converter;
    private readonly DynamicService $service;
    private readonly DocumentFactory $documentFactory;

    /**
     * Create a dynamic client with explicit runtime dependencies.
     *
     * @param array<string, string> $errorDiscriminatorTraits
     * @param list<string> $authSchemeIds Auth scheme trait IDs counted as
     *     service auth schemes when the model applies no auth trait. The
     *     client adds the IDs of the auth schemes in its plugin-resolved
     *     configuration.
     */
    public function __construct(
        SmithyModel $model,
        AbstractClientConfig $config,
        ?string $serviceId = null,
        ?string $protocolId = null,
        PluginCollection $plugins = new PluginCollection(),
        ?SchemaTraitRegistry $traitRegistry = null,
        array $errorDiscriminatorTraits = [],
        array $authSchemeIds = []
    )
    {
        $this->documentFactory = new DocumentFactory();
        $this->converter = new SchemaConverter(
            $model,
            $traitRegistry ?? new SchemaTraitRegistry(),
            $errorDiscriminatorTraits
        );
        $this->service = new DynamicService(
            $model,
            $this->converter,
            $serviceId,
            $protocolId,
            $errorDiscriminatorTraits,
            $this->documentFactory,
            fn(): array => $this->resolveAuthSchemeIds(
                $config,
                $authSchemeIds
            )
        );

        parent::__construct(
            $config->withService($this->service),
            $plugins
        );
    }

    /**
     * Return the given auth scheme IDs plus those of the client config.
     *
     * Reads the plugin-resolved config once construction finishes and the
     * constructor's config while plugins are still being applied.
     *
     * @param list<string> $authSchemeIds
     *
     * @return list<string>
     */
    private function resolveAuthSchemeIds(
        AbstractClientConfig $constructorConfig,
        array $authSchemeIds
    ): array
    {
        $config = $this->config ?? $constructorConfig;
        foreach ($config->supportedAuthSchemes as $scheme) {
            $authSchemeIds[] = $scheme->getSchemeId();
        }

        return $authSchemeIds;
    }

    /**
     * Create a dynamic client from a runtime model or JSON AST path.
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
     */
    public static function create(
        SmithyModel|string $model,
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
        PluginCollection $plugins = new PluginCollection()
    ): static
    {
        return DynamicClientFactory::create(
            model: $model,
            runtime: static::getDefaultRuntime(),
            serviceId: $serviceId,
            protocolId: $protocolId,
            protocol: $protocol,
            protocols: $protocols,
            httpClient: $httpClient,
            transport: $transport,
            endpointResolver: $endpointResolver,
            endpoint: $endpoint,
            endpointFunctions: $endpointFunctions,
            endpointAuthSchemeIds: $endpointAuthSchemeIds,
            endpointParameters: $endpointParameters,
            protocolPreference: $protocolPreference,
            interceptors: $interceptors,
            supportedAuthSchemes: $supportedAuthSchemes,
            authSchemeResolver: $authSchemeResolver,
            authSchemePreference: $authSchemePreference,
            retryStrategy: $retryStrategy,
            retryScope: $retryScope,
            context: $context,
            plugins: $plugins,
            clientClass: static::class
        );
    }

    /**
     * Build the resolved client configuration.
     *
     * Dynamic client subclasses may override this to supply a specialized
     * configuration while retaining the shared client factory.
     *
     * @param list<AuthSchemeInterface> $supportedAuthSchemes
     * @param list<string>|null $authSchemePreference
     * @param array<string, mixed> $context
     * @param string|null $endpoint Endpoint URL the config wraps in a
     *        static resolver. Set this or `$endpointResolver`.
     */
    public static function createClientConfig(
        SmithyModel $model,
        string $serviceId,
        ?string $protocolId,
        ?ProtocolInterface $protocol,
        ProtocolResolverInterface $protocolResolver,
        ?HttpClientInterface $httpClient,
        ?TransportInterface $transport,
        ?EndpointResolverInterface $endpointResolver,
        InterceptorChain $interceptors,
        array $supportedAuthSchemes,
        ?AuthSchemeResolverInterface $authSchemeResolver,
        ?array $authSchemePreference,
        ?RetryStrategyInterface $retryStrategy,
        string $retryScope,
        array $context,
        ?string $endpoint = null
    ): AbstractClientConfig
    {
        if ($transport !== null && $httpClient !== null) {
            throw new \InvalidArgumentException(
                'Provide either an HTTP client or a transport, not both.'
            );
        }
        $transport ??= new HttpClientTransport(
            $httpClient ?? new CurlClient()
        );

        return new ClientConfig(
            transport: $transport,
            endpointResolver: $endpointResolver,
            protocol: $protocol,
            protocolResolver: $protocolResolver,
            protocolId: $protocolId,
            interceptors: $interceptors,
            supportedAuthSchemes: $supportedAuthSchemes,
            authSchemeResolver: $authSchemeResolver,
            authSchemePreference: $authSchemePreference ?? [],
            retryStrategy: $retryStrategy,
            retryScope: $retryScope,
            context: $context,
            endpoint: $endpoint
        );
    }

    /**
     * Execute a modeled operation by name.
     *
     * @param array<string, mixed>|DocumentInterface|null $input
     * @param array<string, mixed> $overrides
     *
     * @throws \InvalidArgumentException When operation or overrides are invalid.
     * @throws \Smithy\Core\Shape\Exception\InvalidShapeValueException When input is invalid.
     * @throws \LogicException When the operation uses unsupported streaming or
     *                         returns an invalid output.
     */
    public function call(
        string $operationName,
        array|DocumentInterface|null $input = null,
        array $overrides = []
    ): StructDocument
    {
        $operation = $this->expectOperation($operationName);
        $this->assertPreviewSupported($operation);
        $requestOverrides = $overrides === []
            ? null
            : RequestOverrideConfig::fromArray($overrides);
        $modeledInput = $operation->createInputFromValue($input);
        $output = $this->executeOperation(
            $operation,
            $modeledInput,
            $requestOverrides
        );
        if ($output->getSchema()->id !== $operation->getOutputSchema()->id) {
            throw new \LogicException(
                "Operation {$operation->getId()} returned "
                . "unexpected shape {$output->getSchema()->id}."
            );
        }

        return $output instanceof StructDocument
            ? $output
            : StructDocument::fromStruct(
                $output,
                $operation->getOutputSchema()
            );
    }

    /**
     * Create a paginator for a modeled operation by name.
     *
     * @param array<string, mixed>|DocumentInterface|null $input
     * @param array<string, mixed> $overrides
     *
     * @return Paginator<StructDocument>
     * @throws \InvalidArgumentException When operation or overrides are invalid.
     * @throws \LogicException When the operation is not paginated or uses
     *                         unsupported streaming.
     */
    public function paginate(
        string $operationName,
        array|DocumentInterface|null $input = null,
        array $overrides = []
    ): Paginator
    {
        $operation = $this->expectOperation($operationName);
        $this->assertPreviewSupported($operation);
        $requestOverrides = $overrides === []
            ? null
            : RequestOverrideConfig::fromArray($overrides);
        $paginator = $this->createPaginator(
            $operation,
            $operation->createInputFromValue($input),
            StructDocument::class
        );

        return $requestOverrides === null
            ? $paginator
            : $paginator->withRequestOverrides($requestOverrides);
    }

    /**
     * Wait for a modeled waiter to reach a terminal success state.
     *
     * @param array<string, mixed>|DocumentInterface|null $input
     *
     * @throws \InvalidArgumentException When the waiter, timing, or request
     *                                   overrides are invalid.
     * @throws \Smithy\Core\Shape\Exception\InvalidShapeValueException When
     *         input is invalid.
     * @throws \Smithy\Client\Waiter\WaiterFailureException When waiting does
     *         not succeed.
     * @throws \LogicException When the operation uses unsupported streaming.
     */
    public function waitUntil(
        string $waiterName,
        array|DocumentInterface|null $input,
        float $maxWaitTime,
        ?WaiterConfig $config = null
    ): WaiterResult
    {
        $operation = $this->service->getWaiterOperation($waiterName);
        if ($operation === null) {
            throw new \InvalidArgumentException(
                "Unknown dynamic waiter: {$waiterName}."
            );
        }

        $this->assertPreviewSupported($operation);

        return $this->createWaiter(
            $operation,
            $waiterName,
            $config
        )->wait(
            $operation->createInputFromValue($input),
            $maxWaitTime
        );
    }

    /**
     * Invoke a modeled operation, paginator, or waiter by generated method
     * convention.
     *
     * @param array<int|string, mixed> $arguments
     * @return StructDocument|Paginator<StructDocument>|WaiterResult
     */
    public function __call(
        string $name,
        array $arguments
    ): StructDocument|Paginator|WaiterResult
    {
        $operation = $this->getOperation($name) ?? $this->getOperation(\ucfirst($name));
        $waited = \str_starts_with($name, 'waitUntil');
        $paginated = \str_ends_with($name, 'Paginated');
        return match (true) {
            $operation !== null => $this->call($name, ...$arguments),
            $waited && $paginated => throw new \BadMethodCallException(
                "Dynamic method '{$name}' is ambiguous. "
                    . 'Use paginate() or waitUntil() explicitly.'
            ),
            $waited => $this->waitUntil(
                \substr($name, \strlen('waitUntil')),
                ...$arguments
            ),
            $paginated => $this->paginate(
                \substr($name, 0, -\strlen('Paginated')),
                ...$arguments
            ),
            default => throw new \BadMethodCallException(
                "Unknown dynamic method: {$name}."
            ),
        };
    }

    /**
     * Return the resolved dynamic service.
     */
    public function getService(): DynamicService
    {
        return $this->service;
    }

    /**
     * Resolve an operation by modeled name.
     */
    public function getOperation(string $name): ?DynamicOperation
    {
        $operation = $this->service->getOperation($name);

        return $operation instanceof DynamicOperation ? $operation : null;
    }

    /**
     * Return runtime implementations registered by this client class.
     */
    protected static function getDefaultRuntime(): DynamicClientRuntime
    {
        return new DynamicClientRuntime(
            endpointFunctions: StdFunctions::all()
        );
    }

    /**
     * Create a schema-backed structure from a modeled shape ID.
     *
     * @throws \InvalidArgumentException When the shape ID is invalid.
     * @throws \Smithy\Core\Shape\Exception\InvalidShapeValueException When the value is invalid.
     */
    public function createStruct(
        string $shapeId,
        mixed $value
    ): StructDocument
    {
        return $this->documentFactory->createStruct(
            $this->converter->getSchema($shapeId),
            $value
        );
    }

    /**
     * Require a modeled operation by name.
     */
    private function expectOperation(string $name): DynamicOperation
    {
        $operation = $this->getOperation($name);
        if ($operation === null && $name !== '') {
            $operation = $this->getOperation(\ucfirst($name));
        }
        if ($operation === null) {
            throw new \InvalidArgumentException(
                "Operation '{$name}' not found in service "
                . "'{$this->service->getId()}'."
            );
        }

        return $operation;
    }

    /**
     * Reject dynamic operation features excluded from the preview.
     *
     * @return void
     * @throws \LogicException When the operation has a streaming member.
     */
    private function assertPreviewSupported(
        DynamicOperation $operation
    ): void
    {
        $unsupported = [];
        $inputStream = $operation->getInputStreamMember();
        if ($inputStream !== null) {
            $unsupported[] = "input streaming member "
                . "'{$inputStream->memberName}'";
        }
        $outputStream = $operation->getOutputStreamMember();
        if ($outputStream !== null) {
            $unsupported[] = "output streaming member "
                . "'{$outputStream->memberName}'";
        }
        if ($unsupported === []) {
            return;
        }

        throw new \LogicException(
            "Dynamic operation {$operation->getId()} is outside the "
            . 'preview scope because it has '
            . \implode(' and ', $unsupported)
            . '. Dynamic streaming blobs and event streams are not '
            . 'supported.'
        );
    }
}
