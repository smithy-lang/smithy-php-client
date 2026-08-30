<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Extension point for the endpoint bytecode {@see Evaluator}.
 *
 * The evaluator dispatches its known opcode set inline. When the
 * dispatch loop encounters an opcode value it does not recognize, it
 * delegates to the optional handler supplied at construction time. If
 * none is wired, the evaluator throws.
 *
 * This lets vendor layers support dedicated opcodes without leaking
 * vendor-specific code into the generic Smithy layer.
 *
 * The `handle()` method exposes the interpreter's hot-path locals by
 * reference so an implementation can push/pop `$stack`, advance `$pc`,
 * and update `$sp` directly - same shape the base opcode arms use.
 *
 * @internal Implementations must be side-effect-free from the perspective
 *     of the interpreter (no throwing except {@see RulesEvaluationException}).
 */
interface OpcodeHandlerInterface
{
    /**
     * Dispatch a vendor-specific opcode.
     *
     * @param int              $op        The opcode byte the base evaluator
     *                         didn't recognize.
     * @param list<int>        $code      The bytecode program being executed.
     *                         Handlers may read further operand
     *                         bytes at `$code[$pc]`, `$code[$pc+1]`
     *                         etc. and advance `$pc` accordingly.
     * @param int              $pc        Program counter, positioned at the
     *                         byte immediately following `$op`.
     *                         Advance by the number of operand
     *                         bytes consumed.
     * @param list<mixed>      $stack     Interpreter stack.
     * @param int              $sp        Stack pointer (index of next free
     *                         slot). Advance for pushes. Decrement
     *                         for pops.
     * @param array<int,mixed> $regs      Register file.
     * @param list<mixed>      $constants Constant pool (read-only from a
     *                         handler's perspective).
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
