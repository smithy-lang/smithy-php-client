<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Client\AbstractClientConfig;
use Smithy\Core\Transport\{
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Execute an ordered interceptor sequence as one interceptor.
 *
 * Each hook invokes the corresponding hook on every interceptor in
 * registration order. Modify hooks pass their result to the next
 * interceptor. Completion hooks also carry the current error: rethrowing
 * that same error forwards it, while returning an output suppresses it.
 *
 * A hook skips interceptors that inherit it unchanged from
 * {@see AbstractInterceptor}, because the inherited hook is a no-op.
 */
final class InterceptorChain implements InterceptorInterface
{
    /** Hooks dispatched through the per-hook subscriber lists. */
    private const HOOKS = [
        'modifyBeforeCall',
        'readBeforeExecution',
        'modifyBeforeSerialization',
        'readBeforeSerialization',
        'readAfterSerialization',
        'modifyBeforeRetryLoop',
        'readBeforeAttempt',
        'modifyBeforeSigning',
        'readBeforeSigning',
        'readAfterSigning',
        'modifyBeforeTransmit',
        'readBeforeTransmit',
        'readAfterTransmit',
        'modifyBeforeDeserialization',
        'readBeforeDeserialization',
        'readAfterDeserialization',
        'modifyBeforeAttemptCompletion',
        'readAfterAttempt',
        'modifyBeforeCompletion',
        'readAfterExecution'
    ];

    /** @var array<class-string, list<string>> Overridden hooks by interceptor class */
    private static array $hooksByClass = [];

    /** @var list<InterceptorInterface> */
    private readonly array $interceptors;
    /** @var list<InterceptorInterface> */
    private readonly array $callInterceptors;
    private readonly bool $interceptsCalls;
    /** @var array<string, list<InterceptorInterface>> Subscribers by hook name */
    private readonly array $byHook;
    /** @var array<string, self|false> Chains by operation identity, false for this chain */
    private array $byOperation = [];

    /** @param InterceptorInterface ...$interceptors Interceptors in execution order. */
    public function __construct(InterceptorInterface ...$interceptors)
    {
        $this->interceptors = $interceptors;
        $callInterceptors = [];
        $byHook = \array_fill_keys(self::HOOKS, []);
        foreach ($interceptors as $interceptor) {
            if ($interceptor->interceptCalls()) {
                $callInterceptors[] = $interceptor;
            }

            foreach (self::subscribedHooks($interceptor) as $hook) {
                $byHook[$hook][] = $interceptor;
            }
        }

        $this->callInterceptors = $callInterceptors;
        $this->interceptsCalls = $callInterceptors !== [];
        $this->byHook = $byHook;
    }

    /**
     * Return a chain with the interceptor appended.
     */
    public function add(InterceptorInterface $interceptor): self
    {
        return new self(...[...$this->interceptors, $interceptor]);
    }

    /**
     * Return a chain with the interceptor prepended.
     */
    public function prepend(InterceptorInterface $interceptor): self
    {
        return new self($interceptor, ...$this->interceptors);
    }

    /**
     * Check whether the chain contains an interceptor assignable to a class.
     *
     * @param class-string<InterceptorInterface> $class
     */
    public function contains(string $class): bool
    {
        foreach ($this->interceptors as $interceptor) {
            if ($interceptor instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the chain contains no interceptors.
     */
    public function isEmpty(): bool
    {
        return $this->interceptors === [];
    }

    /**
     * Return the chain of interceptors that apply to an operation.
     *
     * Drops each {@see AbstractInterceptor} whose {@see AbstractInterceptor::appliesTo()}
     * returns false. Results are cached by operation runtime identity.
     */
    public function forOperation(OperationInterface $operation): self
    {
        $identity = $operation->getRuntimeIdentity();
        $chain = $this->byOperation[$identity] ?? null;
        if ($chain === null) {
            $kept = [];
            foreach ($this->interceptors as $interceptor) {
                if (!$interceptor instanceof AbstractInterceptor
                    || $interceptor->appliesTo($operation)
                ) {
                    $kept[] = $interceptor;
                }
            }

            // Store false instead of $this to avoid a reference cycle.
            $chain = $this->byOperation[$identity] =
                \count($kept) === \count($this->interceptors)
                    ? false
                    : new self(...$kept);
        }

        return $chain === false ? $this : $chain;
    }

    /** @inheritDoc */
    public function modifyBeforeCall(CallContext $ctx): AbstractClientConfig
    {
        $config = $ctx->config;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $config = $interceptor->modifyBeforeCall(
                $ctx->withConfig($config)
            );
        }

        return $config;
    }

    /** @inheritDoc */
    public function interceptCalls(): bool
    {
        return $this->interceptsCalls;
    }

    /** @inheritDoc */
    public function interceptCall(
        InputContext $ctx,
        callable $next
    ): SerializableStructInterface
    {
        return $this->invokeCall(0, $ctx, $next);
    }

    /**
     * Invoke one call wrapper and provide the remainder as its continuation.
     *
     * @param callable(InputContext): SerializableStructInterface $next
     */
    private function invokeCall(
        int $index,
        InputContext $ctx,
        callable $next
    ): SerializableStructInterface
    {
        if (!isset($this->callInterceptors[$index])) {
            return $next($ctx);
        }

        return $this->callInterceptors[$index]->interceptCall(
            $ctx,
            fn(InputContext $nextCtx): SerializableStructInterface =>
                $this->invokeCall($index + 1, $nextCtx, $next)
        );
    }

    /** @inheritDoc */
    public function readBeforeExecution(InputContext $ctx): void
    {
        $error = null;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            try {
                $interceptor->readBeforeExecution($ctx);
            } catch (\Throwable $e) {
                $error = $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /** @inheritDoc */
    public function modifyBeforeSerialization(
        InputContext $ctx
    ): SerializableStructInterface
    {
        $input = $ctx->input;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $input = $interceptor->modifyBeforeSerialization(
                $ctx->withInput($input)
            );
        }

        return $input;
    }

    /** @inheritDoc */
    public function readBeforeSerialization(InputContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readBeforeSerialization($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterSerialization(RequestContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readAfterSerialization($ctx);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        $request = $ctx->request;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $request = $interceptor->modifyBeforeRetryLoop(
                $ctx->withRequest($request)
            );
        }

        return $request;
    }

    /** @inheritDoc */
    public function readBeforeAttempt(RequestContext $ctx): void
    {
        $error = null;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            try {
                $interceptor->readBeforeAttempt($ctx);
            } catch (\Throwable $e) {
                $error = $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /** @inheritDoc */
    public function modifyBeforeSigning(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        $request = $ctx->request;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $request = $interceptor->modifyBeforeSigning(
                $ctx->withRequest($request)
            );
        }

        return $request;
    }

    /** @inheritDoc */
    public function readBeforeSigning(RequestContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readBeforeSigning($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterSigning(RequestContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readAfterSigning($ctx);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeTransmit(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        $request = $ctx->request;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $request = $interceptor->modifyBeforeTransmit(
                $ctx->withRequest($request)
            );
        }

        return $request;
    }

    /** @inheritDoc */
    public function readBeforeTransmit(RequestContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readBeforeTransmit($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterTransmit(ResponseContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readAfterTransmit($ctx);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeDeserialization(
        ResponseContext $ctx
    ): TransportResponseInterface
    {
        $response = $ctx->response;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $response = $interceptor->modifyBeforeDeserialization(
                $ctx->withResponse($response)
            );
        }

        return $response;
    }

    /** @inheritDoc */
    public function readBeforeDeserialization(ResponseContext $ctx): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readBeforeDeserialization($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterDeserialization(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            $interceptor->readAfterDeserialization($ctx, $error);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeAttemptCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        $output = $ctx->output;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            try {
                $output = $interceptor->modifyBeforeAttemptCompletion(
                    $ctx->withOutput($output),
                    $error
                );
                $error = null;
            } catch (\Throwable $e) {
                if ($e !== $error) {
                    throw $e;
                }
            }
        }

        if ($error !== null) {
            throw $error;
        }

        return $output;
    }

    /** @inheritDoc */
    public function readAfterAttempt(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
        $originalError = $error;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            try {
                $interceptor->readAfterAttempt($ctx, $error);
            } catch (\Throwable $e) {
                $error = $e;
            }
        }

        if ($error !== null && $error !== $originalError) {
            throw $error;
        }
    }

    /** @inheritDoc */
    public function modifyBeforeCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        $output = $ctx->output;
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            try {
                $output = $interceptor->modifyBeforeCompletion(
                    $ctx->withOutput($output),
                    $error
                );
                $error = null;
            } catch (\Throwable $e) {
                if ($e !== $error) {
                    throw $e;
                }
            }
        }

        if ($error !== null) {
            throw $error;
        }

        return $output;
    }

    /** @inheritDoc */
    public function readAfterExecution(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
        foreach ($this->byHook[__FUNCTION__] as $interceptor) {
            try {
                $interceptor->readAfterExecution($ctx, $error);
            } catch (\Throwable $e) {
                $error = $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Return the hooks an interceptor overrides.
     *
     * Interceptors that do not extend {@see AbstractInterceptor} subscribe to every hook.
     *
     * @return list<string>
     */
    private static function subscribedHooks(
        InterceptorInterface $interceptor
    ): array
    {
        if (!$interceptor instanceof AbstractInterceptor) {
            return self::HOOKS;
        }

        $class = $interceptor::class;
        if (isset(self::$hooksByClass[$class])) {
            return self::$hooksByClass[$class];
        }

        $hooks = [];
        foreach (self::HOOKS as $hook) {
            $declaringClass = (new \ReflectionMethod($class, $hook))->class;
            if ($declaringClass !== AbstractInterceptor::class) {
                $hooks[] = $hook;
            }
        }

        return self::$hooksByClass[$class] = $hooks;
    }
}
