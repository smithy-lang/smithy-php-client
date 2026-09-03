<?php

declare(strict_types=1);

namespace Smithy\Client;

use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Define the contract for generated service clients.
 *
 *  - {@see getConfig} exposes the frozen {@see ClientConfig}. Subclasses
 *    narrow the return type through covariance.
 *  - {@see executeOperation} is the generic entry point. Generated typed
 *    methods (`putObject(PutObjectInput): PutObjectOutput`) delegate to
 *    it. Direct callers use it when the operation is known only at runtime.
 */
interface ClientInterface
{
    /**
     * Access the frozen configuration.
     */
    public function getConfig(): ClientConfig;

    /**
     * Execute an operation through the client's pipeline.
     *
     * Generated typed methods such as `putObject()` delegate here. The
     * generated method narrows the base return type to its output shape.
     * Request overrides apply only to this execution.
     */
    public function executeOperation(
        OperationInterface $operation,
        SerializableStructInterface $input,
        ?RequestOverrideConfig $overrides = null
    ): SerializableStructInterface;
}
