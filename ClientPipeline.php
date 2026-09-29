<?php

declare(strict_types=1);

namespace Smithy\Client;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Smithy\Async\Async;
use Smithy\Client\Auth\{
    IdentityInterface,
    ResolvedAuthScheme,
    SignerResult
};
use Smithy\Client\Endpoint\Endpoint;
use Smithy\Client\Endpoint\EndpointResolverParams;
use Smithy\Client\Interceptor\InputContext;
use Smithy\Client\Interceptor\InterceptorInterface;
use Smithy\Client\Interceptor\OutputContext;
use Smithy\Client\Interceptor\RequestContext;
use Smithy\Client\Interceptor\ResponseContext;
use Smithy\Client\Retry\RetryRequestFlagResolverInterface;
use Smithy\Client\Retry\TokenAcquisitionFailedException;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;
use Smithy\Http\Client\Exception\HttpException;
use Smithy\Http\Client\HttpRequestOptions;
use Smithy\Http\Message\{
    Request as SmithyRequest,
    RequestStreamInterface
};

/**
 * Run operations through serialization, retries, transport, and
 * deserialization.
 *
 * Invoke registered interceptors at each execution stage.
 *
 * An {@see AbstractClient} constructs one pipeline and reuses it across
 * operations.
 *
 * Hook error semantics (matching smithy-java's `ClientInterceptor`
 * documentation):
 *
 *  - Batch read hooks invoke every interceptor even if one throws. The most
 *    recent error replaces earlier errors.
 *  - All other read hooks fail fast: the first thrown exception aborts
 *    the batch and jumps to the enclosing completion hook.
 *  - Pre-attempt modify hooks (`modifyBeforeSerialization`,
 *    `modifyBeforeRetryLoop`, `modifyBeforeSigning`, `modifyBeforeTransmit`,
 *    `modifyBeforeDeserialization`) fail fast.
 *  - Completion modify hooks (`modifyBeforeAttemptCompletion`,
 *    `modifyBeforeCompletion`) pass the current error to each interceptor.
 *    A thrown exception stops the chain.
 *
 * Retry semantics: on an attempt-level failure the pipeline calls
 * `modifyBeforeAttemptCompletion` then `readAfterAttempt`, hands the
 * error to the {@see \Smithy\Client\Retry\RetryStrategyInterface}, waits
 * for the requested delay, and either retries or completes the execution.
 */
final class ClientPipeline
{
    private const UNRESOLVED_ENDPOINT = '/';

    public function __construct(
        private readonly ClientConfig $config
    ) {}

    /**
     * Run the operation and return its output.
     */
    public function execute(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ?RequestOverrideConfig $overrides = null
    ): SerializableStructInterface
    {
        $config = $this->config;
        $interceptor = $config->interceptorChain;

        if ($interceptor->isEmpty()) {
            $defaultHttpOptions = $config
                ->httpClient
                ->getDefaultRequestOptions();
            $httpOptions = $overrides?->resolveHttpOptions(
                $defaultHttpOptions
            );
            $effectiveHttpOptions = $httpOptions ?? $defaultHttpOptions;

            return $this->executeWithoutInterceptors(
                $operation,
                $input,
                $config,
                $overrides,
                $httpOptions,
                $effectiveHttpOptions
            );
        }

        $error = null;
        $reqCtx = null;
        $outCtx = null;

        try {
            $inputCtx = new InputContext($operation, $input, $config);
            $interceptor->readBeforeExecution($inputCtx);

            $defaultHttpOptions = $config
                ->httpClient
                ->getDefaultRequestOptions();
            $httpOptions = $overrides?->resolveHttpOptions(
                $defaultHttpOptions
            );
            $effectiveHttpOptions = $httpOptions ?? $defaultHttpOptions;

            $input = $interceptor->modifyBeforeSerialization($inputCtx);
            if ($input !== $inputCtx->input) {
                $inputCtx->replaceInput($input);
            }
            $interceptor->readBeforeSerialization($inputCtx);

            $request = $this->serialize($operation, $input);
            $request = $this->applyOverrideHeaders($request, $overrides);

            $reqCtx = new RequestContext(
                $operation,
                $input,
                $config,
                $request
            );

            $interceptor->readAfterSerialization($reqCtx);

            $request = $interceptor->modifyBeforeRetryLoop($reqCtx);
            if ($request !== $reqCtx->request) {
                $reqCtx->replaceRequest($request);
            }
            $retryRequest = $reqCtx->request;

            $requestFlags = self::resolveRetryRequestFlags(
                $operation,
                $config
            );
            $retryState = $config->retryStrategy->begin(
                $config->retryScope,
                $requestFlags
            );

            $attempt = 1;
            while (true) {
                $reqCtx->replaceRequest($retryRequest);
                $attemptResult = $this->executeAttempt(
                    $interceptor,
                    $reqCtx,
                    $overrides,
                    $httpOptions
                );
                $outCtx = $attemptResult->outputContext;

                $error = $this->completeAttempt(
                    $interceptor,
                    $outCtx,
                    $attemptResult->error
                );

                if ($error !== null) {
                    if ($overrides?->maxAttempts !== null
                        && $attempt >= $overrides->maxAttempts
                    ) {
                        break;
                    }

                    try {
                        $delay = $config->retryStrategy->refresh(
                            $retryState,
                            $error
                        );
                    } catch (TokenAcquisitionFailedException $e) {
                        Async::delay($e->delay);
                        break;
                    }

                    if (!self::prepareRequestForRetry(
                        $outCtx->request ?? $retryRequest,
                        $outCtx->response,
                        $effectiveHttpOptions,
                        $error
                    )) {
                        break;
                    }

                    Async::delay($delay);
                    ++$attempt;

                    continue;
                }

                $config->retryStrategy->recordSuccess($retryState);
                break;
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        $outCtx ??= new OutputContext(
            $operation,
            $input,
            $config,
            $reqCtx?->request,
            null,
            null
        );

        return $this->completeExecution($interceptor, $outCtx, $error);
    }

    /**
     * Run attempt completion hooks and return the resulting error.
     */
    private function completeAttempt(
        InterceptorInterface $interceptor,
        OutputContext $ctx,
        ?\Throwable $error
    ): ?\Throwable
    {
        try {
            $output = $interceptor
                ->modifyBeforeAttemptCompletion($ctx, $error);
            $ctx->replaceOutput($output);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
            $ctx->replaceOutput(null);
        }

        try {
            $interceptor->readAfterAttempt($ctx, $error);
        } catch (\Throwable $e) {
            $error = $e;
        }

        return $error;
    }

    /**
     * Run execution completion hooks and return the final output.
     */
    private function completeExecution(
        InterceptorInterface $interceptor,
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        try {
            $output = $interceptor->modifyBeforeCompletion($ctx, $error);
            $ctx->replaceOutput($output);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e;
            $ctx->replaceOutput(null);
        }

        try {
            $interceptor->readAfterExecution($ctx, $error);
        } catch (\Throwable $e) {
            $error = $e;
        }

        if ($error !== null) {
            throw $error;
        }

        return $output;
    }

    /**
     * Serialize the operation input.
     */
    private function serialize(
        OperationInterface $operation,
        SerializableStructInterface $input
    ): RequestInterface
    {
        return $this->config->protocol->serializeRequest(
            $operation,
            $input,
            self::UNRESOLVED_ENDPOINT
        );
    }

    private function resolveEndpoint(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ClientConfig $config
    ): Endpoint
    {
        return $config->endpointResolver->resolveEndpoint(
            new EndpointResolverParams($operation, $input, $config)
        );
    }

    private function applyEndpointHeaders(
        RequestInterface $request,
        Endpoint $endpoint
    ): RequestInterface
    {
        $headers = $endpoint->headers;
        if ($headers === []) {
            return $request;
        }

        $rawHeaders = [];
        foreach ($headers as $name => $value) {
            $lower = \strtolower($name);
            $rawHeaders[$lower] = [$name, [(string) $value]];
        }

        if ($request instanceof SmithyRequest) {
            return $request->withNormalizedHeaders($rawHeaders);
        }

        foreach ($rawHeaders as [$name, $values]) {
            $request = $request->withHeader($name, $values);
        }

        return $request;
    }

    private function applyOverrideHeaders(
        RequestInterface $request,
        ?RequestOverrideConfig $overrides
    ): RequestInterface
    {
        if ($overrides === null) {
            return $request;
        }

        foreach ($overrides->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        return $request;
    }

    /**
     * Resolve attempt-scoped auth and endpoint state.
     */
    private function prepareAttemptRequest(
        OperationInterface $operation,
        SerializableStructInterface $input,
        RequestInterface $request,
        ClientConfig $config,
        ?RequestOverrideConfig $overrides
    ): PreparedAttempt
    {
        $preference = $overrides?->authSchemePreference
            ?? $config->authSchemePreference;
        $resolvedAuthScheme = $config
            ->authSchemeResolver
            ->resolveAuthScheme(
                $operation,
                $config,
                $preference
            );
        $identityResolver = $resolvedAuthScheme
            ->scheme
            ->getIdentityResolver($config);
        $identity = $identityResolver
            ->resolveIdentity($resolvedAuthScheme->identityProperties)
            ->unwrap();
        $endpoint = $this->resolveEndpoint($operation, $input, $config);
        $signingAuthScheme = $config
            ->authSchemeResolver
            ->resolveEndpointAuthScheme(
                $resolvedAuthScheme,
                $operation,
                $config,
                $endpoint->authSchemes,
                $preference
            );
        if ($signingAuthScheme->scheme !== $resolvedAuthScheme->scheme) {
            $identityResolver = $signingAuthScheme
                ->scheme
                ->getIdentityResolver($config);
            $identity = $identityResolver
                ->resolveIdentity($signingAuthScheme->identityProperties)
                ->unwrap();
        }

        $request = $config->protocol->setServiceEndpoint(
            $request,
            $endpoint
        );
        $request = $this->applyEndpointHeaders($request, $endpoint);
        $request = $this->applyOverrideHeaders($request, $overrides);

        return new PreparedAttempt(
            $request,
            $signingAuthScheme,
            $identityResolver,
            $identity
        );
    }

    /**
     * Execute one signed HTTP attempt.
     *
     * On success the result error is null and its output context contains the
     * deserialized output. On failure the error is populated and the output
     * context describes how far the attempt got.
     */
    private function executeAttempt(
        InterceptorInterface $interceptor,
        RequestContext $reqCtx,
        ?RequestOverrideConfig $overrides,
        ?HttpRequestOptions $httpOptions
    ): AttemptResult
    {
        $response = null;
        $output = null;
        $error = null;
        $request = $reqCtx->request;
        $identity = null;
        $identityResolver = null;

        try {
            $interceptor->readBeforeAttempt($reqCtx);
        } catch (\Throwable $e) {
            $error = $e;
        }

        if ($error !== null) {
            return new AttemptResult(
                new OutputContext(
                    $reqCtx->operation,
                    $reqCtx->input,
                    $reqCtx->config,
                    $request,
                    null,
                    null
                ),
                $error
            );
        }

        try {
            $config = $reqCtx->config;
            $prepared = $this->prepareAttemptRequest(
                $reqCtx->operation,
                $reqCtx->input,
                $request,
                $config,
                $overrides
            );
            $request = $prepared->request;
            $identityResolver = $prepared->identityResolver;
            $identity = $prepared->identity;
            $reqCtx->replaceRequest($request);

            $request = $interceptor->modifyBeforeSigning($reqCtx);
            if ($request !== $reqCtx->request) {
                $reqCtx->replaceRequest($request);
            }

            $interceptor->readBeforeSigning($reqCtx);

            $request = $this->signRequest(
                $prepared->authScheme,
                $request,
                $identity
            )->signedRequest;
            $reqCtx->replaceRequest($request);

            $interceptor->readAfterSigning($reqCtx);

            $request = $interceptor->modifyBeforeTransmit($reqCtx);
            if ($request !== $reqCtx->request) {
                $reqCtx->replaceRequest($request);
            }

            $interceptor->readBeforeTransmit($reqCtx);

            $response = $this->config->httpClient->sendRequest(
                $request,
                $httpOptions
            );

            $respCtx = new ResponseContext(
                $reqCtx->operation,
                $reqCtx->input,
                $reqCtx->config,
                $request,
                $response
            );

            $interceptor->readAfterTransmit($respCtx);

            $response = $interceptor->modifyBeforeDeserialization($respCtx);
            if ($response !== $respCtx->response) {
                $respCtx->replaceResponse($response);
            }

            $interceptor->readBeforeDeserialization($respCtx);

            $deserError = null;
            try {
                $output = $this->deserializeResponse(
                    $reqCtx->operation,
                    $response
                );
            } catch (\Throwable $e) {
                $deserError = $e;
            }

            $outCtx = new OutputContext(
                $reqCtx->operation,
                $reqCtx->input,
                $reqCtx->config,
                $request,
                $response,
                $output,
                $identity,
                $identityResolver
            );

            // This hook runs even when deserialization fails.
            try {
                $interceptor->readAfterDeserialization($outCtx, $deserError);
            } catch (\Throwable $e) {
                $deserError = $e;
            }

            if ($deserError !== null) {
                $error = $deserError;
                $output = null;
                $outCtx->replaceOutput(null);
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        if (!isset($outCtx)) {
            $outCtx = new OutputContext(
                $reqCtx->operation,
                $reqCtx->input,
                $reqCtx->config,
                $request,
                $response,
                $output,
                $identity,
                $identityResolver
            );
        }

        return new AttemptResult($outCtx, $error);
    }

    private function executeWithoutInterceptors(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ClientConfig $config,
        ?RequestOverrideConfig $overrides,
        ?HttpRequestOptions $httpOptions,
        HttpRequestOptions $effectiveHttpOptions
    ): SerializableStructInterface
    {
        $request = $this->serialize($operation, $input);
        $request = $this->applyOverrideHeaders($request, $overrides);

        $requestFlags = self::resolveRetryRequestFlags($operation, $config);
        $retryState = $config->retryStrategy->begin(
            $config->retryScope,
            $requestFlags
        );

        $attempt = 1;
        while (true) {
            $attemptRequest = $request;
            $response = null;
            try {
                $prepared = $this->prepareAttemptRequest(
                    $operation,
                    $input,
                    $request,
                    $config,
                    $overrides
                );
                $attemptRequest = $prepared->request;
                $signedRequest = $this->signRequest(
                    $prepared->authScheme,
                    $attemptRequest,
                    $prepared->identity
                )->signedRequest;
                $attemptRequest = $signedRequest;
                $response = $config->httpClient->sendRequest(
                    $signedRequest,
                    $httpOptions
                );
                $output = $this->deserializeResponse(
                    $operation,
                    $response
                );
            } catch (\Throwable $error) {
                if ($overrides?->maxAttempts !== null
                    && $attempt >= $overrides->maxAttempts
                ) {
                    throw $error;
                }
                try {
                    $delay = $config->retryStrategy->refresh(
                        $retryState,
                        $error
                    );
                    if (!self::prepareRequestForRetry(
                        $attemptRequest,
                        $response,
                        $effectiveHttpOptions,
                        $error
                    )) {
                        throw $error;
                    }
                    Async::delay($delay);
                    ++$attempt;
                } catch (TokenAcquisitionFailedException $e) {
                    Async::delay($e->delay);
                    throw $error;
                }

                continue;
            }

            $config->retryStrategy->recordSuccess($retryState);

            return $output;
        }
    }

    /**
     * Reset mutable request state before another attempt.
     */
    private static function prepareRequestForRetry(
        RequestInterface $request,
        ?ResponseInterface $response,
        HttpRequestOptions $httpOptions,
        \Throwable $error
    ): bool
    {
        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            try {
                if ($body instanceof RequestStreamInterface
                    && $body->isReplayable()
                ) {
                    $body->rewindForRetry();
                } elseif ($body->isSeekable()) {
                    $body->rewind();
                } elseif (!self::unconsumedRequestBodyCanRetry(
                    $body,
                    $response,
                    $error
                )) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        try {
            $httpOptions->trailers?->rewindForRetry();
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether a non-replayable body was left untouched.
     */
    private static function unconsumedRequestBodyCanRetry(
        \Psr\Http\Message\StreamInterface $body,
        ?ResponseInterface $response,
        \Throwable $error
    ): bool
    {
        if ($response !== null) {
            return false;
        }

        $httpError = self::findHttpException($error);
        if ($httpError !== null) {
            return $httpError->requestBodySeekable === false
                && !$httpError->requestBodyStarted
                && !$httpError->requestBodyFullyConsumed;
        }

        return $body instanceof RequestStreamInterface
            && !$body->started()
            && !$body->fullyConsumed();
    }

    private static function findHttpException(
        \Throwable $error
    ): ?HttpException
    {
        do {
            if ($error instanceof HttpException) {
                return $error;
            }

            $error = $error->getPrevious();
        } while ($error !== null);

        return null;
    }

    private function deserializeResponse(
        OperationInterface $operation,
        ResponseInterface $response
    ): SerializableStructInterface
    {
        return $this->config->protocol->deserializeResponse(
            $operation,
            $response
        );
    }

    /**
     * Sign one attempt and always release the attempt-scoped signer.
     *
     * A cleanup failure is surfaced after successful signing. If signing
     * already failed, its error remains authoritative because PHP has no
     * suppressed-exception mechanism.
     */
    private function signRequest(
        ResolvedAuthScheme $authScheme,
        RequestInterface $request,
        IdentityInterface $identity
    ): SignerResult
    {
        $signer = $authScheme->scheme->getSigner();
        $signingError = null;

        try {
            return $signer->sign(
                $request,
                $identity,
                $authScheme->signerProperties
            );
        } catch (\Throwable $error) {
            $signingError = $error;
            throw $error;
        } finally {
            try {
                $signer->close();
            } catch (\Throwable $cleanupError) {
                if ($signingError === null) {
                    throw $cleanupError;
                }
            }
        }
    }

    /**
     * Return retry request flags for this execution.
     */
    private static function resolveRetryRequestFlags(
        OperationInterface $operation,
        ClientConfig $config
    ): int
    {
        $resolver = $config->context[
            RetryRequestFlagResolverInterface::CONFIG_KEY
        ] ?? null;
        if (!$resolver instanceof RetryRequestFlagResolverInterface) {
            return 0;
        }

        return $resolver->resolve($operation, $config);
    }
}
