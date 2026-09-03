<?php

declare(strict_types=1);

namespace Smithy\Client\Pagination;

/**
 * Store modeled pagination metadata for an operation.
 *
 * @internal
 */
final class PaginationSchemaExtension
{
    /**
     * Create modeled pagination metadata.
     */
    public function __construct(
        public readonly string $inputToken,
        public readonly string $outputToken,
        public readonly ?string $items = null,
        public readonly ?string $pageSize = null
    ) {}
}
