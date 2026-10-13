<?php

declare(strict_types=1);

namespace Smithy\Client\Pagination;

use Smithy\Core\Serde\{
    Schema,
    SerializableStructInterface
};
use Smithy\Core\Shape\OperationInterface;

/**
 * Provide projection-neutral access to a paginated operation input.
 *
 * @internal
 */
final class PaginationInput
{
    private function __construct(
        private readonly SerializableStructInterface $input,
        private readonly Schema $tokenMember,
        private readonly ?Schema $pageSizeMember
    ) {}

    /**
     * Resolve the modeled pagination members once for repeated page requests.
     */
    public static function create(
        SerializableStructInterface $input,
        OperationInterface $operation,
        PaginationSchemaExtension $pagination
    ): self
    {
        $inputSchema = $operation->getInputSchema();
        if ($input->getSchema()->id !== $inputSchema->id) {
            throw new \LogicException(
                "Operation {$operation->getId()} expected pagination input "
                . "{$inputSchema->id}, got {$input->getSchema()->id}."
            );
        }

        $tokenMember = self::expectMember(
            $inputSchema,
            $pagination->inputToken,
            $operation->getId(),
            'input token'
        );
        $pageSize = $pagination->pageSize;
        $pageSizeMember = $pageSize === null || $pageSize === ''
            ? null
            : self::expectMember(
                $inputSchema,
                $pageSize,
                $operation->getId(),
                'page size'
            );

        return new self($input, $tokenMember, $pageSizeMember);
    }

    /**
     * Return the modeled input passed to the client pipeline.
     */
    public function getInput(): SerializableStructInterface
    {
        return $this->input;
    }

    /**
     * Return the current pagination token.
     *
     * @return string|array<array-key, mixed>|null
     */
    public function getToken(): string|array|null
    {
        $token = $this->input instanceof PaginatedRequestInterface
            ? $this->input->getPaginationToken()
            : $this->input->getMemberValue($this->tokenMember);
        if ($token === null
            || \is_string($token)
            || \is_array($token)
        ) {
            return $token;
        }

        throw new \LogicException(
            "Pagination input member {$this->tokenMember->memberName} "
            . 'must be a string, map, or null.'
        );
    }

    /**
     * Create a copy with a new pagination token.
     *
     * @param string|array<array-key, mixed>|null $token
     */
    public function withToken(string|array|null $token): self
    {
        $input = $this->input instanceof PaginatedRequestInterface
            ? $this->input->withPaginationToken($token)
            : $this->input->withMemberValue($this->tokenMember, $token);

        return new self(
            $input,
            $this->tokenMember,
            $this->pageSizeMember
        );
    }

    /**
     * Return whether the operation models a page-size member.
     */
    public function hasPageSize(): bool
    {
        return $this->pageSizeMember !== null;
    }

    /**
     * Return the current page size.
     */
    public function getPageSize(): ?int
    {
        if ($this->pageSizeMember === null) {
            return null;
        }

        $pageSize = $this->input
            instanceof PageSizePaginatedRequestInterface
                ? $this->input->getPaginationPageSize()
                : $this->input->getMemberValue($this->pageSizeMember);
        if ($pageSize === null || \is_int($pageSize)) {
            return $pageSize;
        }

        throw new \LogicException(
            "Pagination input member {$this->pageSizeMember->memberName} "
            . 'must be an integer or null.'
        );
    }

    /**
     * Create a copy with a new page size.
     */
    public function withPageSize(int $pageSize): self
    {
        if ($this->pageSizeMember === null) {
            throw new \LogicException(
                'Pagination input does not model a page size.'
            );
        }

        $input = $this->input
            instanceof PageSizePaginatedRequestInterface
                ? $this->input->withPaginationPageSize($pageSize)
                : $this->input->withMemberValue(
                    $this->pageSizeMember,
                    $pageSize
                );

        return new self(
            $input,
            $this->tokenMember,
            $this->pageSizeMember
        );
    }

    /**
     * Resolve one required direct input member.
     */
    private static function expectMember(
        Schema $input,
        string $name,
        string $operationId,
        string $field
    ): Schema
    {
        $member = $input->getMember($name);
        if ($member === null) {
            throw new \LogicException(
                "Operation {$operationId} pagination {$field} member "
                . "'{$name}' does not exist."
            );
        }

        return $member;
    }
}
