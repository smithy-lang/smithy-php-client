<?php

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Smithy\Client\ClientConfig;
use Smithy\Core\Serde\SerializableStructInterface;

/**
 * No-op base class for {@see InterceptorInterface} implementations.
 *
 * All read hooks are empty; all modify hooks return the current value
 * unchanged. Customers override only the hooks they need.
 *
 * ```php
 * final class TimingInterceptor extends AbstractInterceptor
 * {
 *     private float $startTime;
 *
 *     public function readBeforeTransmit(RequestContext $ctx): void
 *     {
 *         $this->startTime = microtime(true);
 *     }
 *
 *     public function readAfterTransmit(ResponseContext $ctx): void
 *     {
 *         $duration = microtime(true) - $this->startTime;
 *         error_log("{$ctx->operation->getName()} took {$duration}s");
 *     }
 * }
 * ```
 */
abstract class AbstractInterceptor implements InterceptorInterface
{
    /** @inheritDoc */
    public function modifyBeforeCall(CallContext $ctx): ClientConfig
    {
        return $ctx->config;
    }

    /** @inheritDoc */
    public function interceptCalls(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function interceptCall(
        InputContext $ctx,
        callable $next
    ): SerializableStructInterface
    {
        return $next($ctx);
    }

    /** @inheritDoc */
    public function readBeforeExecution(InputContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function modifyBeforeSerialization(
        InputContext $ctx
    ): SerializableStructInterface
    {
        return $ctx->input;
    }

    /** @inheritDoc */
    public function readBeforeSerialization(InputContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function readAfterSerialization(RequestContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function modifyBeforeRetryLoop(RequestContext $ctx): RequestInterface
    {
        return $ctx->request;
    }

    /** @inheritDoc */
    public function readBeforeAttempt(RequestContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function modifyBeforeSigning(RequestContext $ctx): RequestInterface
    {
        return $ctx->request;
    }

    /** @inheritDoc */
    public function readBeforeSigning(RequestContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function readAfterSigning(RequestContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function modifyBeforeTransmit(RequestContext $ctx): RequestInterface
    {
        return $ctx->request;
    }

    /** @inheritDoc */
    public function readBeforeTransmit(RequestContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function readAfterTransmit(ResponseContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function modifyBeforeDeserialization(
        ResponseContext $ctx
    ): ResponseInterface
    {
        return $ctx->response;
    }

    /** @inheritDoc */
    public function readBeforeDeserialization(ResponseContext $ctx): void
    {
    }

    /** @inheritDoc */
    public function readAfterDeserialization(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
    }

    /**
     * Rethrow the error if set, otherwise return the
     * output. Matches Java's `OutputHook::forward()`.
     *
     * @throws \Throwable If the attempt completed with an error.
     */
    public function modifyBeforeAttemptCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        if ($error !== null) {
            throw $error;
        }

        return $ctx->output;
    }

    /** @inheritDoc */
    public function readAfterAttempt(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
    }

    /** @inheritDoc */
    public function modifyBeforeCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        if ($error !== null) {
            throw $error;
        }

        return $ctx->output;
    }

    /** @inheritDoc */
    public function readAfterExecution(
        OutputContext $ctx,
        ?\Throwable $error
    ): void
    {
    }
}
