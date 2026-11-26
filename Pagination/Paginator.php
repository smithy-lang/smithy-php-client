<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Pagination;

use Smithy\Client\{
    ClientInterface,
    RequestOverrideConfig
};
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Iterate over every response page for a paginated operation.
 *
 * @template TOutput of SerializableStructInterface
 * @implements \IteratorAggregate<int, TOutput>
 */
final class Paginator implements \IteratorAggregate
{
    /** @var list<Schema> */
    private readonly array $outputTokenPath;
    /** @var list<Schema> */
    private readonly array $itemsPath;
    private readonly PaginationInput $input;
    private ?int $maxItems = null;
    private ?RequestOverrideConfig $requestOverrides = null;
    private bool $stopOnSameToken = true;

    /**
     * Bind an operation and its original input to a client.
     *
     * @param class-string<TOutput> $outputClass Output class of `$operation`.
     *        Every page must be an instance of it.
     *
     * @throws \LogicException If pagination metadata is incomplete or
     *                         references an invalid output path.
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly OperationInterface $operation,
        SerializableStructInterface $input,
        private readonly string $outputClass =
            SerializableStructInterface::class
    ) {
        $pagination = $operation->getSchema()->getExtension(
            PaginationSchemaExtensionProvider::key()
        );
        if (!$pagination instanceof PaginationSchemaExtension) {
            throw new \LogicException(
                "Operation {$operation->getId()} is not paginated."
            );
        }

        $this->outputTokenPath = self::resolvePath(
            $operation->getOutputSchema(),
            $pagination->outputToken,
            $operation->getId(),
            'output token'
        );
        $itemsPath = $pagination->items;
        $this->itemsPath = $itemsPath === null || $itemsPath === ''
            ? []
            : self::resolvePath(
                $operation->getOutputSchema(),
                $itemsPath,
                $operation->getId(),
                'items'
            );
        $this->input = PaginationInput::create(
            $input,
            $operation,
            $pagination
        );
    }

    /**
     * Create a copy limited to a total number of modeled items.
     *
     * @throws \InvalidArgumentException If `$maxItems` is not positive.
     * @throws \LogicException If the operation does not model item and
     *                         page-size members.
     * @return self<TOutput>
     */
    public function withMaxItems(int $maxItems): self
    {
        if ($maxItems < 1) {
            throw new \InvalidArgumentException(
                'Paginator max items must be greater than zero.'
            );
        }

        if ($this->itemsPath === []) {
            throw new \LogicException(
                "Operation {$this->operation->getId()} does not model "
                . 'pagination items.'
            );
        }

        if (!$this->input->hasPageSize()) {
            throw new \LogicException(
                "Operation {$this->operation->getId()} does not model "
                . 'a pagination page size.'
            );
        }

        $copy = clone $this;
        $copy->maxItems = $maxItems;

        return $copy;
    }

    /**
     * Create a copy that applies request overrides to every page request.
     *
     * @return self<TOutput>
     */
    public function withRequestOverrides(
        RequestOverrideConfig $overrides
    ): self
    {
        $copy = clone $this;
        $copy->requestOverrides = $overrides;

        return $copy;
    }

    /**
     * Create a copy that sets whether iteration ends when a response
     * returns the same token as the previous request.
     *
     * When true, iteration ends after a page whose token equals the token
     * sent with its request. When false, iteration continues until the
     * token is empty or the max items limit is reached. Defaults to true.
     *
     * @return self<TOutput>
     */
    public function withStopOnSameToken(bool $stop): self
    {
        $copy = clone $this;
        $copy->stopOnSameToken = $stop;

        return $copy;
    }

    /**
     * Iterate lazily over response pages.
     *
     * @return \Traversable<int, TOutput>
     * @throws \Throwable Whatever operation execution surfaces.
     */
    public function getIterator(): \Traversable
    {
        $input = $this->input;
        $previousToken = $input->getToken();
        $hasPreviousToken = !self::isEmptyToken($previousToken);
        $remaining = $this->maxItems;
        $pageSize = $input->getPageSize();

        while (true) {
            $requestInput = $input;
            if ($remaining !== null && $requestInput->hasPageSize()) {
                $requestPageSize = $pageSize === null || $pageSize < 1
                    ? $remaining
                    : \min($pageSize, $remaining);
                $requestInput = $requestInput->withPageSize(
                    $requestPageSize
                );
            }

            $output = $this->client->executeOperation(
                $this->operation,
                $requestInput->getInput(),
                $this->requestOverrides
            );
            if (!$output instanceof $this->outputClass) {
                throw new \UnexpectedValueException(
                    "Operation {$this->operation->getId()} returned "
                        . $output::class
                        . " instead of {$this->outputClass}."
                );
            }

            yield $output;

            if ($remaining !== null) {
                $remaining -= $this->extractItemCount($output);
                if ($remaining <= 0) {
                    return;
                }
            }

            $token = $this->extractToken($output);
            if (self::isEmptyToken($token)
                || (
                    $this->stopOnSameToken
                    && $hasPreviousToken
                    && self::tokensEqual($token, $previousToken)
                )
            ) {
                return;
            }

            $input = $requestInput->withToken($token);
            $previousToken = $token;
            $hasPreviousToken = true;
        }
    }

    /**
     * Resolve a structure-member path once for repeated extraction.
     *
     * @return list<Schema>
     */
    private static function resolvePath(
        Schema $root,
        string $path,
        string $operationId,
        string $field
    ): array
    {
        $resolved = [];
        $current = $root;

        foreach (\explode('.', $path) as $component) {
            $member = $current->getMember($component);
            if ($member === null) {
                throw new \LogicException(
                    "Operation {$operationId} pagination {$field} path "
                    . "'{$path}' does not resolve at '{$component}'."
                );
            }

            $resolved[] = $member;
            $current = $member->resolveTarget();
        }

        return $resolved;
    }

    /**
     * Extract the token from a response using the precomputed schema path.
     *
     * @return string|array<mixed>|null
     */
    private function extractToken(
        SerializableStructInterface $output
    ): string|array|null
    {
        $token = self::extractPathValue(
            $output,
            $this->outputTokenPath
        );
        if ($token === null
            || \is_string($token)
            || \is_array($token)
        ) {
            return $token;
        }

        throw new \LogicException(
            "Operation {$this->operation->getId()} pagination output "
            . 'token must be a string, map, or null.'
        );
    }

    /**
     * Count items at the precomputed modeled items path.
     */
    private function extractItemCount(
        SerializableStructInterface $output
    ): int
    {
        $items = self::extractPathValue($output, $this->itemsPath);
        if (\is_array($items) || $items instanceof \Countable) {
            return \count($items);
        }

        return 0;
    }

    /**
     * Extract a value using a precomputed schema path.
     *
     * @param list<Schema> $path
     */
    private static function extractPathValue(
        SerializableStructInterface $output,
        array $path
    ): mixed
    {
        $value = $output;

        foreach ($path as $member) {
            if (!$value instanceof SerializableStructInterface) {
                return null;
            }

            $value = $value->getMemberValue($member);
        }

        return $value;
    }

    /**
     * @param string|array<mixed>|null $token
     */
    private static function isEmptyToken(
        string|array|null $token
    ): bool
    {
        return $token === null
            || $token === ''
            || $token === [];
    }

    /**
     * @param string|array<mixed>|null $left
     * @param string|array<mixed>|null $right
     */
    private static function tokensEqual(
        string|array|null $left,
        string|array|null $right
    ): bool
    {
        if (\is_array($left) && \is_array($right)) {
            return self::arraysEqual($left, $right);
        }

        return $left === $right;
    }

    /**
     * Compare arrays by key and recursively compare their values.
     *
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    private static function arraysEqual(
        array $left,
        array $right
    ): bool
    {
        if (\count($left) !== \count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (!\array_key_exists($key, $right)
                || !self::tokenValuesEqual($value, $right[$key])
            ) {
                return false;
            }
        }

        return true;
    }

    private static function tokenValuesEqual(
        mixed $left,
        mixed $right
    ): bool
    {
        if (\is_array($left) && \is_array($right)) {
            return self::arraysEqual($left, $right);
        }

        if (\is_object($left) && \is_object($right)) {
            return $left == $right;
        }

        return $left === $right;
    }
}
