<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Client\ClientConfig;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Hook data available while resolving call-scoped configuration.
 */
final readonly class CallContext
{
    public function __construct(
        public OperationInterface $operation,
        public SerializableStructInterface $input,
        public ClientConfig $config
    ) {}

    /**
     * Return a context with the client configuration replaced.
     */
    public function withConfig(ClientConfig $config): self
    {
        return $config === $this->config
            ? $this
            : new self($this->operation, $this->input, $config);
    }
}
