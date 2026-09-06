<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Registry of Smithy rules-engine standard-library functions.
 *
 * Implements the full spec surface documented in "Endpoints 2.0: Rule-Set
 * Standard Library": `isSet`, `not`, `getAttr`, `substring`,
 * `stringEquals`, `booleanEquals`, `coalesce`, `split`, `ite`,
 * `uriEncode`, `parseURL`, `isValidHostLabel`.
 *
 * Not every stdlib function is dispatched through this registry at run
 * time - the compiler prefers dedicated opcodes for the fast-path
 * conditions (`isSet`, `stringEquals`, `booleanEquals`) and for
 * `getAttr` (lowered to `GET_PROPERTY`/`GET_INDEX` chains). They remain
 * in the registry for compile-time resolution and for expressions that
 * cannot use a dedicated opcode.
 *
 * Consumers get a fully-populated `array<string, RulesFunctionInterface>`
 * from {@see StdFunctions::all()} and pass it (usually merged with
 * vendor-specific extension registries) to the
 * {@see \Smithy\Client\Endpoint\Bytecode\Compiler\BddCompiler}.
 */
final class StdFunctions
{
    /** @var array<string, RulesFunctionInterface>|null */
    private static ?array $cache = null;

    private function __construct() {}

    /**
     * Look up a single stdlib function by name. Used by the generated
     * endpoint-bytecode class to reconstitute its function table without
     * paying the cost of building the whole registry.
     */
    public static function get(string $name): RulesFunctionInterface
    {
        $fn = self::all()[$name] ?? null;
        if ($fn === null) {
            throw new \InvalidArgumentException(
                "Unknown stdlib function: {$name}"
            );
        }
        return $fn;
    }

    /**
     * All registered standard-library functions, keyed by name.
     *
     * @return array<string, RulesFunctionInterface>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        return self::$cache = [
            'booleanEquals'    => self::booleanEquals(),
            'stringEquals'     => self::stringEquals(),
            'isSet'            => self::isSet(),
            'not'              => self::not(),
            'getAttr'          => self::getAttr(),
            'substring'        => self::substring(),
            'uriEncode'        => self::uriEncode(),
            'parseURL'         => self::parseURL(),
            'isValidHostLabel' => self::isValidHostLabel(),
            'coalesce'         => self::coalesce(),
            'split'            => self::split(),
            'ite'              => self::ite(),
        ];
    }

    private static function booleanEquals(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'booleanEquals';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 2;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return \is_bool($a0) && $a0 === $a1;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply2($args[0], $args[1]);
            }
        };
    }

    private static function stringEquals(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'stringEquals';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 2;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return \is_string($a0) && $a0 === $a1;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply2($args[0], $args[1]);
            }
        };
    }

    private static function isSet(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'isSet';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 1;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return $a0 !== null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply1($args[0]);
            }
        };
    }

    private static function not(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'not';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 1;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return $a0 !== true;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply1($args[0]);
            }
        };
    }

    private static function getAttr(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'getAttr';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 2;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                if (!\is_array($a0) || !\is_string($a1)) {
                    return null;
                }

                $steps = $this->parsePropertyPath($a1);
                if ($steps === null || $steps === []) {
                    return null;
                }

                $current = $a0;
                foreach ($steps as [$kind, $value]) {
                    if (!\is_array($current)) {
                        return null;
                    }
                    if ($kind === 'prop') {
                        if (!isset($current[$value])) {
                            return null;
                        }
                        $current = $current[$value];
                    } else {
                        $index = $value;
                        if ($index < 0) {
                            $index += \count($current);
                        }
                        if (!isset($current[$index])) {
                            return null;
                        }
                        $current = $current[$index];
                    }
                }

                return $current;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply2($args[0], $args[1]);
            }

            /**
             * @return list<array{0:string, 1:string|int}>|null
             */
            private function parsePropertyPath(string $path): ?array
            {
                $out = [];
                $token = '';
                $i = 0;
                $n = \strlen($path);
                while ($i < $n) {
                    $ch = $path[$i];
                    switch ($ch) {
                        case '.':
                            if ($token !== '') {
                                $out[] = ['prop', $token];
                                $token = '';
                            }
                            $i++;
                            break;
                        case '[':
                            if ($token !== '') {
                                $out[] = ['prop', $token];
                                $token = '';
                            }
                            $close = \strpos($path, ']', $i);
                            if ($close === false) {
                                return null;
                            }
                            $index = \substr($path, $i + 1, $close - $i - 1);
                            if ($index === ''
                                || !\preg_match('/^-?\d+$/', $index)
                            ) {
                                return null;
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
        };
    }

    private static function substring(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'substring';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 4;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                [$input, $start, $stop, $reverse] = [
                    $args[0] ?? null,
                    $args[1] ?? 0,
                    $args[2] ?? 0,
                    (bool) ($args[3] ?? false)
                ];
                if (!\is_string($input) || !\is_int($start) || !\is_int($stop)) {
                    return null;
                }
                if ($start >= $stop) {
                    return null;
                }
                $len = \strlen($input);
                if ($len < $stop) {
                    return null;
                }
                // ASCII-only guard: an octet > 127 aborts, which avoids
                // multi-byte indexing ambiguity. `preg_match` performs
                // the scan in C via PCRE.
                if (\preg_match('/[\x80-\xff]/', $input) === 1) {
                    return null;
                }
                return $reverse
                    ? \substr($input, $len - $stop, $stop - $start)
                    : \substr($input, $start, $stop - $start);
            }
        };
    }

    private static function uriEncode(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'uriEncode';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 1;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return \is_string($a0) ? \rawurlencode($a0) : null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply1($args[0]);
            }
        };
    }

    private static function parseURL(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'parseURL';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 1;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                if (!\is_string($a0)) {
                    return null;
                }
                if (!\preg_match('/^https?:\/\/[^\s\/?#]+(?:[\/?#]|$)/i', $a0)) {
                    return null;
                }
                // Spec: URLs containing a query MUST be rejected.
                if (\str_contains($a0, '?')) {
                    return null;
                }
                $parts = @\parse_url($a0);
                if ($parts === false
                    || empty($parts['scheme'])
                    || empty($parts['host'])
                ) {
                    return null;
                }
                if (isset($parts['query'])
                    || isset($parts['fragment'])
                    || isset($parts['user'])
                    || isset($parts['pass'])
                ) {
                    return null;
                }

                $scheme = \strtolower((string) $parts['scheme']);
                if ($scheme !== 'http' && $scheme !== 'https') {
                    return null;
                }

                $host   = (string) $parts['host'];
                $port   = $parts['port'] ?? null;
                $isIp = $this->isValidUrlHost($host);
                if ($isIp === null) {
                    return null;
                }

                // Reassemble the authority, preserving any explicit port.
                $authority = $host;
                if ($port !== null) {
                    $authority .= ':' . $port;
                }

                $path = $parts['path'] ?? '';
                if ($path === '') {
                    $path = '/';
                }

                // Normalized path: guaranteed to start AND end with `/`.
                $normalized = $path;
                if ($normalized === '' || $normalized[0] !== '/') {
                    $normalized = '/' . $normalized;
                }
                if (\substr($normalized, -1) !== '/') {
                    $normalized .= '/';
                }

                // Preserve bracketed form in the authority for IPv6.
                if (\filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)
                    && $host[0] !== '['
                ) {
                    $authority = '[' . $host . ']' . ($port !== null ? ':' . $port : '');
                }

                return [
                    'scheme'         => $scheme,
                    'authority'      => $authority,
                    'path'           => $path,
                    'normalizedPath' => $normalized,
                    'isIp'           => $isIp,
                ];
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply1($args[0]);
            }

            private function isValidUrlHost(string $host): ?bool
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

                foreach (\explode('.', $host) as $part) {
                    if ($part === ''
                        || !\preg_match(
                            '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
                            $part
                        )
                    ) {
                        return null;
                    }
                }

                return false;
            }
        };
    }

    private static function isValidHostLabel(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'isValidHostLabel';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 2;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                if (!\is_string($a0)) {
                    return false;
                }
                if ($a1 === true) {
                    foreach (\explode('.', $a0) as $label) {
                        if (!self::isLabel($label)) {
                            return false;
                        }
                    }
                    return true;
                }
                return self::isLabel($a0);
            }

            private static function isLabel(string $label): bool
            {
                // 1..63 chars, alnum at each end, hyphen allowed inside.
                return (bool) \preg_match(
                    '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/',
                    $label
                );
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return null;
            }

            public function apply(array $args): mixed
            {
                return $this->apply2($args[0], $args[1]);
            }
        };
    }

    private static function coalesce(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'coalesce';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return -1;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return $a0;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return $a0 ?? $a1;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return $a0 ?? $a1 ?? $a2;
            }

            public function apply(array $args): mixed
            {
                $n = \count($args);
                for ($i = 0; $i < $n - 1; $i++) {
                    if ($args[$i] !== null) {
                        return $args[$i];
                    }
                }
                // Last argument returned regardless of nullability, per spec.
                return $args[$n - 1] ?? null;
            }
        };
    }

    private static function split(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'split';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 3;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                if (!\is_string($a0)
                    || !\is_string($a1)
                    || !\is_int($a2)
                    || $a1 === ''
                    || $a2 < 0
                ) {
                    return null;
                }
                if ($a0 === '') {
                    return [''];
                }
                if ($a2 === 1) {
                    return [$a0];
                }
                // explode's `$limit` is exclusive (0 means "no limit" is
                // *not* the same as our contract - PHP's 0 means single
                // element). Feed PHP_INT_MAX for unlimited.
                return \explode($a1, $a0, $a2 === 0 ? \PHP_INT_MAX : $a2);
            }

            public function apply(array $args): mixed
            {
                return $this->apply3($args[0], $args[1], $args[2]);
            }
        };
    }

    private static function ite(): RulesFunctionInterface
    {
        return new class implements RulesFunctionInterface {
            public function getName(): string
            {
                return 'ite';
            }

            public function getRegistryClass(): string
            {
                return StdFunctions::class;
            }

            public function getArgumentCount(): int
            {
                return 3;
            }

            public function apply0(): mixed
            {
                return null;
            }

            public function apply1(mixed $a0): mixed
            {
                return null;
            }

            public function apply2(mixed $a0, mixed $a1): mixed
            {
                return null;
            }

            public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
            {
                return $a0 === true ? $a1 : $a2;
            }

            public function apply(array $args): mixed
            {
                return $this->apply3($args[0], $args[1], $args[2]);
            }
        };
    }
}
