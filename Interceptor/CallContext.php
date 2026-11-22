<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Client\AbstractClientConfig;
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
        public AbstractClientConfig $config
    ) {}

    /**
     * Return a context with the client configuration replaced.
     */
    public function withConfig(AbstractClientConfig $config): self
    {
        return $config === $this->config
            ? $this
            : new self($this->operation, $this->input, $config);
    }
}
