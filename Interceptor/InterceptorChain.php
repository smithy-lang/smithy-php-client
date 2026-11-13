<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Client\ClientConfig;
use Smithy\Core\Transport\{
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Core\Serde\SerializableStructInterface;

/**
 * Execute an ordered interceptor sequence as one interceptor.
 *
 * Each hook invokes the corresponding hook on every interceptor in
 * registration order. Modify hooks pass their result to the next
 * interceptor. Completion hooks also carry the current error: rethrowing
 * that same error forwards it, while returning an output suppresses it.
 */
final readonly class InterceptorChain implements InterceptorInterface
{
    /** @var list<InterceptorInterface> */
    private array $interceptors;

    /** @var list<InterceptorInterface> */
    private array $callInterceptors;

    private bool $interceptsCalls;

    /** @param InterceptorInterface ...$interceptors Interceptors in execution order. */
    public function __construct(InterceptorInterface ...$interceptors)
    {
        $this->interceptors = $interceptors;
        $callInterceptors = [];
        foreach ($interceptors as $interceptor) {
            if ($interceptor->interceptCalls()) {
                $callInterceptors[] = $interceptor;
            }
        }

        $this->callInterceptors = $callInterceptors;
        $this->interceptsCalls = $callInterceptors !== [];
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

    /** @inheritDoc */
    public function modifyBeforeCall(CallContext $ctx): ClientConfig
    {
        $config = $ctx->config;
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
            $input = $interceptor->modifyBeforeSerialization(
                $ctx->withInput($input)
            );
        }

        return $input;
    }

    /** @inheritDoc */
    public function readBeforeSerialization(InputContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readBeforeSerialization($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterSerialization(RequestContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readAfterSerialization($ctx);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        $request = $ctx->request;
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
            $request = $interceptor->modifyBeforeSigning(
                $ctx->withRequest($request)
            );
        }

        return $request;
    }

    /** @inheritDoc */
    public function readBeforeSigning(RequestContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readBeforeSigning($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterSigning(RequestContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readAfterSigning($ctx);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeTransmit(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        $request = $ctx->request;
        foreach ($this->interceptors as $interceptor) {
            $request = $interceptor->modifyBeforeTransmit(
                $ctx->withRequest($request)
            );
        }

        return $request;
    }

    /** @inheritDoc */
    public function readBeforeTransmit(RequestContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readBeforeTransmit($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterTransmit(ResponseContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readAfterTransmit($ctx);
        }
    }

    /** @inheritDoc */
    public function modifyBeforeDeserialization(
        ResponseContext $ctx
    ): TransportResponseInterface
    {
        $response = $ctx->response;
        foreach ($this->interceptors as $interceptor) {
            $response = $interceptor->modifyBeforeDeserialization(
                $ctx->withResponse($response)
            );
        }

        return $response;
    }

    /** @inheritDoc */
    public function readBeforeDeserialization(ResponseContext $ctx): void
    {
        foreach ($this->interceptors as $interceptor) {
            $interceptor->readBeforeDeserialization($ctx);
        }
    }

    /** @inheritDoc */
    public function readAfterDeserialization(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
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
        foreach ($this->interceptors as $interceptor) {
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
}
