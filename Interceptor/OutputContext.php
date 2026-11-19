<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Auth\{
    IdentityInterface,
    IdentityResolverInterface
};
use Smithy\Client\ClientConfig;
use Smithy\Core\Transport\{
    TransportRequestInterface,
    TransportResponseInterface
};
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Hook data available after the response has been deserialized (or an
 * attempt has failed).
 *
 * Supplied to `readAfterDeserialization`, `modifyBeforeAttemptCompletion`,
 * `readAfterAttempt`, `modifyBeforeCompletion`, and `readAfterExecution`.
 *
 * The `output` field is null when the attempt failed. The current error
 * is passed to hooks separately (matching Java's
 * `readAfterAttempt(hook, error)` signature) so that hooks always see a
 * populated context object regardless of success or failure.
 *
 * `request` and `response` are nullable because they may not exist yet at
 * the point the completion hooks fire. An error thrown from
 * `modifyBeforeSerialization` invokes `modifyBeforeCompletion` before a
 * request is constructed.
 *
 * `identity` and `identityResolver` identify the exact attempt-scoped
 * identity and resolver selected for signing. They are null when execution
 * fails before identity resolution or when the operation does not use auth.
 */
final class OutputContext
{
    /**
     * Create output hook context.
     */
    public function __construct(
        public OperationInterface $operation,
        public SerializableStructInterface $input,
        public ClientConfig $config,
        public ?TransportRequestInterface $request,
        public ?TransportResponseInterface $response,
        public ?SerializableStructInterface $output,
        public ?IdentityInterface $identity = null,
        public ?IdentityResolverInterface $identityResolver = null
    ) {}

    /**
     * Replace the mutable output value in this context.
     *
     * @return void
     */
    public function replaceOutput(?SerializableStructInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * Replace the mutable response value in this context.
     *
     * @return void
     */
    public function replaceResponse(
        ?TransportResponseInterface $response
    ): void
    {
        $this->response = $response;
    }

    /**
     * Return a new context with `output` replaced.
     */
    public function withOutput(?SerializableStructInterface $output): self
    {
        return $output === $this->output
            ? $this
            : new self(
                $this->operation,
                $this->input,
                $this->config,
                $this->request,
                $this->response,
                $output,
                $this->identity,
                $this->identityResolver
            );
    }

    /**
     * Return a new context with `response` replaced.
     */
    public function withResponse(
        ?TransportResponseInterface $response
    ): self
    {
        return $response === $this->response
            ? $this
            : new self(
                $this->operation,
                $this->input,
                $this->config,
                $this->request,
                $response,
                $this->output,
                $this->identity,
                $this->identityResolver
            );
    }
}
