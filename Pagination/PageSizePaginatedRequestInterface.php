<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Pagination;

/**
 * Define a paginated input with a modeled page-size member.
 */
interface PageSizePaginatedRequestInterface extends PaginatedRequestInterface
{
    /**
     * Get the pagination page size.
     */
    public function getPaginationPageSize(): ?int;

    /**
     * Create a copy with the pagination page size.
     */
    public function withPaginationPageSize(int $pageSize): self;
}
