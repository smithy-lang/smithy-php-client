<?php

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Smithy\Client\ClientConfig;
use Smithy\Core\Serde\SerializableStructInterface;

/**
 * A hook contract for the client execution pipeline.
 *
 * The pipeline calls each hook at fixed points in the request/response
 * lifecycle. Hooks take three forms:
 *
 *  - **Call wrappers** (`interceptCall`) enclose the entire pipeline and may
 *    delegate through `$next`, substitute the input, or short-circuit the
 *    call.
 *  - **Read hooks** (`readBefore*`, `readAfter*`) observe in-flight data
 *    without changing it. Most read hooks stop the current stage when they
 *    throw. The execution-boundary and attempt-boundary read hooks invoke
 *    every interceptor before surfacing the latest error.
 *  - **Modify hooks** (`modifyBefore*`) return a value that replaces the
 *    in-flight data for subsequent interceptors and pipeline stages. A
 *    thrown modify hook aborts the current stage and jumps to completion.
 *
 * The two completion hooks (`modifyBeforeAttemptCompletion`,
 * `modifyBeforeCompletion`) receive the current error as a separate
 * parameter, matching smithy-java. They may transform the output,
 * replace the error, or suppress the error by returning an output.
 *
 * Customers extend {@see AbstractInterceptor}, which provides empty
 * defaults for every hook.
 */
interface InterceptorInterface
{
    /**
     * Modify the configuration used for one client call.
     *
     * This is the first interceptor hook invoked for a call. Interceptors
     * added to the returned configuration participate in call wrapping and
     * execution hooks, but do not receive this hook for the current call.
     */
    public function modifyBeforeCall(CallContext $ctx): ClientConfig;

    /**
     * Report whether this interceptor wraps calls through
     * {@see interceptCall}.
     *
     * The chain only folds interceptors that opt in, so ordinary
     * interceptors do not enter the call-wrapper path.
     */
    public function interceptCalls(): bool;

    /**
     * Wrap an entire call outside the retry loop.
     *
     * Wrappers compose by nesting. The first registered interceptor is the
     * outermost wrapper. Implementations may delegate, substitute the input,
     * short-circuit, hedge, time, or otherwise control the call. The current
     * client is available through `InputContext::$client` for re-entry.
     *
     * @param callable(InputContext): SerializableStructInterface $next
     */
    public function interceptCall(
        InputContext $ctx,
        callable $next
    ): SerializableStructInterface;

    // Execution-level hooks run once per call.

    /**
     * Observe input before execution starts.
     *
     * @return void
     */
    public function readBeforeExecution(InputContext $ctx): void;

    /**
     * Modify input before serialization.
     */
    public function modifyBeforeSerialization(
        InputContext $ctx
    ): SerializableStructInterface;

    /**
     * Observe input before serialization.
     *
     * @return void
     */
    public function readBeforeSerialization(InputContext $ctx): void;

    /**
     * Observe the serialized request.
     *
     * @return void
     */
    public function readAfterSerialization(RequestContext $ctx): void;

    /**
     * Modify the request before the retry loop starts.
     */
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): RequestInterface;

    // Attempt-level hooks may repeat on retry.

    /**
     * Observe the request before an attempt starts.
     *
     * @return void
     */
    public function readBeforeAttempt(RequestContext $ctx): void;

    /**
     * Modify the request before signing.
     */
    public function modifyBeforeSigning(RequestContext $ctx): RequestInterface;

    /**
     * Observe the request before signing.
     *
     * @return void
     */
    public function readBeforeSigning(RequestContext $ctx): void;

    /**
     * Observe the signed request.
     *
     * @return void
     */
    public function readAfterSigning(RequestContext $ctx): void;

    /**
     * Modify the signed request before transmission.
     */
    public function modifyBeforeTransmit(RequestContext $ctx): RequestInterface;

    /**
     * Observe the request before transmission.
     *
     * @return void
     */
    public function readBeforeTransmit(RequestContext $ctx): void;

    /**
     * Observe the response after transmission.
     *
     * @return void
     */
    public function readAfterTransmit(ResponseContext $ctx): void;

    /**
     * Modify the response before deserialization.
     */
    public function modifyBeforeDeserialization(
        ResponseContext $ctx
    ): ResponseInterface;

    /**
     * Observe the response before deserialization.
     *
     * @return void
     */
    public function readBeforeDeserialization(ResponseContext $ctx): void;

    /**
     * Called after deserialization finishes (success or failure).
     * `$error` is null on success, or the failure produced during the
     * deserialization stage.
     *
     * @return void
     */
    public function readAfterDeserialization(
        OutputContext $ctx,
        ?\Throwable $error
    ): void;

    /**
     * Complete an attempt. Return the output to succeed, or rethrow
     * `$error` (or a new error) to fail this attempt. The pipeline then
     * consults the retry strategy.
     *
     * @throws \Throwable To fail the attempt.
     */
    public function modifyBeforeAttemptCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface;

    /**
     * Observe attempt completion.
     *
     * @return void
     */
    public function readAfterAttempt(
        OutputContext $ctx,
        ?\Throwable $error
    ): void;

    // Completion hooks run once, even on failure.

    /**
     * Complete the execution. Return the output to succeed, or rethrow
     * `$error` (or a new error) to fail the call.
     *
     * @throws \Throwable To fail the execution.
     */
    public function modifyBeforeCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface;

    /**
     * Observe execution completion.
     *
     * @return void
     */
    public function readAfterExecution(
        OutputContext $ctx,
        ?\Throwable $error
    ): void;
}
