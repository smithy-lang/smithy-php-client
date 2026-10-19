<?php

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Client\ClientConfig;
use Smithy\Client\Transport\TransportRequestInterface;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Hook data available after the input has been serialized to a request,
 * but before the response has arrived.
 *
 * Supplied to `readAfterSerialization`, `modifyBeforeRetryLoop`,
 * `readBeforeAttempt`, `modifyBeforeSigning`, `readAfterSigning`,
 * `modifyBeforeTransmit`, and `readBeforeTransmit`.
 */
final class RequestContext
{
    /**
     * Create request hook context.
     */
    public function __construct(
        public OperationInterface $operation,
        public SerializableStructInterface $input,
        public ClientConfig $config,
        public TransportRequestInterface $request
    ) {}

    /**
     * Replace the mutable request value in this context.
     *
     * @return void
     */
    public function replaceRequest(TransportRequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Return a new context with `request` replaced.
     */
    public function withRequest(TransportRequestInterface $request): self
    {
        return $request === $this->request
            ? $this
            : new self(
                $this->operation,
                $this->input,
                $this->config,
                $request
            );
    }
}
