<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint;

use Smithy\Client\ClientConfig;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Carry parameters supplied to an endpoint resolver.
 *
 * Constructed once per execution by the pipeline. Modeled endpoint resolvers
 * combine operation and input traits with endpoint parameters stored in
 * `config`; static resolvers ignore all fields.
 */
final class EndpointResolverParams
{
    /**
     * ClientConfig::context key containing endpoint parameter values.
     */
    public const CONTEXT_PARAMETERS = 'smithy.endpointParameters';

    /**
     * ClientConfig::context key containing an endpoint resolution trace sink.
     */
    public const CONTEXT_TRACE_SINK = 'smithy.endpointResolutionTraceSink';

    /**
     * Create endpoint resolver parameters.
     */
    public function __construct(
        public readonly OperationInterface $operation,
        public readonly SerializableStructInterface $input,
        public readonly ClientConfig $config
    ) {}
}
