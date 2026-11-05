<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Define a native function callable from endpoint bytecode.
 *
 * Functions are registered by name in a {@see Bytecode} function table
 * and dispatched by the `FN0` through `FN` opcodes. Implementations receive
 * already-popped PHP scalar or array values and return a value that can be
 * pushed directly onto the interpreter stack.
 *
 * Implementations should be stateless and inexpensive because they execute
 * inside the endpoint-resolution hot path.
 */
interface RulesFunctionInterface
{
    /**
     * Return the name used by Smithy rules expressions.
     *
     * The compiler uses this name to resolve `fn` expressions to entries in
     * the bytecode function table.
     */
    public function getName(): string;

    /**
     * Return the fully qualified registry class that owns this function.
     *
     * The registry must expose
     * `get(string $name): RulesFunctionInterface`. The source generator
     * uses that method to reconstruct function-table entries without
     * hard-coding provider-specific registries.
     */
    public function getRegistryClass(): string;

    /**
     * Return the argument count used to select an `FNn` opcode.
     *
     * Return -1 for a variadic function dispatched through `FN`.
     */
    public function getArgumentCount(): int;

    /**
     * Invoke the zero-argument specialization.
     */
    public function apply0(): mixed;

    /**
     * Invoke the one-argument specialization.
     */
    public function apply1(mixed $a0): mixed;

    /**
     * Invoke the two-argument specialization.
     */
    public function apply2(mixed $a0, mixed $a1): mixed;

    /**
     * Invoke the three-argument specialization.
     */
    public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed;

    /**
     * Invoke with an argument list.
     *
     * Fixed-arity calls contain exactly {@see self::getArgumentCount()}
     * values. Variadic calls contain the arity encoded by the instruction.
     *
     * @param list<mixed> $args
     */
    public function apply(array $args): mixed;
}
