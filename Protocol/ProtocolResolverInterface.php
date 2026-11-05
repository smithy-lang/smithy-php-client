<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Protocol;

use Smithy\Core\Shape\ServiceInterface;
use Smithy\Protocol\ProtocolInterface;

/**
 * Select a protocol implementation from runtime service metadata.
 */
interface ProtocolResolverInterface
{
    /**
     * Resolve the protocol used by a service.
     */
    public function resolveProtocol(
        ServiceInterface $service,
        ?string $protocolId = null,
        ?ProtocolInterface $protocol = null
    ): ProtocolInterface;

    /**
     * Return every registered protocol implementation.
     *
     * @return array<string, ProtocolInterface>
     */
    public function getProtocols(): array;

    /**
     * Return registered protocols modeled by the service.
     *
     * @return array<string, ProtocolInterface>
     */
    public function getApplicableProtocols(ServiceInterface $service): array;
}
