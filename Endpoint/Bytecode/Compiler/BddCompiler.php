<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode\Compiler;

use Smithy\Client\Endpoint\Bytecode\Bytecode;
use Smithy\Client\Endpoint\Bytecode\Opcodes;
use Smithy\Client\Endpoint\Bytecode\RulesFunctionInterface;

/**
 * Compiles a Smithy `smithy.rules#endpointBdd` trait into an executable
 * {@see Bytecode} value.
 *
 * Inputs:
 *   - `$trait`     Raw trait dict as parsed from `model.json`. Expected
 *                  keys: `parameters`, `conditions`, `results`, `root`,
 *                  `nodeCount`, `nodes` (base64), optional `version`.
 *   - `$functions` `array<string, RulesFunctionInterface>` merged registry of
 *                  standard and provider-defined rules functions.
 *
 * Output: a {@see Bytecode} instance suitable for the endpoint evaluator.
 *
 * Compilation strategy:
 *   1. Assign a register index to every parameter and every named
 *      assignment surfaced by a condition (`assign` field).
 *   2. Walk conditions once, classifying each into either a fast-path
 *      shape (recorded in `$conditionTypes` / `$conditionOperands`) or a
 *      full-dispatch program appended to `$code` with an entry recorded
 *      in `$conditionOffsets`.
 *   3. Walk results the same way. Every result becomes a program in
 *      `$code` terminating in `RETURN_ENDPOINT` or `RETURN_ERROR`.
 *   4. Decode the base64 `nodes` blob into a flat `int[]` triples list
 *      matching smithy-java's big-endian 12-byte-per-node layout.
 *
 * The compiler is single-pass. A small handful of peephole fusions
 * are applied where the shape is unambiguous: the BDD condition
 * fast-paths (`COND_ISSET`, `COND_IS_TRUE`, `COND_STRING_EQ_REG_CONST`)
 * and the `getAttr` head-collapse into `GET_PROPERTY_REG` /
 * `GET_INDEX_REG`. Everything else uses the canonical opcodes.
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

    /** @var list<array{name:string, builtIn:?string, default:mixed, required:bool, type:string}> */
    private array $registerDefs = [];

    /**
     * @param array<string, RulesFunctionInterface> $functions
     * @param array<string, int>                    $extendedOpcodes Optional
     *                      function-name to opcode-byte map for vendor
     *                      fusions. When a compiled `{"fn": name, ...}`
     *                      matches a key, the compiler emits `[..., $op]`
     *                      instead of the generic `FN1`/`FN2`/`FN3`
     *                      sequence. Consumed by runtime through
     *                      {@see \Smithy\Client\Endpoint\Bytecode\OpcodeHandlerInterface}.
     *                      the codegen layer is responsible for wiring a
     *                      matching handler to the evaluator.
     * @param bool $assumeAsciiEndpointStrings Whether string-typed endpoint
     *                      parameters may skip runtime non-ASCII detection.
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
     * @param array<string, mixed> $trait
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

        // 1. Allocate a register for each parameter, preserving definition order.
        //    The evaluator uses the register index to look up defaults and
        //    to accept input by name via `inputRegisterMap`.
        $inputRegisterMap = [];
        foreach ($params as $name => $def) {
            $idx = $this->allocRegister($name, [
                'name' => $name,
                'builtIn' => $def['builtIn'] ?? null,
                'default' => $def['default'] ?? null,
                'required' => (bool) ($def['required'] ?? false),
                'type' => \strtolower((string) ($def['type'] ?? 'string')),
            ]);
            $inputRegisterMap[$name] = $idx;
        }

        // 2. Compile conditions. Each condition's `assign` allocates a
        //    register. The condition program (when a fallback is needed)
        //    ends with SET_REG_RETURN so the value lands in the register.
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

        // 3. Compile results. Each result appends a program that ends in
        //    RETURN_ENDPOINT or RETURN_ERROR.
        $resultOffsets = [];
        foreach ($results as $res) {
            $resultOffsets[] = \count($code);
            $prog = $this->compileResult($res);
            foreach ($prog as $b) {
                $code[] = $b;
            }
        }

        // 4. Decode BDD nodes. Blob is base64 of big-endian 12-byte-per-node
        //    triples: [condIdx(int32), high(int32), low(int32)].
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
                "BDD node blob size {$expected} bytes expected, got " . \strlen($bytes)
            );
        }
        // Unpack as big-endian signed 32-bit ints, one per 4 bytes.
        $ints = \unpack('N*', $bytes);
        if ($ints === false) {
            throw new \InvalidArgumentException('Failed to unpack BDD node blob');
        }
        // \unpack('N*') gives 1-indexed unsigned values. Convert to signed
        // and reindex.
        $out = [];
        foreach ($ints as $u) {
            // On 64-bit PHP, 'N' returns 0..2^32-1. Interpret >= 2^31 as negative.
            $out[] = $u >= 0x80000000 ? $u - 0x100000000 : $u;
        }
        return $out;
    }

    /**
     * @param array{name:string, builtIn:?string, default:mixed, required:bool, type:string} $def
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
        // Stable string key: scalar + type. Arrays and other types serialize.
        // `gettype` returns a small closed set of strings, so match on it
        // to get a single dispatch instead of a chain of `is_*` calls.
        $key = match (\gettype($value)) {
            'string'  => 's:' . $value,
            'integer' => 'i:' . $value,
            'boolean' => 'b:' . ($value ? '1' : '0'),
            'NULL'    => 'n:',
            default   => 'x:' . \serialize($value),
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
            throw new \RuntimeException("Unknown endpoint rules function: {$name}");
        }
        $idx = \count($this->functionList);
        $this->functionList[] = $this->functions[$name];
        $this->functionIndex[$name] = $idx;
        return $idx;
    }

    /**
     * @param list<mixed> $argv
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

        // Fast path: condition without assignment matching one of the
        // recognized shapes. Conditions carrying an `assign` always need
        // the full program so the value can be stored into a register.
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
                    if (isset($argv[0]['ref']) && \count($argv) === 2 && \is_bool($argv[1])) {
                        $reg = $this->registerIndex[$argv[0]['ref']] ?? null;
                        if ($reg !== null) {
                            return [
                                $argv[1] ? Opcodes::COND_IS_TRUE : Opcodes::COND_IS_FALSE,
                                $reg,
                                null,
                            ];
                        }
                    }
                    break;
                case 'stringEquals':
                    // stringEquals({ref: X}, "literal")
                    if (isset($argv[0]['ref']) && \count($argv) === 2 && \is_string($argv[1])) {
                        $reg = $this->registerIndex[$argv[0]['ref']] ?? null;
                        if ($reg !== null) {
                            $constIdx = $this->internConst($argv[1]);
                            return [
                                Opcodes::COND_STRING_EQ_REG_CONST,
                                ($constIdx << 8) | $reg,
                                null,
                            ];
                        }
                    }
                    break;
            }
        }

        // Full-dispatch fallback: emit the function-call program.
        $prog = $this->emitExpression(['fn' => $fn, 'argv' => $argv]);
        if ($assign !== null) {
            $reg = $this->allocRegister($assign, [
                'name' => $assign,
                'builtIn' => null,
                'default' => null,
                'required' => false,
                'type' => 'any',
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
            // Errors can be either a plain string or a template. Reuse
            // the same expression compiler. Strings become LOAD_CONST.
            foreach ($this->compileTemplate($errorExpr) as $b) {
                $prog[] = $b;
            }
            $prog[] = Opcodes::RETURN_ERROR;
            return $prog;
        }

        // Endpoint result. Emit optional headers/properties (each pushed
        // as a map onto the stack), then URL, then RETURN_ENDPOINT with
        // flag bits indicating what's on the stack.
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
        // URL - either a bare template or a ref.
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
        // headers: map<string, list<string-template>>
        $prog = [];
        $n = 0;
        foreach ($headers as $name => $values) {
            // Compile each header value template, wrap the list, push key.
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
        return \array_merge($prog, [Opcodes::MAPN, $n]);
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
        return \array_merge($prog, [Opcodes::MAPN, $n]);
    }

    /**
     * @return list<int>
     */
    private function emitLiteral(mixed $v): array
    {
        if (\is_array($v)) {
            // Recursive map - build inner map, then wrap.
            if (self::isListArray($v)) {
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

    private static function isListArray(array $a): bool
    {
        return \array_is_list($a);
    }

    /**
     * Compile a string that may be a plain literal or a Smithy string
     * template of the form `"foo{Hostname}.{Metadata#suffix}"`.
     *
     * @return list<int>
     */
    private function compileTemplate(mixed $expr): array
    {
        if (\is_array($expr) && isset($expr['ref'])) {
            $reg = $this->registerIndex[$expr['ref']] ?? null;
            if ($reg === null) {
                throw new \RuntimeException("Unknown parameter reference: {$expr['ref']}");
            }
            return [Opcodes::LOAD_REGISTER, $reg];
        }
        if (\is_array($expr) && isset($expr['fn'])) {
            return $this->emitExpression($expr);
        }
        if (!\is_string($expr)) {
            return $this->emitLoadConst($expr);
        }

        // Split into literal / reference segments.
        $segments = self::splitTemplate($expr);
        if (count($segments) === 1 && $segments[0][0] === 'lit') {
            return $this->emitLoadConst($segments[0][1]);
        }

        if (count($segments) === 1 && $segments[0][0] === 'ref') {
            return $this->emitTemplateRef($segments[0][1]);
        }

        // Plain register/literal templates benchmark better as one fused
        // metadata walk. Property references benchmark better after they
        // are materialized onto the stack, then joined with RESOLVE_TEMPLATE.
        foreach ($segments as $seg) {
            if ($seg[0] === 'ref' && \str_contains($seg[1], '#')) {
                return $this->emitResolveTemplate($segments);
            }
        }

        $meta = [];
        foreach ($segments as $seg) {
            if ($seg[0] === 'lit') {
                // Tag 0 = literal string.
                $meta[] = [0, $seg[1]];
            } else {
                // Tag 1 = plain register ref.
                $path = $seg[1];
                $reg = $this->registerIndex[$path] ?? null;
                if ($reg === null) {
                    throw new \RuntimeException("Unknown template reference: {$path}");
                }
                $meta[] = [1, $reg];
            }
        }
        $metaIdx = $this->internConst($meta);
        return [
            Opcodes::BUILD_TEMPLATE,
            ($metaIdx >> 8) & 0xFF,
            $metaIdx & 0xFF,
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
            $idx & 0xFF,
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
                throw new \RuntimeException("Unknown template reference: {$path}");
            }
            return [Opcodes::LOAD_REGISTER, $reg];
        }

        $regName = \substr($path, 0, $hash);
        $reg = $this->registerIndex[$regName] ?? null;
        if ($reg === null) {
            throw new \RuntimeException("Unknown template reference: {$regName}");
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
     * Split a template string into segments.
     *
     * @return list<array{0:string,1:string}> alternating `['lit',$s]` / `['ref',$path]`
     */
    private static function splitTemplate(string $s): array
    {
        $segments = [];
        $i = 0;
        $n = \strlen($s);
        $lit = '';
        while ($i < $n) {
            $ch = $s[$i];
            // Dispatch on the current char's role in the mini-grammar:
            // `{{` / `}}` escapes, `{` opens a ref, anything else is
            // a literal char. `switch (true)` because two arms need
            // lookahead over `$s[$i+1]`.
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
                        throw new \RuntimeException("Unterminated template ref in: {$s}");
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
        if (empty($segments)) {
            $segments[] = ['lit', ''];
        }
        return $segments;
    }

    /**
     * Compile a function-call expression `{"fn": name, "argv": [...]}`.
     *
     * `getAttr` is special-cased into `GET_PROPERTY`/`GET_INDEX` chains.
     *
     * @param array<string, mixed> $expr
     *
     * @return list<int>
     */
    private function emitExpression(array $expr): array
    {
        $fn = (string) ($expr['fn'] ?? '');
        $argv = $expr['argv'] ?? [];

        // Function-shape dispatch. Named cases fuse specific functions
        // into dedicated opcode sequences. `default` falls through to
        // the generic FN dispatch.
        switch ($fn) {
            case 'substring':
                // substring(input, start, stop, reverse) with all three
                // trailing args as literal int/bool. Emits the dedicated
                // SUBSTRING opcode instead of the general FN dispatch.
                //
                // Flags byte layout matches Evaluator case 34:
                //   bit 0 = reverse-from-end
                //   bit 1 = compiler-proved ASCII-safe input (skips the
                //           `preg_match('/[\x80-\xff]/')` scan)
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
                // fn:uriEncode(x) - 1 arg. Emits the fused URI_ENCODE
                // arm (opcode 37) instead of the generic CALL_FN dispatch.
                // Skips the function-table lookup + virtual `apply1` call
                // on every request that renders a path/query segment.
                if (\count($argv) === 1) {
                    $prog = $this->compileTemplate($argv[0]);
                    $prog[] = Opcodes::URI_ENCODE;
                    return $prog;
                }
                break;

            case 'isValidHostLabel':
                // fn:isValidHostLabel(x, allowSubdomains) - 2 args. Emits
                // the fused IS_VALID_HOST_LABEL arm (opcode 35). The
                // Evaluator arm reads `[label, allowDots]` off the stack,
                // so we compile both args normally and just skip the FN2
                // dispatch - no arg-shape constraint.
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
                // fn:parseURL(x) - 1 arg. Emits the fused PARSE_URL arm
                // (opcode 36). Same free-win as URI_ENCODE / IS_VALID_HOST_LABEL.
                if (\count($argv) === 1) {
                    $prog = $this->compileTemplate($argv[0]);
                    $prog[] = Opcodes::PARSE_URL;
                    return $prog;
                }
                break;

            default:
                // Vendor-fused opcode? Emit args in order, then the
                // opcode byte. Arity comes from the arg list - the
                // handler is responsible for popping the right number.
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

                    // Peephole: fuse the trailing LOAD_REGISTER with the
                    // first property/index step into a single
                    // GET_PROPERTY_REG / GET_INDEX_REG dispatch. Cuts a
                    // full opcode iteration off every `getAttr(ref, "path")`
                    // on chained property and index accessors.
                    $tail = \count($prog);
                    $canFuse = $tail === 2
                        && $prog[0] === Opcodes::LOAD_REGISTER
                        && $steps !== [];
                    if ($canFuse) {
                        $reg = $prog[1];
                        $first = \array_shift($steps);
                        if ($first[0] === 'prop') {
                            $idx = $this->internConst($first[1]);
                            $prog = [
                                Opcodes::GET_PROPERTY_REG,
                                $reg,
                                ($idx >> 8) & 0xFF,
                                $idx & 0xFF,
                            ];
                        } elseif ($first[1] < 0) {
                            // Negative index like `[-2]` - GET_INDEX_REG
                            // does a raw lookup which would miss on a
                            // 0-indexed list. GET_NEGATIVE_INDEX_REG
                            // takes an unsigned "N from end" operand.
                            $prog = [
                                Opcodes::GET_NEGATIVE_INDEX_REG,
                                $reg,
                                -$first[1],
                            ];
                        } else {
                            $prog = [
                                Opcodes::GET_INDEX_REG,
                                $reg,
                                $first[1],
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
                            // See note above on GET_NEGATIVE_INDEX_REG.
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

        // Generic FN dispatch: push args then invoke by arity.
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
                default => Opcodes::FN,
            };
        $prog[] = $op;
        $prog[] = $fnIdx;
        if ($op === Opcodes::FN) {
            $prog[] = $arity;
        }
        return $prog;
    }

    /**
     * Parse "foo.bar[3].baz" -> [['prop','foo'],['prop','bar'],['idx',3],['prop','baz']].
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
                        throw new \RuntimeException("Malformed index in path: {$path}");
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
     * Compile-time predicate: can we prove `$expr` evaluates to a
     * string whose bytes are all ASCII (0x00-0x7F)?
     *
     * Used to elide the `preg_match('/[\x80-\xff]/', ...)` scan in
     * SUBSTRING / SUBSTRING_EQ. Conservative - returns false whenever
     * proof isn't obvious, in which case the Evaluator falls back to
     * the safe substring path.
     *
     * Rules covered:
     *   1. `{ref: X}` where X is a `string`-typed endpoint parameter
     *      and codegen explicitly guarantees endpoint strings are ASCII.
     *   2. `substring(...)` / `getAttr(<ascii-safe>, ...)` - the output
     *      is bounded above by the input's byte set, so ASCII in ->
     *      ASCII out.
     *   3. Literal string values (they're inlined into the bytecode
     *      as PHP source, but if the model contains non-ASCII we
     *      can't safely elide the scan).
     */
    private function isAsciiSafeExpr(mixed $expr): bool
    {
        // Register reference - require an explicit codegen guarantee and
        // check the declared parameter type.
        if (\is_array($expr) && isset($expr['ref'])) {
            $reg = $this->registerIndex[$expr['ref']] ?? null;
            if ($reg === null) {
                return false;
            }
            return $this->assumeAsciiEndpointStrings
                && ($this->registerDefs[$reg]['type'] ?? '') === 'string';
        }

        // Function call - recurse into shapes that preserve ASCII.
        if (\is_array($expr) && isset($expr['fn'])) {
            $fn = (string) $expr['fn'];
            $argv = $expr['argv'] ?? [];
            return match ($fn) {
                // substring / getAttr / uriEncode preserve ASCII-in ->
                // ASCII-out.
                'substring', 'uriEncode' => isset($argv[0]) && $this->isAsciiSafeExpr($argv[0]),
                'getAttr' => isset($argv[0]) && $this->isAsciiSafeExpr($argv[0]),
                default => false,
            };
        }

        // Literal - scan bytes. Small enough to be cheap at compile
        // time even for the biggest partition-file constant.
        if (\is_string($expr)) {
            return \preg_match('/[\x80-\xff]/', $expr) !== 1;
        }

        return false;
    }
}
