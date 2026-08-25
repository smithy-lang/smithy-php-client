<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode\Compiler;

use Smithy\Client\Endpoint\Bytecode\Bytecode;
use Smithy\Client\Endpoint\Bytecode\RulesFunctionInterface;

/**
 * Emits a PHP class source string that reconstructs a given
 * {@see Bytecode} value as a process-cached singleton.
 *
 * Invoked at build time by the codegen framework
 * ({@see \Smithy\Codegen\Generator\EndpointBytecodeGenerator}). the
 * produced file is committed to source and loaded like any other class
 * at runtime. This trades a small amount of source-tree bulk for zero
 * per-request compilation cost and zero I/O beyond the one-time class
 * load.
 *
 * Function-table entries are reconstituted through their owning
 * registry class - each {@see RulesFunctionInterface} self-reports its
 * registry via `getRegistryClass()`. This keeps the generator free of
 * hard-coded registry lists, so downstream extensions (provider-specific
 * functions, service-specific functions, etc.) plug in without
 * modifying this class.
 */
final class BytecodeSourceGenerator
{
    /**
     * @param string $namespace Namespace of the generated class (e.g.
     *               `Example\\Widget\\Endpoint`).
     * @param string $className Short class name (e.g. `WidgetEndpointBytecode`).
     * @param string $sourceModel Path/URI printed in the docblock for
     *               provenance (e.g. `models/widget.json`).
     */
    public function generate(
        Bytecode $bytecode,
        string $namespace,
        string $className,
        string $sourceModel = ''
    ): string
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace {$namespace};";
        $lines[] = '';
        $lines[] = 'use Smithy\\Client\\Endpoint\\Bytecode\\Bytecode;';
        // Registry `use` statements are emitted just-in-time based on
        // which registries the ruleset's functions belong to.
        foreach ($this->collectRegistries($bytecode) as $fqcn) {
            $lines[] = "use {$fqcn};";
        }
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = " * Generated endpoint bytecode for {$className}.";
        $lines[] = ' *';
        $lines[] = ' * @generated Do not edit.';
        $lines[] = ' * @see EndpointBytecodeGenerator';
        if ($sourceModel !== '') {
            $lines[] = " * @source {$sourceModel}";
        }
        $lines[] = ' */';
        $lines[] = "final class {$className}";
        $lines[] = '{';
        $lines[] = '    private static ?Bytecode $instance = null;';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * The compiled bytecode. Cached at class level so';
        $lines[] = '     * client resolver shares one copy for';
        $lines[] = '     * the lifetime of the process.';
        $lines[] = '     */';
        $lines[] = '    public static function get(): Bytecode';
        $lines[] = '    {';
        $lines[] = '        if (self::$instance !== null) {';
        $lines[] = '            return self::$instance;';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        return self::$instance = new Bytecode(';
        $lines[] = '            code: '
            . $this->emitIntList($bytecode->code) . ',';
        $lines[] = '            constants: '
            . $this->emitConstants($bytecode->constants) . ',';
        $lines[] = '            functions: '
            . $this->emitFunctions($bytecode->functions) . ',';
        $lines[] = '            registers: '
            . $this->emitRegisters($bytecode->registers) . ',';
        $lines[] = '            inputRegisterMap: '
            . $this->emitStringIntMap($bytecode->inputRegisterMap) . ',';
        $lines[] = '            bddNodes: '
            . $this->emitIntList($bytecode->bddNodes) . ',';
        $lines[] = '            bddRoot: ' . $bytecode->bddRoot . ',';
        $lines[] = '            conditionOffsets: '
            . $this->emitIntList($bytecode->conditionOffsets) . ',';
        $lines[] = '            resultOffsets: '
            . $this->emitIntList($bytecode->resultOffsets) . ',';
        $lines[] = '            conditionTypes: '
            . $this->emitIntList($bytecode->conditionTypes) . ',';
        $lines[] = '            conditionOperands: '
            . $this->emitIntList($bytecode->conditionOperands) . ',';
        $lines[] = '        );';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        return \implode("\n", $lines);
    }

    /**
     * @param list<int> $values
     */
    private function emitIntList(array $values): string
    {
        if ($values === []) {
            return '[]';
        }
        // Wrap every 16 ints for readability. single-line lists for anything
        // short.
        if (\count($values) <= 16) {
            return '[' . \implode(', ', \array_map(intval(...), $values)) . ']';
        }
        $chunks = array_chunk($values, 16);
        $rendered = \array_map(
            fn(array $chunk) => '                '
                . \implode(', ', \array_map(intval(...), $chunk)) . ',',
            $chunks
        );
        return "[\n" . \implode("\n", $rendered) . "\n            ]";
    }

    /**
     * @param list<mixed> $values
     */
    private function emitConstants(array $values): string
    {
        if ($values === []) {
            return '[]';
        }
        $rendered = [];
        foreach ($values as $v) {
            $rendered[] = '                ' . $this->emitScalar($v) . ',';
        }
        return "[\n" . \implode("\n", $rendered) . "\n            ]";
    }

    /**
     * @param list<RulesFunctionInterface> $functions
     */
    private function emitFunctions(array $functions): string
    {
        if ($functions === []) {
            return '[]';
        }
        $rendered = [];
        foreach ($functions as $fn) {
            $rendered[] = '                '
                . $this->emitFunctionRef($fn) . ',';
        }
        return "[\n" . \implode("\n", $rendered) . "\n            ]";
    }

    private function emitFunctionRef(RulesFunctionInterface $fn): string
    {
        // Emit `Registry::get('functionName')`. The unqualified class
        // name is fine because we emit a matching `use ....` at the top
        // of the file for every distinct registry the ruleset touches.
        $registry = $fn->getRegistryClass();
        $shortName = self::shortName($registry);
        return \sprintf(
            '%s::get(%s)',
            $shortName,
            var_export($fn->getName(), true)
        );
    }

    /**
     * Collect the set of registry FQCNs referenced by the ruleset,
     * so the generator can emit one `use` per registry at the top
     * of the file.
     *
     * @return list<string>
     */
    private function collectRegistries(Bytecode $bytecode): array
    {
        $set = [];
        foreach ($bytecode->functions as $fn) {
            $set[$fn->getRegistryClass()] = true;
        }
        $out = \array_keys($set);
        sort($out); // stable ordering keeps diffs quiet.
        return $out;
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : \substr($fqcn, $pos + 1);
    }

    /**
     * @param list<array{
     *     name:string,
     *     builtIn:?string,
     *     default:mixed,
     *     required:bool,
     *     type:string
     * }> $registers
     */
    private function emitRegisters(array $registers): string
    {
        if ($registers === []) {
            return '[]';
        }
        $rendered = [];
        foreach ($registers as $r) {
            $rendered[] = '                ['
                . "'name' => " . var_export($r['name'], true) . ', '
                . "'builtIn' => " . var_export($r['builtIn'], true) . ', '
                . "'default' => " . $this->emitScalar($r['default']) . ', '
                . "'required' => " . var_export($r['required'], true) . ', '
                . "'type' => " . var_export($r['type'], true)
                . '],';
        }
        return "[\n" . \implode("\n", $rendered) . "\n            ]";
    }

    /**
     * @param array<string, int> $map
     */
    private function emitStringIntMap(array $map): string
    {
        if ($map === []) {
            return '[]';
        }
        $rendered = [];
        foreach ($map as $k => $v) {
            $rendered[] = '                '
                . var_export($k, true)
                . ' => '
                . (int) $v
                . ',';
        }
        return "[\n" . \implode("\n", $rendered) . "\n            ]";
    }

    /**
     * Emit a PHP literal for a scalar, list, or associative array of
     * scalars. Uses `var_export` for correctness. wraps the output in
     * lightweight formatting where it fits on one line.
     */
    private function emitScalar(mixed $value): string
    {
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return var_export($value, true);
        }
        if (is_float($value)) {
            // var_export preserves precision for the current PHP config.
            return var_export($value, true);
        }
        if (\is_string($value)) {
            return var_export($value, true);
        }
        if (\is_array($value)) {
            // Recurse. Format compactly if short.
            $encoded = var_export($value, true);
            // Normalize `array (\n  0 => ...\n)` output to `[...]` form.
            return $this->normalizeArrayExport($encoded);
        }
        throw new \RuntimeException(
            'Unsupported constant type: ' . get_debug_type($value)
        );
    }

    /**
     * `var_export` uses `array ( ... )` syntax. convert to short `[...]`
     * for readability. Handles nested arrays.
     */
    private function normalizeArrayExport(string $exported): string
    {
        // Simple transform - enough for endpoint-rules constants (scalar
        // leaves).
        $out = \preg_replace('/\barray \(\s*\)/', '[]', $exported) ?? $exported;
        $out = \preg_replace('/\barray \(/', '[', $out) ?? $out;
        $out = \preg_replace('/\)(,?\s*(?:\n|$))/', ']$1', $out) ?? $out;
        return $out;
    }
}
