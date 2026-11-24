<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Extend the endpoint bytecode {@see Evaluator} with vendor opcodes.
 *
 * The evaluator dispatches the generic Smithy opcode set inline. When it
 * encounters an unknown opcode, it delegates to this handler or throws if
 * no handler was configured. This permits optimized vendor instructions
 * without coupling provider code to the generic runtime.
 *
 * Interpreter state is passed by reference so implementations can consume
 * operand bytes, push or pop stack values, and update the stack pointer
 * without allocating a result tuple on each dispatch.
 *
 * Implementations throw {@see RulesEvaluationException} for bytecode
 * evaluation failures.
 */
interface OpcodeHandlerInterface
{
    /**
     * Dispatch a vendor-specific opcode.
     *
     * @param int $op Opcode not recognized by the base evaluator.
     * @param list<int> $code Program being executed. Additional operands
     *                        begin at `$code[$pc]`.
     * @param int $pc Program counter positioned after `$op`. Advance it
     *                for every operand byte consumed.
     * @param list<mixed> $stack Interpreter stack.
     * @param int $sp Index of the next free stack slot. Update it for
     *                every push or pop.
     * @param array<int, mixed> $regs Register file.
     * @param list<mixed> $constants Read-only constant pool.
     *
     * @return void
     */
    public function handle(
        int $op,
        array $code,
        int &$pc,
        array &$stack,
        int &$sp,
        array &$regs,
        array $constants
    ): void;
}
