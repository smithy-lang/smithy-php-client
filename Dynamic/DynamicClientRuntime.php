<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Dynamic;

use Smithy\Client\Auth\AuthSchemeInterface;
use Smithy\Client\Endpoint\Bytecode\RulesFunctionInterface;
use Smithy\Client\Protocol\ProtocolResolver;
use Smithy\Core\Serde\SchemaTraitRegistry;
use Smithy\Protocol\ProtocolInterface;

/**
 * Runtime implementations available to dynamic clients.
 *
 * @internal Used by dynamic client implementations and provider subclasses.
 */
final class DynamicClientRuntime
{
    private readonly ProtocolResolver $protocolResolver;
    /** @var list<AuthSchemeInterface> */
    private readonly array $authSchemes;
    /** @var array<string, RulesFunctionInterface> */
    private readonly array $endpointFunctions;
    /** @var array<string, string> */
    private readonly array $endpointAuthSchemeIds;
    /** @var array<string, string> */
    private readonly array $errorDiscriminatorTraits;

    /**
     * @param array<string, ProtocolInterface> $protocols
     * @param list<AuthSchemeInterface> $authSchemes
     * @param array<string, RulesFunctionInterface> $endpointFunctions
     * @param array<string, string> $endpointAuthSchemeIds
     * @param list<string> $protocolPreference
     * @param array<string, string> $errorDiscriminatorTraits
     */
    public function __construct(
        array $protocols = [],
        array $authSchemes = [],
        array $endpointFunctions = [],
        array $endpointAuthSchemeIds = [],
        array $protocolPreference = [],
        private readonly ?SchemaTraitRegistry $traitRegistry = null,
        array $errorDiscriminatorTraits = []
    )
    {
        $this->protocolResolver = new ProtocolResolver(
            $protocols,
            $protocolPreference
        );
        $this->authSchemes = self::validateAuthSchemes($authSchemes);
        $this->endpointFunctions = self::validateEndpointFunctions(
            $endpointFunctions
        );
        $this->endpointAuthSchemeIds = self::validateEndpointAuthSchemeIds(
            $endpointAuthSchemeIds
        );
        $this->errorDiscriminatorTraits =
            self::validateErrorDiscriminatorTraits(
                $errorDiscriminatorTraits
            );
    }

    /**
     * Merge another runtime over this one.
     */
    public function merge(self $runtime): self
    {
        if ($runtime === $this) {
            return $this;
        }

        return new self(
            protocols: [
                ...$this->getProtocols(),
                ...$runtime->getProtocols()
            ],
            authSchemes: [
                ...$this->authSchemes,
                ...$runtime->authSchemes
            ],
            endpointFunctions: [
                ...$this->endpointFunctions,
                ...$runtime->endpointFunctions
            ],
            endpointAuthSchemeIds: [
                ...$this->endpointAuthSchemeIds,
                ...$runtime->endpointAuthSchemeIds
            ],
            protocolPreference: [
                ...$runtime->getProtocolPreference(),
                ...$this->getProtocolPreference()
            ],
            traitRegistry: $runtime->traitRegistry ?? $this->traitRegistry,
            errorDiscriminatorTraits: [
                ...$this->errorDiscriminatorTraits,
                ...$runtime->errorDiscriminatorTraits
            ]
        );
    }

    /**
     * Return protocol implementations keyed by protocol shape ID.
     *
     * @return array<string, ProtocolInterface>
     */
    public function getProtocols(): array
    {
        return $this->protocolResolver->getProtocols();
    }

    /**
     * Return the shared protocol resolver registrations.
     */
    public function getProtocolResolver(): ProtocolResolver
    {
        return $this->protocolResolver;
    }

    /**
     * Return authentication schemes.
     *
     * @return list<AuthSchemeInterface>
     */
    public function getAuthSchemes(): array
    {
        return $this->authSchemes;
    }

    /**
     * Return endpoint functions keyed by function name.
     *
     * @return array<string, RulesFunctionInterface>
     */
    public function getEndpointFunctions(): array
    {
        return $this->endpointFunctions;
    }

    /**
     * Return endpoint auth scheme aliases.
     *
     * @return array<string, string>
     */
    public function getEndpointAuthSchemeIds(): array
    {
        return $this->endpointAuthSchemeIds;
    }

    /**
     * Return preferred protocol shape IDs.
     *
     * @return list<string>
     */
    public function getProtocolPreference(): array
    {
        return $this->protocolResolver->getPreference();
    }

    /**
     * Return the custom schema trait registry, when configured.
     */
    public function getTraitRegistry(): ?SchemaTraitRegistry
    {
        return $this->traitRegistry;
    }

    /**
     * Return error traits that contribute wire discriminators.
     *
     * @return array<string, string>
     */
    public function getErrorDiscriminatorTraits(): array
    {
        return $this->errorDiscriminatorTraits;
    }

    /**
     * Validate and deduplicate authentication scheme registrations.
     *
     * @param array<mixed> $authSchemes
     *
     * @return list<AuthSchemeInterface>
     */
    private static function validateAuthSchemes(array $authSchemes): array
    {
        $byId = [];
        foreach ($authSchemes as $scheme) {
            if (!$scheme instanceof AuthSchemeInterface) {
                throw new \InvalidArgumentException(
                    'Authentication schemes must implement '
                    . AuthSchemeInterface::class
                    . '.'
                );
            }

            $schemeId = $scheme->getSchemeId();
            if ($schemeId === '') {
                throw new \InvalidArgumentException(
                    'Authentication scheme IDs cannot be empty.'
                );
            }
            $byId[$schemeId] = $scheme;
        }

        return \array_values($byId);
    }

    /**
     * Validate endpoint function registrations.
     *
     * @param array<mixed> $functions
     *
     * @return array<string, RulesFunctionInterface>
     */
    private static function validateEndpointFunctions(
        array $functions
    ): array
    {
        foreach ($functions as $name => $function) {
            if (!\is_string($name)
                || $name === ''
                || !$function instanceof RulesFunctionInterface
                || $function->getName() !== $name
            ) {
                throw new \InvalidArgumentException(
                    'Endpoint function mappings must contain function '
                    . 'names mapped to matching RulesFunctionInterface '
                    . 'instances.'
                );
            }
        }

        return $functions;
    }

    /**
     * Validate endpoint authentication scheme aliases.
     *
     * @param array<mixed> $schemeIds
     *
     * @return array<string, string>
     */
    private static function validateEndpointAuthSchemeIds(
        array $schemeIds
    ): array
    {
        foreach ($schemeIds as $name => $schemeId) {
            if (!\is_string($name)
                || $name === ''
                || !\is_string($schemeId)
                || $schemeId === ''
            ) {
                throw new \InvalidArgumentException(
                    'Endpoint auth scheme mappings must contain names '
                    . 'mapped to canonical Smithy shape IDs.'
                );
            }
        }

        return $schemeIds;
    }

    /**
     * Validate error discriminator trait registrations.
     *
     * @param array<mixed> $traits
     *
     * @return array<string, string>
     */
    private static function validateErrorDiscriminatorTraits(
        array $traits
    ): array
    {
        foreach ($traits as $traitId => $memberName) {
            if (!\is_string($traitId)
                || $traitId === ''
                || !\is_string($memberName)
                || $memberName === ''
            ) {
                throw new \InvalidArgumentException(
                    'Error discriminator trait mappings must contain '
                    . 'trait shape IDs mapped to member names.'
                );
            }
        }

        return $traits;
    }
}
