<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Core\Transport\TransportRequestInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Shape\OperationInterface;
use Smithy\Http\Message\{
    AsyncRequestStreamInterface,
    HttpTransportRequest,
    Request,
    RequestStreamInterface,
    ResourceRequestStream,
    StreamBodyAdapter
};

/**
 * Add a required Content-MD5 header before request signing.
 */
final class HttpChecksumInterceptor extends AbstractInterceptor
{
    /** @inheritDoc */
    public function appliesTo(OperationInterface $operation): bool
    {
        return $operation->getSchema()->hasTrait(
            Schema::T_HTTP_CHECKSUM_REQUIRED
        );
    }

    /**
     * @inheritDoc
     *
     * @throws \RuntimeException If the request body cannot be buffered.
     */
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        if (!$ctx->operation->getSchema()->hasTrait(
            Schema::T_HTTP_CHECKSUM_REQUIRED
        )) {
            return $ctx->request;
        }

        $request = HttpTransportRequest::expect($ctx->request);
        $httpRequest = $request->request;
        if ($httpRequest->hasHeader('Content-MD5')) {
            return $ctx->request;
        }

        $body = StreamBodyAdapter::wrap($httpRequest->getBody());

        return $body->isReplayable()
            ? $ctx->request
            : $request->withRequest(
                $httpRequest->withBody(self::bufferBody($body))
            );
    }

    /**
     * @inheritDoc
     *
     * @throws \RuntimeException If the request body cannot be hashed.
     */
    public function modifyBeforeSigning(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        if (!$ctx->operation->getSchema()->hasTrait(
            Schema::T_HTTP_CHECKSUM_REQUIRED
        )) {
            return $ctx->request;
        }

        $transportRequest = HttpTransportRequest::expect($ctx->request);
        $request = $transportRequest->request;
        if ($request->hasHeader('Content-MD5')) {
            return $ctx->request;
        }

        $body = StreamBodyAdapter::wrap($request->getBody());
        if (!$body->isReplayable()) {
            $bufferedBody = self::bufferBody($body);
            $request = $request->withBody($bufferedBody);
            $body = $bufferedBody;
        }
        $checksum = \base64_encode($body->hash('md5', true));

        $request = $request instanceof Request
            ? $request->withNormalizedHeader(
                'content-md5',
                'Content-MD5',
                [$checksum]
            )
            : $request->withHeader('Content-MD5', $checksum);

        return $transportRequest->withRequest($request);
    }

    /**
     * Buffer a one-shot request body into a replayable temporary stream.
     *
     * @throws \RuntimeException If the body cannot be buffered.
     */
    private static function bufferBody(
        RequestStreamInterface $body
    ): ResourceRequestStream
    {
        $output = null;

        try {
            $output = ResourceRequestStream::temp();

            while (!$body->eof()) {
                if ($body instanceof AsyncRequestStreamInterface) {
                    $body->awaitReadable();
                }
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    if ($body->eof()) {
                        break;
                    }

                    throw new \RuntimeException(
                        'Request body returned no data before EOF.'
                    );
                }

                $output->write($chunk);
            }

            $output->rewindForRetry();

            return $output;
        } catch (\Throwable $error) {
            if ($body instanceof AsyncRequestStreamInterface) {
                $body->detachConsumer();
            }
            $output?->close();

            throw $error;
        }
    }
}
