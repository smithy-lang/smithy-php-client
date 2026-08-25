<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Registry of Smithy rules-engine standard-library functions.
 *
 * Implements the spec surface documented in "Endpoints 2.0: Rule-Set
 * Standard Library": `isSet`, `not`, `getAttr`, `substring`,
 * `stringEquals`, `booleanEquals`, `coalesce`, `split`, `ite`,
 * `uriEncode`, `parseURL`, and `isValidHostLabel`.
 *
 * Not every stdlib function dispatches through this registry at run
 * time. The compiler prefers dedicated opcodes for fast conditions and
 * for `getAttr` chains, but the registry still resolves those names at
 * compile time.
 *
 * Consumers get a fully populated `array<string, RulesFunctionInterface>`
 * from {@see StdFunctions::all()} and pass it to the BDD compiler,
 * usually merged with vendor-specific extension registries.
 */
final class StdFunctions
{
    /** @var array<string, RulesFunctionInterface>|null */
    private static ?array $cache = null;

    private function __construct() {}

    /**
     * Look up a single stdlib function by name.
     *
     * The generated endpoint-bytecode class uses this method to rebuild
     * its function table without constructing the whole registry itself.
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
            'booleanEquals' => self::booleanEquals(),
            'stringEquals' => self::stringEquals(),
            'isSet' => self::isSet(),
            'not' => self::not(),
            'getAttr' => self::getAttr(),
            'substring' => self::substring(),
            'uriEncode' => self::uriEncode(),
            'parseURL' => self::parseURL(),
            'isValidHostLabel' => self::isValidHostLabel(),
            'coalesce' => self::coalesce(),
            'split' => self::split(),
            'ite' => self::ite()
        ];
    }

    private static function booleanEquals(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'booleanEquals',
            2,
            static fn (array $args): mixed => \is_bool($args[0])
                && $args[0] === $args[1]
        );
    }

    private static function stringEquals(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'stringEquals',
            2,
            static fn (array $args): mixed => \is_string($args[0])
                && $args[0] === $args[1]
        );
    }

    private static function isSet(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'isSet',
            1,
            static fn (array $args): mixed => $args[0] !== null
        );
    }

    private static function not(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'not',
            1,
            static fn (array $args): mixed => $args[0] !== true
        );
    }

    private static function getAttr(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'getAttr',
            2,
            static function (array $args): mixed {
                [$value, $path] = $args;
                if (!\is_array($value) || !\is_string($path)) {
                    return null;
                }

                if (\preg_match(
                    '/^([A-Za-z0-9_]+)\[(-?\d+)\]$/',
                    $path,
                    $match
                )) {
                    $array = $value[$match[1]] ?? null;
                    if (!\is_array($array)) {
                        return null;
                    }

                    $index = (int) $match[2];
                    if ($index < 0) {
                        $index += \count($array);
                    }

                    return $array[$index] ?? null;
                }

                return $value[$path] ?? null;
            }
        );
    }

    private static function substring(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'substring',
            4,
            static function (array $args): mixed {
                [$input, $start, $stop, $reverse] = [
                    $args[0] ?? null,
                    $args[1] ?? 0,
                    $args[2] ?? 0,
                    (bool) ($args[3] ?? false)
                ];

                if (!\is_string($input)
                    || !\is_int($start)
                    || !\is_int($stop)
                ) {
                    return null;
                }

                if ($start >= $stop) {
                    return null;
                }

                $length = \strlen($input);
                if ($length < $stop) {
                    return null;
                }

                if (\preg_match('/[\x80-\xff]/', $input) === 1) {
                    return null;
                }

                return $reverse
                    ? \substr($input, $length - $stop, $stop - $start)
                    : \substr($input, $start, $stop - $start);
            }
        );
    }

    private static function uriEncode(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'uriEncode',
            1,
            static fn (array $args): mixed => \is_string($args[0])
                ? \rawurlencode($args[0])
                : null
        );
    }

    private static function parseURL(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'parseURL',
            1,
            static function (array $args): mixed {
                $value = $args[0] ?? null;
                if (!\is_string($value) || \str_contains($value, '?')) {
                    return null;
                }

                $parts = @\parse_url($value);
                if ($parts === false
                    || empty($parts['scheme'])
                    || empty($parts['host'])
                ) {
                    return null;
                }

                if (isset($parts['query']) || isset($parts['fragment'])) {
                    return null;
                }

                $scheme = (string) $parts['scheme'];
                $host = (string) $parts['host'];
                $port = $parts['port'] ?? null;
                $authority = $host;
                if ($port !== null) {
                    $authority .= ':' . $port;
                }

                $path = $parts['path'] ?? '';
                if ($path === '') {
                    $path = '/';
                }

                $normalized = $path;
                if ($normalized === '' || $normalized[0] !== '/') {
                    $normalized = '/' . $normalized;
                }
                if (\substr($normalized, -1) !== '/') {
                    $normalized .= '/';
                }

                $isIp = false;
                if (\str_starts_with($host, '[')
                    && \str_ends_with($host, ']')
                ) {
                    $isIp = true;
                }
                if (!$isIp && self::isIp($host, \FILTER_FLAG_IPV4)) {
                    $isIp = true;
                }
                if (!$isIp && self::isIp($host, \FILTER_FLAG_IPV6)) {
                    $isIp = true;
                }
                if ($isIp
                    && self::isIp($host, \FILTER_FLAG_IPV6)
                    && $host[0] !== '['
                ) {
                    $authority = '[' . $host . ']'
                        . ($port !== null ? ':' . $port : '');
                }

                return [
                    'scheme' => $scheme,
                    'authority' => $authority,
                    'path' => $path,
                    'normalizedPath' => $normalized,
                    'isIp' => $isIp
                ];
            }
        );
    }

    private static function isValidHostLabel(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'isValidHostLabel',
            2,
            static function (array $args): mixed {
                [$value, $allowSubDomains] = $args;
                if (!\is_string($value)) {
                    return false;
                }

                if ($allowSubDomains === true) {
                    foreach (\explode('.', $value) as $label) {
                        if (!self::isLabel($label)) {
                            return false;
                        }
                    }

                    return true;
                }

                return self::isLabel($value);
            }
        );
    }

    private static function coalesce(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'coalesce',
            -1,
            static function (array $args): mixed {
                $count = \count($args);
                for ($i = 0; $i < $count - 1; $i++) {
                    if ($args[$i] !== null) {
                        return $args[$i];
                    }
                }

                return $args[$count - 1] ?? null;
            }
        );
    }

    private static function split(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'split',
            3,
            static function (array $args): mixed {
                [$value, $delimiter, $limit] = $args;
                if (!\is_string($value)
                    || !\is_string($delimiter)
                    || !\is_int($limit)
                    || $delimiter === ''
                    || $limit < 0
                ) {
                    return null;
                }

                if ($value === '') {
                    return [''];
                }

                if ($limit === 1) {
                    return [$value];
                }

                return \explode(
                    $delimiter,
                    $value,
                    $limit === 0 ? \PHP_INT_MAX : $limit
                );
            }
        );
    }

    private static function ite(): RulesFunctionInterface
    {
        return new StdRulesFunction(
            'ite',
            3,
            static fn (array $args): mixed => $args[0] === true
                ? $args[1]
                : $args[2]
        );
    }

    private static function isLabel(string $label): bool
    {
        return (bool) \preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/',
            $label
        );
    }

    private static function isIp(string $host, int $flag): bool
    {
        return \filter_var($host, \FILTER_VALIDATE_IP, $flag) !== false;
    }
}

/**
 * Smithy endpoint standard-library function.
 */
final class StdRulesFunction implements RulesFunctionInterface
{
    /**
     * @param \Closure(list<mixed>): mixed $handler
     */
    public function __construct(
        private readonly string $name,
        private readonly int $argumentCount,
        private readonly \Closure $handler
    ) {}

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getRegistryClass(): string
    {
        return StdFunctions::class;
    }

    /**
     * @inheritDoc
     */
    public function getArgumentCount(): int
    {
        return $this->argumentCount;
    }

    /**
     * @inheritDoc
     */
    public function apply0(): mixed
    {
        return $this->invoke([]);
    }

    /**
     * @inheritDoc
     */
    public function apply1(mixed $a0): mixed
    {
        return $this->invoke([$a0]);
    }

    /**
     * @inheritDoc
     */
    public function apply2(mixed $a0, mixed $a1): mixed
    {
        return $this->invoke([$a0, $a1]);
    }

    /**
     * @inheritDoc
     */
    public function apply3(mixed $a0, mixed $a1, mixed $a2): mixed
    {
        return $this->invoke([$a0, $a1, $a2]);
    }

    /**
     * @inheritDoc
     */
    public function apply(array $args): mixed
    {
        return $this->invoke($args);
    }

    /**
     * Invoke the handler when arity matches.
     *
     * @param list<mixed> $args
     */
    private function invoke(array $args): mixed
    {
        if ($this->argumentCount !== -1
            && \count($args) !== $this->argumentCount
        ) {
            return null;
        }

        return ($this->handler)($args);
    }
}
