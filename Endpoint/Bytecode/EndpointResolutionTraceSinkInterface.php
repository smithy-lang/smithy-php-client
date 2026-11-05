<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Selects endpoint resolutions to trace.
 *
 * A sink may be shared by many clients and resolutions. Returning null keeps
 * the normal cached resolution path; returning a trace opts the resolution
 * into an uncached, instrumented evaluation.
 */
interface EndpointResolutionTraceSinkInterface
{
    /**
     * Begin tracing an endpoint resolution, or return null to skip it.
     *
     * The supplied parameter map is the resolver input after client,
     * operation, and request values have been merged. The returned trace
     * receives the effective parameter map after modeled defaults are applied.
     *
     * @param array<string, mixed> $parameters
     */
    public function begin(
        Bytecode $bytecode,
        array $parameters
    ): ?EndpointResolutionTraceInterface;
}
