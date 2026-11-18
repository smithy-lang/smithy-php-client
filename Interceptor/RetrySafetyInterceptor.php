<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Core\Exception\ApiException;
use Smithy\Core\Retry\RetryInfoInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Apply modeled retry information to an attempt's error.
 *
 * Matches smithy-java's `ApplyModelRetryInfoPlugin`. Runs in
 * `modifyBeforeAttemptCompletion`, which the pipeline invokes before the
 * retry strategy classifies the attempt's error. The first error in the
 * previous-chain that carries retry info decides, exactly as
 * {@see \Smithy\Client\Retry\StandardRetryStrategy} reads it. When that error
 * is an {@see ApiException}:
 *
 * 1. If the operation is `@readonly` or `@idempotent`, a
 *    {@see \Smithy\Core\Retry\RetrySafety::Maybe} is promoted to `Yes`. A
 *    `No` or `Yes` is never changed.
 * 2. If the error is modeled and its schema has `@retryable`, it is retry
 *    safe, and a throttle when the trait sets `throttling`. Generated errors
 *    already apply this in their constructor; this covers runtime-loaded
 *    errors such as {@see \Smithy\Core\Shape\Exception\DocumentException}.
 *    It is not gated on `Maybe`: smithy-php derives `No` from a 4xx status,
 *    while the trait overrides that, as it does for generated errors.
 */
final class RetrySafetyInterceptor extends AbstractInterceptor
{
    /**
     * @inheritDoc
     *
     * @throws \Throwable The attempt error, possibly reclassified.
     */
    public function modifyBeforeAttemptCompletion(
        OutputContext $ctx,
        ?\Throwable $error
    ): SerializableStructInterface
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof RetryInfoInterface) {
                if ($e instanceof ApiException) {
                    self::apply($ctx->operation, $e);
                }
                break;
            }
        }

        return parent::modifyBeforeAttemptCompletion($ctx, $error);
    }

    private static function apply(
        OperationInterface $operation,
        ApiException $error
    ): void
    {
        $schema = $operation->getSchema();
        if ($schema->hasTrait(Schema::T_READ_ONLY)
            || $schema->hasTrait(Schema::T_IDEMPOTENT)
        ) {
            $error->promoteMaybeRetrySafety();
        }

        if ($error instanceof SerializableStructInterface) {
            $retryable = $error->getSchema()->getTrait(Schema::T_RETRYABLE);
            if ($retryable !== null) {
                // `{}` materializes as `true`; otherwise the trait's object.
                $error->applyRetryableTrait(
                    \is_array($retryable)
                        && ($retryable['throttling'] ?? false) === true
                );
            }
        }
    }
}
