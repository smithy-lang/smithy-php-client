<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Immutable container of a compiled endpoint ruleset.
 *
 * Layout:
 *
 *  - `$code`         Flat `list<int>` of opcode bytes and operand bytes.
 *                    Every value is 0..255 except signed-byte operands
 *                    (SPLIT_GET's index) which use the two-complement range.
 *
 *  - `$constants`    Values referenced by `LOAD_CONST(_W)`, `GET_PROPERTY`,
 *                    `RESOLVE_TEMPLATE` implicit arguments, etc. Any PHP
 *                    scalar or array.
 *
 *  - `$functions`    `list<RulesFunctionInterface>`. Index space is the operand of
 *                    the `FNn` opcodes.
 *
 *  - `$registers`    `list<array{name:string, builtIn:?string, default:mixed,
 *                    required:bool, type:string}>`. Determines the shape
 *                    of the register array the evaluator allocates and
 *                    how input parameters are mapped into it.
 *
 *  - `$inputRegisterMap`
 *                    `array<string,int>` mapping input parameter name to
 *                    register index. Populated for parameters callable from
 *                    the SDK layer (not for builtIn-only parameters).
 *
 *  - `$bddNodes`     Flat `list<int>` of BDD triples `[condIdx, hi, lo]`
 *                    laid out contiguously. Node `i` occupies indices
 *                    `[3i, 3i+1, 3i+2]`.
 *
 *  - `$bddRoot`      Signed root reference into `$bddNodes`. Positive =
 *                    node index + 1. Negative values are complemented
 *                    node indexes. +1/-1 are true/false terminals.
 *                    Values >= RESULT_OFFSET are BDD result references.
 *                    Result index zero is the implicit `NoMatchRule`.
 *
 *  - `$conditionOffsets`
 *                    `list<int>` - one entry per BDD condition. Value is
 *                    the `$code` offset at which the condition's fallback
 *                    (full-dispatch) program begins.
 *
 *  - `$resultOffsets`
 *                    `list<int>` - one entry per BDD result, including the
 *                    implicit `NoMatchRule` in slot zero. Each value is the
 *                    `$code` offset at which the result's program begins.
 *                    Result programs terminate with `RETURN_VALUE`,
 *                    `RETURN_ENDPOINT`, or `RETURN_ERROR`.
 *
 *  - `$conditionTypes` / `$conditionOperands`
 *                    Two parallel `list<int>` of length equal to the
 *                    number of conditions. Together they identify BDD
 *                    fast-path conditions: type is one of the `COND_*`
 *                    constants. Operand meaning depends on type
 *                    (register index or packed register + constant).
 *
 * The class is deliberately just data. All hot-path work happens in
 * {@see Evaluator}, which reads these arrays directly.
 */
final class Bytecode
{
    /**
     * BDD result-reference offset. Raw result index zero is the implicit
     * `NoMatchRule`; serialized trait results begin at index one.
     */
    public const RESULT_OFFSET = 100_000_000;

    /**
     * Flat `list<mixed>` of register defaults in register-index order.
     * Precomputed once at construction so `Evaluator::initRegisters`
     * can copy-on-write instead of re-walking `$registers` on every
     * resolve. Populated eagerly in the constructor.
     *
     * @var list<mixed>
     */
    public readonly array $defaultRegisters;

    /**
     * @param list<int>                 $code
     * @param list<mixed>               $constants
     * @param list<RulesFunctionInterface> $functions
     * @param list<array{
     *     name:string,
     *     builtIn:?string,
     *     default:mixed,
     *     required:bool,
     *     type:string
     * }> $registers
     * @param array<string, int>        $inputRegisterMap
     * @param list<int>                 $bddNodes
     * @param list<int>                 $conditionOffsets
     * @param list<int>                 $resultOffsets
     * @param list<int>                 $conditionTypes
     * @param list<int>                 $conditionOperands
     */
    public function __construct(
        public readonly array $code,
        public readonly array $constants,
        public readonly array $functions,
        public readonly array $registers,
        public readonly array $inputRegisterMap,
        public readonly array $bddNodes,
        public readonly int $bddRoot,
        public readonly array $conditionOffsets,
        public readonly array $resultOffsets,
        public readonly array $conditionTypes,
        public readonly array $conditionOperands
    )
    {
        $defaults = [];
        foreach ($registers as $i => $def) {
            $defaults[$i] = $def['default'];
        }
        $this->defaultRegisters = $defaults;
    }

    /**
     * True if `$ref` addresses an internal BDD node (not terminal, not result).
     */
    public static function isNodeReference(int $ref): bool
    {
        return ($ref > 1 && $ref < self::RESULT_OFFSET)
            || ($ref < -1 && $ref > -self::RESULT_OFFSET);
    }

    /**
     * True if `$ref` is the always-true (+1) or always-false (-1) terminal.
     */
    public static function isTerminal(int $ref): bool
    {
        return $ref === 1 || $ref === -1;
    }

    /**
     * True if `$ref` addresses a result-program entry.
     */
    public static function isResultReference(int $ref): bool
    {
        return $ref >= self::RESULT_OFFSET;
    }
}
