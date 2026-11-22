<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client;

/**
 * Immutable configuration for a {@see ClientInterface}.
 *
 * General-purpose {@see AbstractClientConfig} that copies the base fields.
 */
class ClientConfig extends AbstractClientConfig
{
    /**
     * Shallow copy the config, overriding fields named in `$overrides`.
     * Subclasses override this to include their own fields.
     *
     * Explicit `null` values are ignored and preserve the current value.
     *
     * @param array<string, mixed> $overrides
     */
    protected function copyWith(array $overrides): static
    {
        $protocol = \array_key_exists('protocol', $overrides)
            ? $overrides['protocol']
            : $this->protocol;
        $protocolResolver = $overrides['protocolResolver']
            ?? $this->protocolResolver;
        $protocolId = \array_key_exists('protocolId', $overrides)
            ? $overrides['protocolId']
            : $this->protocolId;
        $explicitFields = $this->explicitFieldsFor($overrides);
        $transport = $overrides['transport'] ?? $this->transport;
        $endpointResolver =
            $overrides['endpointResolver'] ?? $this->endpointResolver;
        $interceptors =
            $overrides['interceptors'] ?? $this->interceptorChain;
        $supportedAuthSchemes = $overrides['supportedAuthSchemes']
            ?? $this->supportedAuthSchemes;
        $authSchemeResolver = $overrides['authSchemeResolver']
            ?? $this->authSchemeResolver;
        $identityResolvers = $overrides['identityResolvers']
            ?? $this->identityResolvers;
        $retryStrategy =
            $overrides['retryStrategy'] ?? $this->retryStrategy;
        $retryScope = $overrides['retryScope'] ?? $this->retryScope;
        $context = $overrides['context'] ?? $this->context;
        $service = $overrides['service'] ?? $this->service;
        $pluginState = $overrides['pluginState'] ?? $this->getPluginState();

        return new static(
            transport: $transport,
            endpointResolver: $endpointResolver,
            protocol: $protocol,
            protocolResolver: $protocolResolver,
            protocolId: $protocolId,
            interceptors: $interceptors,
            supportedAuthSchemes: $supportedAuthSchemes,
            authSchemeResolver: $authSchemeResolver,
            retryStrategy: $retryStrategy,
            retryScope: $retryScope,
            context: $context,
            authSchemePreference: $overrides['authSchemePreference']
                ?? $this->authSchemePreference,
            service: $service,
            pluginState: $pluginState,
            explicitFields: $explicitFields,
            identityResolvers: $identityResolvers
        );
    }
}
