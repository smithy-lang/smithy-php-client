<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Interceptor;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Shape\OperationInterface;

/**
 * Inject a default idempotency token before request serialization.
 *
 * The interceptor scans the operation input schema for a member carrying
 * `@idempotencyToken`. When the caller left that member unset, the
 * interceptor clones the input with a generated token value.
 */
final class IdempotencyTokenInterceptor extends AbstractInterceptor
{
    /** @var array<string, Schema|null> */
    private array $tokenMemberByOperation = [];

    /**
     * @param (\Closure(): string)|null $tokenProvider
     */
    public function __construct(
        private readonly ?\Closure $tokenProvider = null
    ) {}

    /** @inheritDoc */
    public function appliesTo(OperationInterface $operation): bool
    {
        return $this->resolveTokenMember($operation) !== null;
    }

    /**
     * Fill an unset idempotency token member.
     *
     * @throws \RuntimeException If the token provider returns an invalid value.
     */
    public function modifyBeforeSerialization(
        InputContext $ctx
    ): SerializableStructInterface
    {
        $tokenInfo = $this->resolveTokenMember($ctx->operation);
        if ($tokenInfo === null) {
            return $ctx->input;
        }

        $tokenMember = $tokenInfo;
        $value = $ctx->input->getMemberValue($tokenMember);
        if (\is_string($value) && $value === '') {
            $value = null;
        }

        if ($value !== null) {
            return $ctx->input;
        }

        return $ctx->input->withMemberValue(
            $tokenMember,
            $this->resolveTokenValue()
        );
    }

    private function resolveTokenMember(
        OperationInterface $operation
    ): ?Schema
    {
        $runtimeIdentity = $operation->getRuntimeIdentity();
        if (\array_key_exists(
            $runtimeIdentity,
            $this->tokenMemberByOperation
        )) {
            return $this->tokenMemberByOperation[$runtimeIdentity];
        }

        $inputSchema = $operation->getInputSchema();
        foreach ($inputSchema->getMembers() as $memberSchema) {
            if ($memberSchema->hasTrait(Schema::T_IDEMPOTENCY_TOKEN)) {
                return $this->tokenMemberByOperation[$runtimeIdentity]
                    = $memberSchema;
            }
        }

        return $this->tokenMemberByOperation[$runtimeIdentity] = null;
    }

    private function resolveTokenValue(): string
    {
        if ($this->tokenProvider !== null) {
            $value = ($this->tokenProvider)();
            if (\is_string($value) && $value !== '') {
                return $value;
            }

            throw new \RuntimeException(
                'Idempotency token provider must return a non-empty string.'
            );
        }

        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $parts = \unpack('Na/nb/nc/nd/ne/Nf', $bytes);

        return \sprintf(
            '%08x-%04x-%04x-%04x-%04x%08x',
            $parts['a'],
            $parts['b'],
            $parts['c'],
            $parts['d'],
            $parts['e'],
            $parts['f']
        );
    }
}
