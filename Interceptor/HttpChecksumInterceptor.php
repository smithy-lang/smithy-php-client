<?php

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Psr\Http\Message\RequestInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Http\Message\{
    AsyncRequestStreamInterface,
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
    /**
     * @inheritDoc
     *
     * @throws \RuntimeException If the request body cannot be buffered.
     */
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): RequestInterface
    {
        if (!$ctx->operation->getSchema()->hasTrait(
            Schema::T_HTTP_CHECKSUM_REQUIRED
        )) {
            return $ctx->request;
        }

        if ($ctx->request->hasHeader('Content-MD5')) {
            return $ctx->request;
        }

        $body = StreamBodyAdapter::wrap($ctx->request->getBody());

        return $body->isReplayable()
            ? $ctx->request
            : $ctx->request->withBody(self::bufferBody($body));
    }

    /**
     * @inheritDoc
     *
     * @throws \RuntimeException If the request body cannot be hashed.
     */
    public function modifyBeforeSigning(
        RequestContext $ctx
    ): RequestInterface
    {
        if (!$ctx->operation->getSchema()->hasTrait(
            Schema::T_HTTP_CHECKSUM_REQUIRED
        )) {
            return $ctx->request;
        }

        if ($ctx->request->hasHeader('Content-MD5')) {
            return $ctx->request;
        }

        $request = $ctx->request;
        $body = StreamBodyAdapter::wrap($request->getBody());
        if (!$body->isReplayable()) {
            $bufferedBody = self::bufferBody($body);
            $request = $request->withBody($bufferedBody);
            $body = $bufferedBody;
        }
        $checksum = \base64_encode($body->hash('md5', true));

        return $request instanceof Request
            ? $request->withNormalizedHeader(
                'content-md5',
                'Content-MD5',
                [$checksum]
            )
            : $request->withHeader('Content-MD5', $checksum);
    }

    /**
     * Buffer a one-shot request body into a replayable temporary stream.
     *
     * @throws \RuntimeException If the body cannot be buffered.
     */
    private static function bufferBody(
        RequestStreamInterface $body
    ): ResourceRequestStream {
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
