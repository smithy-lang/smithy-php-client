<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode\Compiler;

use Smithy\Client\Endpoint\Bytecode\Bytecode;
use Smithy\Client\Endpoint\Bytecode\Opcodes;
use Smithy\Client\Endpoint\Bytecode\RulesFunctionInterface;

/**
 * Compile a Smithy `smithy.rules#endpointBdd` trait into {@see Bytecode}.
 *
 * The input is the raw trait map parsed from a Smithy model. It contains
 * endpoint parameters, conditions, result programs, the BDD root, and a
 * base64-encoded node table. Standard and provider-specific rules
 * functions are supplied separately through the function registry.
 *
 * Compilation proceeds in four stages:
 *
 *  1. Allocate registers for modeled parameters and named condition
 *     assignments.
 *  2. Classify conditions into BDD fast paths or append a full-dispatch
 *     condition program to the bytecode stream.
 *  3. Reserve result slot zero for the implicit `NoMatchRule`, then emit
 *     each modeled endpoint or error result.
 *  4. Decode the serialized BDD node table into contiguous
 *     `[condition, high, low]` triples.
 *
 * The compiler also applies unambiguous peephole fusions, including
 * specialized condition types, compact string templates, dedicated
 * standard-library opcodes, and register/property access combinations.
 * Expressions that do not match a specialized form use the canonical
 * function-dispatch opcodes.
 */
final class BddCompiler
{
    /** @var array<string, RulesFunctionInterface> */
    private array $functions;

    /** @var array<string, int> */
    private array $functionIndex = [];

    /** @var list<RulesFunctionInterface> */
    private array $functionList = [];

    /** @var list<mixed> */
    private array $constants = [];

    /** @var array<string, int> */
    private array $constantIndex = [];

    /** @var array<string, int> */
    private array $registerIndex = [];

    /**
     * @var list<array{
     *     name:string,
     *     builtIn:?string,
     *     default:mixed,
     *     required:bool,
     *     type:string
     * }>
     */
    private array $registerDefs = [];

    /**
     * @param array<string, RulesFunctionInterface> $functions
     * @param array<string, int> $extendedOpcodes Optional function-name to
     *                                            vendor opcode map. Matching
     *                                            calls emit that opcode
     *                                            instead of an `FNn`
     *                                            instruction. Codegen must
     *                                            wire a corresponding
     *                                            opcode handler.
     * @param bool $assumeAsciiEndpointStrings Whether string-typed endpoint
     *                                          parameters may skip runtime
     *                                          non-ASCII detection.
     */
    public function __construct(
        array $functions,
        private readonly array $extendedOpcodes = [],
        private readonly bool $assumeAsciiEndpointStrings = false
    )
    {
        foreach ($extendedOpcodes as $name => $opcode) {
            if ($opcode < 56 || $opcode > 255) {
                throw new \InvalidArgumentException(
                    "Extended opcode for {$name} must be between 56 and 255"
                );
            }
        }
        $this->functions = $functions;
    }

    /**
     * Compile the trait into immutable runtime bytecode.
     *
     * Compiler-owned tables are reset before every invocation so one
     * compiler instance can safely compile multiple endpoint rulesets.
     *
     * @param array<string, mixed> $trait Raw `endpointBdd` trait value.
     */
    public function compile(array $trait): Bytecode
    {
        $this->constants = [];
        $this->constantIndex = [];
        $this->functionIndex = [];
        $this->functionList = [];
        $this->registerIndex = [];
        $this->registerDefs = [];

        $params = $trait['parameters'] ?? [];
        $conditions = $trait['conditions'] ?? [];
        $results = $trait['results'] ?? [];

        // Preserve parameter order because it defines register indexes.
        $inputRegisterMap = [];
        foreach ($params as $name => $def) {
            $idx = $this->allocRegister($name, [
                'name' => $name,
                'builtIn' => $def['builtIn'] ?? null,
                'default' => $def['default'] ?? null,
                'required' => (bool) ($def['required'] ?? false),
                'type' => \strtolower((string) ($def['type'] ?? 'string'))
            ]);
            $inputRegisterMap[$name] = $idx;
        }

        // Assigned conditions allocate registers for their results.
        $conditionTypes = [];
        $conditionOperands = [];
        $conditionOffsets = [];
        $code = [];

        foreach ($conditions as $cond) {
            [$type, $operand, $prog] = $this->compileCondition($cond);
            $conditionTypes[] = $type;
            $conditionOperands[] = $operand;
            if ($prog === null) {
                // Fast-path conditions are never dispatched through code.
                $conditionOffsets[] = 0;
            } else {
                $conditionOffsets[] = \count($code);
                foreach ($prog as $b) {
                    $code[] = $b;
                }
            }
        }

        // Reserve result slot zero for the implicit NoMatchRule.
        $resultOffsets = [\count($code)];
        $code[] = Opcodes::LOAD_CONST;
        $code[] = $this->internConst(null);
        $code[] = Opcodes::RETURN_VALUE;

        foreach ($results as $res) {
            $resultOffsets[] = \count($code);
            $prog = $this->compileResult($res);
            foreach ($prog as $b) {
                $code[] = $b;
            }
        }

        $bddNodes = self::decodeNodes(
            (string) ($trait['nodes'] ?? ''),
            (int) ($trait['nodeCount'] ?? 0)
        );

        return new Bytecode(
            code: $code,
            constants: $this->constants,
            functions: $this->functionList,
            registers: $this->registerDefs,
            inputRegisterMap: $inputRegisterMap,
            bddNodes: $bddNodes,
            bddRoot: (int) ($trait['root'] ?? 0),
            conditionOffsets: $conditionOffsets,
            resultOffsets: $resultOffsets,
            conditionTypes: $conditionTypes,
            conditionOperands: $conditionOperands
        );
    }

    /**
     * Decode the BDD's base64, big-endian node table.
     *
     * Each node is three signed 32-bit integers: condition index, high
     * reference, and low reference. The returned list stores those triples
     * contiguously in evaluator order.
     *
     * @return list<int>
     */
    public static function decodeNodes(string $base64, int $nodeCount): array
    {
        $bytes = \base64_decode($base64, true);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Invalid base64 BDD node blob');
        }
        $expected = $nodeCount * 12;
        if (\strlen($bytes) !== $expected) {
            throw new \InvalidArgumentException(
                "BDD node blob size {$expected} bytes expected, got "
                    . \strlen($bytes)
            );
        }
        // Unpack as big-endian signed 32-bit ints, one per 4 bytes.
        $ints = \unpack('N*', $bytes);
        if ($ints === false) {
            throw new \InvalidArgumentException(
                'Failed to unpack BDD node blob'
            );
        }

        $out = [];
        foreach ($ints as $u) {
            $out[] = $u >= 0x80000000 ? $u - 0x100000000 : $u;
        }

        return $out;
    }

    /**
     * @param array{
     *     name:string,
     *     builtIn:?string,
     *     default:mixed,
     *     required:bool,
     *     type:string
     * } $def
     */
    private function allocRegister(string $name, array $def): int
    {
        if (isset($this->registerIndex[$name])) {
            return $this->registerIndex[$name];
        }
        $idx = \count($this->registerDefs);
        $this->registerDefs[] = $def;
        $this->registerIndex[$name] = $idx;

        return $idx;
    }

    private function internConst(mixed $value): int
    {
        $key = match (\gettype($value)) {
            'string'  => 's:' . $value,
            'integer' => 'i:' . $value,
            'boolean' => 'b:' . ($value ? '1' : '0'),
            'NULL'    => 'n:',
            default   => 'x:' . \serialize($value)
        };
        if (isset($this->constantIndex[$key])) {
            return $this->constantIndex[$key];
        }
        $idx = \count($this->constants);
        $this->constants[] = $value;
        $this->constantIndex[$key] = $idx;

        return $idx;
    }

    private function functionIndex(string $name): int
    {
        if (isset($this->functionIndex[$name])) {
            return $this->functionIndex[$name];
        }
        if (!isset($this->functions[$name])) {
            throw new \RuntimeException(
                "Unknown endpoint rules function: {$name}"
            );
        }
        $idx = \count($this->functionList);
        $this->functionList[] = $this->functions[$name];
        $this->functionIndex[$name] = $idx;

        return $idx;
    }

    /**
     * @param list<mixed> $argv
     *
     * @return void
     */
    private function validateFunctionArity(string $fn, array $argv): void
    {
        $function = $this->functions[$fn] ?? null;
        if ($function === null) {
            return;
        }

        $arity = \count($argv);
        $expectedArity = $function->getArgumentCount();
        if ($fn === 'coalesce' && $arity < 2) {
            throw new \RuntimeException(
                "Endpoint rules function {$fn} expects at least 2 "
                    . "arguments, got {$arity}"
            );
        }
        if ($expectedArity !== -1 && $arity !== $expectedArity) {
            throw new \RuntimeException(
                "Endpoint rules function {$fn} expects {$expectedArity} "
                    . "arguments, got {$arity}"
            );
        }
    }

    /**
     * @param array<string, mixed> $cond
     *
     * @return array{0:int, 1:int, 2:list<int>|null}
     */
    private function compileCondition(array $cond): array
    {
        $fn = (string) ($cond['fn'] ?? '');
        $argv = $cond['argv'] ?? [];
        $assign = $cond['assign'] ?? null;
        $this->validateFunctionArity($fn, $argv);

        // Assigned conditions need a full program to store their result.
        if ($assign === null) {
            switch ($fn) {
                case 'isSet':
                    // isSet({ref: X})
                    if (isset($argv[0]['ref'])) {
                        $reg = $this->registerIndex[$argv[0]['ref']] ?? null;
                        if ($reg !== null) {
                            return [Opcodes::COND_ISSET, $reg, null];
                        }
                    }
                    break;
                case 'booleanEquals':
                    // booleanEquals({ref: X}, true|false)
                    if (isset($argv[0]['ref'])
                        && \count($argv) === 2
                        && \is_bool($argv[1])
                    ) {
                        $reg = $this->registerIndex[$argv[0]['ref']] ?? null;
                        if ($reg !== null) {
                            return [
                                $argv[1]
                                    ? Opcodes::COND_IS_TRUE
                                    : Opcodes::COND_IS_FALSE,
                                $reg,
                                null
                            ];
                        }
                    }
                    break;
                case 'stringEquals':
                    // stringEquals({ref: X}, "literal")
                    if (isset($argv[0]['ref'])
                        && \count($argv) === 2
                        && \is_string($argv[1])
                    ) {
                        $reg = $this->registerIndex[$argv[0]['ref']] ?? null;
                        if ($reg !== null) {
                            $constIdx = $this->internConst($argv[1]);
                            return [
                                Opcodes::COND_STRING_EQ_REG_CONST,
                                ($constIdx << 8) | $reg,
                                null
                            ];
                        }
                    }
                    break;
            }
        }

        $prog = $this->emitExpression(['fn' => $fn, 'argv' => $argv]);
        if ($assign !== null) {
            $reg = $this->allocRegister($assign, [
                'name' => $assign,
                'builtIn' => null,
                'default' => null,
                'required' => false,
                'type' => 'any'
            ]);
            $prog[] = Opcodes::SET_REG_RETURN;
            $prog[] = $reg;
        } else {
            $prog[] = Opcodes::RETURN_VALUE;
        }

        return [Opcodes::COND_FULL, 0, $prog];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<int>
     */
    private function compileResult(array $result): array
    {
        $type = (string) ($result['type'] ?? 'endpoint');
        $prog = [];

        if ($type === 'error') {
            $errorExpr = $result['error'] ?? '';
            foreach ($this->compileTemplate($errorExpr) as $b) {
                $prog[] = $b;
            }
            $prog[] = Opcodes::RETURN_ERROR;

            return $prog;
        }

        $endpoint = $result['endpoint'] ?? [];
        $properties = $endpoint['properties'] ?? [];
        $headers = $endpoint['headers'] ?? [];
        $flags = 0;

        if (!empty($headers)) {
            foreach ($this->emitHeaders($headers) as $b) {
                $prog[] = $b;
            }
            $flags |= 0x01;
        }
        if (!empty($properties)) {
            foreach ($this->emitProperties($properties) as $b) {
                $prog[] = $b;
            }
            $flags |= 0x02;
        }
        $url = $endpoint['url'] ?? '';
        foreach ($this->compileTemplate($url) as $b) {
            $prog[] = $b;
        }
        $prog[] = Opcodes::RETURN_ENDPOINT;
        $prog[] = $flags;

        return $prog;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return list<int>
     */
    private function emitHeaders(array $headers): array
    {
        $prog = [];
        $n = 0;
        foreach ($headers as $name => $values) {
            $valueCount = \count((array) $values);
            foreach ((array) $values as $v) {
                foreach ($this->compileTemplate($v) as $b) {
                    $prog[] = $b;
                }
            }
            $prog[] = Opcodes::LISTN;
            $prog[] = $valueCount;
            $prog[] = Opcodes::LOAD_CONST;
            $prog[] = $this->internConst($name);
            $n++;
        }
        $prog[] = Opcodes::MAPN;
        $prog[] = $n;

        return $prog;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return list<int>
     */
    private function emitProperties(array $properties): array
    {
        $prog = [];
        $n = 0;
        foreach ($properties as $key => $value) {
            foreach ($this->emitLiteral($value) as $b) {
                $prog[] = $b;
            }
            $prog[] = Opcodes::LOAD_CONST;
            $prog[] = $this->internConst($key);
            $n++;
        }
        $prog[] = Opcodes::MAPN;
        $prog[] = $n;

        return $prog;
    }

    /**
     * @return list<int>
     */
    private function emitLiteral(mixed $v): array
    {
        if (\is_array($v)) {
            if (\array_is_list($v)) {
                $prog = [];
                foreach ($v as $el) {
                    foreach ($this->emitLiteral($el) as $b) {
                        $prog[] = $b;
                    }
                }
                $prog[] = Opcodes::LISTN;
                $prog[] = \count($v);

                return $prog;
            }

            return $this->emitProperties($v);
        }

        return \is_string($v)
            ? $this->compileTemplate($v)
            : $this->emitLoadConst($v);
    }

    /**
     * Compile a literal, reference, function call, or string template.
     *
     * Plain literals become constant loads, references become register
     * loads, and function maps are delegated to {@see self::emitExpression()}.
     * String templates use compact `BUILD_TEMPLATE` metadata when every
     * reference is a direct register. Property references fall back to an
     * explicit load/access sequence followed by `RESOLVE_TEMPLATE`.
     *
     * @return list<int>
     */
    private function compileTemplate(mixed $expr): array
    {
        if (\is_array($expr) && isset($expr['ref'])) {
            $reg = $this->registerIndex[$expr['ref']] ?? null;
            if ($reg === null) {
                $reference = $expr['ref'];

                throw new \RuntimeException(
                    "Unknown parameter reference: {$reference}"
                );
            }
            return [Opcodes::LOAD_REGISTER, $reg];
        }
        if (\is_array($expr) && isset($expr['fn'])) {
            return $this->emitExpression($expr);
        }
        if (!\is_string($expr)) {
            return $this->emitLoadConst($expr);
        }

        $segments = self::splitTemplate($expr);
        if (\count($segments) === 1 && $segments[0][0] === 'lit') {
            return $this->emitLoadConst($segments[0][1]);
        }

        if (\count($segments) === 1 && $segments[0][0] === 'ref') {
            return $this->emitTemplateRef($segments[0][1]);
        }

        foreach ($segments as $seg) {
            if ($seg[0] === 'ref' && \str_contains($seg[1], '#')) {
                return $this->emitResolveTemplate($segments);
            }
        }

        $meta = [];
        foreach ($segments as $seg) {
            if ($seg[0] === 'lit') {
                $meta[] = [0, $seg[1]];
            } else {
                $path = $seg[1];
                $reg = $this->registerIndex[$path] ?? null;
                if ($reg === null) {
                    throw new \RuntimeException(
                        "Unknown template reference: {$path}"
                    );
                }
                $meta[] = [1, $reg];
            }
        }
        $metaIdx = $this->internConst($meta);

        return [
            Opcodes::BUILD_TEMPLATE,
            ($metaIdx >> 8) & 0xFF,
            $metaIdx & 0xFF
        ];
    }

    /**
     * @return list<int>
     */
    private function emitLoadConst(mixed $value): array
    {
        $idx = $this->internConst($value);
        if ($idx <= 0xFF) {
            return [Opcodes::LOAD_CONST, $idx];
        }

        return [
            Opcodes::LOAD_CONST_W,
            ($idx >> 8) & 0xFF,
            $idx & 0xFF
        ];
    }

    /**
     * @param list<array{0:string,1:string}> $segments
     *
     * @return list<int>
     */
    private function emitResolveTemplate(array $segments): array
    {
        $prog = [];
        foreach ($segments as $seg) {
            $segmentProg = $seg[0] === 'lit'
                ? $this->emitLoadConst($seg[1])
                : $this->emitTemplateRef($seg[1]);
            foreach ($segmentProg as $b) {
                $prog[] = $b;
            }
        }
        $prog[] = Opcodes::RESOLVE_TEMPLATE;
        $prog[] = \count($segments);

        return $prog;
    }

    /**
     * @return list<int>
     */
    private function emitTemplateRef(string $path): array
    {
        $hash = \strpos($path, '#');
        if ($hash === false) {
            $reg = $this->registerIndex[$path] ?? null;
            if ($reg === null) {
                throw new \RuntimeException(
                    "Unknown template reference: {$path}"
                );
            }

            return [Opcodes::LOAD_REGISTER, $reg];
        }

        $regName = \substr($path, 0, $hash);
        $reg = $this->registerIndex[$regName] ?? null;
        if ($reg === null) {
            throw new \RuntimeException(
                "Unknown template reference: {$regName}"
            );
        }

        $prog = [];
        foreach (\explode('#', \substr($path, $hash + 1)) as $i => $part) {
            $idx = $this->internConst($part);
            if ($i === 0) {
                $prog[] = Opcodes::GET_PROPERTY_REG;
                $prog[] = $reg;
            } else {
                $prog[] = Opcodes::GET_PROPERTY;
            }
            $prog[] = ($idx >> 8) & 0xFF;
            $prog[] = $idx & 0xFF;
        }

        return $prog;
    }

    /**
     * Split a Smithy string template into literal and reference segments.
     *
     * Double braces (`{{` and `}}`) escape literal braces. A single
     * opening brace begins a reference that must terminate with `}`.
     *
     * @return list<array{0:string,1:string}> Entries are `['lit', value]`
     *                                         or `['ref', path]`.
     */
    private static function splitTemplate(string $s): array
    {
        $segments = [];
        $i = 0;
        $n = \strlen($s);
        $lit = '';
        while ($i < $n) {
            $ch = $s[$i];
            switch (true) {
                case $ch === '{' && $i + 1 < $n && $s[$i + 1] === '{':
                    $lit .= '{';
                    $i += 2;
                    break;
                case $ch === '}' && $i + 1 < $n && $s[$i + 1] === '}':
                    $lit .= '}';
                    $i += 2;
                    break;
                case $ch === '{':
                    if ($lit !== '') {
                        $segments[] = ['lit', $lit];
                        $lit = '';
                    }
                    $close = \strpos($s, '}', $i + 1);
                    if ($close === false) {
                        throw new \RuntimeException(
                            "Unterminated template ref in: {$s}"
                        );
                    }
                    $segments[] = ['ref', \substr($s, $i + 1, $close - $i - 1)];
                    $i = $close + 1;
                    break;
                default:
                    $lit .= $ch;
                    $i++;
                    break;
            }
        }
        if ($lit !== '') {
            $segments[] = ['lit', $lit];
        }
        if ($segments === []) {
            $segments[] = ['lit', ''];
        }

        return $segments;
    }

    /**
     * Compile a function-call expression.
     *
     * Standard functions with dedicated opcodes are fused when their
     * argument shape permits it. `getAttr` lowers to property/index access
     * instructions and combines the first access with a register load when
     * possible. Registered vendor functions may emit an extended opcode.
     * Remaining calls push their arguments and use the appropriate `FNn`
     * instruction.
     *
     * @param array<string, mixed> $expr
     *
     * @return list<int>
     */
    private function emitExpression(array $expr): array
    {
        $fn = (string) ($expr['fn'] ?? '');
        $argv = $expr['argv'] ?? [];

        switch ($fn) {
            case 'substring':
                if (\count($argv) === 4
                    && \is_int($argv[1])
                    && \is_int($argv[2])
                    && \is_bool($argv[3])
                    && $argv[1] >= 0 && $argv[1] <= 255
                    && $argv[2] >= 0 && $argv[2] <= 255
                ) {
                    $prog = $this->compileTemplate($argv[0]);
                    $flags = ($argv[3] ? 0x01 : 0)
                        | ($this->isAsciiSafeExpr($argv[0]) ? 0x02 : 0);
                    $prog[] = Opcodes::SUBSTRING;
                    $prog[] = $argv[1];
                    $prog[] = $argv[2];
                    $prog[] = $flags;

                    return $prog;
                }
                break;

            case 'uriEncode':
                if (\count($argv) === 1) {
                    $prog = $this->compileTemplate($argv[0]);
                    $prog[] = Opcodes::URI_ENCODE;

                    return $prog;
                }
                break;

            case 'isValidHostLabel':
                if (\count($argv) === 2) {
                    $prog = $this->compileTemplate($argv[0]);
                    foreach ($this->compileTemplate($argv[1]) as $b) {
                        $prog[] = $b;
                    }
                    $prog[] = Opcodes::IS_VALID_HOST_LABEL;

                    return $prog;
                }
                break;

            case 'parseURL':
                if (\count($argv) === 1) {
                    $prog = $this->compileTemplate($argv[0]);
                    $prog[] = Opcodes::PARSE_URL;

                    return $prog;
                }
                break;

            default:
                if (isset($this->extendedOpcodes[$fn])) {
                    $prog = [];
                    foreach ($argv as $arg) {
                        foreach ($this->compileTemplate($arg) as $b) {
                            $prog[] = $b;
                        }
                    }
                    $prog[] = $this->extendedOpcodes[$fn];

                    return $prog;
                }
                break;

            case 'getAttr':
                if (isset($argv[0], $argv[1]) && \is_string($argv[1])) {
                    $prog = $this->compileTemplate($argv[0]);
                    $steps = self::parsePropertyPath($argv[1]);

                    $tail = \count($prog);
                    $canFuse = $tail === 2
                        && $prog[0] === Opcodes::LOAD_REGISTER
                        && $steps !== [];
                    if ($canFuse) {
                        $reg = $prog[1];
                        $first = $steps[0];
                        unset($steps[0]);
                        if ($first[0] === 'prop') {
                            $idx = $this->internConst($first[1]);
                            $prog = [
                                Opcodes::GET_PROPERTY_REG,
                                $reg,
                                ($idx >> 8) & 0xFF,
                                $idx & 0xFF
                            ];
                        } elseif ($first[1] < 0) {
                            $prog = [
                                Opcodes::GET_NEGATIVE_INDEX_REG,
                                $reg,
                                -$first[1]
                            ];
                        } else {
                            $prog = [
                                Opcodes::GET_INDEX_REG,
                                $reg,
                                $first[1]
                            ];
                        }
                    }

                    foreach ($steps as $step) {
                        if ($step[0] === 'prop') {
                            $idx = $this->internConst($step[1]);
                            $prog[] = Opcodes::GET_PROPERTY;
                            $prog[] = ($idx >> 8) & 0xFF;
                            $prog[] = $idx & 0xFF;
                        } elseif ($step[1] < 0) {
                            $prog[] = Opcodes::GET_NEGATIVE_INDEX;
                            $prog[] = -$step[1];
                        } else {
                            $prog[] = Opcodes::GET_INDEX;
                            $prog[] = $step[1];
                        }
                    }

                    return $prog;
                }
                break;
        }

        $prog = [];
        foreach ($argv as $arg) {
            foreach ($this->compileTemplate($arg) as $b) {
                $prog[] = $b;
            }
        }
        $arity = \count($argv);
        $fnIdx = $this->functionIndex($fn);
        $this->validateFunctionArity($fn, $argv);
        $expectedArity = $this->functions[$fn]->getArgumentCount();
        $op = $expectedArity === -1
            ? Opcodes::FN
            : match ($arity) {
                0 => Opcodes::FN0,
                1 => Opcodes::FN1,
                2 => Opcodes::FN2,
                3 => Opcodes::FN3,
                default => Opcodes::FN
            };
        $prog[] = $op;
        $prog[] = $fnIdx;
        if ($op === Opcodes::FN) {
            $prog[] = $arity;
        }

        return $prog;
    }

    /**
     * Parse a `getAttr` property path into property and index steps.
     *
     * For example, `foo.bar[3].baz` becomes property `foo`, property
     * `bar`, index `3`, and property `baz`. Negative indexes are retained
     * for lowering to the dedicated negative-index opcodes.
     *
     * @return list<array{0:string,1:string|int}>
     */
    private static function parsePropertyPath(string $path): array
    {
        if ($path === '' || $path[0] === '.' || \str_ends_with($path, '.')) {
            throw new \RuntimeException("Malformed property path: {$path}");
        }

        $out = [];
        $token = '';
        $i = 0;
        $n = \strlen($path);
        while ($i < $n) {
            $ch = $path[$i];
            switch ($ch) {
                case '.':
                    $next = $i + 1 < $n ? $path[$i + 1] : '';
                    if ($token === '') {
                        $previous = $i > 0 ? $path[$i - 1] : '';
                        if ($previous !== ']' || $next === '[') {
                            throw new \RuntimeException(
                                "Malformed property path: {$path}"
                            );
                        }
                        $i++;
                        break;
                    }
                    if ($next === '[') {
                        throw new \RuntimeException(
                            "Malformed property path: {$path}"
                        );
                    }
                    $out[] = ['prop', $token];
                    $token = '';
                    $i++;
                    break;
                case '[':
                    if ($token !== '') {
                        $out[] = ['prop', $token];
                        $token = '';
                    }
                    $close = \strpos($path, ']', $i);
                    if ($close === false) {
                        throw new \RuntimeException(
                            "Malformed index in path: {$path}"
                        );
                    }
                    $index = \substr($path, $i + 1, $close - $i - 1);
                    if ($index === ''
                        || !\preg_match('/^-?\d+$/', $index)
                    ) {
                        throw new \RuntimeException(
                            "Malformed index in path: {$path}"
                        );
                    }
                    $out[] = ['idx', (int) $index];
                    $i = $close + 1;
                    break;
                default:
                    $token .= $ch;
                    $i++;
                    break;
            }
        }
        if ($token !== '') {
            $out[] = ['prop', $token];
        }

        return $out;
    }

    /**
     * Return true if an expression is provably ASCII at compile time.
     *
     * This conservative proof allows substring opcodes to skip their
     * runtime non-ASCII scan. It accepts ASCII literals, string parameters
     * when codegen explicitly guarantees endpoint strings are ASCII, and
     * functions such as `substring`, `uriEncode`, and `getAttr` that
     * preserve the input's byte set. Unknown expression shapes return
     * false and use the safe runtime path.
     */
    private function isAsciiSafeExpr(mixed $expr): bool
    {
        if (\is_array($expr) && isset($expr['ref'])) {
            $reg = $this->registerIndex[$expr['ref']] ?? null;
            if ($reg === null) {
                return false;
            }

            return $this->assumeAsciiEndpointStrings
                && ($this->registerDefs[$reg]['type'] ?? '') === 'string';
        }

        if (\is_array($expr) && isset($expr['fn'])) {
            $fn = (string) $expr['fn'];
            $argv = $expr['argv'] ?? [];

            return match ($fn) {
                'substring', 'uriEncode' => isset($argv[0])
                    && $this->isAsciiSafeExpr($argv[0]),
                'getAttr' => isset($argv[0])
                    && $this->isAsciiSafeExpr($argv[0]),
                default => false
            };
        }

        if (\is_string($expr)) {
            return \preg_match('/[\x80-\xff]/', $expr) !== 1;
        }

        return false;
    }
}
