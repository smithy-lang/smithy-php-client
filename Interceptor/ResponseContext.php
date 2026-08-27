<?php

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Smithy\Client\ClientConfig;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Hook data available after the response has arrived but before it has
 * been deserialized into an output shape.
 *
 * Supplied to `readAfterTransmit`, `modifyBeforeDeserialization`, and
 * `readBeforeDeserialization`.
 */
final class ResponseContext
{
    /**
     * Create response hook context.
     */
    public function __construct(
        public OperationInterface $operation,
        public SerializableStructInterface $input,
        public ClientConfig $config,
        public RequestInterface $request,
        public ResponseInterface $response
    ) {}

    /**
     * Replace the mutable response value in this context.
     *
     * @return void
     */
    public function replaceResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * Return a new context with `response` replaced.
     */
    public function withResponse(ResponseInterface $response): self
    {
        return $response === $this->response
            ? $this
            : new self(
                $this->operation,
                $this->input,
                $this->config,
                $this->request,
                $response
            );
    }
}
