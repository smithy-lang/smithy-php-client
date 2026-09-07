<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Immutable container for a compiled endpoint ruleset.
 *
 * Layout:
 *
 *  - `$code`         Flat `list<int>` of opcode and operand bytes.
 *                    Values are 0..255 except signed-byte operands,
 *                    which use the two's-complement range.
 *
 *  - `$constants`    Values referenced by `LOAD_CONST(_W)`,
 *                    `GET_PROPERTY`, `BUILD_TEMPLATE`, and related
 *                    instructions. Entries may be PHP scalars or arrays.
 *
 *  - `$functions`    `list<RulesFunctionInterface>`. Its indexes are
 *                    operands for the `FNn` opcodes.
 *
 *  - `$registers`    Register definitions in index order. They determine
 *                    the evaluator's register layout, defaults, input
 *                    names, built-ins, required state, and runtime types.
 *
 *  - `$inputRegisterMap`
 *                    `array<string, int>` from modeled endpoint parameter
 *                    names to register indexes. Built-in-only registers
 *                    are not exposed through this map.
 *
 *  - `$bddNodes`     Flat BDD triples `[condition, high, low]`. Node `i`
 *                    occupies indexes `[3i, 3i + 1, 3i + 2]`.
 *
 *  - `$bddRoot`      Signed BDD reference. Positive and negative values
 *                    select normal or complemented nodes; +1 and -1 are
 *                    terminals. Values at or above `RESULT_OFFSET`
 *                    address result programs.
 *
 *  - `$conditionOffsets`
 *                    Code offsets for conditions requiring full bytecode
 *                    dispatch.
 *
 *  - `$resultOffsets`
 *                    Code offsets for result programs, including the
 *                    implicit `NoMatchRule` in slot zero.
 *
 *  - `$conditionTypes` / `$conditionOperands`
 *                    Parallel arrays describing conditions that the BDD
 *                    walker can evaluate without entering the bytecode
 *                    dispatch loop.
 *
 * The class contains no evaluation behavior. {@see Evaluator} reads these
 * arrays directly on the endpoint-resolution hot path.
 */
final readonly class Bytecode
{
    /**
     * BDD result-reference offset. Raw result index zero is the implicit
     * `NoMatchRule`; serialized trait results begin at index one.
     */
    public const RESULT_OFFSET = 100_000_000;

    /**
     * Register defaults in register-index order.
     *
     * @var list<mixed>
     */
    public array $defaultRegisters;

    /**
     * @param list<int> $code
     * @param list<mixed> $constants
     * @param list<RulesFunctionInterface> $functions
     * @param list<array{
     *     name:string,
     *     builtIn:?string,
     *     default:mixed,
     *     required:bool,
     *     type:string
     * }> $registers
     * @param array<string, int> $inputRegisterMap
     * @param list<int> $bddNodes
     * @param list<int> $conditionOffsets
     * @param list<int> $resultOffsets
     * @param list<int> $conditionTypes
     * @param list<int> $conditionOperands
     */
    public function __construct(
        public array $code,
        public array $constants,
        public array $functions,
        public array $registers,
        public array $inputRegisterMap,
        public array $bddNodes,
        public int $bddRoot,
        public array $conditionOffsets,
        public array $resultOffsets,
        public array $conditionTypes,
        public array $conditionOperands
    )
    {
        $defaults = [];
        foreach ($registers as $i => $def) {
            $defaults[$i] = $def['default'];
        }
        $this->defaultRegisters = $defaults;
    }

    /**
     * Return true if a reference addresses an internal BDD node.
     *
     * Terminal and result-program references are excluded.
     */
    public static function isNodeReference(int $ref): bool
    {
        return ($ref > 1 && $ref < self::RESULT_OFFSET)
            || ($ref < -1 && $ref > -self::RESULT_OFFSET);
    }

    /**
     * Return true for the always-true (+1) or always-false (-1) terminal.
     */
    public static function isTerminal(int $ref): bool
    {
        return $ref === 1 || $ref === -1;
    }

    /**
     * Return true if a reference addresses a result-program entry.
     */
    public static function isResultReference(int $ref): bool
    {
        return $ref >= self::RESULT_OFFSET;
    }
}
