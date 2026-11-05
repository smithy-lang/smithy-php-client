<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

/**
 * Lazily materialized waiter definitions for an operation schema.
 *
 * @internal
 */
final readonly class WaiterSchemaExtension
{
    /**
     * @var array<string, WaiterDefinition>
     */
    private array $definitionsByNormalizedName;

    /**
     * @param array<string, WaiterDefinition> $definitions
     */
    public function __construct(
        private array $definitions
    )
    {
        $definitionsByNormalizedName = [];
        foreach ($definitions as $name => $definition) {
            $definitionsByNormalizedName[
                WaiterDefinitionNormalizer::normalizeName($name)
            ] = $definition;
        }

        $this->definitionsByNormalizedName = $definitionsByNormalizedName;
    }

    /**
     * Resolve a waiter by its modeled name.
     */
    public function get(string $name): ?WaiterDefinition
    {
        return $this->definitionsByNormalizedName[
            WaiterDefinitionNormalizer::normalizeName($name)
        ] ?? null;
    }

    /**
     * Return every modeled waiter in declaration order.
     *
     * @return array<string, WaiterDefinition>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }
}
