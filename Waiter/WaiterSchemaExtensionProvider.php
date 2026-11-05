<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Waiter;

use Smithy\Core\Serde\{
    Schema,
    SchemaExtensionKey,
    SchemaExtensionProviderInterface
};

/**
 * Provide modeled waiter definitions for operation schemas.
 *
 * @internal
 */
final class WaiterSchemaExtensionProvider implements
    SchemaExtensionProviderInterface
{
    public const TRAIT = Schema::T_WAITABLE;

    private static ?SchemaExtensionKey $withOutputKey = null;
    private static ?SchemaExtensionKey $withoutOutputKey = null;

    private function __construct(
        private readonly bool $hasOutput
    ) {}

    /**
     * Get the waiter extension key for an operation's output shape.
     */
    public static function key(bool $hasOutput = true): SchemaExtensionKey
    {
        if ($hasOutput) {
            return self::$withOutputKey ??= new SchemaExtensionKey(
                new self(true)
            );
        }

        return self::$withoutOutputKey ??= new SchemaExtensionKey(
            new self(false)
        );
    }

    /**
     * Materialize and validate waiter metadata from an operation schema.
     */
    public function provide(Schema $schema): ?WaiterSchemaExtension
    {
        $trait = $schema->getTrait(self::TRAIT);
        if (!\is_array($trait) || $trait === []) {
            return null;
        }

        $normalized = WaiterDefinitionNormalizer::normalize(
            $schema->id,
            $trait,
            $this->hasOutput
        );
        $definitions = [];
        foreach ($normalized as $name => $definition) {
            $definitions[$name] = WaiterDefinition::fromArray(
                $name,
                $definition
            );
        }

        return new WaiterSchemaExtension($definitions);
    }
}
