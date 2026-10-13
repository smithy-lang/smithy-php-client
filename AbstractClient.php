<?php

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Client\Interceptor\{
    CallContext,
    InputContext
};
use Smithy\Client\Pagination\Paginator;
use Smithy\Client\Plugin\{
    PluginCollection
};
use Smithy\Client\Waiter\Acceptor;
use Smithy\Client\Waiter\Waiter;
use Smithy\Client\Waiter\WaiterConfig;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Provide shared execution behavior for generated service clients.
 *
 * Holds an immutable {@see ClientConfig} and a {@see ClientPipeline}.
 * Generated operation methods call {@see executeOperation()} with a static
 * operation singleton and a typed input shape. Each generated signature
 * narrows the return type to the operation's output shape.
 *
 * The AWS SDK layer subclasses this as `AbstractAwsClient` to layer
 * credential resolution and region configuration on top.
 */
abstract class AbstractClient implements ClientInterface
{
    /** Client configuration after plugin application. */
    protected readonly ClientConfig $config;

    /** Constructed once per client and reused across operations. */
    private readonly ClientPipeline $pipeline;

    /**
     * Resolve and apply client plugins.
     */
    public function __construct(
        ClientConfig $config,
        PluginCollection $plugins = new PluginCollection()
    )
    {
        $this->config = $plugins->apply($config);
        $this->pipeline = new ClientPipeline($this->config);
    }

    /**
     * Access the client configuration.
     */
    public function getConfig(): ClientConfig
    {
        return $this->config;
    }

    /**
     * Run an operation through the pipeline.
     *
     * Generated methods delegate here and narrow the return type through a
     * covariant signature. Direct callers use this method when the operation
     * is known only at runtime.
     *
     * ```php
     * public function putObject(
     *     PutObjectInput $input,
     *     ?RequestOverrideConfig $overrides = null
     * ): PutObjectOutput
     * {
     *     return $this->executeOperation(
     *         PutObjectOperation::instance(),
     *         $input,
     *         $overrides
     *     );
     * }
     * ```
     */
    public function executeOperation(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ?RequestOverrideConfig $overrides = null
    ): SerializableStructInterface
    {
        $callConfig = $overrides === null || $overrides->plugins->isEmpty()
            ? $this->config
            : $overrides->plugins->apply($this->config);
        $interceptor = $callConfig->interceptorChain;
        $callConfig = $interceptor->modifyBeforeCall(
            new CallContext($operation, $input, $callConfig)
        );
        $interceptor = $callConfig->interceptorChain;
        $pipeline = $callConfig === $this->config
            ? $this->pipeline
            : new ClientPipeline($callConfig);

        if (!$interceptor->interceptCalls()) {
            return $pipeline->execute($operation, $input, $overrides);
        }

        $ctx = new InputContext(
            $operation,
            $input,
            $callConfig,
            $this
        );

        return $interceptor->interceptCall(
            $ctx,
            fn(InputContext $ctx): SerializableStructInterface =>
                $pipeline->execute(
                    $operation,
                    $ctx->input,
                    $overrides
                )
        );
    }

    /**
     * Create a paginator bound to this client.
     */
    protected function createPaginator(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): Paginator
    {
        return new Paginator($this, $operation, $input);
    }

    /**
     * Create a waiter bound to an operation on this client.
     *
     * @param list<Acceptor> $acceptors Ordered acceptors.
     * @param int<1, max> $minDelay Modeled minimum delay in seconds.
     * @param int<1, max> $maxDelay Modeled maximum delay in seconds.
     *
     * @throws \InvalidArgumentException When resolved delay bounds are
     *                                   invalid.
     */
    protected function createWaiter(
        OperationInterface $operation,
        array $acceptors,
        int $minDelay,
        int $maxDelay,
        ?WaiterConfig $config = null
    ): Waiter
    {
        return new Waiter(
            fn(
                SerializableStructInterface $input
            ): SerializableStructInterface => $this->executeOperation(
                $operation,
                $input,
                $config?->requestOverrides
            ),
            $acceptors,
            $config?->minDelay ?? $minDelay,
            $config?->maxDelay ?? $maxDelay
        );
    }
}
