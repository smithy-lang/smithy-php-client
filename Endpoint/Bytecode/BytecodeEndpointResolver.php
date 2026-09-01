<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

use JmesPath\Env;
use Smithy\Client\Endpoint\Endpoint;
use Smithy\Client\Endpoint\EndpointResolverInterface;
use Smithy\Client\Endpoint\EndpointResolverParams;
use Smithy\Core\Serde\{
    DynamicTraitValue,
    Schema,
    SerializableStructInterface
};
use Smithy\Core\Shape\OperationInterface;
use Smithy\Core\Shape\Utils\ArrayShapeSerializer;

/**
 * Adapts an {@see Evaluator} to the Smithy client contract.
 *
 * The resolver builds the parameter map from modeled operation context and
 * client-supplied endpoint parameters, delegates to the evaluator, and
 * returns the resulting {@see Endpoint}.
 *
 * Endpoint rule evaluation remains in {@see Evaluator}. This adapter owns
 * parameter collection and result caching.
 *
 * Successful resolutions are cached by the bytecode's declared endpoint
 * inputs. The cache has a per-resolver last-result slot and a bounded
 * process-wide map. Keys include resolver configuration so different
 * static contexts and auth mappings remain isolated.
 *
 * @internal Caching is a private implementation detail. External code
 * should depend on {@see EndpointResolverInterface} - the cache shape,
 * eviction policy, and default size may change without notice.
 */
final class BytecodeEndpointResolver implements EndpointResolverInterface
{
    private const CONTEXT_PARAM_TRAIT = 'smithy.rules#contextParam';
    private const OPERATION_CONTEXT_PARAMS_TRAIT =
        'smithy.rules#operationContextParams';
    private const STATIC_CONTEXT_PARAMS_TRAIT =
        'smithy.rules#staticContextParams';

    /**
     * Default upper bound on the L2 cache. 128 covers typical client
     * working sets while capping memory at a few kilobytes of
     * `Endpoint` values.
     */
    private const CACHE_MAX_ENTRIES = 128;

    private const KEY_MISSING = "\x00";
    private const KEY_NULL = "\x01";
    private const KEY_FALSE = "\x02";
    private const KEY_TRUE = "\x03";
    private const KEY_STRING = "\x04";
    private const KEY_INTEGER = "\x05";
    private const KEY_SERIALIZED = "\x06";

    private readonly Evaluator $evaluator;

    private readonly Bytecode $bytecode;

    private readonly ?OpcodeHandlerInterface $handler;

    /** @var array<string, string> */
    private readonly array $endpointAuthSchemeIds;

    /**
     * Snapshot of `$bytecode->inputRegisterMap`'s keys, captured once at
     * construction. Iterating this list on every resolve gives the
     * canonical parameter ordering that also drives the cache-key
     * layout - anything outside this set is ignored, matching the
     * evaluator's own contract.
     *
     * @var list<string>
     */
    private readonly array $inputNames;

    /** @var array<string, int> */
    private readonly array $inputRegisterMap;

    /**
     * Effective cache cap. Zero disables the L2 map (but the L1 slot
     * still fires for consecutive-identical resolves - that's just two
     * property assignments, not worth suppressing).
     */
    private readonly int $cacheMax;

    /**
     * Namespace prefix prepended to every L2 cache key produced by this
     * resolver. It encodes the bytecode identity and resolver
     * configuration. Resolvers share entries only when their bytecode,
     * static context, auth mappings, and key builder match.
     *
     * Each namespace component is length-prefixed so its boundary with
     * the cache-key body is unambiguous.
     */
    private readonly string $resolverNamespace;

    /**
     * L1: last resolved key. The endpoint slot determines whether this
     * key has been populated, so an empty key remains valid for a
     * ruleset with no inputs.
     */
    private string $lastKey = '';

    private ?Endpoint $lastEndpoint = null;

    /**
     * Shared L2 cache across every resolver in the process. Keys carry
     * their resolver's {@see self::$resolverNamespace} prefix so two
     * resolvers built from different bytecodes, static contexts, or
     * endpoint auth mappings stay isolated. Eviction is FIFO across the
     * entire map.
     *
     * Marked `protected` so tests can reset it between cases. External
     * callers have no reason to touch it.
     *
     * @var array<string, array{
     *     Bytecode,
     *     array<string, mixed>,
     *     OpcodeHandlerInterface|null,
     *     \Closure|null,
     *     array<string, string>,
     *     Endpoint
     * }>
     */
    protected static array $sharedCache = [];

    /**
     * Optional codegen-supplied cache-key builder. When set, replaces
     * the reflective loop in {@see self::buildCacheKey()} with a fully
     * inlined per-service closure. The closure receives the same
     * `$inputs` map the loop would consume and must produce a stable
     * byte string using the key format documented above.
     *
     * Codegen emits a matching `<Service>EndpointKeyBuilder::build()`
     * when
     * {@see \Smithy\Codegen\CodegenSettings::$generateEndpointKeyBuilder}
     * is on. Dynamic clients leave this null and use the generic loop.
     *
     * @var (\Closure(array<string, mixed>): string)|null
     */
    private readonly ?\Closure $keyBuilder;

    /**
     * Cached context descriptors keyed by operation runtime identity.
     *
     * Tuple entries are context-param member bindings, JMESPath bindings,
     * and operation static parameters. False means the operation contributes
     * no endpoint context.
     *
     * @var array<string, false|array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * }>
     */
    private array $operationContexts = [];

    private ?OperationInterface $lastOperation = null;

    /**
     * @var false|array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * }
     */
    private array|false $lastOperationContext = false;

    /**
     * Create an endpoint resolver.
     *
     * @param Bytecode                     $bytecode       Compiled ruleset.
     * @param array<string, mixed>         $staticContext  Client-wide
     *                                     static parameters applied to
     *                                     every resolution.
     * @param OpcodeHandlerInterface|null  $handler        Optional vendor
     *                                     handler for opcodes >= 56.
     * @param int|null                     $cacheSize      Cap on the L2
     *                                     result cache. `null` uses
     *                                     {@see self::CACHE_MAX_ENTRIES}.
     *                                     `0` disables the L2 map.
     * @param (\Closure(array<string, mixed>): string)|null $keyBuilder
     *                                Optional codegen-supplied cache-key builder. See
     *                                {@see self::$keyBuilder} for the contract. Dynamic clients
     *                                leave this null.
     * @param array<string, string> $endpointAuthSchemeIds Raw endpoint
     *                                auth scheme names mapped to canonical
     *                                Smithy shape IDs.
     */
    public function __construct(
        Bytecode $bytecode,
        private readonly array $staticContext = [],
        ?OpcodeHandlerInterface $handler = null,
        ?int $cacheSize = null,
        ?\Closure $keyBuilder = null,
        array $endpointAuthSchemeIds = []
    )
    {
        foreach ($endpointAuthSchemeIds as $name => $schemeId) {
            if (!\is_string($name)
                || $name === ''
                || !\is_string($schemeId)
                || $schemeId === ''
            ) {
                throw new \InvalidArgumentException(
                    'Endpoint auth scheme mappings must contain non-empty '
                    . 'names mapped to non-empty Smithy shape IDs.'
                );
            }
        }

        $this->bytecode = $bytecode;
        $this->handler = $handler;
        $this->endpointAuthSchemeIds = $endpointAuthSchemeIds;
        $this->evaluator = new Evaluator(
            $bytecode,
            $handler,
            $endpointAuthSchemeIds
        );
        // `array_keys` on the input-register map returns the parameter
        // names in the compiled ordering (PHP arrays are insertion-
        // ordered), which is exactly the order the evaluator itself
        // iterates them in.
        $this->inputNames = \array_keys($bytecode->inputRegisterMap);
        $this->inputRegisterMap = $bytecode->inputRegisterMap;
        $this->cacheMax = $cacheSize ?? self::CACHE_MAX_ENTRIES;
        $this->keyBuilder = $keyBuilder;

        // Build a namespace prefix identifying the resolver configuration.
        // The shared-cache entry also keeps the actual identity values so
        // recycled object IDs cannot return stale endpoints.
        $bytecodeId = \spl_object_id($bytecode);
        $resolverConfig = [
            $staticContext,
            $endpointAuthSchemeIds,
            $keyBuilder === null ? null : \spl_object_id($keyBuilder),
            $handler === null ? null : \spl_object_id($handler),
        ];
        $configDigest = $staticContext === []
            && $endpointAuthSchemeIds === []
            && $keyBuilder === null
            && $handler === null
            ? ''
            : \hash('xxh3', \serialize($resolverConfig), binary: true);
        $bytecodeKey = (string) $bytecodeId;
        $this->resolverNamespace = \strlen($bytecodeKey)
            . ':' . $bytecodeKey
            . \strlen($configDigest)
            . ':' . $configDigest;
    }

    public function resolveEndpoint(EndpointResolverParams $params): Endpoint
    {
        $operation = $params->operation;
        if ($operation === $this->lastOperation) {
            $operationContext = $this->lastOperationContext;
        } else {
            $identity = $operation->getRuntimeIdentity();
            $operationContext = $this->operationContexts[$identity]
                ??= $this->createOperationContext($operation);
            $this->lastOperation = $operation;
            $this->lastOperationContext = $operationContext;
        }

        $inputs = $this->staticContext;

        if ($operationContext !== false) {
            foreach (
                $this->collectOperationContext(
                    $operationContext,
                    $params->input
                ) as $name => $value
            ) {
                $inputs[$name] = $value;
            }
        }

        $context = $params->config->context[
            EndpointResolverParams::CONTEXT_PARAMETERS
        ] ?? [];
        if (!\is_array($context)) {
            throw new \InvalidArgumentException(
                'Endpoint parameters must be an associative array.'
            );
        }
        foreach ($context as $name => $value) {
            if (\is_string($name)) {
                $inputs[$name] = $value;
            }
        }

        $key = $this->buildCacheKey($inputs);

        // L1 fast path: back-to-back identical resolve on this
        // resolver instance. `$this->lastKey` is the unqualified body
        // (no namespace prefix) so the compare stays a single `===` on
        // a small string.
        if ($key === $this->lastKey && $this->lastEndpoint !== null) {
            return $this->lastEndpoint;
        }

        // L2: process-wide FIFO map. Prepend the resolver namespace so
        // this resolver only looks at entries with the same identity-shaped
        // key, then verify the retained identities before using the endpoint.
        $namespacedKey = $this->resolverNamespace . $key;
        $cached = $this->cacheMax > 0
            ? (self::$sharedCache[$namespacedKey] ?? null)
            : null;
        if ($cached !== null && $this->isSharedCacheHit($cached)) {
            $this->lastKey = $key;
            return $this->lastEndpoint = $cached[5];
        }
        if ($cached !== null) {
            unset(self::$sharedCache[$namespacedKey]);
        }

        // Miss: run the evaluator. Errors propagate - we don't cache
        // exception paths (they're rare and the throw is cheap
        // relative to a full BDD walk anyway. Caching them would
        // require serializing the exception state which isn't worth
        // the code complexity).
        $endpoint = $this->evaluator->evaluate($inputs);

        // Populate both tiers. The L2 write only fires when the cap
        // is non-zero. The L1 slot always updates.
        if ($this->cacheMax > 0) {
            while (\count(self::$sharedCache) >= $this->cacheMax) {
                // FIFO eviction: drop the oldest entry. `array_shift`
                // on a string-keyed array is O(1) since PHP 7.x - no
                // numeric-index reindexing pass. Eviction is
                // process-wide, matching the cache's process-wide
                // shape.
                \array_shift(self::$sharedCache);
            }
            self::$sharedCache[$namespacedKey] = [
                $this->bytecode,
                $this->staticContext,
                $this->handler,
                $this->keyBuilder,
                $this->endpointAuthSchemeIds,
                $endpoint
            ];
        }
        $this->lastKey = $key;
        $this->lastEndpoint = $endpoint;
        return $endpoint;
    }

    /**
     * @param array{
     *     Bytecode,
     *     array<string, mixed>,
     *     OpcodeHandlerInterface|null,
     *     \Closure|null,
     *     array<string, string>,
     *     Endpoint
     * } $entry
     */
    private function isSharedCacheHit(array $entry): bool
    {
        return $entry[0] === $this->bytecode
            && $entry[1] === $this->staticContext
            && $entry[2] === $this->handler
            && $entry[3] === $this->keyBuilder
            && $entry[4] === $this->endpointAuthSchemeIds;
    }

    /**
     * Find the operation and input traits that contribute endpoint context.
     *
     * @return false|array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * }
     */
    private function createOperationContext(
        OperationInterface $operation
    ): array|false
    {
        $members = [];
        foreach (
            $operation->getInputSchema()->getMembersByIndex()
                as $member
        ) {
            $trait = self::traitValue(
                $member->getDirectTraitById(self::CONTEXT_PARAM_TRAIT)
            );
            $name = \is_array($trait) ? ($trait['name'] ?? null) : null;
            if (\is_string($name)
                && isset($this->inputRegisterMap[$name])
            ) {
                $members[] = [$member, $name];
            }
        }

        $operationSchema = $operation->getSchema();
        $paths = [];
        $pathTrait = self::traitValue(
            $operationSchema->getDirectTraitById(
                self::OPERATION_CONTEXT_PARAMS_TRAIT
            )
        );
        if (\is_array($pathTrait)) {
            foreach ($pathTrait as $name => $definition) {
                $path = \is_array($definition)
                    ? ($definition['path'] ?? null)
                    : null;
                if (\is_string($name)
                    && \is_string($path)
                    && isset($this->inputRegisterMap[$name])
                ) {
                    $paths[] = [$name, $path];
                }
            }
        }

        $static = [];
        $staticTrait = self::traitValue(
            $operationSchema->getDirectTraitById(
                self::STATIC_CONTEXT_PARAMS_TRAIT
            )
        );
        if (\is_array($staticTrait)) {
            foreach ($staticTrait as $name => $definition) {
                if (\is_string($name)
                    && \is_array($definition)
                    && \array_key_exists('value', $definition)
                    && isset($this->inputRegisterMap[$name])
                ) {
                    $static[$name] = $definition['value'];
                }
            }
        }

        return $members === [] && $paths === [] && $static === []
            ? false
            : [$members, $paths, $static];
    }

    /**
     * Materialize one operation's request-scoped endpoint context.
     *
     * @param array{
     *     list<array{Schema, string}>,
     *     list<array{string, string}>,
     *     array<string, mixed>
     * } $context
     *
     * @return array<string, mixed>
     */
    private function collectOperationContext(
        array $context,
        SerializableStructInterface $input
    ): array
    {
        $inputs = [];
        foreach ($context[0] as [$member, $name]) {
            $value = $input->getMemberValue($member);
            if ($member->isRequired()
                && (
                    $value === null
                    || $value === []
                    || (\is_string($value) && \trim($value) === '')
                )
            ) {
                throw new \InvalidArgumentException(
                    "Required endpoint context parameter {$name} must not be empty."
                );
            }
            if ($value !== null) {
                $inputs[$name] = $value;
            }
        }

        if ($context[1] !== []) {
            $document = ArrayShapeSerializer::toArray($input);
            foreach ($context[1] as [$name, $path]) {
                $value = Env::search($path, $document);
                if ($value !== null && !\array_key_exists($name, $inputs)) {
                    $inputs[$name] = $value;
                }
            }
        }

        foreach ($context[2] as $name => $value) {
            $inputs[$name] = $value;
        }

        return $inputs;
    }

    /**
     * Unwrap a runtime-preserved trait value.
     */
    private static function traitValue(mixed $trait): mixed
    {
        return $trait instanceof DynamicTraitValue
            ? $trait->value
            : $trait;
    }

    /**
     * Build a stable, prefix-free cache key from the input map. Only
     * keys listed in the bytecode's input register map contribute.
     *
     * @param array<string, mixed> $inputs Merged static + config +
     *                      per-request parameter map.
     */
    private function buildCacheKey(array $inputs): string
    {
        // Codegen-supplied inline builder wins when present: it knows
        // the register order and skips the reflective loop entirely.
        // Same wire format either way (see the class docblock for the
        // key layout the closure must produce).
        if ($this->keyBuilder !== null) {
            return ($this->keyBuilder)($inputs);
        }

        $key = '';
        foreach ($this->inputNames as $name) {
            if (!\array_key_exists($name, $inputs)) {
                $key .= self::KEY_MISSING;
                continue;
            }

            $value = $inputs[$name];
            if ($value === null) {
                $key .= self::KEY_NULL;
            } elseif ($value === false) {
                $key .= self::KEY_FALSE;
            } elseif ($value === true) {
                $key .= self::KEY_TRUE;
            } elseif (\is_string($value)) {
                $key .= self::KEY_STRING
                    . \strlen($value) . ':' . $value;
            } elseif (\is_int($value)) {
                $key .= self::KEY_INTEGER . $value . ';';
            } else {
                $encoded = \serialize($value);
                $key .= self::KEY_SERIALIZED
                    . \strlen($encoded) . ':' . $encoded;
            }
        }

        return $key;
    }
}
