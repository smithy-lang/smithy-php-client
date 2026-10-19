<?php

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Smithy\Client\Transport\TransportRequestInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Http\Message\{
    AsyncRequestStreamInterface,
    HttpTransportRequest,
    Request,
    RequestStreamInterface,
    ResourceRequestStream
};

/**
 * Compress eligible request bodies before entering the retry loop.
 */
final class RequestCompressionInterceptor extends AbstractInterceptor
{
    /**
     * @param bool $disabled Disable modeled request compression.
     * @param int $minCompressionSizeBytes Minimum body size to compress.
     */
    public function __construct(
        private readonly bool $disabled = false,
        private readonly int $minCompressionSizeBytes = 10240
    ) {}

    /**
     * @inheritDoc
     *
     * @throws \RuntimeException If gzip compression fails.
     */
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        if ($this->disabled) {
            return $ctx->request;
        }

        $encodings = $ctx->operation->getSchema()->getTrait(
            Schema::T_REQUEST_COMPRESSION
        );
        if ($encodings === null || $encodings === []) {
            return $ctx->request;
        }

        $algorithm = null;
        foreach ($encodings as $encoding) {
            if ($encoding === 'gzip'
                && self::supportsGzip()
            ) {
                $algorithm = 'gzip';
                break;
            }
        }

        if ($algorithm === null) {
            return $ctx->request;
        }

        $transportRequest = HttpTransportRequest::expect($ctx->request);
        $request = $transportRequest->request;
        $body = $request->getBody();
        $encodingValues = $request->getHeader('Content-Encoding');
        $encodingValues[] = $algorithm;

        if ($ctx->operation->getInputStreamMember() !== null) {
            return $transportRequest->withRequest(
                self::withCompressedBody(
                    $request,
                    self::compressBody($body),
                    $encodingValues
                )
            );
        }

        $size = $body->getSize();
        if ($size === null || $size < $this->minCompressionSizeBytes) {
            return $ctx->request;
        }

        return $transportRequest->withRequest(
            self::withCompressedBody(
                $request,
                self::compressBody($body),
                $encodingValues
            )
        );
    }

    /**
     * Compress a request body into a replayable temporary stream.
     *
     * @throws \RuntimeException If the body cannot be read or compressed.
     */
    private static function compressBody(
        StreamInterface $body
    ): ResourceRequestStream {
        $output = null;
        $position = null;

        try {
            $context = \deflate_init(ZLIB_ENCODING_GZIP);
            if ($context === false) {
                throw new \RuntimeException(
                    'Unable to initialize gzip compression.'
                );
            }

            $position = $body->isSeekable() ? $body->tell() : null;
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

                $compressed = \deflate_add(
                    $context,
                    $chunk,
                    ZLIB_NO_FLUSH
                );
                if ($compressed === false) {
                    throw new \RuntimeException(
                        'Failed to gzip request body.'
                    );
                }

                if ($compressed !== '') {
                    $output->write($compressed);
                }
            }

            $compressed = \deflate_add(
                $context,
                '',
                ZLIB_FINISH
            );
            if ($compressed === false) {
                throw new \RuntimeException(
                    'Failed to finish gzip request body.'
                );
            }

            if ($compressed !== '') {
                $output->write($compressed);
            }
            $output->rewindForRetry();

            return $output;
        } catch (\Throwable $error) {
            if ($body instanceof AsyncRequestStreamInterface) {
                $body->detachConsumer();
            }
            $output?->close();

            throw $error;
        } finally {
            if ($body instanceof RequestStreamInterface
                && $body->isReplayable()
            ) {
                $body->rewindForRetry();
            } elseif ($position !== null) {
                $body->seek($position);
            }
        }
    }

    /**
     * Replace a request body and apply its compressed framing headers.
     *
     * @param list<string> $encodingValues
     */
    private static function withCompressedBody(
        RequestInterface $request,
        StreamInterface $body,
        array $encodingValues
    ): RequestInterface {
        $updated = $request->withBody($body);

        if ($updated->hasHeader('Transfer-Encoding')) {
            $updated = $updated->withoutHeader('Content-Length');

            return $updated instanceof Request
                ? $updated->withNormalizedHeader(
                    'content-encoding',
                    'Content-Encoding',
                    $encodingValues
                )
                : $updated->withHeader(
                    'Content-Encoding',
                    $encodingValues
                );
        }

        $compressedLength = $body->getSize();
        if ($compressedLength === null) {
            throw new \RuntimeException(
                'Unable to determine compressed request body length.'
            );
        }
        $compressedLength = (string) $compressedLength;

        if ($updated instanceof Request) {
            return $updated->withNormalizedHeaders([
                'content-encoding' => ['Content-Encoding', $encodingValues],
                'content-length' => ['Content-Length', [$compressedLength]]
            ]);
        }

        return $updated
            ->withHeader('Content-Encoding', $encodingValues)
            ->withHeader('Content-Length', $compressedLength);
    }

    /**
     * Determine whether the runtime supports gzip compression.
     */
    private static function supportsGzip(): bool
    {
        return \function_exists('deflate_init')
            && \function_exists('deflate_add');
    }
}
