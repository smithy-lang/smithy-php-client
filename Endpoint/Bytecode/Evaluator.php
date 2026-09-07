<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

use Smithy\Client\Endpoint\{
    Endpoint,
    EndpointAuthScheme
};

/**
 * Execute compiled endpoint bytecode against named input parameters.
 *
 * An evaluator is normally created once by a
 * {@see BytecodeEndpointResolver} and reused for every request. Each
 * evaluation copies the precomputed default register file, validates
 * supplied inputs, walks the binary decision diagram, and executes the
 * selected result program to produce an {@see Endpoint}.
 *
 * Vendor layers may supply an {@see OpcodeHandlerInterface}. The
 * interpreter delegates opcodes outside the generic Smithy range to that
 * handler without adding vendor-specific branches to this runtime.
 */
final readonly class Evaluator
{
    /**
     * @param array<string, string> $endpointAuthSchemeIds Raw endpoint
     *     auth scheme names mapped to canonical Smithy shape IDs.
     */
    public function __construct(
        private Bytecode $bytecode,
        private ?OpcodeHandlerInterface $handler = null,
        private array $endpointAuthSchemeIds = []
    ) {}

    /**
     * Resolve an endpoint from a parameter map.
     *
     * Parameter names match the ruleset's modeled inputs. Missing and null
     * values leave the register's modeled default intact. Supplied values
     * are validated against required state and endpoint parameter types
     * before the BDD is evaluated.
     *
     * @param array<string, mixed> $params Named endpoint parameter values.
     *
     * @throws RulesEvaluationException When the ruleset returns an error,
     *                                  reaches no result, or contains
     *                                  invalid bytecode.
     */
    public function evaluate(array $params): Endpoint
    {
        $bytecode = $this->bytecode;
        $regs = $bytecode->defaultRegisters;
        // Iterate `$params` rather than `$inputRegisterMap`. The
        // caller-supplied param set is commonly smaller than the
        // ruleset's declared inputs. Null values are treated as absent so
        // modeled defaults still apply.
        $inputRegisterMap = $bytecode->inputRegisterMap;
        foreach ($params as $name => $value) {
            if ($value !== null && isset($inputRegisterMap[$name])) {
                $regs[$inputRegisterMap[$name]] = $value;
            }
        }
        $this->validateInputRegisters($regs);

        $nodes = $bytecode->bddNodes;
        $condTypes = $bytecode->conditionTypes;
        $condOps = $bytecode->conditionOperands;
        $constants = $bytecode->constants;
        $ref = $bytecode->bddRoot;
        $resultOffset = Bytecode::RESULT_OFFSET;
        $negResultOffset = -$resultOffset;

        // BDD walk with inline node-reference test and match-based
        // condition dispatch.
        while (($ref > 1 && $ref < $resultOffset)
            || ($ref < -1 && $ref > $negResultOffset)
        ) {
            $idx = $ref > 0 ? $ref - 1 : -$ref - 1;
            $base = $idx * 3;
            $condIdx = $nodes[$base];
            $condType = $condTypes[$condIdx];
            $operand = $condOps[$condIdx];

            // Single-dispatch switch on the condition type. Integer
            // literals match Opcodes::COND_* - see the trailing comment
            // on each case.
            switch ($condType) {
                case 0: // COND_FULL
                    $v = $this->runBytecode(
                        $bytecode->conditionOffsets[$condIdx],
                        $regs
                    );
                    $result = $v !== null && $v !== false;
                    break;
                case 1: // COND_ISSET
                    $result = $regs[$operand] !== null;
                    break;
                case 2: // COND_IS_TRUE
                    $result = $regs[$operand] === true;
                    break;
                case 3: // COND_IS_FALSE
                    $result = $regs[$operand] === false;
                    break;
                case 4: // COND_NOT_SET
                    $result = $regs[$operand] === null;
                    break;
                case 5: // COND_STRING_EQ_REG_CONST
                    $s = $regs[$operand & 0xFF];
                    $result = $s !== null && $s === $constants[$operand >> 8];
                    break;
                default:
                    throw new RulesEvaluationException(
                        "Unknown condition type {$condType} at condition "
                            . $condIdx
                    );
            }

            // XOR with complement bit. Positive ref = normal, negative = inverted.
            // Parenthesize the xor because it has lower precedence than the ternary.
            $take = ($result xor ($ref < 0));
            $ref = $take ? $nodes[$base + 1] : $nodes[$base + 2];
        }

        // Inline terminal check ($ref === 1 || $ref === -1).
        if ($ref === 1 || $ref === -1) {
            // Terminals map to the implicit `NoMatchRule` (SEP:BDD section Result
            // Encoding). We surface it as an evaluation error since the
            // Smithy client contract expects an Endpoint or an exception.
            throw new RulesEvaluationException(
                'No endpoint matched the supplied parameters'
            );
        }

        $resultIdx = $ref - Bytecode::RESULT_OFFSET;
        if ($resultIdx < 0 || !isset($bytecode->resultOffsets[$resultIdx])) {
            throw new RulesEvaluationException(
                'No endpoint matched the supplied parameters'
            );
        }
        $result = $this->runBytecode(
            $bytecode->resultOffsets[$resultIdx],
            $regs
        );

        if ($result === null) {
            throw new RulesEvaluationException(
                'No endpoint matched the supplied parameters'
            );
        }
        if (!$result instanceof Endpoint) {
            throw new RulesEvaluationException(
                'Endpoint result program did not return an Endpoint'
            );
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $regs
     */
    private function validateInputRegisters(array $regs): void
    {
        foreach ($this->bytecode->inputRegisterMap as $name => $reg) {
            $def = $this->bytecode->registers[$reg];
            $value = $regs[$reg];
            if ($value === null) {
                if ($def['required']) {
                    throw new \InvalidArgumentException(
                        "Required endpoint parameter {$name} must not be null."
                    );
                }
                continue;
            }

            if (!$this->matchesRegisterType($value, $def['type'])) {
                throw new \InvalidArgumentException(
                    "Endpoint parameter {$name} must be of type {$def['type']}."
                );
            }
        }
    }

    private function matchesRegisterType(mixed $value, string $type): bool
    {
        return match ($type) {
            'any' => true,
            'boolean' => \is_bool($value),
            'string' => \is_string($value),
            'stringarray' => \is_array($value)
                && \array_is_list($value)
                && $this->isStringList($value),
            default => false,
        };
    }

    private function isStringList(array $value): bool
    {
        foreach ($value as $item) {
            if (!\is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a condition or result program from an instruction offset.
     *
     * Condition programs return scalar values used by the BDD walker.
     * Result programs return an {@see Endpoint}, an explicit rules error,
     * or null for the implicit `NoMatchRule`. The dispatch loop remains in
     * one method so stack, register, and program-counter behavior can be
     * audited together.
     *
     * @param array<int, mixed> $regs Mutable evaluator register file.
     */
    private function runBytecode(int $start, array &$regs): mixed
    {
        $bytecode = $this->bytecode;
        $code = $bytecode->code;
        $constants = $bytecode->constants;
        $functions = $bytecode->functions;
        $codeLen = \count($code);

        $stack = [];
        $sp = 0;
        $pc = $start;

        while ($pc < $codeLen) {
            $op = $code[$pc++];

            // Case labels use integer literals with trailing `// NAME`
            // comments (match Opcodes::*). PHP compiles integer-cased
            // switch statements to a hash-jump table under opcache.
            // referencing class constants adds a source-level fetch we
            // don't need in the hot loop.
            switch ($op) {
                case 0: // LOAD_CONST
                    $stack[$sp++] = $constants[$code[$pc++]];
                    break;

                case 1: // LOAD_CONST_W
                    $index = ($code[$pc] << 8) | $code[$pc + 1];
                    $stack[$sp++] = $constants[$index];
                    $pc += 2;
                    break;

                case 2: // SET_REGISTER
                    $regs[$code[$pc++]] = $stack[$sp - 1];
                    break;

                case 3: // LOAD_REGISTER
                    $stack[$sp++] = $regs[$code[$pc++]];
                    break;

                case 4: // NOT
                    $stack[$sp - 1] = $stack[$sp - 1] !== true;
                    break;

                case 5: // ISSET
                    $stack[$sp - 1] = $stack[$sp - 1] !== null;
                    break;

                case 6: // TEST_REGISTER_ISSET
                    $stack[$sp++] = $regs[$code[$pc++]] !== null;
                    break;

                case 7: // TEST_REGISTER_NOT_SET
                    $stack[$sp++] = $regs[$code[$pc++]] === null;
                    break;

                case 8: // LIST0
                    $stack[$sp++] = [];
                    break;

                case 9: // LIST1
                    $stack[$sp - 1] = [$stack[$sp - 1]];
                    break;

                case 10: // LIST2
                    $stack[$sp - 2] = [$stack[$sp - 2], $stack[$sp - 1]];
                    $sp -= 1;
                    break;

                case 11: // LISTN
                    $n = $code[$pc++];
                    $base = $sp - $n;
                    $list = [];
                    // Hoist the index arithmetic out of the loop - one add
                    // per iteration instead of one add + one subscript
                    // computation. Same pattern as MAPN below.
                    $src = $base;
                    for ($i = 0; $i < $n; $i++, $src++) {
                        $list[$i] = $stack[$src];
                    }
                    $stack[$base] = $list;
                    $sp = $base + 1;
                    break;

                case 12: // MAP0
                    $stack[$sp++] = [];
                    break;

                case 13: // MAP1
                    // Stack: [value, key] -> [{key: value}]
                    $stack[$sp - 2] = [$stack[$sp - 1] => $stack[$sp - 2]];
                    $sp -= 1;
                    break;

                case 14: // MAP2
                    // Stack: [v1, k1, v2, k2]
                    $b = $sp - 4;
                    $stack[$b] = [
                        $stack[$b + 1] => $stack[$b],
                        $stack[$b + 3] => $stack[$b + 2]
                    ];
                    $sp = $b + 1;
                    break;

                case 15: // MAP3
                    $b = $sp - 6;
                    $stack[$b] = [
                        $stack[$b + 1] => $stack[$b],
                        $stack[$b + 3] => $stack[$b + 2],
                        $stack[$b + 5] => $stack[$b + 4]
                    ];
                    $sp = $b + 1;
                    break;

                case 16: // MAP4
                    $b = $sp - 8;
                    $stack[$b] = [
                        $stack[$b + 1] => $stack[$b],
                        $stack[$b + 3] => $stack[$b + 2],
                        $stack[$b + 5] => $stack[$b + 4],
                        $stack[$b + 7] => $stack[$b + 6]
                    ];
                    $sp = $b + 1;
                    break;

                case 17: // MAPN
                case 54: // STRUCTN
                    $n = $code[$pc++];
                    $b = $sp - ($n * 2);
                    $m = [];
                    // Hoist index arithmetic: walk a stride-2 cursor
                    // instead of recomputing `$b + $i * 2 + 1` /
                    // `$b + $i * 2` per iteration.
                    $src = $b;
                    for ($i = 0; $i < $n; $i++, $src += 2) {
                        $m[$stack[$src + 1]] = $stack[$src];
                    }
                    $stack[$b] = $m;
                    $sp = $b + 1;
                    break;

                case 18: // RESOLVE_TEMPLATE
                    $n = $code[$pc++];
                    $b = $sp - $n;
                    $out = '';
                    // Cursor-based concat - one increment per iteration,
                    // no per-iteration subscript arithmetic.
                    $src = $b;
                    for ($i = 0; $i < $n; $i++, $src++) {
                        $out .= $stack[$src];
                    }
                    $stack[$b] = $out;
                    $sp = $b + 1;
                    break;

                case 19: // FN0
                    $stack[$sp++] = $functions[$code[$pc++]]->apply0();
                    break;

                case 20: // FN1
                    $fn = $functions[$code[$pc++]];
                    $stack[$sp - 1] = $fn->apply1($stack[$sp - 1]);
                    break;

                case 21: // FN2
                    $fn = $functions[$code[$pc++]];
                    $stack[$sp - 2] = $fn->apply2(
                        $stack[$sp - 2],
                        $stack[$sp - 1]
                    );
                    $sp -= 1;
                    break;

                case 22: // FN3
                    $fn = $functions[$code[$pc++]];
                    $stack[$sp - 3] = $fn->apply3(
                        $stack[$sp - 3],
                        $stack[$sp - 2],
                        $stack[$sp - 1]
                    );
                    $sp -= 2;
                    break;

                case 23: // FN
                    $fn = $functions[$code[$pc++]];
                    $n = $code[$pc++];
                    $b = $sp - $n;
                    $args = [];
                    $src = $b;
                    for ($i = 0; $i < $n; $i++, $src++) {
                        $args[$i] = $stack[$src];
                    }
                    $stack[$b] = $fn->apply($args);
                    $sp = $b + 1;
                    break;

                case 24: // GET_PROPERTY
                    $prop = $constants[($code[$pc] << 8) | $code[$pc + 1]];
                    $pc += 2;
                    $target = $stack[$sp - 1];
                    $stack[$sp - 1] = \is_array($target)
                        ? ($target[$prop] ?? null)
                        : null;
                    break;

                case 25: // GET_INDEX
                    $i = $code[$pc++];
                    $target = $stack[$sp - 1];
                    $stack[$sp - 1] = \is_array($target)
                        ? ($target[$i] ?? null)
                        : null;
                    break;

                case 26: // GET_PROPERTY_REG
                    $target = $regs[$code[$pc++]];
                    $prop = $constants[($code[$pc] << 8) | $code[$pc + 1]];
                    $pc += 2;
                    $stack[$sp++] = \is_array($target)
                        ? ($target[$prop] ?? null)
                        : null;
                    break;

                case 27: // GET_INDEX_REG
                    $target = $regs[$code[$pc++]];
                    $i = $code[$pc++];
                    $stack[$sp++] = \is_array($target)
                        ? ($target[$i] ?? null)
                        : null;
                    break;

                case 28: // IS_TRUE
                    $stack[$sp - 1] = $stack[$sp - 1] === true;
                    break;

                case 29: // TEST_REGISTER_IS_TRUE
                    $stack[$sp++] = $regs[$code[$pc++]] === true;
                    break;

                case 30: // TEST_REGISTER_IS_FALSE
                    $stack[$sp++] = $regs[$code[$pc++]] === false;
                    break;

                case 31: // EQUALS
                    $stack[$sp - 2] = $stack[$sp - 2] === $stack[$sp - 1];
                    $sp -= 1;
                    break;

                case 32: // STRING_EQUALS
                    $a = $stack[$sp - 2];
                    $b = $stack[$sp - 1];
                    $stack[$sp - 2] = \is_string($a) && $a === $b;
                    $sp -= 1;
                    break;

                case 33: // BOOLEAN_EQUALS
                    $a = $stack[$sp - 2];
                    $b = $stack[$sp - 1];
                    $stack[$sp - 2] = \is_bool($a) && $a === $b;
                    $sp -= 1;
                    break;

                case 34: // SUBSTRING
                    // Flags byte layout:
                    //   bit 0: reverse-from-end
                    //   bit 1: input is compiler-proved ASCII-safe.
                    //          skip the `preg_match('/[\x80-\xff]/')`
                    //          rejection scan. Set by BddCompiler when
                    //          arg 0 originates from a Smithy-typed
                    //          `string` register (Endpoint params are
                    //          the only source of string regs and are
                    //          guaranteed ASCII by spec) or an already
                    //          ASCII-safe expression.
                    $startPos = $code[$pc++];
                    $endPos = $code[$pc++];
                    $flags = $code[$pc++];
                    $s = $stack[$sp - 1];
                    if (!\is_string($s)) {
                        $stack[$sp - 1] = null;
                        break;
                    }
                    $reverse = ($flags & 0x01) !== 0;
                    $stack[$sp - 1] = ($flags & 0x02) !== 0
                        ? self::substringAscii(
                            $s,
                            $startPos,
                            $endPos,
                            $reverse
                        )
                        : self::substring(
                            $s,
                            $startPos,
                            $endPos,
                            $reverse
                        );
                    break;

                case 35: // IS_VALID_HOST_LABEL
                    $allowDots = $stack[$sp - 1] === true;
                    $label = $stack[$sp - 2];
                    $stack[$sp - 2] = \is_string($label)
                        ? self::isValidHostLabel($label, $allowDots)
                        : false;
                    $sp -= 1;
                    break;

                case 36: // PARSE_URL
                    $url = $stack[$sp - 1];
                    $stack[$sp - 1] = \is_string($url)
                        ? self::parseUrl($url)
                        : null;
                    break;

                case 37: // URI_ENCODE
                    $s = $stack[$sp - 1];
                    $stack[$sp - 1] = \is_string($s) ? \rawurlencode($s) : null;
                    break;

                case 38: // RETURN_ERROR
                    throw new RulesEvaluationException((string) $stack[--$sp]);

                case 39: // RETURN_ENDPOINT
                    $flags = $code[$pc++];
                    $url = $stack[--$sp];
                    $properties = ($flags & 0x02) !== 0 ? $stack[--$sp] : [];
                    $headers = ($flags & 0x01) !== 0 ? $stack[--$sp] : [];
                    return new Endpoint(
                        url: \is_string($url) ? $url : self::urlToString($url),
                        authSchemes: $this->extractAuthSchemes($properties),
                        headers: self::normalizeHeaders($headers)
                    );

                case 40: // RETURN_VALUE
                    return $stack[--$sp];

                case 41: // SPLIT
                    $limit = $stack[$sp - 1];
                    $delim = $stack[$sp - 2];
                    $s = $stack[$sp - 3];
                    $stack[$sp - 3] = \is_int($limit)
                        && \is_string($delim)
                        ? self::split($s, $delim, $limit)
                        : null;
                    $sp -= 2;
                    break;

                case 42: // JNN_OR_POP
                    $offset = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    if ($stack[$sp - 1] !== null) {
                        $pc += $offset;
                    } else {
                        $sp -= 1;
                    }
                    break;

                case 43: // GET_NEGATIVE_INDEX
                    $i = $code[$pc++];
                    $target = $stack[$sp - 1];
                    if (\is_array($target)) {
                        $len = \count($target);
                        $stack[$sp - 1] = $target[$len - $i] ?? null;
                    } else {
                        $stack[$sp - 1] = null;
                    }
                    break;

                case 44: // GET_NEGATIVE_INDEX_REG
                    $target = $regs[$code[$pc++]];
                    $i = $code[$pc++];
                    if (\is_array($target)) {
                        $len = \count($target);
                        $stack[$sp++] = $target[$len - $i] ?? null;
                    } else {
                        $stack[$sp++] = null;
                    }
                    break;

                case 45: // JMP_IF_FALSE
                    $cond = $stack[--$sp];
                    $offset = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    if ($cond !== true) {
                        $pc += $offset;
                    }
                    break;

                case 46: // JUMP
                    $offset = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2 + $offset;
                    break;

                case 47: // SUBSTRING_EQ
                    $regIdx = $code[$pc++];
                    $startPos = $code[$pc++];
                    $endPos = $code[$pc++];
                    $flags = $code[$pc++];
                    $reverse = ($flags & 0x01) !== 0;
                    $ascii = ($flags & 0x02) !== 0;
                    $constIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $v = $regs[$regIdx];
                    // Short-circuit `&&` guarantees the substring helper
                    // only sees a `string`. ASCII bit skips the byte
                    // scan. Same encoding as case 34.
                    if (!\is_string($v)) {
                        $stack[$sp++] = false;
                        break;
                    }
                    $stack[$sp++] = ($ascii
                        ? self::substringAscii($v, $startPos, $endPos, $reverse)
                        : self::substring($v, $startPos, $endPos, $reverse)
                    ) === $constants[$constIdx];
                    break;

                case 49: // SPLIT_GET
                    $regIdx = $code[$pc++];
                    $delimIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $idxByte = $code[$pc++];
                    // Sign-extend the byte for negative indices.
                    $splitIdx = $idxByte >= 128 ? $idxByte - 256 : $idxByte;
                    $v = $regs[$regIdx];
                    $delim = $constants[$delimIdx];
                    $stack[$sp++] = self::splitGet($v, $delim, $splitIdx);
                    break;

                case 50: // SELECT_BOOL_REG
                    $v = $regs[$code[$pc++]];
                    $trueIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $falseIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $stack[$sp++] = $v === true
                        ? $constants[$trueIdx]
                        : $constants[$falseIdx];
                    break;

                case 51: // STRING_EQUALS_REG_CONST
                    $regIdx = $code[$pc++];
                    $constIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $v = $regs[$regIdx];
                    $stack[$sp++] = \is_string($v)
                        && $v === $constants[$constIdx];
                    break;

                case 52: // SET_REG_RETURN
                    $v = $stack[--$sp];
                    $regs[$code[$pc++]] = $v;
                    return $v;

                case 53: // BUILD_URI
                    $schemeIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $host = $stack[$sp - 2];
                    $path = $stack[$sp - 1];
                    $stack[$sp - 2] = $constants[$schemeIdx]
                        . '://' . $host . $path;
                    $sp -= 1;
                    break;

                case 55: // BUILD_TEMPLATE
                    // Fused string-template: one dispatch replaces the
                    // LOAD_CONST / LOAD_REGISTER / GET_PROPERTY_REG chain
                    // plus RESOLVE_TEMPLATE that the compiler used to emit.
                    $metaIdx = ($code[$pc] << 8) | $code[$pc + 1];
                    $pc += 2;
                    $out = '';
                    foreach ($constants[$metaIdx] as $seg) {
                        $tag = $seg[0];
                        if ($tag === 0) {
                            $out .= $seg[1];
                        } elseif ($tag === 1) {
                            $out .= $regs[$seg[1]];
                        } else {
                            // Tag 2: register-property path. Payload is
                            // [reg-idx, list<prop-const-idx>].
                            $t = $regs[$seg[1][0]];
                            foreach ($seg[1][1] as $propIdx) {
                                $t = \is_array($t)
                                    ? ($t[$constants[$propIdx]] ?? null)
                                    : null;
                            }
                            $out .= $t ?? '';
                        }
                    }
                    $stack[$sp++] = $out;
                    break;

                default:
                    // Vendor-extension seam. `$handler` is a composed
                    // OpcodeHandlerInterface.
                    // When null, the interpreter faults. When set, the
                    // handler advances `$pc` / `$sp` / `$stack` in place -
                    // no tuple-return allocation per dispatch.
                    if ($this->handler === null) {
                        throw new RulesEvaluationException(
                            "Unknown opcode {$op} at pc " . ($pc - 1)
                        );
                    }
                    $this->handler->handle(
                        $op,
                        $code,
                        $pc,
                        $stack,
                        $sp,
                        $regs,
                        $constants
                    );
                    break;
            }
        }

        throw new RulesEvaluationException(
            'Bytecode program ran off the end without returning'
        );
    }

    /**
     * Smithy `substring` - extract a slice of an ASCII string. Rejects
     * any non-ASCII bytes with a single native `preg_match` scan.
     *
     * Callers must gate on `\is_string()` - the parameter is typed
     * `string` and PHP will throw a `TypeError` on non-string input.
     */
    private static function substring(string $s, int $start, int $end, bool $reverse): ?string
    {
        $len = \strlen($s);
        if ($start >= $end
            || $end > $len
            || \preg_match('/[\x80-\xff]/', $s) === 1
        ) {
            return null;
        }

        return $reverse
            ? \substr($s, $len - $end, $end - $start)
            : \substr($s, $start, $end - $start);
    }

    /**
     * ASCII-fast-path substring - used when the BddCompiler proved at
     * compile time that the input can never contain non-ASCII bytes
     * (typically arg 0 originates from a Smithy `string` parameter,
     * which is guaranteed ASCII by the endpoint-rules spec).
     *
     * Skips the `preg_match('/[\x80-\xff]/')` scan that dominates
     * `substring`'s hot cost. Bounds check still runs - the spec
     * requires null on `$end > len`.
     */
    private static function substringAscii(
        string $s,
        int $start,
        int $end,
        bool $reverse
    ): ?string
    {
        $len = \strlen($s);
        if ($start >= $end || $end > $len) {
            return null;
        }

        return $reverse
            ? \substr($s, $len - $end, $end - $start)
            : \substr($s, $start, $end - $start);
    }

    /**
     * Callers must gate on `\is_string()` - see {@see substring()} note.
     */
    private static function isValidHostLabel(string $s, bool $allowDots): bool
    {
        if ($s === '') {
            return false;
        }
        if ($allowDots) {
            foreach (\explode('.', $s) as $part) {
                if (!self::isValidHostLabel($part, false)) {
                    return false;
                }
            }
            return true;
        }

        return (bool) \preg_match(
            '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
            $s
        );
    }

    /**
     * Split a string by a delimiter into at most `$limit` pieces.
     *
     * When `$limit == 0`, no limit is imposed. Negative limits are
     * rejected.
     *
     * The `$s` param remains `mixed` because case 41 (SPLIT) pops it
     * off the stack (register value - could be null). Caller casts to
     * `(string)`, so the `\is_string` guard survives to protect against
     * a null-cast to `""`.
     *
     * @return list<string>|null
     */
    private static function split(mixed $s, string $delim, int $limit): ?array
    {
        if (!\is_string($s) || $delim === '' || $limit < 0) {
            return null;
        }

        return $limit === 0
            ? \explode($delim, $s)
            : \explode($delim, $s, $limit);
    }

    /**
     * Split-and-index - used by the SPLIT_GET opcode. The delimiter
     * comes from the constant pool at compile time. The compiler
     * always interns it as a string, so no runtime guard is needed.
     */
    private static function splitGet(mixed $s, string $delim, int $idx): ?string
    {
        if (!\is_string($s) || $delim === '') {
            return null;
        }
        $parts = \explode($delim, $s);
        return $parts[$idx < 0 ? \count($parts) + $idx : $idx] ?? null;
    }

    /**
     * @return array{
     *     scheme:string,
     *     authority:string,
     *     path:string,
     *     normalizedPath:string,
     *     isIp:bool
     * }|null
     */
    private static function parseUrl(string $url): ?array
    {
        if (!\preg_match('/^https?:\/\/[^\s\/?#]+(?:[\/?#]|$)/i', $url)) {
            return null;
        }

        // `parse_url` emits an E_WARNING only for seriously malformed input
        // (e.g. paths starting with `//`). On typical malformed URLs it
        // returns `false` silently. Silence the warning-suppression `@`
        // that dominated call cost when the userland error handler was
        // active. The `false` and missing-key checks below still catch
        // every reject path.
        $parts = @\parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        $scheme = \strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = $parts['host'];
        $isIp = self::isValidUrlHost($host);
        if ($isIp === null) {
            return null;
        }

        $authority = $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }
        $path = $parts['path'] ?? '/';
        $normalized = \str_ends_with($path, '/') ? $path : $path . '/';
        return [
            'scheme' => $scheme,
            'authority' => $authority,
            'path' => $path,
            'normalizedPath' => $normalized,
            'isIp' => $isIp
        ];
    }

    private static function isValidUrlHost(string $host): ?bool
    {
        if ($host === '' || \preg_match('/[\x00-\x20\x7F]/', $host)) {
            return null;
        }

        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $ip = \substr($host, 1, -1);
            return \filter_var(
                $ip,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_IPV6
            ) === false ? null : true;
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return self::isValidHostLabel($host, true) ? false : null;
    }

    private static function urlToString(mixed $url): string
    {
        // Result of parseURL - reconstruct scheme://authority/path.
        if (\is_array($url)
            && isset($url['scheme'], $url['authority'])
        ) {
            return $url['scheme']
                . '://' . $url['authority']
                . ($url['path'] ?? '');
        }

        return (string) $url;
    }

    /**
     * Extract Smithy auth scheme overrides from the endpoint's property map.
     * The Smithy rules-engine returns them keyed as `authSchemes` - a list
     * of maps like `[{name: sigv4, signingName: lambda, signingRegion: us-east-1}]`.
     *
     * @return list<EndpointAuthScheme>
     */
    private function extractAuthSchemes(mixed $properties): array
    {
        if (!\is_array($properties)) {
            return [];
        }

        $entries = $properties['authSchemes'] ?? null;
        if (!\is_array($entries)) {
            return [];
        }

        $schemes = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? null;
            if (!\is_string($name) || $name === '') {
                continue;
            }

            unset($entry['name']);
            $schemes[] = new EndpointAuthScheme(
                $this->endpointAuthSchemeIds[$name] ?? $name,
                $entry
            );
        }

        return $schemes;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeHeaders(mixed $headers): array
    {
        if (!\is_array($headers) || $headers === []) {
            return [];
        }
        $out = [];
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                // Rules engine emits `list<string>`. SDK wants a single
                // comma-joined string.
                $out[$name] = \implode(', ', $value);
            } else {
                $out[$name] = (string) $value;
            }
        }
        return $out;
    }
}
