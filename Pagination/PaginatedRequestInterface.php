<?php

declare(strict_types=1);

namespace Smithy\Client\Pagination;

use Smithy\Core\Serde\SerializableStructInterface;

/**
 * Define a paginated operation input.
 */
interface PaginatedRequestInterface extends SerializableStructInterface
{
    /**
     * Get the pagination token.
     *
     * @return string|array<array-key, mixed>|null
     */
    public function getPaginationToken(): string|array|null;

    /**
     * Create a copy with the pagination token.
     *
     * @param string|array<array-key, mixed>|null $token
     */
    public function withPaginationToken(string|array|null $token): self;
}
