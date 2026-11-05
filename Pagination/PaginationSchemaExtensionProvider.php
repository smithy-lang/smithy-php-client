<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Pagination;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;

/**
 * Provide modeled pagination metadata for operation schemas.
 *
 * @internal
 */
final class PaginationSchemaExtensionProvider implements
    SchemaExtensionProviderInterface
{
    public const TRAIT = Schema::T_PAGINATED;

    private static ?SchemaExtensionKey $key = null;

    /**
     * Get the singleton pagination extension key.
     */
    public static function key(): SchemaExtensionKey
    {
        return self::$key ??= new SchemaExtensionKey(new self());
    }

    /**
     * Build pagination metadata from an operation schema.
     */
    public function provide(Schema $schema): ?PaginationSchemaExtension
    {
        $pagination = $schema->getTrait(Schema::T_PAGINATED);
        if (!\is_array($pagination)) {
            return null;
        }

        $inputToken = self::requireField(
            $schema,
            $pagination,
            'inputToken'
        );
        $outputToken = self::requireField(
            $schema,
            $pagination,
            'outputToken'
        );

        return new PaginationSchemaExtension(
            inputToken: $inputToken,
            outputToken: $outputToken,
            items: self::optionalField($schema, $pagination, 'items'),
            pageSize: self::optionalField($schema, $pagination, 'pageSize')
        );
    }

    /** @param array<string, mixed> $pagination */
    private static function requireField(
        Schema $schema,
        array $pagination,
        string $field
    ): string
    {
        $value = $pagination[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new \LogicException(
                "Operation {$schema->id} pagination metadata is missing "
                . "{$field}."
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $pagination */
    private static function optionalField(
        Schema $schema,
        array $pagination,
        string $field
    ): ?string
    {
        $value = $pagination[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \LogicException(
                "Operation {$schema->id} pagination metadata {$field} "
                . 'must be a string.'
            );
        }

        return $value === '' ? null : $value;
    }
}
